<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/export_helpers.php';
auth_require('manage_finance');

$db       = db();
$churchId = current_church_id();
$month    = (int)($_GET['month'] ?? date('n'));
$year     = (int)($_GET['year']  ?? date('Y'));
$monthNames = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];

$totals = $db->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount END),0) AS total_income, COALESCE(SUM(CASE WHEN type='expense' THEN amount END),0) AS total_expense FROM finance_entries WHERE church_id=? AND MONTH(entry_date)=? AND YEAR(entry_date)=?");
$totals->execute([$churchId, $month, $year]);
$totals = $totals->fetch();
$saldo = $totals['total_income'] - $totals['total_expense'];

$byCategory = $db->prepare("SELECT fc.name, fc.type, COALESCE(SUM(fe.amount),0) AS total, COUNT(fe.id) AS count FROM finance_categories fc LEFT JOIN finance_entries fe ON fe.category_id=fc.id AND fe.church_id=? AND MONTH(fe.entry_date)=? AND YEAR(fe.entry_date)=? WHERE fc.church_id=? AND fc.active=1 GROUP BY fc.id HAVING total > 0 ORDER BY fc.type, total DESC");
$byCategory->execute([$churchId, $month, $year, $churchId]);
$byCategory = $byCategory->fetchAll();

$entries = $db->prepare("SELECT fe.*, fc.name AS cat_name, m.name AS member_name FROM finance_entries fe LEFT JOIN finance_categories fc ON fc.id=fe.category_id LEFT JOIN members m ON m.id=fe.member_id WHERE fe.church_id=? AND MONTH(fe.entry_date)=? AND YEAR(fe.entry_date)=? ORDER BY fe.type DESC, fe.entry_date ASC");
$entries->execute([$churchId, $month, $year]);
$entries = $entries->fetchAll();

print_page_start('Relatório Financeiro', $monthNames[$month] . ' ' . $year, $churchId);
?>
<table style="margin-bottom:24px">
  <tbody>
    <tr>
      <td style="font-weight:600;color:#0F6E56">Total de entradas</td>
      <td style="text-align:right;font-weight:600;color:#0F6E56">R$ <?= number_format($totals['total_income'],2,',','.') ?></td>
    </tr>
    <tr>
      <td style="font-weight:600;color:#A32D2D">Total de saídas</td>
      <td style="text-align:right;font-weight:600;color:#A32D2D">R$ <?= number_format($totals['total_expense'],2,',','.') ?></td>
    </tr>
    <tr>
      <td style="font-weight:700">Saldo do mês</td>
      <td style="text-align:right;font-weight:700;color:<?= $saldo >= 0 ? '#0F6E56' : '#A32D2D' ?>">R$ <?= number_format($saldo,2,',','.') ?></td>
    </tr>
  </tbody>
</table>

<h2 style="font-size:14px;margin-bottom:10px">Por categoria</h2>
<table style="margin-bottom:24px">
  <thead><tr><th>Categoria</th><th>Tipo</th><th style="text-align:right">Lançamentos</th><th style="text-align:right">Total</th></tr></thead>
  <tbody>
    <?php foreach ($byCategory as $c): ?>
      <tr>
        <td><?= htmlspecialchars($c['name']) ?></td>
        <td><?= $c['type'] === 'income' ? 'Entrada' : 'Saída' ?></td>
        <td style="text-align:right"><?= $c['count'] ?></td>
        <td style="text-align:right;font-weight:500;color:<?= $c['type']==='income'?'#0F6E56':'#A32D2D' ?>">R$ <?= number_format($c['total'],2,',','.') ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<h2 style="font-size:14px;margin-bottom:10px">Extrato completo</h2>
<table>
  <thead><tr><th>Data</th><th>Descrição</th><th>Categoria</th><th>Membro</th><th style="text-align:right">Valor</th></tr></thead>
  <tbody>
    <?php foreach ($entries as $e): ?>
      <tr>
        <td><?= date('d/m/Y', strtotime($e['entry_date'])) ?></td>
        <td><?= htmlspecialchars($e['description'] ?? $e['cat_name'] ?? '—') ?></td>
        <td><?= htmlspecialchars($e['cat_name'] ?? '—') ?></td>
        <td><?= htmlspecialchars($e['member_name'] ?? '—') ?></td>
        <td style="text-align:right;font-weight:500;color:<?= $e['type']==='income'?'#0F6E56':'#A32D2D' ?>">
          <?= $e['type']==='income'?'+':'-' ?> R$ <?= number_format($e['amount'],2,',','.') ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php print_page_end(); ?>
