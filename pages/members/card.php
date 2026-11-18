<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db       = db();
$memberId = auth_member_id();
$viewId   = (int)($_GET['member_id'] ?? 0);
$isOwn    = !$viewId || $viewId === $memberId;
$targetId = $isOwn ? $memberId : $viewId;

if (!$targetId) { header('Location: /dashboard.php'); exit; }
if (!$isOwn && !auth_member_can_view_member($targetId)) {
    header('Location: /dashboard.php?no_access=1');
    exit;
}

$stmt = $db->prepare("SELECT * FROM members WHERE id = ? AND church_id = ?");
$stmt->execute([$targetId, current_church_id()]);
$m = $stmt->fetch();

if (!$m) { header('Location: /dashboard.php'); exit; }

// Só o próprio membro precisa ter completado o cadastro; liderança pode
// pré-visualizar/imprimir a carteirinha antes disso.
$needsProfile = false;
if ($isOwn) {
    $done = (bool)$db->query("SELECT 1 FROM member_profile_answers WHERE member_id = {$m['id']} AND completed_at IS NOT NULL")->fetchColumn();
    if (!$done) $needsProfile = true;
}

$notActive = $m['status'] !== 'active';

// Gera o token de verificação na primeira vez que a carteirinha é aberta
if (!$needsProfile && !$notActive && empty($m['card_token'])) {
    $token = bin2hex(random_bytes(20));
    $db->prepare("UPDATE members SET card_token = ? WHERE id = ?")->execute([$token, $m['id']]);
    $m['card_token'] = $token;
}

$initials     = strtoupper(implode('', array_map(fn($p) => $p[0], array_slice(explode(' ', $m['name']), 0, 2))));
$churchName    = setting('church_name', APP_NAME, (int)$m['church_id']);
$logoUrl       = setting('church_logo_url', '', (int)$m['church_id']);
$churchAddress = setting('church_address', '', (int)$m['church_id']);
$churchCnpj    = setting('church_cnpj', '', (int)$m['church_id']);
$verifyUrl    = APP_URL . '/verify.php?t=' . ($m['card_token'] ?? '');
$backHref     = $isOwn ? '/pages/members/profile.php' : '/pages/members/view.php?id=' . $m['id'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Carteirinha de Membro · <?= htmlspecialchars($churchName) ?></title>
  <link rel="stylesheet" href="/public/css/app.css">
  <style>
    :root {
      --accent:    <?= setting('accent_color', '#1D9E75', (int)$m['church_id']) ?>;
      --accent-dk: <?= adjustColor(setting('accent_color','#1D9E75',(int)$m['church_id']), -20) ?>;
      --accent-lt: <?= hexToRgba(setting('accent_color','#1D9E75',(int)$m['church_id']), 0.12) ?>;
      --sb-bg:     <?= setting('primary_color', '#012a36', (int)$m['church_id']) ?>;
    }
    html, body { margin: 0; min-height: 100vh; }
    body {
      display: flex; align-items: center; justify-content: center;
      min-height: 100vh; padding: 28px 16px;
      background: linear-gradient(160deg, var(--sb-bg) 0%, var(--accent-dk) 100%);
    }
    .top-link {
      position: fixed; top: 16px; color: white; text-decoration: none;
      font-size: 13px; display: flex; align-items: center; gap: 6px;
      opacity: .85; z-index: 5;
    }
    .top-link:hover { opacity: 1; }
    @media print {
      body { background: white !important; display: block; padding: 0; }
      .top-link { display: none !important; }
    }
  </style>
</head>
<body>

<a href="<?= htmlspecialchars($backHref) ?>" class="top-link no-print" style="left:16px">← Voltar</a>
<?php if (!$needsProfile && !$notActive): ?>
  <a href="#" onclick="window.print();return false" class="top-link no-print" style="right:16px">🖨️ Imprimir</a>
<?php endif; ?>

<?php if ($needsProfile): ?>
  <div style="max-width:380px;width:100%;background:white;border-radius:20px;box-shadow:0 20px 60px rgba(0,0,0,.35);padding:40px 28px;text-align:center">
    <div style="font-size:42px;margin-bottom:12px">📝</div>
    <p style="font-size:16px;font-weight:600;margin-bottom:8px">Quase lá!</p>
    <p style="font-size:13px;color:var(--text-muted);margin-bottom:22px">
      Pra liberar sua carteirinha digital, complete primeiro seu cadastro com as perguntas do seu perfil de fé.
    </p>
    <a href="/pages/members/profile.php#complete-profile" class="btn btn-primary">Completar cadastro</a>
  </div>

<?php elseif ($notActive): ?>
  <div style="max-width:380px;width:100%;background:white;border-radius:20px;box-shadow:0 20px 60px rgba(0,0,0,.35);padding:40px 28px;text-align:center">
    <div style="font-size:42px;margin-bottom:12px">⚠️</div>
    <p style="font-size:16px;font-weight:600;margin-bottom:8px">Carteirinha indisponível</p>
    <p style="font-size:13px;color:var(--text-muted)">
      Este cadastro não está ativo no momento. Fale com a liderança da igreja.
    </p>
  </div>

<?php else: ?>

  <div id="member-card" style="width:100%;max-width:380px;border-radius:20px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.35);background:white">

    <!-- Topo: logo + nome da igreja, acima da foto -->
    <div style="background:var(--sb-bg);padding:14px 18px;display:flex;align-items:center;gap:10px">
      <div style="width:28px;height:28px;border-radius:7px;overflow:hidden;background:var(--accent);display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <?php if ($logoUrl): ?>
          <img src="<?= htmlspecialchars($logoUrl) ?>" style="width:100%;height:100%;object-fit:cover">
        <?php else: ?>
          <span style="color:white;font-weight:700;font-size:13px">✝</span>
        <?php endif; ?>
      </div>
      <span style="color:white;font-size:13px;font-weight:600"><?= htmlspecialchars($churchName) ?></span>
    </div>

    <!-- Foto em destaque -->
    <div style="height:340px;background:var(--accent-lt)">
      <?php if ($m['photo_url']): ?>
        <img src="<?= htmlspecialchars($m['photo_url']) ?>" style="width:100%;height:100%;object-fit:cover;display:block">
      <?php else: ?>
        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:72px;font-weight:700;color:var(--accent-dk)"><?= $initials ?></div>
      <?php endif; ?>
    </div>

    <!-- Dados -->
    <div style="padding:20px;text-align:center">
      <div style="font-size:19px;font-weight:700;color:var(--text);line-height:1.3;margin-bottom:10px">
        <?php if (!empty($m['ministerial_office'])): ?>
          <?= htmlspecialchars(ministerial_office_label($m['ministerial_office'], $m['gender'])) ?>
        <?php endif; ?>
        <?= htmlspecialchars($m['name']) ?>
      </div>

      <div style="font-size:12px;color:var(--text-muted);line-height:1.6">
        <?php if ($m['join_date']): ?>Membro desde <?= date('Y', strtotime($m['join_date'])) ?><?php endif; ?>
      </div>

      <div style="margin:18px 0;border-top:1px dashed var(--border)"></div>

      <div id="card-qr" style="display:flex;justify-content:center;margin-bottom:8px"></div>
      <div style="font-size:10px;color:var(--text-muted)">Escaneie para verificar a autenticidade</div>
      <div style="font-size:10px;color:var(--text-muted);margin-top:2px">ID #<?= $m['id'] ?></div>
    </div>

    <?php if ($churchAddress || $churchCnpj): ?>
      <div style="padding:14px 20px;background:var(--accent-lt);text-align:center;font-size:10px;color:var(--text-muted);line-height:1.6">
        <?php if ($churchAddress): ?><?= htmlspecialchars($churchAddress) ?><br><?php endif; ?>
        <?php if ($churchCnpj): ?>CNPJ <?= htmlspecialchars($churchCnpj) ?><?php endif; ?>
      </div>
    <?php endif; ?>

    <div style="padding:12px 20px;text-align:center;font-size:9px;color:var(--text-muted);line-height:1.5;border-top:1px dashed var(--border)">
      Esta carteirinha identifica o(a) portador(a) como membro desta igreja, assegurando-lhe o direito de visita e assistência religiosa a enfermos internados, nos termos da Lei Federal nº 9.982/2000 e do art. 5º, VII, da Constituição Federal.
    </div>
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <script>
    new QRCode(document.getElementById('card-qr'), {
      text: <?= json_encode($verifyUrl) ?>,
      width: 128,
      height: 128,
      colorDark: "#000000",
      colorLight: "#ffffff"
    });
  </script>

<?php endif; ?>

</body>
</html>
