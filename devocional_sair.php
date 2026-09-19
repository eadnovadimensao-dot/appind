<?php
// Página pública (sem login) aberta pelo link no fim da mensagem do devocional:
// o membro para de receber, ou volta a receber. Vale pro número de telefone.
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/devotional.php';

header('Cache-Control: no-store');
$db    = db();
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

$member = null;
if ($token !== '') {
    $q = $db->prepare("SELECT id, name, devotional_optout FROM members WHERE devotional_token = ?");
    $q->execute([$token]);
    $member = $q->fetch();
}

$done = null;
if ($member && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $stop = ($_POST['action'] ?? '') === 'stop';
    devotional_set_optout($db, (int)$member['id'], $stop);
    $member['devotional_optout'] = $stop ? 1 : 0;
    $done = $stop ? 'stop' : 'start';
}

$churchName   = setting('church_name', 'Igreja', SEDE_ID);
$primaryColor = setting('primary_color', '#012a36', SEDE_ID);
$accentColor  = setting('accent_color', '#1D9E75', SEDE_ID);
$first        = $member ? explode(' ', trim($member['name']))[0] : '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($churchName) ?> · Devocional</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: system-ui, -apple-system, sans-serif; background: #f0f0f0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
    .card { background: #fff; border-radius: 16px; padding: 32px; max-width: 420px; width: 100%; text-align: center; }
    .church { font-size: 13px; color: #6b7280; margin-bottom: 20px; }
    .icon { font-size: 44px; margin-bottom: 12px; }
    h1 { font-size: 19px; font-weight: 600; color: #1a2332; margin-bottom: 10px; }
    p { font-size: 14px; color: #4b5563; line-height: 1.6; margin-bottom: 20px; }
    button { border: none; border-radius: 10px; padding: 12px 22px; font-size: 14px; font-weight: 500; cursor: pointer; color: #fff; background: <?= htmlspecialchars($accentColor) ?>; }
    button.stop { background: #6b7280; }
    a { color: <?= htmlspecialchars($accentColor) ?>; font-size: 13px; text-decoration: none; }
  </style>
</head>
<body>
<div class="card">
  <div class="church"><?= htmlspecialchars($churchName) ?></div>
  <?php if (!$member): ?>
    <div class="icon">❌</div>
    <h1>Link inválido</h1>
    <p>Não encontrei esse cadastro. Se quiser parar de receber, fale com a liderança da igreja.</p>
  <?php elseif ($member['devotional_optout']): ?>
    <div class="icon">🔕</div>
    <h1><?= $done === 'stop' ? 'Pronto, ' . htmlspecialchars($first) . '!' : 'Você não recebe o devocional' ?></h1>
    <p><?= $done === 'stop' ? 'Você não vai mais receber o devocional no WhatsApp.' : 'Este número está descadastrado do devocional.' ?> Você pode voltar a receber quando quiser.</p>
    <form method="POST"><input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>"><input type="hidden" name="action" value="start"><button type="submit">Voltar a receber</button></form>
  <?php else: ?>
    <div class="icon">🌅</div>
    <h1><?= $done === 'start' ? 'Que bom ter você de volta, ' . htmlspecialchars($first) . '!' : 'Devocional diário' ?></h1>
    <p><?= $done === 'start' ? 'Você voltou a receber o devocional no WhatsApp.' : 'Olá, ' . htmlspecialchars($first) . '. Deseja parar de receber o devocional no WhatsApp?' ?></p>
    <?php if ($done !== 'start'): ?>
      <form method="POST"><input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>"><input type="hidden" name="action" value="stop"><button type="submit" class="stop">Parar de receber</button></form>
    <?php endif; ?>
  <?php endif; ?>
  <p style="margin:20px 0 0"><a href="/login.php">Acessar o sistema</a></p>
</div>
</body>
</html>
