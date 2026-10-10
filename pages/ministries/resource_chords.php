<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
require_once __DIR__ . "/../../includes/chords.php";
auth_check();

$db    = db();
$resId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("
    SELECT r.*, mn.name AS ministry_name
    FROM ministry_resources r
    JOIN ministries mn ON mn.id = r.ministry_id
    WHERE r.id = ?
");
$stmt->execute([$resId]);
$res = $stmt->fetch();
if (!$res || current_church_id() != $res['church_id']) { header('Location: /pages/ministries/index.php'); exit; }
if (!auth_member_in_ministry((int)$res['ministry_id'])) { header('Location: /dashboard.php?no_access=1'); exit; }
if (!$res['chord_sheet_text']) { header('Location: /pages/ministries/resources.php?ministry_id=' . $res['ministry_id']); exit; }

$pageTitle  = 'Cifra · ' . $res['title'];
$activePage = 'ministries';
$extraScriptSrc = ['/public/js/chord-transpose.js', '/public/js/autoscroll.js'];
require_once __DIR__ . '/../../includes/layout.php';
?>

<div style="margin-bottom:16px">
  <a href="/pages/ministries/resources.php?ministry_id=<?= $res['ministry_id'] ?>" style="font-size:13px;color:var(--text-muted);text-decoration:none">
    ← Materiais · <?= htmlspecialchars($res['ministry_name']) ?>
  </a>
</div>

<div class="card">
  <h1 style="font-size:18px;font-weight:600;margin-bottom:4px"><?= htmlspecialchars($res['title']) ?></h1>
  <div style="font-size:12px;color:var(--text-muted);margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($res['capo']): ?><span class="badge badge-gray">Capo <?= (int)$res['capo'] ?></span><?php endif; ?>
    <?php if ($res['bpm']): ?><span class="badge badge-gray"><?= (int)$res['bpm'] ?> BPM</span><?php endif; ?>
  </div>

  <div data-chord-sheet data-offset="0" data-capo="<?= (int)($res['capo'] ?? 0) ?>">
    <div class="chord-controls">
      <button type="button" data-transpose-down title="Baixar um tom">−</button>
      <span>Tom: <strong data-current-key><?= htmlspecialchars($res['key_tone'] ?: '—') ?></strong></span>
      <button type="button" data-transpose-up title="Subir um tom">+</button>
      <button type="button" data-transpose-reset style="width:auto;padding:0 10px;font-size:12px" title="Voltar ao tom original">Original</button>
      <span style="width:1px;align-self:stretch;background:var(--border);margin:0 2px"></span>
      <button type="button" data-font-down title="Diminuir a letra" style="font-size:13px">A−</button>
      <button type="button" data-font-up title="Aumentar a letra" style="font-size:17px">A+</button>
      <?php if ($res['capo']): ?>
        <button type="button" data-capo-view style="width:auto;padding:0 10px;font-size:12px">🎸 Ver formas c/ capo</button>
      <?php endif; ?>
    </div>
    <?= render_chord_chart($res['chord_sheet_text']) ?>
  </div>
</div>

<div class="autoscroll-spacer"></div>
<div class="autoscroll-bar" id="autoscroll-bar">
  <button type="button" id="autoscroll-slower" title="Mais devagar">🐢</button>
  <span class="autoscroll-speed" id="autoscroll-speed-label">4</span>
  <button type="button" id="autoscroll-faster" title="Mais rápido">🐇</button>
  <button type="button" id="autoscroll-toggle">▶ Rolar sozinho</button>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
