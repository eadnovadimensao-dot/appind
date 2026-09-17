<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/export_helpers.php';
auth_require('manage_finance');

$db       = db();
$churchId = current_church_id();
$month    = (int)($_GET['month'] ?? date('n'));
$year     = (int)($_GET['year']  ?? date('Y'));

$entries = $db->prepare("
    SELECT fe.entry_date, fe.type, fe.amount, fe.description, fc.name AS cat_name, m.name AS member_name
    FROM finance_entries fe
    LEFT JOIN finance_categories fc ON fc.id = fe.category_id
    LEFT JOIN members m ON m.id = fe.member_id
    WHERE fe.church_id = ? AND MONTH(fe.entry_date) = ? AND YEAR(fe.entry_date) = ?
    ORDER BY fe.entry_date ASC
");
$entries->execute([$churchId, $month, $year]);
$entries = $entries->fetchAll();

$rows = [];
foreach ($entries as $e) {
    $rows[] = [
        'Data'       => date('d/m/Y', strtotime($e['entry_date'])),
        'Tipo'       => $e['type'] === 'income' ? 'Entrada' : 'Saída',
        'Categoria'  => $e['cat_name'] ?? '',
        'Descrição'  => $e['description'] ?? '',
        'Membro'     => $e['member_name'] ?? '',
        'Valor'      => number_format($e['amount'], 2, ',', ''),
    ];
}

csv_download('financeiro_' . $year . '-' . str_pad($month,2,'0',STR_PAD_LEFT) . '.csv', $rows);
