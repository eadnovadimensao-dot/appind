<?php
require_once __DIR__ . '/config/database.php';

$db           = db();
$churchName   = setting('church_name', APP_NAME, SEDE_ID);
$primaryColor = setting('primary_color', '#012a36', SEDE_ID);
$accentColor  = setting('accent_color',  '#1D9E75', SEDE_ID);
$logoUrl      = setting('church_logo_url', '', SEDE_ID);

$token   = trim($_GET['token'] ?? '');
$error   = null;
$success = false;

// Validar token
$reset = null;
if ($token) {
    $stmt = $db->prepare("
        SELECT pr.*, u.name, u.email FROM password_resets pr
        JOIN users u ON u.id = pr.user_id
        WHERE pr.token = ? AND pr.used = 0 AND pr.expires_at > NOW()
    ");
    $stmt->execute([$token]);
    $reset = $stmt->fetch();
}

if (!$reset && $token) {
    $error = 'Link inválido ou expirado. Solicite um novo link.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reset) {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm']  ?? '';

    if (strlen($password) < 6) {
        $error = 'A senha deve ter pelo menos 6 caracteres.';
    } elseif ($password !== $confirm) {
        $error = 'As senhas não conferem.';
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $db->prepare("UPDATE users SET password_hash=?, login_attempts=0, locked_until=NULL WHERE id=?")
           ->execute([$hash, $reset['user_id']]);
        $db->prepare("UPDATE password_resets SET used=1 WHERE token=?")
           ->execute([$token]);
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Nova senha · <?= htmlspecialchars($churchName) ?></title>
  <link rel="stylesheet" href="/public/css/app.css">
  <style>
    body { display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--content-bg); }
    .auth-box { width:100%;max-width:400px;padding:0 16px; }
    .auth-card { background:white;border-radius:14px;box-shadow:0 4px 24px rgba(0,0,0,.09);overflow:hidden; }
    .auth-header { background:<?= htmlspecialchars($primaryColor) ?>;padding:32px;text-align:center; }
    .auth-logo { width:64px;height:64px;border-radius:14px;display:inline-flex;align-items:center;justify-content:center;font-size:28px;color:white;margin-bottom:14px;overflow:hidden; }
    .auth-body { padding:28px; }
    .auth-footer { text-align:center;margin-top:20px;font-size:12px;color:var(--text-muted); }
    .footer a { color:<?= htmlspecialchars($accentColor) ?>;text-decoration:none; }
    .btn-submit { background:<?= htmlspecialchars($accentColor) ?>;color:white;width:100%;justify-content:center;padding:11px;font-size:15px;border:none;border-radius:7px;cursor:pointer;font-family:inherit;font-weight:500; }
    .strength { height:4px;border-radius:99px;margin-top:6px;transition:all .3s; }
  </style>
</head>
<body>
<div class="auth-box">
  <div class="auth-card">
    <div class="auth-header">
      <div class="auth-logo">
        <?php if ($logoUrl): ?>
          <img src="<?= htmlspecialchars($logoUrl) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:14px">
        <?php else: ?>✝<?php endif; ?>
      </div>
      <div style="font-size:20px;font-weight:600;color:white;margin-bottom:4px"><?= htmlspecialchars($churchName) ?></div>
      <div style="font-size:13px;color:rgba(255,255,255,.55)">Nova senha</div>
    </div>
    <div class="auth-body">
      <?php if ($error): ?>
        <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:10px 14px;font-size:13px;color:#A32D2D;margin-bottom:16px"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div style="text-align:center;padding:12px 0">
          <div style="font-size:42px;margin-bottom:12px">🎉</div>
          <p style="font-weight:500;color:#1a2332;margin-bottom:8px">Senha alterada!</p>
          <p style="font-size:14px;color:#6b7280;margin-bottom:20px">Sua senha foi atualizada com sucesso.</p>
          <a href="/login.php" class="btn btn-primary" style="display:block;text-align:center;padding:11px;font-size:15px;background:<?= htmlspecialchars($accentColor) ?>;color:white;border-radius:7px;text-decoration:none">
            Fazer login
          </a>
        </div>
      <?php elseif ($reset): ?>
        <p style="font-size:14px;color:#6b7280;margin-bottom:20px">
          Olá, <strong style="color:#1a2332"><?= htmlspecialchars($reset['name']) ?></strong>! Defina sua nova senha abaixo.
        </p>
        <form method="POST">
          <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
          <div class="form-group">
            <label class="form-label">Nova senha</label>
            <input type="password" name="password" id="password" class="form-control"
                   placeholder="Mínimo 6 caracteres" required autofocus
                   oninput="checkStrength(this.value)">
            <div class="strength" id="strength-bar" style="background:#e5e7eb"></div>
            <div id="strength-label" style="font-size:11px;color:#9ca3af;margin-top:3px"></div>
          </div>
          <div class="form-group" style="margin-bottom:20px">
            <label class="form-label">Confirmar senha</label>
            <input type="password" name="confirm" class="form-control"
                   placeholder="Repita a senha" required>
          </div>
          <button type="submit" class="btn-submit">Salvar nova senha</button>
        </form>
      <?php elseif (!$token): ?>
        <p style="font-size:14px;color:#6b7280">Acesse o link enviado por e-mail para redefinir sua senha.</p>
      <?php endif; ?>
    </div>
  </div>
  <div class="auth-footer">
    <a href="/login.php">← Voltar para o login</a>
  </div>
</div>

<script>
function checkStrength(val) {
  const bar = document.getElementById('strength-bar');
  const lbl = document.getElementById('strength-label');
  if (!val) { bar.style.background='#e5e7eb'; bar.style.width='0%'; lbl.textContent=''; return; }
  let score = 0;
  if (val.length >= 6)  score++;
  if (val.length >= 10) score++;
  if (/[A-Z]/.test(val)) score++;
  if (/[0-9]/.test(val)) score++;
  if (/[^A-Za-z0-9]/.test(val)) score++;
  const levels = [
    { pct:'20%', color:'#E24B4A', label:'Muito fraca' },
    { pct:'40%', color:'#EF9F27', label:'Fraca' },
    { pct:'60%', color:'#EF9F27', label:'Razoável' },
    { pct:'80%', color:'#1D9E75', label:'Boa' },
    { pct:'100%', color:'#0F6E56', label:'Excelente' },
  ];
  const l = levels[Math.min(score-1, 4)] || levels[0];
  bar.style.background = l.color;
  bar.style.width = l.pct;
  lbl.textContent = l.label;
  lbl.style.color = l.color;
}
</script>
</body>
</html>
