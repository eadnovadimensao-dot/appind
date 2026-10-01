<?php
// Check-in coletivo por QR Code: um código fixo por filial, colado na entrada.
// Público, sem login — a pessoa busca o próprio nome e confirma, igual uma
// lista de presença de papel. Fica aberto pra confirmar a família inteira
// num só scan, sem precisar escanear de novo a cada pessoa.
require_once __DIR__ . '/config/database.php';

$db       = db();
$churchId = (int)($_GET['c'] ?? 0);
$church   = $churchId ? $db->query("SELECT id, name FROM churches WHERE id = $churchId")->fetch() : null;

if (!$church) {
    http_response_code(404);
    exit('QR Code inválido.');
}

$churchName   = setting('church_name', 'Igreja', $churchId);
$primaryColor = setting('primary_color', '#012a36', $churchId);
$accentColor  = setting('accent_color',  '#1D9E75', $churchId);
$logoUrl      = setting('church_logo_url', '', $churchId);

// Culto de hoje dessa filial
$service = $db->prepare("SELECT id, title, time_start FROM services WHERE church_id = ? AND service_date = CURDATE() ORDER BY time_start ASC LIMIT 1");
$service->execute([$churchId]);
$service = $service->fetch();

$confirmedName = null;
$familySuggestions = [];
$error = null;

// Confirmar presença (POST de um dos botões da lista de busca ou da sugestão de família)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $service) {
    $memberId = (int)($_POST['member_id'] ?? 0);
    $m = $db->prepare("SELECT id, name, phone, family_id FROM members WHERE id = ? AND church_id = ? AND status = 'active'");
    $m->execute([$memberId, $churchId]);
    $m = $m->fetch();

    if ($m) {
        $existing = $db->prepare("SELECT id, checked_in_at FROM service_checkins WHERE service_id = ? AND member_id = ?");
        $existing->execute([$service['id'], $memberId]);
        $existing = $existing->fetch();

        if ($existing && $existing['checked_in_at']) {
            // já confirmado antes, não faz nada
        } elseif ($existing) {
            $db->prepare("UPDATE service_checkins SET checked_in_at = NOW(), checkin_source = 'qr' WHERE id = ?")->execute([$existing['id']]);
        } else {
            $db->prepare("INSERT INTO service_checkins (service_id, member_id, checkin_token, checked_in_at, checkin_source) VALUES (?,?,?,NOW(),'qr')")
               ->execute([$service['id'], $memberId, bin2hex(random_bytes(16))]);
        }

        $confirmedName = $m['name'];

        // Sugestão de família: outros membros ativos da mesma família que ainda não confirmaram hoje
        if ($m['family_id']) {
            $fam = $db->prepare("
                SELECT m2.id, m2.name, m2.photo_url
                FROM members m2
                WHERE m2.family_id = ? AND m2.id != ? AND m2.church_id = ? AND m2.status = 'active'
                  AND m2.id NOT IN (SELECT member_id FROM service_checkins WHERE service_id = ? AND checked_in_at IS NOT NULL)
                ORDER BY m2.name
            ");
            $fam->execute([$m['family_id'], $memberId, $churchId, $service['id']]);
            $familySuggestions = $fam->fetchAll();
        }
    }
}

// Busca por nome
$q       = trim($_GET['q'] ?? $_POST['q'] ?? '');
$results = [];
if ($service && $q !== '') {
    $search = $db->prepare("
        SELECT m.id, m.name, m.photo_url, c.name AS cell_name,
               (SELECT checked_in_at FROM service_checkins WHERE service_id = ? AND member_id = m.id) AS checked_in_at
        FROM members m
        LEFT JOIN cells c ON c.id = m.cell_id
        WHERE m.church_id = ? AND m.status = 'active' AND m.name LIKE ?
        ORDER BY m.name LIMIT 12
    ");
    $search->execute([$service['id'], $churchId, '%' . $q . '%']);
    $results = $search->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($churchName) ?> · Check-in</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: system-ui, -apple-system, sans-serif; background: #f0f0f0; min-height: 100vh; padding: 20px 16px; }
    .wrap { max-width: 440px; margin: 0 auto; }
    .header { text-align: center; margin-bottom: 20px; }
    .logo { width: 56px; height: 56px; border-radius: 14px; background: <?= htmlspecialchars($primaryColor) ?>; display: flex; align-items: center; justify-content: center; font-size: 24px; color: white; margin: 0 auto 10px; overflow: hidden; }
    .church-name { font-size: 14px; font-weight: 600; color: #1a2332; }
    .service-name { font-size: 12px; color: #6b7280; margin-top: 2px; }
    .card { background: white; border-radius: 16px; padding: 24px; margin-bottom: 14px; }
    .search-form { display: flex; gap: 8px; }
    .search-input { flex: 1; padding: 12px 14px; border: 1.5px solid #e5e7eb; border-radius: 10px; font-size: 15px; }
    .search-btn { background: <?= htmlspecialchars($accentColor) ?>; color: white; border: none; border-radius: 10px; padding: 0 18px; font-size: 14px; font-weight: 500; }
    .result { display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid #f0f0f0; }
    .result:last-child { border-bottom: none; }
    .avatar { width: 38px; height: 38px; border-radius: 50%; background: #e5e7eb; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 600; color: #6b7280; overflow: hidden; flex-shrink: 0; }
    .avatar img { width: 100%; height: 100%; object-fit: cover; }
    .result-info { flex: 1; min-width: 0; }
    .result-name { font-size: 14px; font-weight: 500; color: #1a2332; }
    .result-cell { font-size: 11px; color: #9ca3af; }
    .confirm-btn { background: <?= htmlspecialchars($accentColor) ?>; color: white; border: none; border-radius: 8px; padding: 8px 14px; font-size: 13px; font-weight: 500; white-space: nowrap; }
    .already { font-size: 12px; color: #9ca3af; }
    .success-box { text-align: center; padding: 8px 0 18px; }
    .success-icon { font-size: 40px; margin-bottom: 6px; }
    .success-text { font-size: 15px; color: #0F6E56; font-weight: 500; }
    .family-box { background: #FFF7E6; border: 1px solid #F0D595; border-radius: 10px; padding: 14px; margin-top: 14px; }
    .family-title { font-size: 13px; color: #8A5A00; margin-bottom: 10px; }
    .family-chip { display: inline-flex; align-items: center; gap: 6px; background: white; border: 1px solid #F0D595; border-radius: 20px; padding: 8px 14px; margin: 0 6px 6px 0; }
    .no-service { text-align: center; padding: 40px 20px; color: #6b7280; font-size: 14px; }
  </style>
</head>
<body>
<div class="wrap">
  <div class="header">
    <div class="logo">
      <?php if ($logoUrl): ?><img src="<?= htmlspecialchars($logoUrl) ?>" style="width:100%;height:100%;object-fit:cover"><?php else: ?>⛪<?php endif; ?>
    </div>
    <div class="church-name"><?= htmlspecialchars($churchName) ?></div>
    <?php if ($service): ?>
      <div class="service-name"><?= htmlspecialchars($service['title']) ?><?= $service['time_start'] ? ' · ' . substr($service['time_start'],0,5) : '' ?></div>
    <?php endif; ?>
  </div>

  <?php if (!$service): ?>
    <div class="card no-service">Não há culto hoje cadastrado nessa filial.</div>
  <?php else: ?>

    <?php if ($confirmedName): ?>
      <div class="card">
        <div class="success-box">
          <div class="success-icon">🎉</div>
          <div class="success-text">Presença confirmada, <?= htmlspecialchars(explode(' ', $confirmedName)[0]) ?>!</div>
        </div>
        <?php if ($familySuggestions): ?>
          <div class="family-box">
            <div class="family-title">Confirmar também da família?</div>
            <?php foreach ($familySuggestions as $f): ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="member_id" value="<?= $f['id'] ?>">
                <button type="submit" class="family-chip">
                  <span class="avatar" style="width:22px;height:22px;font-size:10px">
                    <?php if ($f['photo_url']): ?><img src="<?= htmlspecialchars($f['photo_url']) ?>"><?php else: ?><?= strtoupper(substr($f['name'],0,1)) ?><?php endif; ?>
                  </span>
                  <?= htmlspecialchars(explode(' ', $f['name'])[0]) ?>
                </button>
              </form>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="card">
      <form method="GET" class="search-form">
        <input type="hidden" name="c" value="<?= $churchId ?>">
        <input type="text" name="q" class="search-input" placeholder="Digite seu nome…" value="<?= htmlspecialchars($q) ?>" autofocus>
        <button type="submit" class="search-btn">Buscar</button>
      </form>

      <?php if ($q !== ''): ?>
        <div style="margin-top:16px">
          <?php if (empty($results)): ?>
            <p style="font-size:13px;color:#9ca3af;text-align:center;padding:12px 0">Ninguém encontrado com esse nome.</p>
          <?php else: ?>
            <?php foreach ($results as $r): ?>
              <div class="result">
                <div class="avatar">
                  <?php if ($r['photo_url']): ?><img src="<?= htmlspecialchars($r['photo_url']) ?>"><?php else: ?><?= strtoupper(substr($r['name'],0,1)) ?><?php endif; ?>
                </div>
                <div class="result-info">
                  <div class="result-name"><?= htmlspecialchars($r['name']) ?></div>
                  <?php if ($r['cell_name']): ?><div class="result-cell"><?= htmlspecialchars($r['cell_name']) ?></div><?php endif; ?>
                </div>
                <?php if ($r['checked_in_at']): ?>
                  <span class="already">✅ confirmado</span>
                <?php else: ?>
                  <form method="POST">
                    <input type="hidden" name="member_id" value="<?= $r['id'] ?>">
                    <button type="submit" class="confirm-btn">Sou eu</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

  <?php endif; ?>
</div>
</body>
</html>
