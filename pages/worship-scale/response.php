<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$result     = $_GET['result'] ?? '';
$activityId = (int)($_GET['activity_id'] ?? 0);

$pageTitle  = 'Escala respondida';
$activePage = 'worship-scale';
require_once __DIR__ . '/../../includes/layout.php';
?>
<div style="max-width:400px;margin:40px auto;text-align:center">
  <div style="font-size:60px;margin-bottom:16px"><?= $result==='accepted'?'✅':'❌' ?></div>
  <h2 style="font-size:18px;font-weight:500;margin-bottom:8px">
    <?= $result==='accepted' ? 'Presença confirmada!' : 'Recusa registrada' ?>
  </h2>
  <p style="font-size:13px;color:var(--text-muted);margin-bottom:24px">
    <?= $result==='accepted'
      ? 'Sua participação foi confirmada. O líder foi notificado.'
      : 'Sua recusa foi registrada e o líder foi notificado para tomar as providências.' ?>
  </p>
  <a href="/dashboard.php" class="btn btn-primary">Voltar ao início</a>
</div>
<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
