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
 * duplica quem já foi convidado).
 */
function queue_service_checkins(PDO $db, int $serviceId, string $serviceTitle, string $serviceDate, ?string $timeStart, int $churchId): void {
    $startAt = strtotime($serviceDate . ' ' . ($timeStart ?: '09:00:00'));
    $sendAt  = $startAt + SERVICE_CHECKIN_MINUTES_AFTER_START * 60;
    $delayMinutes = max(0, (int)round(($sendAt - time()) / 60));

    $members = $db->prepare("
        SELECT id, phone FROM members
        WHERE church_id = ? AND status = 'active' AND phone IS NOT NULL AND phone != ''
    ");
    $members->execute([$churchId]);
    $members = $members->fetchAll();

    $existing = $db->prepare("SELECT member_id FROM service_checkins WHERE service_id = ?");
    $existing->execute([$serviceId]);
    $existing = array_flip($existing->fetchAll(PDO::FETCH_COLUMN));

    $insert = $db->prepare("INSERT INTO service_checkins (service_id, member_id, checkin_token) VALUES (?,?,?)");
    $timeLabel = $timeStart ? substr($timeStart, 0, 5) : '';
    $message = "🙏 *{$serviceTitle}*" . ($timeLabel ? " ($timeLabel)" : '') . "\n\nVocê está no culto hoje? Confirme sua presença!";

    foreach ($members as $m) {
        if (isset($existing[$m['id']])) continue; // já convidado (ou já confirmou) antes

        $token = bin2hex(random_bytes(32));
        $insert->execute([$serviceId, $m['id'], $token]);

        $checkinUrl = APP_URL . '/checkin.php?token=' . $token;
        queue_whatsapp($m['phone'], $message, $churchId, [['label' => '✅ Presente', 'url' => $checkinUrl]], $delayMinutes, $serviceId);
    }
}
