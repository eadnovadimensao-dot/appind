<?php
// Bíblia Livre (BLIVRE, 2018) — Creative Commons Atribuição 4.0 Brasil.
// Texto: https://blivre.org — uso livre, inclusive comercial, com atribuição.
// Reconhece referência em texto livre ("João 3:16", "1co 13:4-7", "Salmos 23")
// e busca o texto real dos versículos na tabela bible_verses.

function bible_normalize(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = strtr($s, [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c',
    ]);
    // "i joão" / "ii joão" / "iii joão" -> "1 joão" / "2 joão" / "3 joão"
    $s = preg_replace('/^iii\s+/', '3 ', $s);
    $s = preg_replace('/^ii\s+/',  '2 ', $s);
    $s = preg_replace('/^i\s+/',   '1 ', $s);
    return $s;
}

// Dois mapas: um EXATO (só maiúscula/minúscula, preserva acento — "Jó" nunca
// se confunde com "Jo", a abreviação de João) e um normalizado sem acento
// como fallback (pra quem digita "Efesios" sem acento, por exemplo). O exato
// tem prioridade sempre — só cai pro normalizado se não achar no exato.
function bible_book_maps(PDO $db): array {
    static $maps = null;
    if ($maps !== null) return $maps;
    $exact = [];
    $normalized = [];
    $books = $db->query("SELECT DISTINCT book_order, book_abbrev, book_name FROM bible_verses ORDER BY book_order")->fetchAll();
    foreach ($books as $b) {
        foreach ([$b['book_abbrev'], $b['book_name']] as $key) {
            $lower = mb_strtolower(trim($key));
            $norm  = bible_normalize($key);
            foreach ([$lower, str_replace(' ', '', $lower)] as $k) { if (!isset($exact[$k])) $exact[$k] = $b; }
            foreach ([$norm, str_replace(' ', '', $norm)] as $k) { if (!isset($normalized[$k])) $normalized[$k] = $b; }
        }
    }
    $maps = ['exact' => $exact, 'normalized' => $normalized];
    return $maps;
}

/**
 * Interpreta uma referência em texto livre. Retorna null se não reconhecer.
 * Aceita: "João 3:16", "Jo 3:16-18", "Salmos 23" (capítulo inteiro),
 * "1 Coríntios 13.4-7", "1co 13:4".
 */
function bible_parse_reference(PDO $db, string $ref): ?array {
    $ref = trim($ref);
    if ($ref === '') return null;
    if (!preg_match('/^(.+?)\s+(\d+)(?:[:.](\d+)(?:\s*[-–]\s*(\d+))?)?\s*$/u', $ref, $m)) {
        return null;
    }
    $bookRaw    = trim($m[1]);
    $chapter    = (int)$m[2];
    $verseStart = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : null;
    $verseEnd   = isset($m[4]) && $m[4] !== '' ? (int)$m[4] : $verseStart;

    $maps      = bible_book_maps($db);
    $lowerKey  = mb_strtolower($bookRaw);
    $normKey   = bible_normalize($bookRaw);
    $book = $maps['exact'][$lowerKey]
         ?? $maps['exact'][str_replace(' ', '', $lowerKey)]
         ?? $maps['normalized'][$normKey]
         ?? $maps['normalized'][str_replace(' ', '', $normKey)]
         ?? null;
    if (!$book) return null;

    return [
        'book_abbrev' => $book['book_abbrev'],
        'book_name'   => $book['book_name'],
        'chapter'     => $chapter,
        'verse_start' => $verseStart,
        'verse_end'   => $verseEnd,
        'raw'         => $ref,
    ];
}

/** Busca os versículos de uma referência já interpretada. */
function bible_get_verses(PDO $db, array $parsed): array {
    if (empty($parsed['book_abbrev'])) return [];
    if ($parsed['verse_start']) {
        $stmt = $db->prepare("SELECT verse, text FROM bible_verses WHERE book_abbrev=? AND chapter=? AND verse BETWEEN ? AND ? ORDER BY verse");
        $stmt->execute([$parsed['book_abbrev'], $parsed['chapter'], $parsed['verse_start'], $parsed['verse_end']]);
    } else {
        $stmt = $db->prepare("SELECT verse, text FROM bible_verses WHERE book_abbrev=? AND chapter=? ORDER BY verse");
        $stmt->execute([$parsed['book_abbrev'], $parsed['chapter']]);
    }
    return $stmt->fetchAll();
}

/** "João 3:16-18" a partir de uma referência interpretada. */
function bible_format_reference(array $parsed): string {
    $ref = $parsed['book_name'] . ' ' . $parsed['chapter'];
    if (!empty($parsed['verse_start'])) {
        $ref .= ':' . $parsed['verse_start'];
        if (!empty($parsed['verse_end']) && $parsed['verse_end'] != $parsed['verse_start']) {
            $ref .= '-' . $parsed['verse_end'];
        }
    }
    return $ref;
}

/** Texto corrido dos versículos, com o número de cada um. */
function bible_format_text(array $verses): string {
    return implode(' ', array_map(fn($v) => "*{$v['verse']}* {$v['text']}", $verses));
}

// Quantos dias antes do culto o texto de meditação é enviado
const SCRIPTURE_MEDITATION_DAYS_BEFORE = 2;

/**
 * Sincroniza a fila de meditação com o que está salvo em service_scriptures
 * pra esse culto: cancela os avisos ainda não entregues (status='pending')
 * e enfileira de novo com o texto atual, pra todos os membros ativos da
 * filial — 2 dias antes da data do culto. Seguro chamar de novo a cada vez
 * que o culto é criado OU editado (não duplica; mensagens já entregues não
 * são recolhidas, mas as pendentes são atualizadas). Se não houver mais
 * nenhuma referência válida, só cancela o que estava pendente.
 */
function queue_scripture_meditation(PDO $db, int $serviceId, string $serviceTitle, string $serviceDate, int $churchId): void {
    // Cancela os avisos dessa meditação que ainda não foram entregues,
    // pra não mandar texto desatualizado se o culto for editado de novo.
    $db->prepare("DELETE FROM whatsapp_queue WHERE service_id = ? AND status = 'pending'")->execute([$serviceId]);

    $refs = $db->prepare("SELECT * FROM service_scriptures WHERE service_id = ? AND book_abbrev IS NOT NULL ORDER BY position");
    $refs->execute([$serviceId]);
    $refs = $refs->fetchAll();
    if (empty($refs)) {
        $db->prepare("UPDATE services SET meditation_queued_at = NULL WHERE id = ?")->execute([$serviceId]);
        return;
    }

    $blocks = [];
    foreach ($refs as $r) {
        $parsed = [
            'book_abbrev' => $r['book_abbrev'], 'book_name' => $r['book_name'],
            'chapter' => $r['chapter'], 'verse_start' => $r['verse_start'], 'verse_end' => $r['verse_end'],
        ];
        $verses = bible_get_verses($db, $parsed);
        if (empty($verses)) continue;
        $blocks[] = "📖 *" . bible_format_reference($parsed) . "*\n" . bible_format_text($verses);
    }
    if (empty($blocks)) {
        $db->prepare("UPDATE services SET meditation_queued_at = NULL WHERE id = ?")->execute([$serviceId]);
        return;
    }

    $churchName = setting('church_name', 'Igreja', $churchId);
    $body = "🙏 *Preparação para {$serviceTitle}*\n\nMedite nessa Palavra antes do culto:\n\n"
          . implode("\n\n", $blocks) . "\n\n_{$churchName}_";

    $sendAt = strtotime($serviceDate . ' -' . SCRIPTURE_MEDITATION_DAYS_BEFORE . ' days 08:00:00');
    $delayMinutes = max(0, (int)round(($sendAt - time()) / 60));

    $members = $db->prepare("SELECT name, phone FROM members WHERE church_id = ? AND status = 'active' AND phone IS NOT NULL AND phone != ''");
    $members->execute([$churchId]);
    foreach ($members->fetchAll() as $m) {
        $firstName = explode(' ', trim($m['name']))[0];
        $message   = "Olá, {$firstName}! 👋\n\n{$body}";
        queue_whatsapp($m['phone'], $message, $churchId, null, $delayMinutes, $serviceId);
    }

    $db->prepare("UPDATE services SET meditation_queued_at = NOW() WHERE id = ?")->execute([$serviceId]);
}
