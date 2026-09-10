<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
auth_check();

$db         = db();
$churchId   = current_church_id();
$canFinance = auth_can('manage_finance');

// KPIs
$totalMembers = $db->query("SELECT COUNT(*) FROM members WHERE church_id=$churchId AND status='active'")->fetchColumn();
$totalCells   = $db->query("SELECT COUNT(*) FROM cells WHERE church_id=$churchId AND active=1")->fetchColumn();

// Aniversariantes da semana
$birthdays = $db->prepare("
    SELECT name,
           DATE_FORMAT(birth_date, '%d/%m') AS bday
    FROM members
    WHERE church_id = ?
      AND status = 'active'
      AND birth_date IS NOT NULL
      AND DAYOFYEAR(birth_date) BETWEEN DAYOFYEAR(CURDATE()) AND DAYOFYEAR(CURDATE()) + 6
    ORDER BY DAYOFYEAR(birth_date)
    LIMIT 5
");
$birthdays->execute([$churchId]);
$birthdays = $birthdays->fetchAll();

// Financeiro do mês (só se puder ver)
$finance = ['income' => 0, 'expense' => 0];
if ($canFinance) {
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN type='income'  THEN amount END), 0) AS income,
            COALESCE(SUM(CASE WHEN type='expense' THEN amount END), 0) AS expense
        FROM finance_entries
        WHERE church_id = ?
          AND MONTH(entry_date) = MONTH(CURDATE())
          AND YEAR(entry_date)  = YEAR(CURDATE())
    ");
    $stmt->execute([$churchId]);
    $finance = $stmt->fetch();
}

// Próximo culto
$nextService = $db->prepare("
    SELECT s.*, m.name AS preacher_name
    FROM services s
    LEFT JOIN members m ON m.id = s.preacher_id
    WHERE s.church_id=? AND s.service_date >= CURDATE()
    ORDER BY s.service_date ASC LIMIT 1
");
$nextService->execute([$churchId]);
$nextService = $nextService->fetch();

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
require_once __DIR__ . '/includes/layout.php';
?>

<?php if (isset($_GET['no_access'])): ?>
  <div style="background:#FEF3C7;border:1px solid #FCD34D;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#854F0B">
    🔒 Você não tem permissão para acessar essa área.
  </div>
<?php endif; ?>

<!-- KPIs -->
<div class="kpi-grid" style="margin-bottom:20px">
  <div class="kpi">
    <div class="kpi-icon">👥</div>
    <div class="kpi-label">Membros ativos</div>
    <div class="kpi-value"><?= $totalMembers ?></div>
  </div>
  <div class="kpi">
    <div class="kpi-icon">🔗</div>
    <div class="kpi-label">Células ativas</div>
    <div class="kpi-value"><?= $totalCells ?></div>
  </div>
  <?php if ($canFinance): ?>
  <div class="kpi">
    <div class="kpi-icon">💰</div>
    <div class="kpi-label">Entradas do mês</div>
    <div class="kpi-value" style="color:var(--green)">R$ <?= number_format($finance['income'],2,',','.') ?></div>
  </div>
  <div class="kpi">
    <div class="kpi-icon">📊</div>
    <div class="kpi-label">Saldo do mês</div>
    <?php $saldo = $finance['income'] - $finance['expense']; ?>
    <div class="kpi-value" style="color:<?= $saldo>=0?'var(--green)':'var(--red)' ?>">
      R$ <?= number_format($saldo,2,',','.') ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

  <!-- Próximo culto -->
  <?php if ($nextService): ?>
  <div class="card">
    <p class="card-title">⛪ Próximo culto</p>
    <div style="font-size:16px;font-weight:500;margin-bottom:6px"><?= htmlspecialchars($nextService['title']) ?></div>
    <div style="font-size:13px;color:var(--text-muted);display:flex;flex-direction:column;gap:4px">
      <span>📅 <?= date('d/m/Y', strtotime($nextService['service_date'])) ?>
        <?= $nextService['time_start'] ? ' às ' . substr($nextService['time_start'],0,5) : '' ?>
      </span>
      <?php if ($nextService['preacher_name']): ?>
        <span>🎤 <?= htmlspecialchars($nextService['preacher_name']) ?></span>
      <?php endif; ?>
      <?php if ($nextService['sermon_title']): ?>
        <span>📖 <?= htmlspecialchars($nextService['sermon_title']) ?></span>
      <?php endif; ?>
    </div>
    <a href="/pages/services/view.php?id=<?= $nextService['id'] ?>"
       class="btn btn-secondary" style="margin-top:14px;font-size:12px">Ver programação</a>
  </div>
  <?php endif; ?>

  <!-- Aniversariantes -->
  <div class="card">
    <p class="card-title">🎂 Aniversariantes da semana</p>
    <?php if (empty($birthdays)): ?>
      <p style="font-size:13px;color:var(--text-muted)">Nenhum aniversariante esta semana.</p>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:8px">
        <?php foreach ($birthdays as $b): ?>
          <div style="display:flex;align-items:center;gap:10px">
            <div class="avatar"><?= strtoupper(substr($b['name'],0,2)) ?></div>
            <div>
              <div style="font-weight:500;font-size:13px"><?= htmlspecialchars($b['name']) ?></div>
              <div style="font-size:12px;color:var(--text-muted)"><?= $b['bday'] ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($canFinance): ?>
  <!-- Resumo financeiro -->
  <div class="card">
    <p class="card-title">💰 Financeiro do mês</p>
    <div style="display:flex;flex-direction:column;gap:12px">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <span style="font-size:13px;color:var(--text-muted)">Entradas</span>
        <span style="font-weight:500;color:var(--green)">R$ <?= number_format($finance['income'],2,',','.') ?></span>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center">
        <span style="font-size:13px;color:var(--text-muted)">Saídas</span>
        <span style="font-weight:500;color:var(--red)">R$ <?= number_format($finance['expense'],2,',','.') ?></span>
      </div>
      <div style="border-top:1px solid var(--border);padding-top:12px;display:flex;justify-content:space-between;align-items:center">
        <span style="font-weight:500">Saldo</span>
        <span style="font-weight:600;font-size:15px;color:<?= $saldo>=0?'var(--green)':'var(--red)' ?>">
          R$ <?= number_format($saldo,2,',','.') ?>
        </span>
      </div>
    </div>
    <a href="/pages/finance/index.php" class="btn btn-secondary" style="margin-top:14px;font-size:12px">Ver financeiro</a>
  </div>
  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/layout-footer.php'; ?>
