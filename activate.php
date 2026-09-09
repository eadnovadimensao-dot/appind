<?php
require_once __DIR__ . '/config/database.php';

$db    = db();
$token = trim($_GET['token'] ?? '');
$error = '';
$success = false;

if (!$token) { header('Location: /login.php'); exit; }

// Buscar convite válido
$stmt = $db->prepare("SELECT * FROM users WHERE invite_token = ? AND invite_expires > NOW() AND active = 1 LIMIT 1");
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    $error = 'Link inválido ou expirado. Solicite um novo convite ao administrador.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    $password  = $_POST['password']  ?? '';
    $password2 = $_POST['password2'] ?? '';

    if (strlen($password) < 8) {
        $error = 'A senha deve ter pelo menos 8 caracteres.';
    } elseif ($password !== $password2) {
        $error = 'As senhas não conferem.';
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $db->prepare("UPDATE users SET password_hash=?, invite_token=NULL, invite_expires=NULL WHERE id=?")
           ->execute([$hash, $user['id']]);
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ativar conta · Igreja Manager</title>
  <link rel="stylesheet" href="/public/css/app.css">
  <style>
    body { display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--content-bg); }
    .box { width:100%;max-width:420px;padding:0 16px; }
    .card-wrap { background:white;border-radius:14px;box-shadow:0 4px 24px rgba(0,0,0,.09);overflow:hidden; }
    .card-header { background:var(--sb-bg);padding:28px;text-align:center; }
    .card-logo { width:48px;height:48px;border-radius:10px;background:var(--accent);display:inline-flex;align-items:center;justify-content:center;font-size:22px;color:white;margin-bottom:10px; }
    .card-body { padding:28px; }
  </style>
</head>
<body>
<div class="box">
  <div class="card-wrap">
    <div class="card-header">
      <div class="card-logo">✝</div>
      <div style="font-size:17px;font-weight:600;color:white;margin-bottom:4px">Criar sua senha</div>
      <div style="font-size:13px;color:rgba(255,255,255,.5)">Igreja Manager</div>
    </div>
    <div class="card-body">
      <?php if ($success): ?>
        <div style="text-align:center;padding:12px 0">
          <div style="font-size:40px;margin-bottom:12px">✅</div>
          <p style="font-size:15px;font-weight:500;margin-bottom:6px">Conta ativada!</p>
          <p style="font-size:13px;color:var(--text-muted);margin-bottom:20px">Sua senha foi criada com sucesso.</p>
          <a href="/login.php" class="btn btn-primary" style="width:100%;justify-content:center">Fazer login</a>
        </div>
      <?php elseif ($error && !$user): ?>
        <div style="text-align:center;padding:12px 0">
          <div style="font-size:40px;margin-bottom:12px">❌</div>
          <p style="font-size:14px;color:#A32D2D"><?= htmlspecialchars($error) ?></p>
          <a href="/login.php" class="btn btn-secondary" style="margin-top:16px">Voltar ao login</a>
        </div>
      <?php else: ?>
        <p style="font-size:14px;color:var(--text-muted);margin-bottom:20px">
          Olá, <strong style="color:var(--text)"><?= htmlspecialchars($user['name']) ?></strong>! Crie uma senha para acessar o sistema.
        </p>
        <?php if ($error): ?>
          <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:10px 14px;font-size:13px;color:#A32D2D;margin-bottom:16px">
            <?= htmlspecialchars($error) ?>
          </div>
        <?php endif; ?>
        <form method="POST">
          <div class="form-group">
            <label class="form-label">Nova senha <span style="color:var(--text-muted)">(mín. 8 caracteres)</span></label>
            <input type="password" name="password" class="form-control" placeholder="••••••••" required minlength="8">
          </div>
          <div class="form-group" style="margin-bottom:20px">
            <label class="form-label">Confirmar senha</label>
            <input type="password" name="password2" class="form-control" placeholder="••••••••" required>
          </div>
          <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:10px">
            Ativar minha conta
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
