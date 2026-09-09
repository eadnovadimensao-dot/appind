<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
$pageTitle  = 'Relatório Financeiro';
$activePage = 'finance';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db       = db();
$churchId = current_church_id();
$month    = (int)($_GET['month'] ?? date('n'));
$year     = (int)($_GET['year']  ?? date('Y'));
$monthNames = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];

$totals = $db->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount END),0) AS total_income, COALESCE(SUM(CASE WHEN type='expense' THEN amount END),0) AS total_expense FROM finance_entries WHERE church_id=? AND MONTH(entry_date)=? AND YEAR(entry_date)=?");
$totals->execute([$churchId,$month,$year]);
$totals = $totals->fetch();

$byCategory = $db->prepare("SELECT fc.name, fc.type, fc.color, COALESCE(SUM(fe.amount),0) AS total, COUNT(fe.id) AS count FROM finance_categories fc LEFT JOIN finance_entries fe ON fe.category_id=fc.id AND fe.church_id=? AND MONTH(fe.entry_date)=? AND YEAR(fe.entry_date)=? WHERE fc.church_id=? AND fc.active=1 GROUP BY fc.id HAVING total > 0 ORDER BY fc.type, total DESC");
$byCategory->execute([$churchId,$month,$year,$churchId]);
$byCategory = $byCategory->fetchAll();

$entries = $db->prepare("SELECT fe.*, fc.name AS cat_name, m.name AS member_name FROM finance_entries fe LEFT JOIN finance_categories fc ON fc.id=fe.category_id LEFT JOIN members m ON m.id=fe.member_id WHERE fe.church_id=? AND MONTH(fe.entry_date)=? AND YEAR(fe.entry_date)=? ORDER BY fe.type DESC, fe.entry_date ASC");
$entries->execute([$churchId,$month,$year]);
$entries = $entries->fetchAll();
?>
<div style="margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
  <h2 style="font-size:16px;font-weight:500">Relatório · <?= $monthNames[$month] ?> <?= $year ?></h2>
  <a href="/pages/finance/index.php" style="font-size:13px;color:var(--text-muted);text-decoration:none">← Financeiro</a>
</div>
<div class="card" style="margin-bottom:16px">
  <p class="card-title">Resumo do mês</p>
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;text-align:center">
    <div><div style="font-size:24px;font-weight:500;color:var(--green)">R$ <?= number_format($totals['total_income'],2,',','.') ?></div><div style="font-size:12px;color:var(--text-muted)">Total entradas</div></div>
    <div><div style="font-size:24px;font-weight:500;color:var(--red)">R$ <?= number_format($totals['total_expense'],2,',','.') ?></div><div style="font-size:12px;color:var(--text-muted)">Total saídas</div></div>
    <div><div style="font-size:24px;font-weight:500;color:<?= ($totals['total_income']-$totals['total_expense'])>=0?'var(--green)':'var(--red)' ?>">R$ <?= number_format($totals['total_income']-$totals['total_expense'],2,',','.') ?></div><div style="font-size:12px;color:var(--text-muted)">Saldo</div></div>
  </div>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
  <?php foreach(['income'=>'Entradas','expense'=>'Saídas'] as $t=>$label): $cats = array_filter($byCategory,fn($c)=>$c['type']===$t); ?>
  <div class="card" style="padding:0">
    <div style="padding:12px 18px;border-bottom:1px solid var(--border)"><p style="font-weight:500;font-size:14px"><?= $label ?> por categoria</p></div>
    <?php if(empty($cats)): ?><div class="empty-state" style="padding:20px;font-size:13px">Nenhum lançamento.</div>
    <?php else: foreach($cats as $c): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 18px;border-bottom:1px solid var(--border);font-size:13px">
        <span style="display:flex;align-items:center;gap:6px"><span style="width:8px;height:8px;border-radius:50%;background:<?= $c['color'] ?>;display:inline-block"></span><?= htmlspecialchars($c['name']) ?> <span style="color:var(--text-muted)">(<?= $c['count'] ?>)</span></span>
        <span style="font-weight:500;color:<?= $t==='income'?'var(--green)':'var(--red)' ?>">R$ <?= number_format($c['total'],2,',','.') ?></span>
      </div>
    <?php endforeach; endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<div class="card" style="padding:0">
  <div style="padding:12px 18px;border-bottom:1px solid var(--border)"><p style="font-weight:500;font-size:14px">Extrato completo</p></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Data</th><th>Descrição</th><th>Categoria</th><th>Membro</th><th style="text-align:right">Valor</th></tr></thead>
      <tbody>
        <?php foreach($entries as $e): ?>
          <tr>
            <td style="color:var(--text-muted);white-space:nowrap"><?= date('d/m/Y',strtotime($e['entry_date'])) ?></td>
            <td><?= htmlspecialchars($e['description']??$e['cat_name']??'—') ?><?= $e['campaign_name']?'<div style="font-size:11px;color:var(--accent)">🎯 '.htmlspecialchars($e['campaign_name']).'</div>':'' ?></td>
            <td style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($e['cat_name']??'—') ?></td>
            <td style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($e['member_name']??'—') ?></td>
            <td style="text-align:right;font-weight:500;color:<?= $e['type']==='income'?'var(--green)':'var(--red)' ?>;white-space:nowrap"><?= $e['type']==='income'?'+':'-' ?> R$ <?= number_format($e['amount'],2,',','.') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
