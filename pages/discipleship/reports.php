<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/discipleship.php';
auth_check();

$db = db();
$id = (int)($_GET['id'] ?? 0);

$d = $db->prepare("SELECT * FROM discipleships WHERE id = ? AND church_id = ?");
$d->execute([$id, current_church_id()]);
$d = $d->fetch();
if (!$d) { header('Location: /pages/discipleship/index.php'); exit; }
if (!auth_can_see_discipleship_reports($d)) { header('Location: /dashboard.php?no_access=1'); exit; }

$canLog = auth_can_log_discipleship_report($d);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canLog) {
    $happened = ($_POST['happened'] ?? '1') !== '0';
    $date     = trim($_POST['meeting_date'] ?? '');
    if ($date === '') $errors[] = 'Data do encontro é obrigatória.';

    if ($happened) {
        $subject = trim($_POST['subject'] ?? '') ?: null;
        $notes   = trim($_POST['notes'] ?? '') ?: null;
        $prayers = trim($_POST['prayer_requests'] ?? '') ?: null;
        $reason  = null;
    } else {
        $reason = trim($_POST['no_meeting_reason'] ?? '');
        if ($reason === '') $errors[] = 'Conte o motivo do encontro não ter acontecido.';
        $subject = null; $notes = null; $prayers = null;
    }

    if (empty($errors)) {
        $db->prepare("
            INSERT INTO discipleship_reports (discipleship_id, meeting_date, happened, no_meeting_reason, subject, notes, prayer_requests, created_by)
            VALUES (?,?,?,?,?,?,?,?)
        ")->execute([$id, $date, $happened ? 1 : 0, $reason, $subject, $notes, $prayers, auth_member_id()]);
        header('Location: /pages/discipleship/reports.php?id=' . $id . '&ok=1');
        exit;
    }
}

$reports = discipleship_reports($db, $id);

$names = $db->prepare("SELECT dc.name AS disciple_name, ds.name AS discipler_name, c.name AS cell_name FROM discipleships dd JOIN members dc ON dc.id=dd.disciple_member_id JOIN members ds ON ds.id=dd.discipler_member_id JOIN cells c ON c.id=dd.cell_id WHERE dd.id=?");
$names->execute([$id]);
$names = $names->fetch();

$pageTitle  = 'Encontros · ' . $names['disciple_name'];
$activePage = 'discipleship';
require_once __DIR__ . '/../../includes/layout.php';
$happenedPost = ($_POST['happened'] ?? '1') !== '0';
?>

<div style="margin-bottom:16px">
  <a href="/pages/discipleship/index.php" style="font-size:13px;color:var(--text-muted);text-decoration:none">← Discipulado</a>
</div>

<?php if (isset($_GET['ok'])): ?>
  <div style="background:#E1F5EE;border:1px solid var(--accent);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#0F6E56">✅ Registrado.</div>
<?php endif; ?>
<?php if ($errors): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px">
  <p style="font-size:14px;font-weight:500"><?= htmlspecialchars($names['disciple_name']) ?> com <?= htmlspecialchars($names['discipler_name']) ?></p>
  <p style="font-size:12px;color:var(--text-muted)">Célula <?= htmlspecialchars($names['cell_name']) ?> · <?= count($reports) ?> encontro(s) registrado(s)</p>
</div>

<?php if ($canLog): ?>
<form method="POST" class="card" style="margin-bottom:16px">
  <input type="hidden" name="happened" id="happened" value="<?= $happenedPost ? '1' : '0' ?>">
  <p class="card-title">Registrar encontro</p>
  <div style="display:flex;gap:8px;margin-bottom:16px">
    <button type="button" id="btn-happened" class="btn <?= $happenedPost ? 'btn-primary' : 'btn-secondary' ?>" style="flex:1" onclick="setHappened(true)">✓ Houve encontro</button>
    <button type="button" id="btn-not-happened" class="btn <?= !$happenedPost ? 'btn-primary' : 'btn-secondary' ?>" style="flex:1" onclick="setHappened(false)">✕ Não houve</button>
  </div>

  <div class="form-group" style="max-width:220px">
    <label class="form-label">Data *</label>
    <input type="date" name="meeting_date" class="form-control" value="<?= htmlspecialchars($_POST['meeting_date'] ?? date('Y-m-d')) ?>" required>
  </div>

  <div id="block-not-happened">
    <div class="form-group" style="margin-bottom:0">
      <label class="form-label">Motivo</label>
      <textarea name="no_meeting_reason" class="form-control" rows="2" placeholder="Ex: viagem, imprevisto…"><?= htmlspecialchars($_POST['no_meeting_reason'] ?? '') ?></textarea>
    </div>
  </div>

  <div id="block-happened">
    <div class="form-group">
      <label class="form-label">Assunto</label>
      <input type="text" name="subject" class="form-control" placeholder="Sobre o que conversaram…" value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label class="form-label">Anotações</label>
      <textarea name="notes" class="form-control" rows="3" placeholder="Como foi o encontro, destaques…"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
    </div>
    <div class="form-group" style="margin-bottom:0">
      <label class="form-label">Pedidos de oração</label>
      <textarea name="prayer_requests" class="form-control" rows="2"><?= htmlspecialchars($_POST['prayer_requests'] ?? '') ?></textarea>
    </div>
  </div>

  <button type="submit" class="btn btn-primary" style="margin-top:16px">Salvar</button>
</form>
<?php endif; ?>

<div class="card" style="padding:0">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
    <p style="font-weight:500;font-size:14px">Histórico</p>
  </div>
  <?php if (empty($reports)): ?>
    <div class="empty-state" style="padding:24px">Nenhum encontro registrado ainda.</div>
  <?php else: ?>
    <?php foreach ($reports as $r): ?>
      <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
          <span style="font-size:13px;font-weight:500"><?= date('d/m/Y', strtotime($r['meeting_date'])) ?></span>
          <?php if (!$r['happened']): ?><span class="badge badge-gray">Não houve</span><?php endif; ?>
        </div>
        <?php if (!$r['happened']): ?>
          <p style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($r['no_meeting_reason'] ?? '') ?></p>
        <?php else: ?>
          <?php if ($r['subject']): ?><p style="font-size:13px;font-weight:500;margin-bottom:2px"><?= htmlspecialchars($r['subject']) ?></p><?php endif; ?>
          <?php if ($r['notes']): ?><p style="font-size:12px;color:var(--text-muted);white-space:pre-line"><?= htmlspecialchars($r['notes']) ?></p><?php endif; ?>
          <?php if ($r['prayer_requests']): ?><p style="font-size:12px;color:var(--accent);margin-top:4px">🙏 <?= htmlspecialchars($r['prayer_requests']) ?></p><?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php
$extraJs = <<<JS
function setHappened(v) {
  document.getElementById('happened').value = v ? '1' : '0';
  document.getElementById('block-happened').style.display = v ? 'block' : 'none';
  document.getElementById('block-not-happened').style.display = v ? 'none' : 'block';
  document.getElementById('btn-happened').className = 'btn ' + (v ? 'btn-primary' : 'btn-secondary');
  document.getElementById('btn-not-happened').className = 'btn ' + (!v ? 'btn-primary' : 'btn-secondary');
}
setHappened(document.getElementById('happened').value === '1');
JS;
require_once __DIR__ . '/../../includes/layout-footer.php';
?>
