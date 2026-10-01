<?php
// Acompanhamento automático de visitante: sequência de 3 mensagens nas semanas
// depois do cadastro, e aviso pra liderança quando o visitante responde algo
// (sinal de interesse). Para sozinha se a pessoa virar membro ativo ou já
// estiver voltando (checkin registrado depois do cadastro).

const VISITOR_FOLLOWUP_HOUR = 9; // só manda a partir dessa hora

// dia => [numero do passo, mensagem]
const VISITOR_FOLLOWUP_STEPS = [
    1 => 3,   // passo 1, a partir de 3 dias
    2 => 7,   // passo 2, a partir de 7 dias
    3 => 21,  // passo 3, a partir de 21 dias
];

function visitor_followup_message(int $step, string $firstName): string {
    return match ($step) {
        1 => "Olá, {$firstName}! 👋 Foi muito bom ter você com a gente! Esperamos te ver de novo no próximo culto. Qualquer coisa que precisar, estamos à disposição. 🙏",
        2 => "Oi, {$firstName}! Tudo bem? Nos vemos domingo? Adoraríamos ter você de novo com a gente. 🙏",
        3 => "Olá, {$firstName}! Já pensou em fazer parte de uma célula? É um jeito bacana de criar laços com mais gente da igreja. Se quiser saber mais, é só responder essa mensagem. 🙏",
        default => '',
    };
}

/** Chamada pelo cron, uma vez por dia: manda o próximo passo pra quem está na janela certa. */
function queue_visitor_followups(PDO $db): void {
    if ((int)date('G') < VISITOR_FOLLOWUP_HOUR) return;

    $visitors = $db->query("
        SELECT id, name, phone, church_id, created_at
        FROM members
        WHERE status = 'visitor' AND phone IS NOT NULL AND phone != ''
    ")->fetchAll();

    foreach ($visitors as $v) {
        $daysSince = (int)floor((time() - strtotime($v['created_at'])) / 86400);

        // Já voltou a aparecer desde que virou visitante? Para o acompanhamento, não incomoda mais.
        $returnedQ = $db->prepare("
            SELECT COUNT(*) FROM (
                SELECT 1 FROM service_checkins WHERE member_id = ? AND checked_in_at IS NOT NULL AND checked_in_at > ?
                UNION ALL
                SELECT 1 FROM ministry_activity_members WHERE member_id = ? AND checked_in_at IS NOT NULL AND checked_in_at > ?
            ) x
        ");
        $returnedQ->execute([$v['id'], $v['created_at'], $v['id'], $v['created_at']]);
        if ((int)$returnedQ->fetchColumn() > 0) continue;

        $sent = $db->prepare("SELECT step FROM visitor_followups_sent WHERE member_id = ? AND step <= 3");
        $sent->execute([$v['id']]);
        $sentSteps = array_map('intval', $sent->fetchAll(PDO::FETCH_COLUMN));

        foreach (VISITOR_FOLLOWUP_STEPS as $step => $minDays) {
            if ($daysSince < $minDays) continue;
            if (in_array($step, $sentSteps, true)) continue;
            // Sequencial: não pula passo (só manda o 2 se o 1 já saiu, etc.)
            if ($step > 1 && !in_array($step - 1, $sentSteps, true)) continue;

            $claim = $db->prepare("INSERT IGNORE INTO visitor_followups_sent (member_id, step, sent_at) VALUES (?, ?, NOW())");
            $claim->execute([$v['id'], $step]);
            if ($claim->rowCount() !== 1) continue;

            $firstName = explode(' ', trim($v['name']))[0];
            queue_whatsapp($v['phone'], visitor_followup_message($step, $firstName), (int)$v['church_id'], null, 0, null, 'visitor_followup');
            break; // só um passo por execução, mesmo que tenha pulado vários dias
        }
    }
}

/**
 * Alguém com telefone de visitante (status='visitor') respondeu uma mensagem
 * de verdade no WhatsApp — sinal de interesse. Avisa os admins/pastores e os
 * líderes do ministério Check-in dessa igreja, no máximo uma vez por dia por
 * visitante (pra não floodar a liderança se a pessoa mandar várias mensagens).
 * Chamada pelo webhook_zapi.php quando nenhum check-in bateu com o texto.
 */
function notify_visitor_interest(PDO $db, string $rawPhone, string $text): bool {
    $visitors = $db->query("SELECT id, name, phone, church_id FROM members WHERE status = 'visitor' AND phone IS NOT NULL AND phone != ''")->fetchAll();
    $visitor = null;
    foreach ($visitors as $v) {
        if (normalize_whatsapp_phone((string)$v['phone']) === $rawPhone) { $visitor = $v; break; }
    }
    if (!$visitor) return false;

    $recent = $db->prepare("SELECT 1 FROM visitor_followups_sent WHERE member_id = ? AND step = 99 AND sent_at > DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $recent->execute([$visitor['id']]);
    if ($recent->fetchColumn()) return true; // já avisou a liderança hoje, não repete

    $db->prepare("INSERT INTO visitor_followups_sent (member_id, step, sent_at) VALUES (?, 99, NOW())
                  ON DUPLICATE KEY UPDATE sent_at = NOW()")
       ->execute([$visitor['id']]);

    $recipients = $db->prepare("
        SELECT DISTINCT m.phone FROM users u JOIN members m ON m.id = u.member_id
        WHERE u.church_id = ? AND u.role IN ('admin','supermaster') AND u.active = 1
          AND m.phone IS NOT NULL AND m.phone != ''
    ");
    $recipients->execute([$visitor['church_id']]);
    $phones = $recipients->fetchAll(PDO::FETCH_COLUMN);

    $leaders = $db->prepare("
        SELECT m.phone FROM ministry_leaders ml
        JOIN members m ON m.id = ml.member_id
        JOIN ministries mn ON mn.id = ml.ministry_id
        WHERE mn.name = 'Check-in' AND mn.church_id = ? AND m.phone IS NOT NULL AND m.phone != ''
    ");
    $leaders->execute([$visitor['church_id']]);
    foreach ($leaders->fetchAll(PDO::FETCH_COLUMN) as $p) $phones[] = $p;

    $phones = array_unique($phones);
    if (!$phones) return true;

    $viewUrl = APP_URL . '/pages/members/view.php?id=' . $visitor['id'];
    $msg = "🙋 *Visitante demonstrou interesse!*\n\n{$visitor['name']} respondeu:\n\"" . mb_substr($text, 0, 200) . "\"\n\nVale um contato! Ver cadastro:\n{$viewUrl}";
    foreach ($phones as $p) {
        queue_whatsapp($p, $msg, (int)$visitor['church_id'], null, 0, null, 'visitor_interest');
    }
    return true;
}
