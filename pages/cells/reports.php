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
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Data</th>
            <th>Tema</th>
            <th>Presentes</th>
            <th>Visitantes</th>
            <th>Oferta</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($reports as $r): ?>
            <tr>
              <td style="font-weight:500"><?= date('d/m/Y', strtotime($r['report_date'])) ?></td>
              <td style="color:var(--text-muted)"><?= htmlspecialchars($r['subject'] ?? '—') ?></td>
              <td><?= $r['total_present'] ?></td>
              <td><?= $r['visitors'] ?></td>
              <td>R$ <?= number_format($r['offering'], 2, ',', '.') ?></td>
              <td style="text-align:right">
                <a href="/pages/cells/report_view.php?id=<?= $r['id'] ?>" class="btn btn-secondary" style="font-size:12px;padding:5px 12px">Ver</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
