<?php
// Devocional diário: um pastor escreve, fica salvo no portal e sai por WhatsApp
// pra todos os membros ativos (de todas as igrejas), assinado por quem escreveu.
// Membro pode se descadastrar (link na mensagem ou no portal).
require_once __DIR__ . '/bible.php';

// Hora do dia em que o devocional agendado pra hoje é enfileirado
const DEVOTIONAL_SEND_HOUR = 6;

/** Escreve devocional: supermaster ou membro cadastrado como autor. */
function auth_can_write_devotional(): bool {
    if (auth_role() === 'supermaster') return true;
    $memberId = auth_member_id();
    if (!$memberId) return false;
    static $cache = [];
    if (!array_key_exists($memberId, $cache)) {
        $q = db()->prepare("SELECT 1 FROM devotional_authors WHERE member_id = ?");
        $q->execute([$memberId]);
        $cache[$memberId] = (bool)$q->fetchColumn();
    }
    return $cache[$memberId];
}

/** Só quem escreveu (ou o supermaster) edita/apaga um devocional. */
function auth_can_edit_devotional(array $dev): bool {
    if (auth_role() === 'supermaster') return true;
    return auth_can_write_devotional() && $dev['author_member_id'] && (int)$dev['author_member_id'] === (int)auth_member_id();
}

/** Referências bíblicas do devocional com o texto dos versículos: [['reference','text'], ...]. */
function devotional_verse_blocks(PDO $db, int $devotionalId): array {
    $q = $db->prepare("SELECT * FROM devotional_scriptures WHERE devotional_id = ? AND book_abbrev IS NOT NULL ORDER BY position");
    $q->execute([$devotionalId]);
    $blocks = [];
    foreach ($q->fetchAll() as $r) {
        $parsed = ['book_abbrev' => $r['book_abbrev'], 'book_name' => $r['book_name'], 'chapter' => $r['chapter'],
                   'verse_start' => $r['verse_start'], 'verse_end' => $r['verse_end']];
        $verses = bible_get_verses($db, $parsed);
        if ($verses) $blocks[] = ['reference' => bible_format_reference($parsed), 'verses' => $verses];
    }
    return $blocks;
}

/** Salva as referências digitadas (troca as antigas) e devolve as que não foram reconhecidas. */
function devotional_save_scriptures(PDO $db, int $devotionalId, array $rawRefs): array {
    $db->prepare("DELETE FROM devotional_scriptures WHERE devotional_id = ?")->execute([$devotionalId]);
    $ins = $db->prepare("
        INSERT INTO devotional_scriptures (devotional_id, raw_reference, book_abbrev, book_name, chapter, verse_start, verse_end, position)
        VALUES (?,?,?,?,?,?,?,?)
    ");
    $unknown = [];
    $i = 0;
    foreach (array_values(array_filter(array_map('trim', $rawRefs), fn($r) => $r !== '')) as $raw) {
        $p = bible_parse_reference($db, $raw);
        if (!$p) $unknown[] = $raw;
        $ins->execute([$devotionalId, $raw, $p['book_abbrev'] ?? null, $p['book_name'] ?? null,
                       $p['chapter'] ?? null, $p['verse_start'] ?? null, $p['verse_end'] ?? null, $i++]);
    }
    return $unknown;
}

function devotional_message(PDO $db, array $dev, string $firstName): string {
    $text = "Olá, {$firstName}! 👋\n\n🌅 *Devocional de hoje*\n*{$dev['title']}*";
    foreach (devotional_verse_blocks($db, (int)$dev['id']) as $b) {
        $text .= "\n\n📖 *{$b['reference']}*\n" . bible_format_text($b['verses']);
    }
    $text .= "\n\n" . trim($dev['body']);
    $text .= "\n\n✍️ {$dev['author_name']}";
    $text .= "\n_" . setting('church_name', 'Igreja', SEDE_ID) . "_";
    return $text;
}

/** Token pessoal do membro (link de descadastro sem precisar de login). */
function devotional_token(PDO $db, int $memberId): string {
    $q = $db->prepare("SELECT devotional_token FROM members WHERE id = ?");
    $q->execute([$memberId]);
    $t = $q->fetchColumn();
    if (!$t) {
        $t = bin2hex(random_bytes(24));
        $db->prepare("UPDATE members SET devotional_token = ? WHERE id = ?")->execute([$t, $memberId]);
    }
    return $t;
}

/** Link do botão "Parar de receber" (abre a página pública de descadastro). */
function devotional_optout_url(PDO $db, int $memberId): string {
    return APP_URL . '/devocional_sair.php?token=' . devotional_token($db, $memberId);
}

/**
 * Descadastra/recadastra: vale pro telefone inteiro (pais e filhos costumam
 * dividir o mesmo número — se um pediu pra parar, o número para).
 */
function devotional_set_optout(PDO $db, int $memberId, bool $optout): void {
    $q = $db->prepare("SELECT phone FROM members WHERE id = ?");
    $q->execute([$memberId]);
    $norm = normalize_whatsapp_phone((string)$q->fetchColumn());
    $ids = [$memberId];
    if ($norm) {
        foreach ($db->query("SELECT id, phone FROM members WHERE phone IS NOT NULL AND phone != ''")->fetchAll() as $m) {
            if (normalize_whatsapp_phone($m['phone']) === $norm) $ids[] = (int)$m['id'];
        }
    }
    $ids = array_unique($ids);
    $db->prepare("UPDATE members SET devotional_optout = ? WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")")
       ->execute(array_merge([$optout ? 1 : 0], $ids));
}

/**
 * Membros que recebem: ativos com telefone, um por número, sem quem pediu pra
 * parar (basta um dos membros do mesmo número ter pedido).
 */
function devotional_recipients(PDO $db): array {
    $byPhone = [];
    foreach ($db->query("SELECT id, church_id, name, phone, status, devotional_optout FROM members WHERE phone IS NOT NULL AND phone != '' ORDER BY id")->fetchAll() as $m) {
        $n = normalize_whatsapp_phone($m['phone']);
        if (!$n) continue;
        $byPhone[$n][] = $m;
    }
    $out = [];
    foreach ($byPhone as $group) {
        foreach ($group as $m) if ($m['devotional_optout']) continue 2;
        foreach ($group as $m) if ($m['status'] === 'active') { $out[] = $m; continue 2; }
    }
    return $out;
}

function devotional_queue_for_member(PDO $db, array $dev, array $member): void {
    $url = devotional_optout_url($db, (int)$member['id']);
    $msg = devotional_message($db, $dev, explode(' ', trim($member['name']))[0]);
    queue_whatsapp($member['phone'], $msg, (int)$member['church_id'], [['label' => '🔕 Parar de receber', 'url' => $url]], 0, null, 'devotional');
}

/**
 * Chamada pela rotina de todo minuto: a partir das 6h, enfileira o devocional
 * agendado pra HOJE que ainda não saiu. Devocional com data passada (arquivo)
 * nunca é enviado, pra cadastrar antigos não disparar mensagem pra igreja toda.
 */
function queue_due_devotionals(PDO $db): void {
    if ((int)date('G') < DEVOTIONAL_SEND_HOUR) return;
    $due = $db->query("SELECT * FROM devotionals WHERE send_whatsapp = 1 AND sent_at IS NULL AND publish_date = CURDATE()")->fetchAll();
    foreach ($due as $dev) {
        // "reserva" o envio antes de enfileirar: duas execuções seguidas não duplicam
        $claim = $db->prepare("UPDATE devotionals SET sent_at = NOW() WHERE id = ? AND sent_at IS NULL");
        $claim->execute([$dev['id']]);
        if ($claim->rowCount() !== 1) continue;
        foreach (devotional_recipients($db) as $m) devotional_queue_for_member($db, $dev, $m);
    }
}
