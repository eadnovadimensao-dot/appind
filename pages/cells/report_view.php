<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
auth_check();
$db       = db();
$churchId = current_church_id();
$id       = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("
    SELECT cr.*, c.name AS cell_name, m.name AS author_name
    FROM cell_reports cr
    JOIN cells c ON c.id = cr.cell_id
    LEFT JOIN members m ON m.id = cr.created_by
    WHERE cr.id = ? AND cr.church_id = ?
");
$stmt->execute([$id, $churchId]);
$report = $stmt->fetch();
if (!$report) { header('Location: /pages/cells/index.php'); exit; }

$activePage = 'cells';
require_once __DIR__ . '/../../includes/layout.php';



$pageTitle = 'Relatório · ' . date('d/m/Y', strtotime($report['report_date']));

// Quem estava presente
$present = $db->prepare("
    SELECT m.id, m.name FROM cell_report_members crm
    JOIN members m ON m.id = crm.member_id
    WHERE crm.report_id = ?
    ORDER BY m.name
");
$present->execute([$id]);
$present = $present->fetchAll();
?>

<div style="margin-bottom:16px">
  <a href="/pages/cells/view.php?id=<?= $report['cell_id'] ?>" style="font-size:13px;color:var(--text-muted);text-decoration:none">
    ← <?= htmlspecialchars($report['cell_name']) ?>
  </a>
</div>

<!-- Cabeçalho -->
<div class="card" style="margin-bottom:16px">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <h1 style="font-size:17px;font-weight:500;margin-bottom:4px">
        Relatório de <?= date('d/m/Y', strtotime($report['report_date'])) ?>
      </h1>
      <?php if ($report['happened'] && $report['subject']): ?>
        <p style="font-size:14px;color:var(--text-muted)">📖 <?= htmlspecialchars($report['subject']) ?></p>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$report['happened']): ?>
    <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border)">
      <span class="badge badge-gray" style="margin-bottom:8px;display:inline-block">✕ Não houve reunião</span>
      <p style="font-size:13px;line-height:1.7;white-space:pre-line"><?= htmlspecialchars($report['no_meeting_reason'] ?? '') ?></p>
    </div>
  <?php else: ?>
  <!-- KPIs -->
  <div style="display:flex;gap:24px;margin-top:16px;padding-top:16px;border-top:1px solid var(--border);flex-wrap:wrap">
    <div style="text-align:center">
      <div style="font-size:24px;font-weight:500;color:var(--accent)"><?= $report['total_present'] ?></div>
      <div style="font-size:12px;color:var(--text-muted)">Presentes</div>
    </div>
    <div style="text-align:center">
      <div style="font-size:24px;font-weight:500;color:var(--accent)"><?= $report['visitors'] ?></div>
      <div style="font-size:12px;color:var(--text-muted)">Visitantes</div>
    </div>
    <div style="text-align:center">
      <div style="font-size:24px;font-weight:500;color:var(--accent)">R$ <?= number_format($report['offering'], 2, ',', '.') ?></div>
      <div style="font-size:12px;color:var(--text-muted)">Oferta</div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if ($report['happened']): ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

  <!-- Presença -->
  <div class="card">
    <p class="card-title">Lista de presença (<?= count($present) ?>)</p>
    <?php if (empty($present)): ?>
      <p style="font-size:13px;color:var(--text-muted)">Nenhuma presença registrada.</p>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:6px">
        <?php foreach ($present as $p): ?>
          <div style="display:flex;align-items:center;gap:8px;font-size:13px">
            <div class="avatar" style="width:26px;height:26px;font-size:10px">
              <?= strtoupper(substr($p['name'],0,2)) ?>
            </div>
            <a href="/pages/members/view.php?id=<?= $p['id'] ?>" style="color:var(--text);text-decoration:none">
              <?= htmlspecialchars($p['name']) ?>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Pedidos de oração -->
  <div class="card">
    <p class="card-title">Pedidos de oração</p>
    <?php if ($report['prayer_requests']): ?>
      <p style="font-size:13px;line-height:1.7;white-space:pre-line"><?= htmlspecialchars($report['prayer_requests']) ?></p>
    <?php else: ?>
      <p style="font-size:13px;color:var(--text-muted)">Nenhum pedido registrado.</p>
    <?php endif; ?>
  </div>

  <!-- Descrição -->
  <?php if ($report['description']): ?>
  <div class="card" style="grid-column:1/-1">
    <p class="card-title">Relatório da reunião</p>
    <p style="font-size:13px;line-height:1.8;white-space:pre-line"><?= htmlspecialchars($report['description']) ?></p>
  </div>
  <?php endif; ?>

</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
