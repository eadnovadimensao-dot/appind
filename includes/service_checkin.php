<?php
// Check-in geral de culto: manda uma mensagem com botão de WhatsApp pra
// todos os membros ativos da filial, no mesmo estilo do "✅ Cheguei" já
// usado pra quem está escalado num ministério. Aqui é pra congregação
// inteira, não só quem serve.

// Quantos minutos DEPOIS do início do culto manda o convite de check-in
// (dá tempo da maioria já ter chegado e sentado).
const SERVICE_CHECKIN_MINUTES_AFTER_START = 15;

/**
 * Garante um registro + token de check-in pra cada membro ativo da filial
 * que ainda não tem um pra esse culto, e enfileira o convite por WhatsApp.
 * Aditivo e seguro de chamar de novo a cada criação/edição do culto: nunca
 * mexe em quem já tem registro (preserva quem já confirmou presença e não
 * duplica quem já foi convidado). Quem já está escalado nesse culto
 * (service_scale) fica de fora — essas pessoas já recebem o "✅ Cheguei"
 * da escala do próprio ministério, com a antecedência configurada nele.
 */
function queue_service_checkins(PDO $db, int $serviceId, string $serviceTitle, string $serviceDate, ?string $timeStart, int $churchId): void {
    $startAt = strtotime($serviceDate . ' ' . ($timeStart ?: '09:00:00'));
    $sendAt  = $startAt + SERVICE_CHECKIN_MINUTES_AFTER_START * 60;
    $delayMinutes = max(0, (int)round(($sendAt - time()) / 60));

    // Fora do convite geral: quem está em service_scale OU já confirmou escala
    // num ministério nessa data (culto gerado automaticamente não preenche
    // service_scale, mas essas pessoas recebem o "Cheguei" do ministério).
    $members = $db->prepare("
        SELECT m.id, m.name, m.phone FROM members m
        WHERE m.church_id = ? AND m.status = 'active' AND m.phone IS NOT NULL AND m.phone != ''
          AND m.id NOT IN (SELECT member_id FROM service_scale WHERE service_id = ?)
          AND m.id NOT IN (
              SELECT mam.member_id FROM ministry_activity_members mam
              JOIN ministry_activities ma ON ma.id = mam.activity_id
              WHERE ma.activity_date = ? AND ma.church_id = ? AND ma.status != 'cancelled'
                AND mam.status = 'confirmed'
          )
    ");
    $members->execute([$churchId, $serviceId, $serviceDate, $churchId]);
    $members = $members->fetchAll();

    $existing = $db->prepare("SELECT member_id FROM service_checkins WHERE service_id = ?");
    $existing->execute([$serviceId]);
    $existing = array_flip($existing->fetchAll(PDO::FETCH_COLUMN));

    $insert = $db->prepare("INSERT INTO service_checkins (service_id, member_id, checkin_token) VALUES (?,?,?)");
    $timeLabel = $timeStart ? substr($timeStart, 0, 5) : '';

    foreach ($members as $m) {
        if (isset($existing[$m['id']])) continue; // já convidado (ou já confirmou) antes

        $token = bin2hex(random_bytes(32));
        $insert->execute([$serviceId, $m['id'], $token]);

        $firstName  = explode(' ', trim($m['name']))[0];
        $message    = "Olá, {$firstName}! 👋\n\n🙏 *{$serviceTitle}*" . ($timeLabel ? " ($timeLabel)" : '') . "\n\nVocê está no culto hoje?";
        queue_whatsapp($m['phone'], $message, $churchId, [['type' => 'reply', 'id' => 'presente', 'label' => '✅ Presente']], $delayMinutes, $serviceId, 'checkin');
    }
}

/**
 * Tira acento/caixa/emoji/pontuação pra comparar texto de resposta livre ou de botão
 * ("✅ Presente" do toque no botão, "Presente!!" digitado, "presença" etc viram "presente").
 */
function normalize_reply_text(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $map = ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c'];
    $s = strtr($s, $map);
    $s = preg_replace('/[^a-z0-9\s]/u', ' ', $s); // tira emoji e pontuação, mantém letra/número/espaço
    return trim(preg_replace('/\s+/', ' ', $s));
}

const CHECKIN_REPLY_WORDS = ['presente', 'presenca', 'aqui', 'cheguei', 'to aqui', 'estou aqui', 'sim'];

/**
 * Alguém respondeu uma mensagem do WhatsApp (texto digitado ou toque no botão de
 * resposta rápida) com um número de telefone que bate com check-in pendente (culto
 * ou escala de ministério, hoje). Confirma a presença se o texto for um dos termos
 * aceitos. Retorna a mensagem de confirmação pra responder, ou null se não achou
 * check-in pendente pra esse telefone ou o texto não bateu.
 * Chamada pelo webhook_zapi.php quando chega mensagem nova (não fromMe).
 */
function try_checkin_by_reply(PDO $db, string $rawPhone, string $text): ?string {
    $norm = normalize_reply_text($text);
    $matched = false;
    foreach (CHECKIN_REPLY_WORDS as $w) {
        if ($norm === $w || str_starts_with($norm, $w . ' ')) { $matched = true; break; }
    }
    if (!$matched) return null;

    // Check-in de culto (congregação): pendente de hoje, telefone bate depois de normalizar
    $rows = $db->query("
        SELECT sc.id, sc.checkin_token, m.name, m.phone
        FROM service_checkins sc
        JOIN services s ON s.id = sc.service_id
        JOIN members m ON m.id = sc.member_id
        WHERE sc.checked_in_at IS NULL AND s.service_date = CURDATE()
    ")->fetchAll();
    foreach ($rows as $r) {
        if (normalize_whatsapp_phone((string)$r['phone']) !== $rawPhone) continue;
        $db->prepare("UPDATE service_checkins SET checked_in_at = NOW() WHERE id = ?")->execute([$r['id']]);
        $first = explode(' ', trim($r['name']))[0];
        return "✅ Presença registrada, {$first}! Bom culto 🙏";
    }

    // Check-in de escala de ministério (quem está servindo hoje)
    $rows = $db->query("
        SELECT mam.activity_id, mam.member_id, m.name, m.phone
        FROM ministry_activity_members mam
        JOIN ministry_activities ma ON ma.id = mam.activity_id
        JOIN members m ON m.id = mam.member_id
        WHERE mam.checked_in_at IS NULL AND mam.checkin_token IS NOT NULL AND ma.activity_date = CURDATE()
    ")->fetchAll();
    foreach ($rows as $r) {
        if (normalize_whatsapp_phone((string)$r['phone']) !== $rawPhone) continue;
        $db->prepare("UPDATE ministry_activity_members SET checked_in_at = NOW() WHERE activity_id = ? AND member_id = ?")
           ->execute([$r['activity_id'], $r['member_id']]);
        $first = explode(' ', trim($r['name']))[0];
        return "✅ Presença registrada, {$first}! Bom culto/ensaio 🙏";
    }

    return null;
}

/**
 * Chamada pela rotina que roda a cada minuto (cron_whatsapp_queue.php):
 * pra cada culto de HOJE (de qualquer igreja), dentro da janela que vai de
 * 2h antes do início até o fim do culto, convida quem ainda não foi
 * convidado — inclusive quem se cadastrou depois do último Salvar. Não
 * depende de ninguém abrir e salvar o culto.
 */
function queue_service_checkins_for_today(PDO $db): void {
    $services = $db->query("
        SELECT id, church_id, title, service_date, time_start, time_end
        FROM services WHERE service_date = CURDATE() AND status != 'done'
    ")->fetchAll();

    foreach ($services as $s) {
        $start = strtotime($s['service_date'] . ' ' . ($s['time_start'] ?: '09:00:00'));
        $end   = $s['time_end'] ? strtotime($s['service_date'] . ' ' . $s['time_end']) : $start + 3 * 3600;
        $now   = time();
        if ($now < $start - 2 * 3600 || $now > $end) continue;
        queue_service_checkins($db, (int)$s['id'], $s['title'], $s['service_date'], $s['time_start'], (int)$s['church_id']);
    }
}

/** Pode ver e marcar presença: supermaster, supervisor de culto ou admin/pastor. */
function auth_can_take_attendance(): bool {
    return auth_can_edit_services() || auth_can('manage_members');
}

/**
 * Presença de um culto, uma linha por membro ativo da igreja:
 * status 'present' (via WhatsApp, marcação manual ou escala do ministério),
 * 'invited' (convite enviado, sem resposta) ou 'none' (sem convite).
 * Retorna ['rows' => [...], 'present' => n, 'invited' => n].
 */
function service_attendance(PDO $db, array $service): array {
    $sid = (int)$service['id'];
    $cid = (int)$service['church_id'];

    $sc = [];
    $q = $db->prepare("SELECT member_id, checked_in_at, checkin_source FROM service_checkins WHERE service_id = ?");
    $q->execute([$sid]);
    foreach ($q->fetchAll() as $r) $sc[(int)$r['member_id']] = $r;

    $mam = [];
    $q = $db->prepare("
        SELECT mam.member_id, MIN(mam.checked_in_at) AS at
        FROM ministry_activity_members mam
        JOIN ministry_activities ma ON ma.id = mam.activity_id
        WHERE ma.activity_date = ? AND ma.church_id = ? AND mam.checked_in_at IS NOT NULL
        GROUP BY mam.member_id
    ");
    $q->execute([$service['service_date'], $cid]);
    foreach ($q->fetchAll() as $r) $mam[(int)$r['member_id']] = $r['at'];

    $scaled = [];
    $q = $db->prepare("SELECT member_id FROM service_scale WHERE service_id = ?");
    $q->execute([$sid]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $m) $scaled[(int)$m] = true;

    $q = $db->prepare("SELECT id, name, phone FROM members WHERE church_id = ? AND status = 'active' ORDER BY name");
    $q->execute([$cid]);

    $rows = []; $present = 0; $invited = 0;
    foreach ($q->fetchAll() as $m) {
        $id = (int)$m['id'];
        $status = 'none'; $source = null; $at = null;
        if (!empty($sc[$id]['checked_in_at'])) {
            $status = 'present'; $source = $sc[$id]['checkin_source'] ?: 'whatsapp'; $at = $sc[$id]['checked_in_at'];
        } elseif (isset($mam[$id])) {
            $status = 'present'; $source = 'escala'; $at = $mam[$id];
        } elseif (isset($sc[$id])) {
            $status = 'invited';
        }
        if ($status === 'present') $present++;
        if ($status === 'invited') $invited++;
        $rows[] = ['id' => $id, 'name' => $m['name'], 'phone' => $m['phone'], 'status' => $status,
                   'source' => $source, 'at' => $at, 'scaled' => isset($scaled[$id])];
    }
    return ['rows' => $rows, 'present' => $present, 'invited' => $invited];
}
