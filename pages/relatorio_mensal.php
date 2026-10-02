<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/monthly_report.php';
auth_check();
if (!auth_can('manage_users') && !auth_can('all')) {
    http_response_code(403);
    include __DIR__ . '/../includes/403.php';
    exit;
}

$db       = db();
$churchId = current_church_id();

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
// Mês atual ainda não fechou — mostra o anterior por padrão
if ($year === (int)date('Y') && $month === (int)date('n')) {
    $month--;
    if ($month < 1) { $month = 12; $year--; }
}

$text = monthly_report_text($db, $churchId, $year, $month);

$prevMonth = $month - 1; $prevYear = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $month + 1; $nextYear = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }
$isCurrentOrFuture = ($nextYear > (int)date('Y')) || ($nextYear === (int)date('Y') && $nextMonth >= (int)date('n'));

$pageTitle  = 'Relatório mensal';
$activePage = 'relatorio_mensal';
require_once __DIR__ . '/../includes/layout.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px">
  <div style="display:flex;gap:8px">
    <a href="?year=<?= $prevYear ?>&month=<?= $prevMonth ?>" class="btn btn-secondary">← Mês anterior</a>
    <?php if (!$isCurrentOrFuture): ?>
      <a href="?year=<?= $nextYear ?>&month=<?= $nextMonth ?>" class="btn btn-secondary">Próximo mês →</a>
    <?php endif; ?>
  </div>
</div>

<div class="card" style="max-width:480px">
  <pre style="white-space:pre-wrap;font-family:inherit;font-size:14px;line-height:1.7;color:var(--text)"><?= htmlspecialchars($text) ?></pre>
</div>

<p style="font-size:12px;color:var(--text-muted);margin-top:14px">
  Esse mesmo texto é enviado automaticamente por WhatsApp aos admins/pastores no dia 1 de cada mês, pra o mês que acabou de fechar.
</p>

<?php require_once __DIR__ . '/../includes/layout-footer.php'; ?>
