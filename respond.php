<?php
require_once __DIR__ . '/config/database.php';

$token  = trim($_GET['token']  ?? '');
$action = trim($_GET['action'] ?? '');
$reason = trim($_POST['reason'] ?? '');

if (!$token || !in_array($action, ['confirm','refuse','submit_reason'])) {
    header('Location: /login.php');
    exit;
}

$db = db();

// Buscar a escalação pelo token
$stmt = $db->prepare("
    SELECT mam.*, ma.title, ma.activity_date, mn.name AS ministry_name,
           mn.church_id, m.name AS member_name
    FROM ministry_activity_members mam
    JOIN ministry_activities ma ON ma.id = mam.activity_id
    JOIN ministries mn ON mn.id = ma.ministry_id
    JOIN members m ON m.id = mam.member_id
    WHERE mam.confirm_token = ?
");
$stmt->execute([$token]);
$row = $stmt->fetch();

$error   = null;
$success = null;
$showReasonForm = false;

if (!$row) {
    $error = 'Link inválido ou expirado.';
} elseif ($row['status'] !== 'pending') {
    // Já respondeu
    $success = $row['status'] === 'confirmed'
        ? '✅ Você já confirmou sua presença nesta atividade.'
        : '❌ Você já registrou que não poderá participar.';
} elseif (strtotime($row['token_expires_at']) < time()) {
    $error = 'Este link expirou. Acesse o sistema para responder.';
} elseif ($action === 'refuse' && empty($_POST['reason'])) {
    // Mostrar formulário de motivo
    $showReasonForm = true;
} elseif ($action === 'confirm') {
    $db->prepare("
        UPDATE ministry_activity_members
        SET status='confirmed', responded_at=NOW()
        WHERE confirm_token=?
    ")->execute([$token]);

    // Notificar o líder
    $tpl = notification_template('scale_confirmed', [
        'nome'       => $row['member_name'],
        'ministerio' => $row['ministry_name'],
        'data'       => date('d/m/Y', strtotime($row['activity_date'])),
    ], $row['church_id']);

    // Buscar líderes do ministério para notificar
    $leadersQ = $db->prepare("
        SELECT ml.member_id FROM ministry_leaders ml
        JOIN ministry_activities ma ON ma.ministry_id = ml.ministry_id
        JOIN ministry_activity_members mam ON mam.activity_id = ma.id
        WHERE mam.confirm_token = ?
    ");
    $leadersQ->execute([$token]);
    $leaderIds = array_column($leadersQ->fetchAll(), 'member_id');

    foreach ($leaderIds as $lid) {
        $db->prepare("
            INSERT INTO announcements (church_id, title, content, type, target_type, target_id, channels, status, created_by, sent_at)
            VALUES (?,?,?,'general','member',?,'internal','sent',?,NOW())
        ")->execute([$row['church_id'], $tpl['title'], $tpl['content'], $lid, $row['member_id']]);
    }

    $success = "✅ Presença confirmada! Obrigado, {$row['member_name']}. Até lá! 🙏";

} elseif ($action === 'refuse' && !empty($_POST['reason'])) {
    $db->prepare("
        UPDATE ministry_activity_members
        SET status='refused', refuse_reason=?, responded_at=NOW()
        WHERE confirm_token=?
    ")->execute([$_POST['reason'], $token]);

    // Notificar o líder com template
    $tpl = notification_template('scale_refused', [
        'nome'       => $row['member_name'],
        'ministerio' => $row['ministry_name'],
        'data'       => date('d/m/Y', strtotime($row['activity_date'])),
        'motivo'     => $_POST['reason'],
    ], $row['church_id']);

    $leadersQ = $db->prepare("
        SELECT ml.member_id FROM ministry_leaders ml
        JOIN ministry_activities ma ON ma.ministry_id = ml.ministry_id
        JOIN ministry_activity_members mam ON mam.activity_id = ma.id
        WHERE mam.confirm_token = ?
    ");
    $leadersQ->execute([$token]);
    $leaderIds = array_column($leadersQ->fetchAll(), 'member_id');

    $manageUrl = APP_URL . '/pages/ministries/activity_view.php?id=' . $row['activity_id'];
    $leaderContent = $tpl['content'] . "\n\n🔗 Substituir na escala: " . $manageUrl;

    // Push em lote (mesma lib usada em outros fluxos de notificação)
    $webPush = null;
    $vapidPublic  = setting('vapid_public_key', '', $row['church_id']);
    $vapidPrivate = setting('vapid_private_key', '', $row['church_id']);
    $pushAutoload = __DIR__ . '/vendor/autoload.php';
    if ($vapidPublic && $vapidPrivate && file_exists($pushAutoload)) {
        require_once $pushAutoload;
        $webPush = new \Minishlink\WebPush\WebPush([
            'VAPID' => [
                'subject'    => setting('vapid_subject', 'mailto:admin@igrejanovadimensao.com.br', $row['church_id']),
                'publicKey'  => $vapidPublic,
                'privateKey' => $vapidPrivate,
            ],
        ]);
    }

    foreach ($leaderIds as $lid) {
        $db->prepare("
            INSERT INTO announcements (church_id, title, content, type, target_type, target_id, channels, status, created_by, sent_at)
            VALUES (?,?,?,'general','member',?,'internal,push','sent',?,NOW())
        ")->execute([$row['church_id'], $tpl['title'], $leaderContent, $lid, $row['member_id']]);

        $leader = $db->query("SELECT name, phone, email FROM members WHERE id=" . (int)$lid)->fetch();

        if ($webPush) {
            $subs = $db->prepare("SELECT * FROM push_subscriptions WHERE member_id=?");
            $subs->execute([$lid]);
            foreach ($subs->fetchAll() as $sub) {
                $webPush->queueNotification(
                    \Minishlink\WebPush\Subscription::create([
                        'endpoint'        => $sub['endpoint'],
                        'keys'            => ['p256dh' => $sub['p256dh'], 'auth' => $sub['auth_key']],
                        'contentEncoding' => 'aesgcm',
                    ]),
                    json_encode([
                        'title' => $tpl['title'],
                        'body'  => $row['member_name'] . ' não poderá participar em ' . date('d/m/Y', strtotime($row['activity_date'])),
                        'url'   => $manageUrl,
                        'tag'   => 'refusal-' . $row['activity_id'],
                    ])
                );
            }
        }

        if (!empty($leader['phone'])) {
            send_whatsapp($leader['phone'], $tpl['title'] . "\n\n" . $leaderContent, $row['church_id']);
        }

        if (!empty($leader['email'])) {
            $emailBody = "
            <div style='text-align:center;margin-bottom:24px'>
              <div style='font-size:48px;margin-bottom:12px'>❌</div>
              <h1 style='font-size:20px;font-weight:600;color:#1a2332;margin:0 0 8px'>" . htmlspecialchars($tpl['title']) . "</h1>
              <p style='color:#6b7280;font-size:14px;margin:0;white-space:pre-line'>" . nl2br(htmlspecialchars($tpl['content'])) . "</p>
            </div>
            <p style='text-align:center;margin:0'>
              <a href='{$manageUrl}' style='display:inline-block;background:#1D9E75;color:white;padding:12px 20px;border-radius:10px;text-decoration:none;font-size:14px;font-weight:600'>
                Substituir na escala
              </a>
            </p>";
            $html = email_template($tpl['title'], $emailBody, $row['church_id']);
            send_email($leader['email'], $leader['name'], $tpl['title'], $html, $row['church_id']);
        }
    }

    if ($webPush) {
        foreach ($webPush->flush() as $report) {
            if ($report->isSubscriptionExpired()) {
                $db->prepare("DELETE FROM push_subscriptions WHERE endpoint=?")->execute([$report->getRequest()->getUri()->__toString()]);
            }
        }
    }

    $success = "Entendemos, {$row['member_name']}. Obrigado por avisar! 🙏";
}

// Buscar settings da igreja para identidade visual
$churchId = $row['church_id'] ?? 1;
$churchName  = setting('church_name', 'Igreja', $churchId);
$primaryColor = setting('primary_color', '#012a36', $churchId);
$accentColor  = setting('accent_color', '#1D9E75', $churchId);
$logoUrl      = setting('church_logo_url', null, $churchId);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($churchName) ?> · Confirmação de escala</title>
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
    .sub { font-size: 14px; color: #6b7280; line-height: 1.6; margin-bottom: 24px; }
    .activity-box {
      background: #f9fafb; border-radius: 10px;
      padding: 14px 16px; margin-bottom: 24px; text-align: left;
    }
    .activity-box .label { font-size: 11px; color: #9ca3af; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 4px; }
    .activity-box .value { font-size: 14px; font-weight: 500; color: #1a2332; }
    .btn {
      width: 100%; padding: 13px; border-radius: 10px;
      border: none; font-size: 15px; font-weight: 500;
      cursor: pointer; margin-bottom: 10px;
    }
    .btn-confirm { background: <?= htmlspecialchars($accentColor) ?>; color: white; }
    .btn-refuse { background: #f3f4f6; color: #374151; }
    textarea {
      width: 100%; padding: 12px; border: 1.5px solid #e5e7eb;
      border-radius: 10px; font-size: 14px; font-family: inherit;
      resize: none; margin-bottom: 12px; outline: none;
    }
    textarea:focus { border-color: <?= htmlspecialchars($accentColor) ?>; }
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
    <?php else: ?>
      ⛪
    <?php endif; ?>
  </div>
  <div class="church-name"><?= htmlspecialchars($churchName) ?></div>

  <?php if ($error): ?>
    <div class="icon">❌</div>
    <h1>Link inválido</h1>
    <p class="error"><?= htmlspecialchars($error) ?></p>

  <?php elseif ($success): ?>
    <div class="icon"><?= str_starts_with($success,'✅') ? '🎉' : '🙏' ?></div>
    <h1>Resposta registrada</h1>
    <p class="success"><?= htmlspecialchars($success) ?></p>

  <?php elseif ($showReasonForm): ?>
    <div class="icon">💬</div>
    <h1>Qual o motivo?</h1>
    <p class="sub" style="margin-bottom:20px">Informe o motivo para que o líder possa reorganizar a escala.</p>
    <form method="POST">
      <input type="hidden" name="reason" value="">
      <textarea name="reason" rows="3" placeholder="Ex: Estarei viajando nesta data..." required></textarea>
      <input type="hidden" name="action" value="refuse">
      <button type="submit" class="btn btn-confirm"
              formaction="/respond.php?token=<?= urlencode($token) ?>&action=refuse">
        Enviar motivo
      </button>
      <a href="/respond.php?token=<?= urlencode($token) ?>&action=confirm">
        <button type="button" class="btn btn-refuse">Voltar</button>
      </a>
    </form>

  <?php else: ?>
    <div class="icon">🎵</div>
    <h1>Confirmação de escala</h1>
    <p class="sub">Olá, <strong><?= htmlspecialchars($row['member_name']) ?></strong>! Você foi escalado(a) para:</p>

    <div class="activity-box">
      <div class="label">Atividade</div>
      <div class="value"><?= htmlspecialchars($row['title']) ?></div>
      <div style="margin-top:8px">
        <div class="label">Ministério</div>
        <div class="value"><?= htmlspecialchars($row['ministry_name']) ?></div>
      </div>
      <div style="margin-top:8px">
        <div class="label">Data</div>
        <div class="value"><?= date('d/m/Y (l)', strtotime($row['activity_date'])) ?></div>
      </div>
    </div>

    <a href="/respond.php?token=<?= urlencode($token) ?>&action=confirm">
      <button class="btn btn-confirm">✅ Confirmar presença</button>
    </a>
    <a href="/respond.php?token=<?= urlencode($token) ?>&action=refuse">
      <button class="btn btn-refuse">❌ Não posso ir</button>
    </a>
  <?php endif; ?>

  <div class="login-link">
    <a href="/login.php">Acessar o sistema</a>
  </div>
</div>
</body>
</html>
