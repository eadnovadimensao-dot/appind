<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/export_helpers.php';
auth_require('manage_finance');

$db       = db();
$churchId = current_church_id();
$month    = (int)($_GET['month'] ?? date('n'));
$year     = (int)($_GET['year']  ?? date('Y'));

$titheId = $db->query("SELECT id FROM finance_categories WHERE church_id=$churchId AND name='Dízimos' LIMIT 1")->fetchColumn();

$membersStmt = $db->prepare("
    SELECT m.id, m.name, m.phone, ch.name AS branch_name
    FROM members m
    JOIN churches ch ON ch.id = m.church_id
    WHERE ch.id = ? AND m.status = 'active'
    ORDER BY ch.name, m.name
");
$membersStmt->execute([current_church_id()]);
$members = $membersStmt->fetchAll();

$tithersThisMonth = $db->prepare("SELECT member_id, SUM(amount) AS total FROM finance_entries WHERE church_id=? AND category_id=? AND MONTH(entry_date)=? AND YEAR(entry_date)=? GROUP BY member_id");
$tithersThisMonth->execute([$churchId, $titheId, $month, $year]);
$tithersMap = [];
foreach ($tithersThisMonth->fetchAll() as $t) { $tithersMap[$t['member_id']] = $t['total']; }

$rows = [];
foreach ($members as $m) {
    $dizimou = isset($tithersMap[$m['id']]);
    $rows[] = [
        'Membro'   => $m['name'],
        'Telefone' => $m['phone'] ?? '',
        'Filial'   => $m['branch_name'] ?? '',
        'Status'   => $dizimou ? 'Dizimou' : 'Pendente',
        'Valor'    => $dizimou ? number_format($tithersMap[$m['id']], 2, ',', '') : '',
    ];
}

csv_download('dizimistas_' . $year . '-' . str_pad($month,2,'0',STR_PAD_LEFT) . '.csv', $rows);
