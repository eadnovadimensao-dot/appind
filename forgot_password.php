<?php
require_once __DIR__ . '/config/database.php';

$db          = db();
$churchName  = setting('church_name', APP_NAME, SEDE_ID);
$primaryColor = setting('primary_color', '#012a36', SEDE_ID);
$accentColor  = setting('accent_color',  '#1D9E75', SEDE_ID);
$logoUrl      = setting('church_logo_url', '', SEDE_ID);
$success      = false;
$error        = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (!$email) {
        $error = 'Informe seu e-mail.';
    } else {
        $user = $db->prepare("SELECT id, name, email FROM users WHERE email = ? AND active = 1");
        $user->execute([$email]);
        $user = $user->fetch();

        if ($user) {
            // Gerar token
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hora

            // Invalidar tokens anteriores
            $db->prepare("UPDATE password_resets SET used=1 WHERE user_id=?")->execute([$user['id']]);

            // Inserir novo token
            $db->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?,?,?)")
               ->execute([$user['id'], $token, $expires]);

            // Montar e-mail
            $resetUrl = APP_URL . '/reset_password.php?token=' . $token;
            $emailBody = "
            <div style='text-align:center;margin-bottom:28px'>
              <div style='font-size:48px;margin-bottom:12px'>🔑</div>
              <h1 style='font-size:22px;font-weight:600;color:#1a2332;margin:0 0 8px'>Redefinir senha</h1>
              <p style='color:#6b7280;font-size:15px;margin:0'>
                Olá, <strong style='color:#1a2332'>" . htmlspecialchars($user['name']) . "</strong>!<br>
                Recebemos uma solicitação para redefinir sua senha.
              </p>
            </div>

            <a href='{$resetUrl}' style='display:block;text-align:center;background:{$accentColor};color:white;padding:14px;border-radius:10px;text-decoration:none;font-size:15px;font-weight:600;margin-bottom:20px'>
              🔑 Redefinir minha senha
            </a>

            <div style='background:#f9fafb;border-radius:10px;padding:14px 16px;margin-bottom:20px'>
              <p style='font-size:13px;color:#6b7280;margin:0;line-height:1.6'>
                ⏰ Este link expira em <strong style='color:#1a2332'>1 hora</strong>.<br>
                Se você não solicitou a redefinição, ignore este e-mail — sua senha permanece a mesma.
              </p>
            </div>

            <p style='font-size:12px;color:#9ca3af;text-align:center;margin:0'>
              Ou copie e cole este link no navegador:<br>
              <span style='color:{$accentColor};word-break:break-all'>{$resetUrl}</span>
            </p>";

            $html = email_template(
                "Redefinição de senha — link válido por 1 hora",
                $emailBody,
                SEDE_ID
            );

            send_email($user['email'], $user['name'], "🔑 Redefinir senha — {$churchName}", $html, SEDE_ID);
        }

        // Sempre mostrar sucesso (não revelar se e-mail existe)
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Esqueci minha senha · <?= htmlspecialchars($churchName) ?></title>
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
      <div style="font-size:13px;color:rgba(255,255,255,.55)">Recuperação de senha</div>
    </div>
    <div class="auth-body">
      <?php if ($success): ?>
        <div style="text-align:center;padding:12px 0">
          <div style="font-size:42px;margin-bottom:12px">📧</div>
          <p style="font-weight:500;color:#1a2332;margin-bottom:8px">E-mail enviado!</p>
          <p style="font-size:14px;color:#6b7280;line-height:1.6">
            Se esse e-mail estiver cadastrado, você receberá as instruções em instantes. Verifique também a pasta de spam.
          </p>
        </div>
      <?php else: ?>
        <?php if ($error): ?>
          <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:10px 14px;font-size:13px;color:#A32D2D;margin-bottom:16px"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <p style="font-size:14px;color:#6b7280;margin-bottom:20px;line-height:1.6">
          Informe seu e-mail cadastrado e enviaremos um link para redefinir sua senha.
        </p>
        <form method="POST">
          <div class="form-group" style="margin-bottom:20px">
            <label class="form-label">E-mail</label>
            <input type="email" name="email" class="form-control"
                   placeholder="seu@email.com"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                   autofocus required>
          </div>
          <button type="submit" class="btn-submit">Enviar link de recuperação</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="auth-footer">
    <a href="/login.php">← Voltar para o login</a>
  </div>
</div>
</body>
</html>
