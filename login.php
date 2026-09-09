<?php
require_once __DIR__ . '/config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax']);
    session_start();
}

// Já logado → redireciona
if (!empty($_SESSION['user_id'])) {
    header('Location: /dashboard.php');
    exit;
}

$error   = '';
$timeout = isset($_GET['timeout']);
$next    = $_GET['next'] ?? '/dashboard.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');
    $ip       = $_SERVER['REMOTE_ADDR'] ?? '';

    if ($email && $password) {
        $db   = db();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND active = 1 LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Verificar bloqueio
        if ($user && $user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $error = 'Conta bloqueada temporariamente. Tente novamente em alguns minutos.';
        } elseif ($user && password_verify($password, $user['password_hash'])) {
            // Login OK
            $db->prepare("UPDATE users SET login_attempts=0, locked_until=NULL, last_login=NOW() WHERE id=?")
               ->execute([$user['id']]);
            $db->prepare("INSERT INTO user_login_log (user_id, email, success, ip) VALUES (?,?,1,?)")
               ->execute([$user['id'], $email, $ip]);

            session_regenerate_id(true);
            $_SESSION['user_id']       = $user['id'];
            $_SESSION['last_activity'] = time();

            // Calcular role efetivo baseado em liderança real
            $effectiveRole = $user['role'];
            if ($user['member_id'] && in_array($user['role'], ['member','leader','cell_leader','ministry_leader'])) {
                $mid = $user['member_id'];
                $isMinistryLeader = (bool)$db->query("SELECT COUNT(*) FROM ministry_leaders WHERE member_id=$mid")->fetchColumn();
                $isCellLeader     = (bool)$db->query("SELECT COUNT(*) FROM cell_leaders WHERE member_id=$mid")->fetchColumn();

                if ($isMinistryLeader) {
                    $effectiveRole = 'leader'; // líder de ministério — acesso completo de líder
                } elseif ($isCellLeader) {
                    $effectiveRole = 'cell_leader'; // líder só de célula — sem escalas
                } else {
                    $effectiveRole = 'member';
                }
                // Sincronizar role no banco se mudou
                if ($effectiveRole !== $user['role']) {
                    $db->prepare("UPDATE users SET role=? WHERE id=?")->execute([$effectiveRole, $user['id']]);
                }
            }

            // church_id: pegar do membro vinculado se existir, senão da tabela users
            $churchId = $user['church_id'];
            if ($user['member_id']) {
                $memberChurch = $db->query("SELECT church_id FROM members WHERE id={$user['member_id']}")->fetchColumn();
                if ($memberChurch) {
                    $churchId = $memberChurch;
                    // Sincronizar church_id no banco se mudou
                    if ($churchId != $user['church_id']) {
                        $db->prepare("UPDATE users SET church_id=? WHERE id=?")->execute([$churchId, $user['id']]);
                    }
                }
            }

            $_SESSION['user'] = [
                'id'        => $user['id'],
                'name'      => $user['name'],
                'email'     => $user['email'],
                'role'      => $effectiveRole,
                'member_id' => $user['member_id'],
                'church_id' => $churchId,
            ];

            header('Location: ' . (filter_var($next, FILTER_VALIDATE_URL) ? '/dashboard.php' : $next));
            exit;
        } else {
            // Falha
            if ($user) {
                $attempts = $user['login_attempts'] + 1;
                $lockUntil = $attempts >= 5 ? date('Y-m-d H:i:s', time() + 900) : null; // 15 min
                $db->prepare("UPDATE users SET login_attempts=?, locked_until=? WHERE id=?")
                   ->execute([$attempts, $lockUntil, $user['id']]);
            }
            $db->prepare("INSERT INTO user_login_log (user_id, email, success, ip) VALUES (?,?,0,?)")
               ->execute([$user['id'] ?? null, $email, $ip]);
            $error = 'E-mail ou senha incorretos.';
        }
    } else {
        $error = 'Preencha e-mail e senha.';
    }
}

// Buscar identidade da igreja para a tela de login
$churchName   = setting('church_name',    APP_NAME, SEDE_ID);
$churchSlogan = setting('church_slogan',  'Sistema de Gestão', SEDE_ID);
$logoUrl      = setting('church_logo_url', '', SEDE_ID);
$primaryColor = setting('primary_color',  '#012a36', SEDE_ID);
$accentColor  = setting('accent_color',   '#1D9E75', SEDE_ID);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login · <?= htmlspecialchars($churchName) ?></title>
  <link rel="stylesheet" href="/public/css/app.css">
  <style>
    body { display:flex; align-items:center; justify-content:center; min-height:100vh; background:var(--content-bg); }
    .login-box { width:100%; max-width:400px; padding:0 16px; }
    .login-card { background:white; border-radius:14px; box-shadow:0 4px 24px rgba(0,0,0,.09); overflow:hidden; }
    .login-header { background:<?= htmlspecialchars($primaryColor) ?>; padding:32px; text-align:center; }
    .login-logo {
      width:64px; height:64px; border-radius:14px;
      display:inline-flex; align-items:center; justify-content:center;
      font-size:28px; color:white; margin-bottom:14px;
      overflow:hidden;
    }
    .login-title { font-size:20px; font-weight:600; color:white; margin-bottom:4px; }
    .login-sub { font-size:13px; color:rgba(255,255,255,.55); }
    .login-body { padding:28px; }
    .login-error { background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:10px 14px;font-size:13px;color:#A32D2D;margin-bottom:16px; }
    .login-timeout { background:#FEF3C7;border:1px solid #FCD34D;border-radius:8px;padding:10px 14px;font-size:13px;color:#854F0B;margin-bottom:16px; }
    .login-footer { text-align:center;margin-top:20px;font-size:12px;color:var(--text-muted); }
    .btn-login { background:<?= htmlspecialchars($accentColor) ?>; color:white; width:100%; justify-content:center; padding:11px; font-size:15px; }
    .btn-login:hover { opacity:.9; }
  </style>
</head>
<body>
<div class="login-box">
  <div class="login-card">
    <div class="login-header">
      <div class="login-logo">
        <?php if ($logoUrl): ?>
          <img src="<?= htmlspecialchars($logoUrl) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:14px">
        <?php else: ?>
          ✝
        <?php endif; ?>
      </div>
      <div class="login-title"><?= htmlspecialchars($churchName) ?></div>
      <div class="login-sub">Sistema de Gestão</div>
    </div>
    <div class="login-body">
      <?php if ($timeout): ?>
        <div class="login-timeout">Sua sessão expirou por inatividade. Faça login novamente.</div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="login-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST">
        <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
        <div class="form-group">
          <label class="form-label">E-mail</label>
          <input type="email" name="email" class="form-control"
                 placeholder="seu@email.com"
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                 autofocus required>
        </div>
        <div class="form-group" style="margin-bottom:20px">
          <label class="form-label" style="display:flex;justify-content:space-between">
            Senha
            <a href="/forgot_password.php" style="font-size:12px;color:var(--accent);text-decoration:none">Esqueci minha senha</a>
          </label>
          <input type="password" name="password" class="form-control"
                 placeholder="••••••••" required>
        </div>
        <button type="submit" class="btn btn-login">
          Entrar
        </button>
      </form>
    </div>
  </div>
  <div class="login-footer">
    &copy; <?= date('Y') ?> <?= htmlspecialchars($churchName) ?>
  </div>
</div>
</body>
</html>
