<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
$pageTitle  = 'Dizimistas';
$activePage = 'finance';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db       = db();
$churchId = current_church_id();

$month = (int)($_GET['month'] ?? date('n'));
$year  = (int)($_GET['year']  ?? date('Y'));

$monthNames = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho',
               'Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];

// ID da categoria dízimos
$titheId = $db->query("SELECT id FROM finance_categories WHERE church_id=$churchId AND name='Dízimos' LIMIT 1")->fetchColumn();

// Membros ativos
$membersStmt = $db->prepare("
    SELECT m.id, m.name, m.cell_id, ch.name AS branch_name
    FROM members m
    JOIN churches ch ON ch.id = m.church_id
    WHERE (ch.id = ? OR ch.parent_id = ?) AND m.status = 'active'
    ORDER BY ch.name, m.name
");
$membersStmt->execute([SEDE_ID, SEDE_ID]);
$members = $membersStmt->fetchAll();

// Quem dizimou este mês
$tithersThisMonth = $db->prepare("
    SELECT member_id, SUM(amount) AS total
    FROM finance_entries
    WHERE church_id=? AND category_id=? AND MONTH(entry_date)=? AND YEAR(entry_date)=?
    GROUP BY member_id
");
$tithersThisMonth->execute([$churchId, $titheId, $month, $year]);
$tithersMap = [];
foreach ($tithersThisMonth->fetchAll() as $t) {
    $tithersMap[$t['member_id']] = $t['total'];
}

$total   = count($members);
$tithers = count($tithersMap);
$missing = $total - $tithers;
?>

<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap">
  <a href="/pages/finance/index.php?month=<?= $month ?>&year=<?= $year ?>" style="font-size:13px;color:var(--text-muted);text-decoration:none">← Financeiro</a>
  <h2 style="font-size:16px;font-weight:500"><?= $monthNames[$month] ?> <?= $year ?></h2>
</div>

<!-- KPIs -->
<div class="kpi-grid" style="margin-bottom:20px">
  <div class="kpi">
    <div class="kpi-icon">👥</div>
    <div class="kpi-label">Membros ativos</div>
    <div class="kpi-value"><?= $total ?></div>
  </div>
  <div class="kpi">
    <div class="kpi-icon">✅</div>
    <div class="kpi-label">Dizimaram</div>
    <div class="kpi-value" style="color:var(--green)"><?= $tithers ?></div>
  </div>
  <div class="kpi">
    <div class="kpi-icon">⏳</div>
    <div class="kpi-label">Não dizimaram</div>
    <div class="kpi-value" style="color:var(--text-muted)"><?= $missing ?></div>
  </div>
  <div class="kpi">
    <div class="kpi-icon">💰</div>
    <div class="kpi-label">Total de dízimos</div>
    <div class="kpi-value" style="color:var(--green)">
      R$ <?= number_format(array_sum($tithersMap),2,',','.') ?>
    </div>
  </div>
</div>

<!-- Lista -->
<div class="card" style="padding:0">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;gap:12px">
    <button onclick="filterTithers('all')"    class="btn btn-secondary" id="btn-all"    style="font-size:12px">Todos (<?= $total ?>)</button>
    <button onclick="filterTithers('yes')"    class="btn btn-secondary" id="btn-yes"    style="font-size:12px">Dizimaram (<?= $tithers ?>)</button>
    <button onclick="filterTithers('no')"     class="btn btn-secondary" id="btn-no"     style="font-size:12px">Não dizimaram (<?= $missing ?>)</button>
  </div>
  <div class="table-wrap">
    <table id="tithers-table">
      <thead>
        <tr><th>Membro</th><th>Filial</th><th>Status</th><th style="text-align:right">Valor dizimado</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($members as $m):
          $dizimou = isset($tithersMap[$m['id']]);
          $valor   = $tithersMap[$m['id']] ?? 0;
          $initials = strtoupper(implode('', array_map(fn($p)=>$p[0], array_slice(explode(' ',$m['name']),0,2))));
        ?>
          <tr class="tithe-row" data-tithed="<?= $dizimou?'yes':'no' ?>">
            <td>
              <div style="display:flex;align-items:center;gap:10px">
                <div class="avatar"><?= $initials ?></div>
                <a href="/pages/members/view.php?id=<?= $m['id'] ?>" style="color:var(--text);text-decoration:none;font-weight:500">
                  <?= htmlspecialchars($m['name']) ?>
                </a>
              </div>
            </td>
            <td style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($m['branch_name'] ?? '—') ?></td>
            <td>
              <?php if ($dizimou): ?>
                <span class="badge badge-green">✓ Dizimou</span>
              <?php else: ?>
                <span class="badge badge-gray">Pendente</span>
              <?php endif; ?>
            </td>
            <td style="text-align:right;font-weight:500;color:<?= $dizimou?'var(--green)':'var(--text-muted)' ?>">
              <?= $dizimou ? 'R$ ' . number_format($valor,2,',','.') : '—' ?>
            </td>
            <td style="text-align:right">
              <?php if (!$dizimou): ?>
                <a href="/pages/finance/create.php?member_id=<?= $m['id'] ?>&category=dizimo"
                   class="btn btn-secondary" style="font-size:11px;padding:4px 10px">+ Lançar</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
$extraJs = <<<JS
function filterTithers(filter) {
  document.querySelectorAll('.tithe-row').forEach(row => {
    if (filter === 'all') { row.style.display = ''; return; }
    row.style.display = row.dataset.tithed === filter ? '' : 'none';
  });
  ['all','yes','no'].forEach(f => {
    const btn = document.getElementById('btn-'+f);
    btn.className = 'btn ' + (f===filter ? 'btn-primary' : 'btn-secondary');
  });
}
JS;
require_once __DIR__ . '/../../includes/layout-footer.php';
?>
