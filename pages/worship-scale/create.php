<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db       = db();
$churchId = current_church_id();
$memberId = auth_member_id();
$isAdmin  = in_array(auth_role(), ['supermaster', 'admin']);

// Buscar ministérios disponíveis
if ($isAdmin) {
    $stmt = $db->prepare("SELECT id, name, leader_id FROM ministries WHERE church_id=? AND active=1 ORDER BY name");
    $stmt->execute([$churchId]);
} else {
    $stmt = $db->prepare("
        SELECT DISTINCT mn.id, mn.name, mn.leader_id
        FROM ministries mn
        LEFT JOIN member_ministries mm ON mm.ministry_id = mn.id
        WHERE mn.church_id = ?
          AND mn.active = 1
          AND (mn.leader_id = ? OR mm.member_id = ?)
        ORDER BY mn.name
    ");
    $stmt->execute([$churchId, $memberId, $memberId]);
}
$ministries = $stmt->fetchAll();

// Se só tem um ministério, já redireciona direto
if (count($ministries) === 1) {
    header('Location: /pages/ministries/activity_create.php?ministry_id=' . $ministries[0]['id']);
    exit;
}

$pageTitle  = 'Nova escala';
$activePage = 'worship-scale';
require_once __DIR__ . '/../../includes/layout.php';
?>

<div style="max-width:600px">
  <p style="font-size:14px;color:var(--text-muted);margin-bottom:20px">
    Selecione o ministério para criar a escala:
  </p>

  <?php if (empty($ministries)): ?>
    <div class="empty-state" style="padding:40px">
      <p style="font-size:28px;margin-bottom:8px">✝️</p>
      <p>Você não está vinculado a nenhum ministério.</p>
      <a href="/pages/ministries/index.php" class="btn btn-secondary" style="margin-top:16px">Ver ministérios</a>
    </div>
  <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
      <?php foreach ($ministries as $mn): ?>
        <a href="/pages/ministries/activity_create.php?ministry_id=<?= $mn['id'] ?>"
           style="text-decoration:none">
          <div class="card" style="cursor:pointer;transition:border-color .15s;border:1.5px solid var(--border)"
               onmouseover="this.style.borderColor='var(--accent)';this.style.background='var(--accent-lt)'"
               onmouseout="this.style.borderColor='var(--border)';this.style.background='white'">
            <div style="font-size:28px;margin-bottom:10px">✝️</div>
            <div style="font-size:14px;font-weight:500;color:var(--text)"><?= htmlspecialchars($mn['name']) ?></div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
