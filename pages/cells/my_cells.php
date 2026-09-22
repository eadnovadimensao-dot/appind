<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$memberId = auth_member_id();
$cells    = $memberId ? member_related_cells($memberId) : [];
usort($cells, fn($a, $b) => strcmp($a['name'], $b['name']));

$roleBadges = ['membro' => 'badge-gray', 'líder' => 'badge-blue', 'supervisor' => 'badge-amber', 'anfitrião' => 'badge-green'];

$pageTitle  = 'Minhas células';
$activePage = 'cells';
require_once __DIR__ . '/../../includes/layout.php';
?>

<div class="card" style="padding:0">
  <div style="padding:16px 20px;border-bottom:1px solid var(--border)">
    <span style="font-size:13px;color:var(--text-muted)"><?= count($cells) ?> célula(s)</span>
  </div>
  <?php if (empty($cells)): ?>
    <div class="empty-state" style="padding:24px">Nenhuma célula vinculada a você ainda.</div>
  <?php else: ?>
    <?php foreach ($cells as $c): ?>
      <a href="/pages/cells/view.php?id=<?= $c['id'] ?>"
         style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 20px;border-bottom:1px solid var(--border);text-decoration:none;color:var(--text)">
        <span style="font-size:14px;font-weight:500"><?= htmlspecialchars($c['name']) ?></span>
        <span style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end">
          <?php foreach (array_unique($c['roles']) as $r): ?>
            <span class="badge <?= $roleBadges[$r] ?? 'badge-gray' ?>"><?= ucfirst($r) ?></span>
          <?php endforeach; ?>
        </span>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
