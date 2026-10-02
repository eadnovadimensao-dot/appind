<?php
// Relatório mensal: resumo de crescimento/frequência pra liderança, no início
// de cada mês (reportando o mês anterior fechado). Mesma lógica alimenta o
// envio automático por WhatsApp e a tela de consulta no admin.

/** Monta o texto do relatório de uma filial pra um mês específico (1-12) de um ano. */
function monthly_report_text(PDO $db, int $churchId, int $year, int $month): string {
    $churchName = $db->query("SELECT name FROM churches WHERE id = $churchId")->fetchColumn() ?: 'Igreja';
    $monthNames = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
    $monthLabel = $monthNames[$month] . '/' . $year;

    $start = sprintf('%04d-%02d-01', $year, $month);
    $end   = date('Y-m-t', strtotime($start));

    $activeMembersQ = $db->prepare("SELECT COUNT(*) FROM members WHERE church_id = ? AND status = 'active'");
    $activeMembersQ->execute([$churchId]);
    $activeMembers = (int)$activeMembersQ->fetchColumn();

    $newMembers = $db->prepare("SELECT COUNT(*) FROM members WHERE church_id = ? AND created_at BETWEEN ? AND ?");
    $newMembers->execute([$churchId, $start . ' 00:00:00', $end . ' 23:59:59']);
    $newMembers = (int)$newMembers->fetchColumn();

    $visitorsWaiting = (int)$db->query("SELECT COUNT(*) FROM members WHERE church_id=$churchId AND status='visitor'")->fetchColumn();

    $services = $db->prepare("SELECT id FROM services WHERE church_id = ? AND type = 'sunday' AND service_date BETWEEN ? AND ?");
    $services->execute([$churchId, $start, $end]);
    $serviceIds = $services->fetchAll(PDO::FETCH_COLUMN);
    $avgAttendance = null;
    if ($serviceIds) {
        $ph = implode(',', array_fill(0, count($serviceIds), '?'));
        $total = $db->prepare("SELECT COUNT(*) FROM service_checkins WHERE checked_in_at IS NOT NULL AND service_id IN ($ph)");
        $total->execute($serviceIds);
        $avgAttendance = round(((int)$total->fetchColumn()) / count($serviceIds));
    }

    $activeCells = (int)$db->query("SELECT COUNT(*) FROM cells WHERE church_id=$churchId AND active=1")->fetchColumn();

    $msg = "📊 *Relatório mensal — {$monthLabel}*\n*{$churchName}*\n\n"
         . "👥 Membros ativos: {$activeMembers}\n"
         . "✨ Novos no mês: {$newMembers}\n"
         . "🙋 Visitantes aguardando acompanhamento: {$visitorsWaiting}\n";

    $msg .= $avgAttendance !== null
        ? "🙏 Frequência média aos domingos: {$avgAttendance} pessoas (" . count($serviceIds) . ' culto' . (count($serviceIds) === 1 ? '' : 's') . ")\n"
        : "🙏 Frequência média aos domingos: sem cultos registrados nesse mês\n";

    $msg .= "🔗 Células ativas: {$activeCells}\n\nDeus é bom! 🙌";

    return $msg;
}

/** Chamada pelo cron, uma vez por dia: no dia 1, manda o relatório do mês anterior pros admins de cada filial. */
function queue_monthly_reports(PDO $db): void {
    if ((int)date('j') !== 1) return;

    $prevMonth = (int)date('n', strtotime('-1 day'));
    $prevYear  = (int)date('Y', strtotime('-1 day'));
    $period    = sprintf('%04d-%02d', $prevYear, $prevMonth);

    foreach ($db->query("SELECT id FROM churches")->fetchAll(PDO::FETCH_COLUMN) as $churchId) {
        $claim = $db->prepare("INSERT IGNORE INTO monthly_report_sent (church_id, period, sent_at) VALUES (?, ?, NOW())");
        $claim->execute([$churchId, $period]);
        if ($claim->rowCount() !== 1) continue; // já mandado esse mês pra essa filial

        $recipients = $db->prepare("
            SELECT DISTINCT m.phone FROM users u JOIN members m ON m.id = u.member_id
            WHERE u.church_id = ? AND u.role IN ('admin','supermaster') AND u.active = 1
              AND m.phone IS NOT NULL AND m.phone != ''
        ");
        $recipients->execute([$churchId]);
        $phones = $recipients->fetchAll(PDO::FETCH_COLUMN);
        if (!$phones) continue;

        $text = monthly_report_text($db, (int)$churchId, $prevYear, $prevMonth);
        foreach ($phones as $p) queue_whatsapp($p, $text, (int)$churchId, null, 0, null, 'monthly_report');
    }
}
