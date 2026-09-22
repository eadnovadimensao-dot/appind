<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/discipleship.php';

$token  = trim($_GET['token'] ?? '');
$stage  = $_GET['stage'] ?? '';
$action = $_GET['action'] ?? '';
$as     = (int)($_GET['as'] ?? 0);

$db = db();
$error = null; $success = null; $already = false;

$d = discipleship_with_cell_by_token($db, $token);

$stageLabels = [
    'pending_discipler'    => 'Você já aceitou. Aguardando o líder da célula.',
    'pending_leader'       => 'Já aprovado. Aguardando a coordenação.',
    'pending_coordination' => 'Já confirmado pela coordenação.',
    'active'               => 'Esse discipulado já está confirmado e em andamento.',
    'rejected'             => 'Esse pedido não está mais em aberto.',
    'completed'            => 'Esse discipulado já foi concluído.',
    'cancelled'            => 'Esse discipulado foi cancelado.',
];

// Qual etapa o status atual representa, e se bate com o que o link promete
$expectedStatus = ['discipler' => 'pending_discipler', 'leader' => 'pending_leader', 'coordination' => 'pending_coordination'][$stage] ?? null;

if (!$d) {
    $error = 'Link inválido.';
} elseif (!$expectedStatus || !in_array($action, ['accept', 'decline'])) {
    $error = 'Link inválido.';
} elseif ($d['status'] !== $expectedStatus) {
    $already = true;
    $success = $stageLabels[$d['status']] ?? 'Esse pedido já foi respondido.';
} else {
    $approve = $action === 'accept';

    if ($stage === 'discipler') {
        discipleship_decide_discipler($db, (int)$d['id'], $approve, (int)$d['discipler_member_id']);
        $success = $approve
            ? "🎉 Combinado! Você agora é discipulador(a) de {$d['disciple_name']}. O líder da célula {$d['cell_name']} vai avaliar em seguida."
            : 'Tudo bem, obrigado por avisar. Não vamos prosseguir com esse pedido agora.';
    } elseif ($stage === 'leader') {
        // Só registra quem decidiu se "as" for de fato líder dessa célula; senão aplica a decisão do mesmo jeito, sem autor.
        $chk = $db->prepare("SELECT 1 FROM cell_leaders WHERE cell_id = ? AND member_id = ?");
        $chk->execute([$d['cell_id'], $as]);
        $decidedBy = $chk->fetchColumn() ? $as : null;
        discipleship_decide_leader($db, (int)$d['id'], $approve, $decidedBy);
        $success = $approve
            ? "✓ Aprovado! {$d['disciple_name']} e {$d['discipler_name']} seguem agora pra confirmação da coordenação."
            : 'Recusado. O discípulo já foi avisado e pode escolher outra pessoa.';
    } elseif ($stage === 'coordination') {
        $chk = $db->prepare("SELECT 1 FROM discipleship_coordinators WHERE member_id = ?");
        $chk->execute([$as]);
        $decidedBy = $chk->fetchColumn() ? $as : null;
        discipleship_decide_coordination($db, (int)$d['id'], $approve, $decidedBy);
        $success = $approve
            ? "🎉 Confirmado! O discipulado de {$d['disciple_name']} com {$d['discipler_name']} já começou."
            : 'Recusado. Os dois já foram avisados.';
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
