<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/discipleship.php';

$token  = trim($_GET['token'] ?? '');
$action = $_GET['action'] ?? '';

$db = db();
$error = null; $success = null; $already = false;

$stmt = $db->prepare("
    SELECT d.*, c.name AS cell_name, dc.name AS disciple_name, ds.name AS discipler_name
    FROM discipleships d
    JOIN cells c ON c.id = d.cell_id
    JOIN members dc ON dc.id = d.disciple_member_id
    JOIN members ds ON ds.id = d.discipler_member_id
    WHERE d.decision_token = ?
");
$stmt->execute([$token]);
$d = $stmt->fetch();

if (!$d) {
    $error = 'Link inválido.';
} elseif ($d['status'] !== 'pending_discipler') {
    $already = true;
    $labels = ['pending_leader' => 'Você já aceitou. Aguardando o líder da célula.',
               'pending_coordination' => 'Você já aceitou. Aguardando a coordenação.',
               'active' => 'Você já aceitou e o discipulado está em andamento.',
               'rejected' => 'Esse pedido não está mais em aberto.',
               'completed' => 'Esse discipulado já foi concluído.',
               'cancelled' => 'Esse discipulado foi cancelado.'];
    $success = $labels[$d['status']] ?? 'Esse pedido já foi respondido.';
} elseif (!in_array($action, ['accept', 'decline'])) {
    $error = 'Link inválido.';
} else {
    $approve = $action === 'accept';
    $db->prepare("
        UPDATE discipleships SET status = ?, discipler_decided_by = discipler_member_id, discipler_decided_at = NOW(), discipler_approved = ?
        WHERE id = ?
    ")->execute([$approve ? 'pending_leader' : 'rejected', $approve ? 1 : 0, $d['id']]);

    if ($approve) {
        discipleship_notify_leader($db, (int)$d['id']);
        $success = "🎉 Combinado! Você agora é discipulador(a) de {$d['disciple_name']}. O líder da célula {$d['cell_name']} vai avaliar em seguida.";
    } else {
        discipleship_notify_discipler_declined($db, $d);
        $success = 'Tudo bem, obrigado por avisar. Não vamos prosseguir com esse pedido agora.';
    }
}

$churchId     = $d['church_id'] ?? SEDE_ID;
$churchName   = setting('church_name', 'Igreja', $churchId);
$primaryColor = setting('primary_color', '#012a36', $churchId);
$accentColor  = setting('accent_color', '#1D9E75', $churchId);
$logoUrl      = setting('church_logo_url', '', $churchId);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($churchName) ?> · Discipulado</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: system-ui, -apple-system, sans-serif;
      background: #f0f0f0;
      min-height: 100vh;
      display: flex; align-items: center; justify-content: center;
      padding: 20px;
    }
    .card {
      background: white; border-radius: 16px;
      padding: 32px; max-width: 420px; width: 100%;
      text-align: center;
    }
    .logo {
      width: 56px; height: 56px; border-radius: 14px;
      background: <?= htmlspecialchars($primaryColor) ?>;
      display: flex; align-items: center; justify-content: center;
      font-size: 24px; color: white; margin: 0 auto 16px;
      overflow: hidden;
    }
    .church-name { font-size: 13px; color: #6b7280; margin-bottom: 24px; }
    .icon { font-size: 48px; margin-bottom: 12px; }
    h1 { font-size: 20px; font-weight: 600; color: #1a2332; margin-bottom: 8px; }
    .success { color: #0F6E56; font-size: 15px; line-height: 1.6; }
    .error { color: #A32D2D; font-size: 15px; }
    .login-link { font-size: 12px; color: #9ca3af; margin-top: 20px; }
    .login-link a { color: <?= htmlspecialchars($accentColor) ?>; text-decoration: none; }
  </style>
</head>
<body>
<div class="card">
  <div class="logo">
    <?php if ($logoUrl): ?>
      <img src="<?= htmlspecialchars($logoUrl) ?>" style="width:100%;height:100%;object-fit:cover">
    <?php else: ?>🤝<?php endif; ?>
  </div>
  <div class="church-name"><?= htmlspecialchars($churchName) ?></div>

  <?php if ($error): ?>
    <div class="icon">❌</div>
    <h1>Link inválido</h1>
    <p class="error"><?= htmlspecialchars($error) ?></p>
  <?php else: ?>
    <div class="icon"><?= $already ? '✅' : ($action === 'decline' ? '🙏' : '🎉') ?></div>
    <h1>Discipulado</h1>
    <p class="success"><?= htmlspecialchars($success) ?></p>
  <?php endif; ?>

  <div class="login-link">
    <a href="/login.php">Acessar o sistema</a>
  </div>
</div>
</body>
</html>
