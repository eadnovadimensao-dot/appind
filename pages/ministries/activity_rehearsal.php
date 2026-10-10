<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
require_once __DIR__ . "/../../includes/chords.php";
auth_check();
$db       = db();
$churchId = current_church_id();
$id       = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("
    SELECT ma.*, mn.name AS ministry_name, mn.id AS ministry_id
    FROM ministry_activities ma
    JOIN ministries mn ON mn.id = ma.ministry_id
    WHERE ma.id = ? AND ma.church_id = ?
");
$stmt->execute([$id, $churchId]);
$act = $stmt->fetch();
if (!$act) { header('Location: /pages/ministries/index.php'); exit; }
if (!auth_member_in_ministry((int)$act['ministry_id'])) { header('Location: /dashboard.php?no_access=1'); exit; }

// Mesma query de repertório da tela da atividade — catálogo tem prioridade
// sobre os campos locais legados.
$songs = $db->prepare("
    SELECT mas.id, mas.resource_id,
           COALESCE(r.title, mas.title) AS title,
           COALESCE(r.key_tone, mas.key_tone) AS key_tone,
           COALESCE(r.external_url, mas.reference_link) AS reference_link,
           r.materials_url,
           COALESCE(r.file_path, mas.file_path) AS file_path,
           r.chord_sheet_text, r.capo, r.bpm
    FROM ministry_activity_songs mas
    LEFT JOIN ministry_resources r ON r.id = mas.resource_id
    WHERE mas.activity_id = ?
    ORDER BY mas.position, mas.id
");
$songs->execute([$id]);
$songs = $songs->fetchAll();

if (empty($songs)) { header('Location: /pages/ministries/activity_view.php?id=' . $id); exit; }

$total = count($songs);
// "song" na URL é 1-based (mais natural pra link/compartilhar); clampa pro intervalo válido.
$current = (int)($_GET['song'] ?? 1);
if ($current < 1) $current = 1;
if ($current > $total) $current = $total;
$i  = $current - 1; // índice 0-based pro array
$sg = $songs[$i];

$pageTitle      = 'Modo Ensaio · ' . $act['title'];
$activePage     = 'ministries';
$extraScriptSrc = ['/public/js/chord-transpose.js', '/public/js/autoscroll.js'];
require_once __DIR__ . '/../../includes/layout.php';
?>

<div style="margin-bottom:16px">
  <a href="/pages/ministries/activity_view.php?id=<?= $id ?>" style="font-size:13px;color:var(--text-muted);text-decoration:none">
    ← <?= htmlspecialchars($act['title']) ?>
  </a>
  <h1 style="font-size:20px;font-weight:600;margin-top:6px">🎤 Modo Ensaio</h1>
  <p style="font-size:13px;color:var(--text-muted);margin-top:2px">
    <?= htmlspecialchars($act['ministry_name']) ?> ·
    <?= date('d/m/Y', strtotime($act['activity_date'])) ?>
    <?= $act['time_start'] ? ' às ' . substr($act['time_start'], 0, 5) : '' ?>
    · Música <?= $current ?> de <?= $total ?>
  </p>
</div>

<?php if ($total > 1): ?>
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:20px">
  <?php foreach ($songs as $idx => $s): ?>
    <a href="?id=<?= $id ?>&song=<?= $idx + 1 ?>"
       class="badge <?= $idx === $i ? 'badge-green' : 'badge-gray' ?>"
       style="font-size:13px;padding:6px 12px;text-decoration:none<?= $idx === $i ? ';font-weight:700' : '' ?>">
      <?= $idx + 1 ?>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px">
  <div style="display:flex;align-items:baseline;gap:10px;margin-bottom:12px">
    <span style="font-size:15px;color:var(--text-muted);font-weight:500"><?= $current ?>.</span>
    <div>
      <div style="font-size:21px;font-weight:600"><?= htmlspecialchars($sg['title']) ?></div>
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px">
        <?php if ($sg['key_tone']): ?>
          <span class="badge badge-gray">Tom: <?= htmlspecialchars($sg['key_tone']) ?></span>
        <?php endif; ?>
        <?php if ($sg['capo']): ?>
          <span class="badge badge-gray">Capo <?= (int)$sg['capo'] ?></span>
        <?php endif; ?>
        <?php if ($sg['bpm']): ?>
          <span class="badge badge-gray"><?= (int)$sg['bpm'] ?> BPM</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($capoLabel = capo_shape_label($sg['key_tone'], $sg['capo'])): ?>
    <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px">
      🎸 <?= htmlspecialchars($capoLabel) ?> — selecione a casa no seletor de capotraste aqui embaixo pra ver.
    </div>
  <?php endif; ?>

  <?php if (!$sg['reference_link'] && !$sg['file_path'] && !$sg['materials_url'] && !$sg['chord_sheet_text']): ?>
    <p style="font-size:13px;color:var(--text-muted)">Sem material cadastrado pra essa música.</p>
  <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:<?= $sg['chord_sheet_text'] ? '16px' : '0' ?>">
      <?php if ($sg['reference_link']): ?>
        <a href="<?= htmlspecialchars($sg['reference_link']) ?>" target="_blank" rel="noopener"
           class="btn btn-primary" style="justify-content:center;padding:12px;font-size:14px">▶ Ouvir referência</a>
      <?php endif; ?>
      <?php if ($sg['file_path']): ?>
        <a href="<?= htmlspecialchars($sg['file_path']) ?>" target="_blank" rel="noopener"
           class="btn btn-secondary" style="justify-content:center;padding:12px;font-size:14px">📄 Ver cifra/partitura</a>
      <?php endif; ?>
      <?php if ($sg['materials_url']): ?>
        <a href="<?= htmlspecialchars($sg['materials_url']) ?>" target="_blank" rel="noopener"
           class="btn btn-secondary" style="justify-content:center;padding:12px;font-size:14px">📁 Abrir materiais (Drive)</a>
      <?php endif; ?>
    </div>
    <?php if ($sg['chord_sheet_text']): ?>
      <div data-chord-sheet data-offset="0" style="border-top:1px solid var(--border);padding-top:14px">
        <div class="chord-controls">
          <button type="button" data-transpose-down title="Baixar um tom">−</button>
          <span>Tom: <strong data-current-key><?= htmlspecialchars($sg['key_tone'] ?: '—') ?></strong></span>
          <button type="button" data-transpose-up title="Subir um tom">+</button>
          <button type="button" data-transpose-reset style="width:auto;padding:0 10px;font-size:12px">Original</button>
          <span style="width:1px;align-self:stretch;background:var(--border);margin:0 2px"></span>
          <button type="button" data-font-down title="Diminuir a letra" style="font-size:13px">A−</button>
          <button type="button" data-font-up title="Aumentar a letra" style="font-size:17px">A+</button>
          <span style="width:1px;align-self:stretch;background:var(--border);margin:0 2px"></span>
          <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-muted)">
            🎸
            <select data-capo-picker class="form-control" style="width:auto;padding:5px 8px;font-size:12px">
              <?= render_capo_picker_options() ?>
            </select>
          </label>
        </div>
        <?= render_chord_chart($sg['chord_sheet_text']) ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div style="display:flex;gap:10px;margin-bottom:10px">
  <?php if ($current > 1): ?>
    <a href="?id=<?= $id ?>&song=<?= $current - 1 ?>" class="btn btn-secondary" style="flex:1;justify-content:center;padding:14px;font-size:15px">← Anterior</a>
  <?php else: ?>
    <span class="btn btn-secondary" style="flex:1;justify-content:center;padding:14px;font-size:15px;opacity:.4;cursor:default">← Anterior</span>
  <?php endif; ?>
  <?php if ($current < $total): ?>
    <a href="?id=<?= $id ?>&song=<?= $current + 1 ?>" class="btn btn-primary" style="flex:1;justify-content:center;padding:14px;font-size:15px">Próxima →</a>
  <?php else: ?>
    <span class="btn btn-secondary" style="flex:1;justify-content:center;padding:14px;font-size:15px;opacity:.4;cursor:default">Próxima →</span>
  <?php endif; ?>
</div>

<a href="/pages/ministries/activity_view.php?id=<?= $id ?>" class="btn btn-secondary" style="justify-content:center;width:100%">
  ← Voltar pra atividade
</a>

<div class="autoscroll-spacer"></div>
<div class="autoscroll-bar" id="autoscroll-bar">
  <button type="button" id="autoscroll-slower" title="Mais devagar">🐢</button>
  <span class="autoscroll-speed" id="autoscroll-speed-label">4</span>
  <button type="button" id="autoscroll-faster" title="Mais rápido">🐇</button>
  <button type="button" id="autoscroll-toggle">▶ Rolar sozinho</button>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
