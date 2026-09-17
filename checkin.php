<?php
require_once __DIR__ . '/config/database.php';

$token = trim($_GET['token'] ?? '');

if (!$token) {
    header('Location: /login.php');
    exit;
}

$db = db();

// Primeiro tenta check-in geral de culto (congregação inteira)
$stmt = $db->prepare("
    SELECT sc.checked_in_at, s.title, s.service_date AS activity_date, s.time_start,
           s.church_id, m.name AS member_name, 'culto' AS kind
    FROM service_checkins sc
    JOIN services s ON s.id = sc.service_id
    JOIN members m ON m.id = sc.member_id
    WHERE sc.checkin_token = ?
");
$stmt->execute([$token]);
$row = $stmt->fetch();

// Se não achou, tenta check-in de escala de ministério (quem está servindo)
if (!$row) {
    $stmt = $db->prepare("
        SELECT mam.checked_in_at, ma.title, ma.activity_date, ma.time_start, mn.name AS ministry_name,
               mn.church_id, m.name AS member_name, 'ministerio' AS kind
        FROM ministry_activity_members mam
        JOIN ministry_activities ma ON ma.id = mam.activity_id
        JOIN ministries mn ON mn.id = ma.ministry_id
        JOIN members m ON m.id = mam.member_id
        WHERE mam.checkin_token = ?
    ");
    $stmt->execute([$token]);
    $row = $stmt->fetch();
}

$error   = null;
$success = null;
$already = false;

if (!$row) {
    $error = 'Link inválido.';
} elseif ($row['checked_in_at']) {
    $already = true;
    $success = "✅ Presença já registrada às " . date('H:i', strtotime($row['checked_in_at'])) . ".";
} else {
    $table = $row['kind'] === 'culto' ? 'service_checkins' : 'ministry_activity_members';
    $db->prepare("UPDATE {$table} SET checked_in_at = NOW() WHERE checkin_token = ?")->execute([$token]);
    $now = date('H:i');
    $success = "✅ Presença registrada às {$now}. Bom culto" . ($row['kind'] === 'ministerio' ? '/ensaio' : '') . ", {$row['member_name']}! 🙏";
}

$churchId     = $row['church_id'] ?? 1;
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
  <title><?= htmlspecialchars($churchName) ?> · Check-in</title>
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
    .activity-box {
      background: #f9fafb; border-radius: 10px;
      padding: 14px 16px; margin: 20px 0; text-align: left;
    }
    .activity-box .label { font-size: 11px; color: #9ca3af; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 4px; }
    .activity-box .value { font-size: 14px; font-weight: 500; color: #1a2332; }
    .login-link { font-size: 12px; color: #9ca3af; margin-top: 20px; }
    .login-link a { color: <?= htmlspecialchars($accentColor) ?>; text-decoration: none; }
  </style>
</head>
<body>
<div class="card">
  <div class="logo">
    <?php if ($logoUrl): ?>
      <img src="<?= htmlspecialchars($logoUrl) ?>" style="width:100%;height:100%;object-fit:cover">
    <?php else: ?>
      ⛪
    <?php endif; ?>
  </div>
  <div class="church-name"><?= htmlspecialchars($churchName) ?></div>

  <?php if ($error): ?>
    <div class="icon">❌</div>
    <h1>Link inválido</h1>
    <p class="error"><?= htmlspecialchars($error) ?></p>
  <?php else: ?>
    <div class="icon"><?= $already ? '✅' : '🎉' ?></div>
    <h1>Check-in</h1>
    <p class="success"><?= htmlspecialchars($success) ?></p>

    <div class="activity-box">
      <div class="label"><?= $row['kind'] === 'culto' ? 'Culto' : 'Atividade' ?></div>
      <div class="value"><?= htmlspecialchars($row['title']) ?></div>
      <?php if ($row['kind'] === 'ministerio'): ?>
        <div style="margin-top:8px">
          <div class="label">Ministério</div>
          <div class="value"><?= htmlspecialchars($row['ministry_name']) ?></div>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="login-link">
    <a href="/login.php">Acessar o sistema</a>
  </div>
</div>
</body>
</html>
