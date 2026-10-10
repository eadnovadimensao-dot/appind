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

$pageTitle      = 'Modo Ensaio · ' . $act['title'];
$activePage     = 'ministries';
$extraScriptSrc = '/public/js/chord-transpose.js';
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
    · <?= count($songs) ?> música(s)
  </p>
</div>

<?php if (count($songs) > 1): ?>
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:20px">
  <?php foreach ($songs as $i => $sg): ?>
    <a href="#song-<?= $i + 1 ?>" class="badge badge-gray" style="font-size:13px;padding:6px 12px;text-decoration:none">
      <?= $i + 1 ?>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php foreach ($songs as $i => $sg): ?>
  <div id="song-<?= $i + 1 ?>" class="card" style="margin-bottom:16px;scroll-margin-top:16px">
    <div style="display:flex;align-items:baseline;gap:10px;margin-bottom:12px">
      <span style="font-size:15px;color:var(--text-muted);font-weight:500"><?= $i + 1 ?>.</span>
      <div>
        <div style="font-size:18px;font-weight:600"><?= htmlspecialchars($sg['title']) ?></div>
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
          </div>
          <div class="chord-sheet"><?= render_chord_sheet($sg['chord_sheet_text']) ?></div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<a href="/pages/ministries/activity_view.php?id=<?= $id ?>" class="btn btn-secondary" style="justify-content:center;width:100%">
  ← Voltar pra atividade
</a>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
