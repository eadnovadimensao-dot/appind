<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
auth_check();
$db       = db();
$churchId = current_church_id();
$cellId   = (int)($_GET['cell_id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM cells WHERE id = ? AND church_id = ?");
$stmt->execute([$cellId, $churchId]);
$cell = $stmt->fetch();
if (!$cell) { header('Location: /pages/cells/index.php'); exit; }

$activePage = 'cells';
require_once __DIR__ . '/../../includes/layout.php';



$pageTitle = 'Relatórios · ' . $cell['name'];

$reports = $db->prepare("
    SELECT cr.*, m.name AS author_name
    FROM cell_reports cr
    LEFT JOIN members m ON m.id = cr.created_by
    WHERE cr.cell_id = ? AND cr.church_id = ?
    ORDER BY cr.report_date DESC
");
$reports->execute([$cellId, $churchId]);
$reports = $reports->fetchAll();
?>

<div style="margin-bottom:16px;display:flex;align-items:center;justify-content:space-between">
  <a href="/pages/cells/view.php?id=<?= $cellId ?>" style="font-size:13px;color:var(--text-muted);text-decoration:none">
    ← <?= htmlspecialchars($cell['name']) ?>
  </a>
  <a href="/pages/cells/report_create.php?cell_id=<?= $cellId ?>" class="btn btn-primary">+ Novo relatório</a>
</div>

<div class="card" style="padding:0">
  <?php if (empty($reports)): ?>
    <div class="empty-state">
      <p style="font-size:28px;margin-bottom:8px">📋</p>
      <p>Nenhum relatório enviado ainda.</p>
      <a href="/pages/cells/report_create.php?cell_id=<?= $cellId ?>" class="btn btn-primary" style="margin-top:16px">+ Enviar primeiro relatório</a>
    </div>
  <?php else: ?>
    <?php foreach ($reports as $r): ?>
      <div style="padding:12px 20px;border-bottom:1px solid var(--border)">
        <div style="font-size:14px;font-weight:500"><?= date('d/m/Y', strtotime($r['report_date'])) ?></div>
        <?php if (!$r['happened']): ?>
          <div style="font-size:12px;color:var(--text-muted);margin-top:4px">
            <span class="badge badge-gray">Não houve reunião</span>
            <?= $r['no_meeting_reason'] ? ' ' . htmlspecialchars($r['no_meeting_reason']) : '' ?>
          </div>
        <?php else: ?>
          <div style="font-size:12px;color:var(--text-muted);margin-top:4px">
            <?= htmlspecialchars($r['subject'] ?? 'Sem tema definido') ?>
          </div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:4px;display:flex;align-items:center;gap:6px;flex-wrap:wrap">
            <span><?= $r['total_present'] ?> presente(s)</span>
            <span>·</span>
            <span><?= $r['visitors'] ?> visitante(s)</span>
            <span>·</span>
            <span>R$ <?= number_format($r['offering'], 2, ',', '.') ?></span>
          </div>
        <?php endif; ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
          <a href="/pages/cells/report_view.php?id=<?= $r['id'] ?>" class="btn btn-secondary" style="font-size:12px;padding:5px 12px">Ver</a>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
