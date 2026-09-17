<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
auth_check();
$db = db(); $churchId = current_church_id();
$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM agenda_events WHERE id = ? AND church_id = ?");
$stmt->execute([$id, $churchId]);
$ev = $stmt->fetch();
if (!$ev) { header('Location: /pages/events/index.php'); exit; }

// Só admin (approve_events) ou quem solicitou o evento pode editar
if (!auth_can('approve_events') && (int)$ev['requested_by'] !== (int)auth_member_id()) {
    http_response_code(403);
    include __DIR__ . '/../../includes/403.php';
    exit;
}

$pageTitle = 'Editar · ' . $ev['title'];
$locations = $db->query("SELECT id, name FROM locations WHERE church_id = $churchId AND active = 1 ORDER BY name")->fetchAll();
$ministries = $db->query("SELECT id, name FROM ministries WHERE church_id = $churchId ORDER BY name")->fetchAll();
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $date  = trim($_POST['event_date'] ?? '');
    $ts    = trim($_POST['time_start'] ?? '');
    $te    = trim($_POST['time_end']   ?? '');
    $loc   = trim($_POST['location_id'] ?? '') ?: null;
    $mn    = trim($_POST['ministry_id'] ?? '') ?: null;
    $desc  = trim($_POST['description'] ?? '');
    $color = trim($_POST['color'] ?? '#1D9E75');
    if ($title === '') $errors[] = 'Título é obrigatório.';
    if (empty($errors)) {
        $db->prepare("UPDATE agenda_events SET title=?,description=?,location_id=?,event_date=?,time_start=?,time_end=?,ministry_id=?,color=? WHERE id=? AND church_id=?")
           ->execute([$title,$desc?:null,$loc,$date,$ts,$te,$mn,$color,$id,$churchId]);
        header('Location: /pages/events/view.php?id='.$id);
        exit;
    }
    // Erro de validação: repopula o formulário com o que a pessoa digitou
    $ev = array_merge($ev, $_POST);
}

$activePage = 'events';
require_once __DIR__ . '/../../includes/layout.php';
?>
<form method="POST" style="width:100%">
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Editar evento</p>
    <?php if (!empty($errors)): ?>
      <div style="background:#FCEBEB;border-radius:8px;padding:12px;margin-bottom:16px;font-size:13px;color:#A32D2D">
        <?php foreach($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Título *</label>
        <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($ev['title']) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Local</label>
        <select name="location_id" class="form-control">
          <option value="">Selecione</option>
          <?php foreach ($locations as $l): ?>
            <option value="<?= $l['id'] ?>" <?= $ev['location_id']==$l['id']?'selected':''?>><?= htmlspecialchars($l['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Data *</label>
        <input type="date" name="event_date" class="form-control" value="<?= substr($ev['event_date'],0,10) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Início *</label>
        <input type="time" name="time_start" class="form-control" value="<?= substr($ev['time_start'],0,5) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Término *</label>
        <input type="time" name="time_end" class="form-control" value="<?= substr($ev['time_end'],0,5) ?>" required>
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Descrição</label>
      <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($ev['description'] ?? '') ?></textarea>
    </div>
  </div>
  <div style="display:flex;gap:10px;align-items:center">
    <button type="submit" class="btn btn-primary">Salvar</button>
    <a href="/pages/events/view.php?id=<?= $id ?>" class="btn btn-secondary">Cancelar</a>
    <a href="/pages/events/cancel.php?id=<?= $id ?>" class="btn btn-secondary" style="margin-left:auto;color:var(--red)" data-confirm="Cancelar este evento?">Cancelar evento</a>
  </div>
</form>
<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
