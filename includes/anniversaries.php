<?php
// Mensagem de aniversário de casamento: mesmo mecanismo do aniversário de
// nascimento, usando members.wedding_date. Uma vez por ano, por pessoa.

const WEDDING_ANNIVERSARY_SEND_HOUR = 8; // só manda a partir dessa hora

function queue_wedding_anniversary_greetings(PDO $db): void {
    if ((int)date('G') < WEDDING_ANNIVERSARY_SEND_HOUR) return;

    $year = (int)date('Y');
    $due = $db->query("
        SELECT m.id, m.name, m.phone, m.church_id, m.wedding_date
        FROM members m
        WHERE m.status = 'active' AND m.phone IS NOT NULL AND m.phone != ''
          AND m.wedding_date IS NOT NULL
          AND MONTH(m.wedding_date) = MONTH(CURDATE()) AND DAY(m.wedding_date) = DAY(CURDATE())
          AND NOT EXISTS (SELECT 1 FROM wedding_anniversary_sent w WHERE w.member_id = m.id AND w.year = $year)
    ")->fetchAll();

    foreach ($due as $m) {
        // Reserva antes de enfileirar: duas execuções seguidas não duplicam
        $claim = $db->prepare("INSERT IGNORE INTO wedding_anniversary_sent (member_id, year, sent_at) VALUES (?, ?, NOW())");
        $claim->execute([$m['id'], $year]);
        if ($claim->rowCount() !== 1) continue;

        $firstName  = explode(' ', trim($m['name']))[0];
        $yearsCount = $year - (int)date('Y', strtotime($m['wedding_date']));
        $yearsPhrase = $yearsCount > 0 ? $yearsCount . ' ' . ($yearsCount === 1 ? 'ano' : 'anos') . ' de casados' : 'mais um ano de união';

        $message = "Feliz aniversário de casamento, {$firstName}! 💍\n\n"
                 . "Hoje vocês celebram {$yearsPhrase}! Que alegria ver essa aliança sendo honrada! "
                 . "Que Deus continue abençoando e fortalecendo esse casamento, renovando o amor e a cumplicidade entre vocês a cada ano.\n\n"
                 . "Receba o carinho e as orações de toda a nossa comunidade. Deus abençoe essa união! 🙏";

        queue_whatsapp($m['phone'], $message, (int)$m['church_id'], null, 0, null, 'wedding_anniversary');
    }
}
