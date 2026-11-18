<?php
require_once __DIR__ . '/config/database.php';

$db    = db();
$token = trim($_GET['t'] ?? '');

$m = null;
if ($token !== '') {
    $stmt = $db->prepare("
        SELECT m.*, ch.name AS branch_name
        FROM members m
        LEFT JOIN churches ch ON ch.id = m.church_id
        WHERE m.card_token = ?
    ");
    $stmt->execute([$token]);
    $m = $stmt->fetch();
}

$valid      = $m && $m['status'] === 'active';
$churchName = $m ? setting('church_name', APP_NAME, (int)$m['church_id']) : APP_NAME;
$logoUrl    = $m ? setting('church_logo_url', '', (int)$m['church_id']) : '';
$initials   = $m ? strtoupper(implode('', array_map(fn($p) => $p[0], array_slice(explode(' ', $m['name']), 0, 2)))) : '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Verificação de membro · <?= htmlspecialchars($churchName) ?></title>
  <link rel="stylesheet" href="/public/css/app.css">
  <style>
    :root {
      --accent: <?= $m ? setting('accent_color', '#1D9E75', (int)$m['church_id']) : '#1D9E75' ?>;
      --accent-dk: <?= $m ? adjustColor(setting('accent_color','#1D9E75',(int)$m['church_id']), -20) : '#0F6E56' ?>;
      --accent-lt: <?= $m ? hexToRgba(setting('accent_color','#1D9E75',(int)$m['church_id']), 0.12) : '#E1F5EE' ?>;
      --sb-bg: <?= $m ? setting('primary_color', '#012a36', (int)$m['church_id']) : '#012a36' ?>;
    }
    body { display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--content-bg); }
    .box { width:100%;max-width:380px;padding:0 16px; }
    .card-wrap { background:white;border-radius:14px;box-shadow:0 4px 24px rgba(0,0,0,.09);overflow:hidden; }
    .card-header { background:var(--sb-bg);padding:22px;text-align:center; }
    .card-body { padding:28px;text-align:center; }
  </style>
</head>
<body>
<div class="box">
  <div class="card-wrap">
    <div class="card-header">
      <div style="width:36px;height:36px;border-radius:8px;overflow:hidden;background:var(--accent);display:inline-flex;align-items:center;justify-content:center;margin-bottom:8px">
        <?php if ($logoUrl): ?>
          <img src="<?= htmlspecialchars($logoUrl) ?>" style="width:100%;height:100%;object-fit:cover">
        <?php else: ?>
          <span style="color:white;font-weight:700;font-size:15px">✝</span>
        <?php endif; ?>
      </div>
      <div style="font-size:14px;color:white"><?= htmlspecialchars($churchName) ?></div>
    </div>
    <div class="card-body">
      <?php if (!$m): ?>
        <div style="font-size:40px;margin-bottom:12px">❌</div>
        <p style="font-size:14px;font-weight:500;color:#A32D2D">Carteirinha não encontrada</p>
        <p style="font-size:12px;color:var(--text-muted);margin-top:6px">Este código não corresponde a nenhum membro.</p>

      <?php elseif (!$valid): ?>
        <div style="font-size:40px;margin-bottom:12px">⚠️</div>
        <p style="font-size:14px;font-weight:500;color:#A32D2D">Cadastro não está ativo</p>
        <p style="font-size:12px;color:var(--text-muted);margin-top:6px">Esta carteirinha não representa um membro ativo no momento.</p>

      <?php else: ?>
        <div style="width:72px;height:72px;border-radius:50%;overflow:hidden;background:var(--accent-lt);display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:600;color:var(--accent-dk);margin:0 auto 14px">
          <?php if ($m['photo_url']): ?>
            <img src="<?= htmlspecialchars($m['photo_url']) ?>" style="width:100%;height:100%;object-fit:cover">
          <?php else: ?>
            <?= $initials ?>
          <?php endif; ?>
        </div>
        <div style="font-size:40px;margin-bottom:6px">✅</div>
        <p style="font-size:16px;font-weight:600"><?= htmlspecialchars($m['name']) ?></p>
        <p style="font-size:13px;color:var(--accent-dk);font-weight:500;margin-top:4px">Membro ativo</p>
        <?php if ($m['join_date']): ?>
          <p style="font-size:12px;color:var(--text-muted);margin-top:8px">Desde <?= date('Y', strtotime($m['join_date'])) ?></p>
        <?php endif; ?>
        <?php if ($m['branch_name']): ?>
          <p style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($m['branch_name']) ?></p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
