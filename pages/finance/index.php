<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require('manage_finance');
$pageTitle    = 'Financeiro';
$activePage   = 'finance';
$topbarAction = ['href' => '/pages/finance/create.php', 'label' => 'Novo lançamento'];
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db       = db();
$churchId = current_church_id();

// Período selecionado
$month = (int)($_GET['month'] ?? date('n'));
$year  = (int)($_GET['year']  ?? date('Y'));

$prevMonth = $month - 1; $prevYear = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $month + 1; $nextYear = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

$monthNames = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho',
               'Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];

// Totais do mês
$totals = $db->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN type='income'  THEN amount END), 0) AS total_income,
        COALESCE(SUM(CASE WHEN type='expense' THEN amount END), 0) AS total_expense,
        COUNT(CASE WHEN type='income'  THEN 1 END) AS count_income,
        COUNT(CASE WHEN type='expense' THEN 1 END) AS count_expense
    FROM finance_entries
    WHERE church_id = ? AND MONTH(entry_date)=? AND YEAR(entry_date)=?
");
$totals->execute([$churchId, $month, $year]);
$totals = $totals->fetch();
$balance = $totals['total_income'] - $totals['total_expense'];

// Totais por categoria
$byCategory = $db->prepare("
    SELECT fc.name, fc.color, fc.type, fc.id,
           COALESCE(SUM(fe.amount), 0) AS total,
           COUNT(fe.id) AS count
    FROM finance_categories fc
    LEFT JOIN finance_entries fe ON fe.category_id = fc.id
        AND fe.church_id = ? AND MONTH(fe.entry_date)=? AND YEAR(fe.entry_date)=?
    WHERE fc.church_id = ? AND fc.active = 1
    GROUP BY fc.id
    ORDER BY fc.type ASC, total DESC
");
$byCategory->execute([$churchId, $month, $year, $churchId]);
$byCategory = $byCategory->fetchAll();

// Dizimistas do mês
$tithers = $db->prepare("
    SELECT COUNT(DISTINCT member_id) AS count
    FROM finance_entries
    WHERE church_id=? AND category_id=(SELECT id FROM finance_categories WHERE church_id=? AND name='Dízimos' LIMIT 1)
      AND MONTH(entry_date)=? AND YEAR(entry_date)=?
");
$tithers->execute([$churchId, $churchId, $month, $year]);
$tithers = (int)$tithers->fetchColumn();

// Últimos lançamentos
$recent = $db->prepare("
    SELECT fe.*, fc.name AS category_name, fc.color, m.name AS member_name
    FROM finance_entries fe
    LEFT JOIN finance_categories fc ON fc.id = fe.category_id
    LEFT JOIN members m ON m.id = fe.member_id
    WHERE fe.church_id = ? AND MONTH(fe.entry_date)=? AND YEAR(fe.entry_date)=?
    ORDER BY fe.entry_date DESC, fe.id DESC
    LIMIT 10
");
$recent->execute([$churchId, $month, $year]);
$recent = $recent->fetchAll();

$incomeCategories  = array_filter($byCategory, fn($c) => $c['type']==='income'  && $c['total'] > 0);
$expenseCategories = array_filter($byCategory, fn($c) => $c['type']==='expense' && $c['total'] > 0);
?>

<!-- Navegação de mês -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap">
  <a href="?month=<?= $prevMonth ?>&year=<?= $prevYear ?>" class="btn btn-secondary" style="padding:6px 12px">‹</a>
  <h2 style="font-size:16px;font-weight:500;color:var(--text)"><?= $monthNames[$month] ?> <?= $year ?></h2>
  <a href="?month=<?= $nextMonth ?>&year=<?= $nextYear ?>" class="btn btn-secondary" style="padding:6px 12px">›</a>
  <a href="?month=<?= date('n') ?>&year=<?= date('Y') ?>" class="btn btn-secondary" style="font-size:12px">Mês atual</a>
  <div style="margin-left:auto;display:flex;gap:8px">
    <a href="/pages/finance/tithers.php?month=<?= $month ?>&year=<?= $year ?>" class="btn btn-secondary">📋 Dizimistas</a>
    <a href="/pages/finance/report.php?month=<?= $month ?>&year=<?= $year ?>" class="btn btn-secondary">📊 Relatório</a>
    <a href="/pages/finance/categories.php" class="btn btn-secondary">⚙ Categorias</a>
  </div>
</div>

<!-- KPIs -->
<div class="kpi-grid" style="margin-bottom:20px">
  <div class="kpi">
    <div class="kpi-icon">⬆️</div>
    <div class="kpi-label">Entradas</div>
    <div class="kpi-value" style="color:var(--green)">R$ <?= number_format($totals['total_income'],2,',','.') ?></div>
    <div style="font-size:11px;color:var(--text-muted);margin-top:4px"><?= $totals['count_income'] ?> lançamentos</div>
  </div>
  <div class="kpi">
    <div class="kpi-icon">⬇️</div>
    <div class="kpi-label">Saídas</div>
    <div class="kpi-value" style="color:var(--red)">R$ <?= number_format($totals['total_expense'],2,',','.') ?></div>
    <div style="font-size:11px;color:var(--text-muted);margin-top:4px"><?= $totals['count_expense'] ?> lançamentos</div>
  </div>
  <div class="kpi">
    <div class="kpi-icon">💼</div>
    <div class="kpi-label">Saldo do mês</div>
    <div class="kpi-value" style="color:<?= $balance >= 0 ? 'var(--green)' : 'var(--red)' ?>">
      R$ <?= number_format($balance,2,',','.') ?>
    </div>
  </div>
  <div class="kpi">
    <div class="kpi-icon">🙏</div>
    <div class="kpi-label">Dizimistas</div>
    <div class="kpi-value"><?= $tithers ?></div>
    <div style="font-size:11px;color:var(--text-muted);margin-top:4px">no mês</div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">

  <!-- Entradas por categoria -->
  <div class="card">
    <p class="card-title">Entradas por categoria</p>
    <?php if (empty($incomeCategories)): ?>
      <p style="font-size:13px;color:var(--text-muted)">Nenhuma entrada neste mês.</p>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:10px">
        <?php foreach ($incomeCategories as $cat): ?>
          <div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
              <div style="display:flex;align-items:center;gap:6px">
                <span style="width:10px;height:10px;border-radius:2px;background:<?= $cat['color'] ?>;display:inline-block;flex-shrink:0"></span>
                <span style="font-size:13px"><?= htmlspecialchars($cat['name']) ?></span>
              </div>
              <span style="font-size:13px;font-weight:500;color:var(--green)">R$ <?= number_format($cat['total'],2,',','.') ?></span>
            </div>
            <!-- Barra de progresso -->
            <?php $pct = $totals['total_income'] > 0 ? ($cat['total'] / $totals['total_income'] * 100) : 0; ?>
            <div style="height:4px;background:var(--border);border-radius:99px;overflow:hidden">
              <div style="height:100%;width:<?= min(100,$pct) ?>%;background:<?= $cat['color'] ?>;border-radius:99px"></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Saídas por categoria -->
  <div class="card">
    <p class="card-title">Saídas por categoria</p>
    <?php if (empty($expenseCategories)): ?>
      <p style="font-size:13px;color:var(--text-muted)">Nenhuma saída neste mês.</p>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:10px">
        <?php foreach ($expenseCategories as $cat): ?>
          <div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
              <div style="display:flex;align-items:center;gap:6px">
                <span style="width:10px;height:10px;border-radius:2px;background:<?= $cat['color'] ?>;display:inline-block;flex-shrink:0"></span>
                <span style="font-size:13px"><?= htmlspecialchars($cat['name']) ?></span>
              </div>
              <span style="font-size:13px;font-weight:500;color:var(--red)">R$ <?= number_format($cat['total'],2,',','.') ?></span>
            </div>
            <?php $pct = $totals['total_expense'] > 0 ? ($cat['total'] / $totals['total_expense'] * 100) : 0; ?>
            <div style="height:4px;background:var(--border);border-radius:99px;overflow:hidden">
              <div style="height:100%;width:<?= min(100,$pct) ?>%;background:<?= $cat['color'] ?>;border-radius:99px"></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Últimos lançamentos -->
<div class="card" style="padding:0">
  <div style="padding:14px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
    <p style="font-weight:500;font-size:14px">Lançamentos do mês</p>
    <a href="/pages/finance/list.php?month=<?= $month ?>&year=<?= $year ?>" style="font-size:12px;color:var(--accent);text-decoration:none">Ver todos →</a>
  </div>
  <?php if (empty($recent)): ?>
    <div class="empty-state" style="padding:32px">
      <p style="font-size:28px;margin-bottom:8px">💰</p>
      <p>Nenhum lançamento neste mês.</p>
      <a href="/pages/finance/create.php" class="btn btn-primary" style="margin-top:16px">+ Novo lançamento</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Data</th><th>Descrição</th><th>Categoria</th><th>Membro</th><th>Método</th><th style="text-align:right">Valor</th></tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $entry): ?>
            <tr>
              <td style="color:var(--text-muted);white-space:nowrap"><?= date('d/m/Y', strtotime($entry['entry_date'])) ?></td>
              <td>
                <div style="font-weight:500"><?= htmlspecialchars($entry['description'] ?? $entry['category_name'] ?? '—') ?></div>
                <?php if ($entry['campaign_name']): ?>
                  <div style="font-size:11px;color:var(--accent)">🎯 <?= htmlspecialchars($entry['campaign_name']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($entry['category_name']): ?>
                  <span style="display:inline-flex;align-items:center;gap:4px;font-size:12px">
                    <span style="width:8px;height:8px;border-radius:50%;background:<?= $entry['color'] ?? '#ccc' ?>;display:inline-block"></span>
                    <?= htmlspecialchars($entry['category_name']) ?>
                  </span>
                <?php else: ?>
                  <span style="color:var(--text-muted)">—</span>
                <?php endif; ?>
              </td>
              <td style="color:var(--text-muted);font-size:13px"><?= htmlspecialchars($entry['member_name'] ?? '—') ?></td>
              <td style="color:var(--text-muted);font-size:12px"><?= htmlspecialchars($entry['payment_method'] ?? '—') ?></td>
              <td style="text-align:right;font-weight:500;white-space:nowrap;color:<?= $entry['type']==='income'?'var(--green)':'var(--red)' ?>">
                <?= $entry['type']==='income'?'+':'-' ?> R$ <?= number_format($entry['amount'],2,',','.') ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
