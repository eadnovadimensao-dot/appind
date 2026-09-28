<?php
// Mensagem de aniversário: todo dia, quem faz aniversário recebe uma mensagem
// pessoal por WhatsApp, uma vez só (controlado por ano, pra não duplicar se
// o cron rodar de novo no mesmo dia, e voltar a valer no aniversário seguinte).

const BIRTHDAY_SEND_HOUR = 8; // só manda a partir dessa hora

function queue_birthday_greetings(PDO $db): void {
    if ((int)date('G') < BIRTHDAY_SEND_HOUR) return;

    $year = (int)date('Y');
    $due = $db->query("
        SELECT m.id, m.name, m.phone, m.church_id
        FROM members m
        WHERE m.status = 'active' AND m.phone IS NOT NULL AND m.phone != ''
          AND m.birth_date IS NOT NULL
          AND MONTH(m.birth_date) = MONTH(CURDATE()) AND DAY(m.birth_date) = DAY(CURDATE())
          AND NOT EXISTS (SELECT 1 FROM birthday_greetings_sent b WHERE b.member_id = m.id AND b.year = $year)
    ")->fetchAll();

    foreach ($due as $m) {
        // Reserva antes de enfileirar: duas execuções seguidas não duplicam
        $claim = $db->prepare("INSERT IGNORE INTO birthday_greetings_sent (member_id, year, sent_at) VALUES (?, ?, NOW())");
        $claim->execute([$m['id'], $year]);
        if ($claim->rowCount() !== 1) continue;

        $firstName = explode(' ', trim($m['name']))[0];
        $message = "Feliz aniversário, {$firstName}! 🎉\n\n"
                 . "Hoje agradecemos a Deus pela sua vida e pela alegria de ter você em nossa igreja. "
                 . "Que o Senhor renove suas forças e encha este novo ano de vida de paz e bênçãos.\n\n"
                 . "Receba o carinho e as orações de toda a nossa comunidade. Deus te abençoe! 🙏";

        queue_whatsapp($m['phone'], $message, (int)$m['church_id'], null, 0, null, 'birthday');
    }
}
