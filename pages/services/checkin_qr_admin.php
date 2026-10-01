<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
if (!auth_can('manage_users') && !auth_can('all')) {
    http_response_code(403);
    include __DIR__ . '/../../includes/403.php';
    exit;
}

$branches = get_branches();

$pageTitle  = 'QR Code de check-in';
$activePage = 'services';
require_once __DIR__ . '/../../includes/layout.php';
?>

<p style="font-size:13px;color:var(--text-muted);margin-bottom:20px">
  Um QR Code fixo por filial. Imprima e deixe na entrada — qualquer um escaneia, busca o próprio nome e confirma
  presença no culto de hoje, sem precisar de login nem depender do WhatsApp.
</p>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px">
  <?php foreach ($branches as $b):
    $url = APP_URL . '/checkin_qr.php?c=' . $b['id'];
    $qrImg = 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=' . urlencode($url);
  ?>
    <div class="card" style="text-align:center">
      <p class="card-title" style="margin-bottom:12px"><?= htmlspecialchars($b['name']) ?></p>
      <img src="<?= htmlspecialchars($qrImg) ?>" alt="QR Code" style="width:100%;max-width:280px;border-radius:8px;border:1px solid var(--border)">
      <p style="font-size:11px;color:var(--text-muted);margin-top:10px;word-break:break-all"><?= htmlspecialchars($url) ?></p>
      <a href="<?= htmlspecialchars($qrImg) ?>" target="_blank" class="btn btn-secondary" style="font-size:12px;padding:6px 14px;margin-top:10px">Abrir imagem pra imprimir</a>
    </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
