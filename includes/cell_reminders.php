<?php
// Movimentação das células: lembrete aos membros no dia da reunião e, depois,
// cobrança do relatório pro líder. Chamadas a cada minuto pelo cron_whatsapp_queue.php.

// Quantas horas antes do horário de início da célula o lembrete de "hoje tem
// célula" é disparado pros membros.
const CELL_MEMBER_REMINDER_HOURS_BEFORE_START = 4;

// Quantas horas depois do horário de início da célula o líder é cobrado do
// relatório, se ainda não tiver criado um pra hoje.
const CELL_REPORT_REMINDER_HOURS_AFTER_START = 2;

// A cada quantas horas o lembrete se repete, enquanto o relatório não for
// criado (só dentro do mesmo dia — some sozinho quando o dia vira).
const CELL_REPORT_REMINDER_REPEAT_HOURS = 2;

/** Nome do dia da semana em português, a partir do valor salvo em cells.day_of_week. */
function cell_day_label(?string $dayOfWeek): string {
    static $days = ['monday' => 'Segunda', 'tuesday' => 'Terça', 'wednesday' => 'Quarta',
        'thursday' => 'Quinta', 'friday' => 'Sexta', 'saturday' => 'Sábado', 'sunday' => 'Domingo'];
    return $days[$dayOfWeek] ?? ($dayOfWeek ?: '');
}

/**
 * Já existe algum envio (de qualquer status) desse "kind" pra essa célula hoje?
 * Evita reenviar o mesmo lembrete se o cron rodar de novo no mesmo dia.
 */
function cell_reminder_already_queued_today(PDO $db, int $cellId, string $kind): bool {
    $stmt = $db->prepare("
        SELECT 1 FROM whatsapp_queue
        WHERE service_id = ? AND kind = ? AND created_at >= CURDATE()
        LIMIT 1
    ");
    $stmt->execute([$cellId, $kind]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Pros membros de cada célula ativa que se reúne hoje: lembrete de que hoje
 * tem célula, CELL_MEMBER_REMINDER_HOURS_BEFORE_START antes do horário de
 * início. Aditivo e seguro de chamar de novo a cada minuto — só manda uma
 * vez por célula por dia.
 */
function queue_cell_meeting_reminders_for_today(PDO $db): void {
    $today = strtolower(date('l'));
    $cells = $db->query("
        SELECT id, church_id, name, day_of_week, time_start, street, number, neighborhood
        FROM cells WHERE active = 1 AND day_of_week = " . $db->quote($today) . " AND time_start IS NOT NULL
    ")->fetchAll();

    foreach ($cells as $cell) {
        $triggerAt = strtotime(date('Y-m-d') . ' ' . $cell['time_start']) - CELL_MEMBER_REMINDER_HOURS_BEFORE_START * 3600;
        if (time() < $triggerAt) continue;
        if (cell_reminder_already_queued_today($db, (int)$cell['id'], 'cell_meeting')) continue;

        $members = $db->prepare("
            SELECT name, phone FROM members
            WHERE cell_id = ? AND church_id = ? AND status = 'active' AND phone IS NOT NULL AND phone != ''
        ");
        $members->execute([$cell['id'], $cell['church_id']]);
        $members = $members->fetchAll();
        if (!$members) continue;

        $timeLabel  = $cell['time_start'] ? substr($cell['time_start'], 0, 5) : '';
        $where      = trim(($cell['street'] ? $cell['street'] . ($cell['number'] ? ', ' . $cell['number'] : '') : '') . ($cell['neighborhood'] ? ' - ' . $cell['neighborhood'] : ''), ' -');
        $churchName = setting('church_name', 'Igreja', (int)$cell['church_id']);

        foreach ($members as $m) {
            $first = explode(' ', trim($m['name']))[0];
            $msg = "Olá, {$first}! 👋\n\n"
                 . "🙌 Hoje é dia de Célula *{$cell['name']}*!"
                 . ($timeLabel ? " às {$timeLabel}" : '')
                 . "\n"
                 . ($where ? "📍 {$where}\n" : '')
                 . "\nA célula é onde a gente cresce junto, ora, aprende e cuida uns dos outros. Não fica de fora hoje! 💙"
                 . "\n\n_{$churchName}_";
            queue_whatsapp($m['phone'], $msg, (int)$cell['church_id'], null, 0, (int)$cell['id'], 'cell_meeting');
        }
    }
}

/**
 * Quando foi o último lembrete de relatório mandado hoje pra essa célula
 * (null se nenhum ainda).
 */
function cell_last_report_reminder_at_today(PDO $db, int $cellId): ?string {
    $stmt = $db->prepare("
        SELECT MAX(created_at) FROM whatsapp_queue
        WHERE service_id = ? AND kind = 'cell_report' AND created_at >= CURDATE()
    ");
    $stmt->execute([$cellId]);
    return $stmt->fetchColumn() ?: null;
}

/**
 * A partir de CELL_REPORT_REMINDER_HOURS_AFTER_START depois do início de
 * cada célula que se reúne hoje, cobra o líder pelo relatório do dia — e
 * continua cobrando a cada CELL_REPORT_REMINDER_REPEAT_HOURS enquanto ele
 * não criar o relatório (para sozinho quando o relatório existe ou quando o
 * dia vira, já que a célula deixa de bater com o dia da semana de hoje).
 * Aditivo, chamado a cada minuto.
 */
function queue_cell_report_reminders_for_today(PDO $db): void {
    $today = strtolower(date('l'));
    $cells = $db->query("
        SELECT id, church_id, name, time_start
        FROM cells WHERE active = 1 AND day_of_week = " . $db->quote($today) . " AND time_start IS NOT NULL
    ")->fetchAll();

    foreach ($cells as $cell) {
        $already = $db->prepare("SELECT 1 FROM cell_reports WHERE cell_id = ? AND report_date = CURDATE() LIMIT 1");
        $already->execute([$cell['id']]);
        if ($already->fetchColumn()) continue; // líder já lançou o relatório de hoje

        $lastAt  = cell_last_report_reminder_at_today($db, (int)$cell['id']);
        $nextDue = $lastAt
            ? strtotime($lastAt) + CELL_REPORT_REMINDER_REPEAT_HOURS * 3600
            : strtotime(date('Y-m-d') . ' ' . $cell['time_start']) + CELL_REPORT_REMINDER_HOURS_AFTER_START * 3600;
        if (time() < $nextDue) continue;

        $leaders = $db->prepare("
            SELECT m.name, m.phone FROM cell_leaders cl JOIN members m ON m.id = cl.member_id
            WHERE cl.cell_id = ? AND m.phone IS NOT NULL AND m.phone != ''
        ");
        $leaders->execute([$cell['id']]);
        $leaders = $leaders->fetchAll();
        if (!$leaders) continue;

        $link   = APP_URL . '/pages/cells/report_create.php?cell_id=' . $cell['id'];
        $isRepeat = $lastAt !== null;

        foreach ($leaders as $l) {
            $first = explode(' ', trim($l['name']))[0];
            $msg = $isRepeat
                ? "Oi, {$first}! 👋\n\n"
                  . "📋 Ainda não recebi o relatório da Célula *{$cell['name']}* de hoje.\n\n"
                  . "Pode mandar quando puder? Leva só 1 minuto:\n"
                  . "👉 {$link}"
                : "Olá, {$first}! 👋\n\n"
                  . "📋 Como foi a Célula *{$cell['name']}* hoje?\n\n"
                  . "O relatório ajuda a liderança a acompanhar e cuidar de cada célula de verdade. Leva só 1 minuto:\n"
                  . "👉 {$link}";
            queue_whatsapp($l['phone'], $msg, (int)$cell['church_id'], null, 0, (int)$cell['id'], 'cell_report');
        }
    }
}
