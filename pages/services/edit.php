<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
$db = db();
$id = (int)($_GET['id'] ?? 0);
$s  = $db->prepare("SELECT * FROM services WHERE id=? AND church_id=?");
$s->execute([$id, current_church_id()]);
$service = $s->fetch();
if (!$service) { header('Location: /pages/services/index.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db->prepare("UPDATE services SET title=?,type=?,service_date=?,time_start=?,time_end=?,preacher_id=?,sermon_title=?,sermon_text=?,notes=? WHERE id=? AND church_id=?")
       ->execute([
           trim($_POST['title']        ?? $service['title']),
           trim($_POST['type']         ?? $service['type']),
           trim($_POST['service_date'] ?? $service['service_date']),
           trim($_POST['time_start']   ?? '') ?: null,
           trim($_POST['time_end']     ?? '') ?: null,
           (int)($_POST['preacher_id'] ?? 0) ?: null,
           trim($_POST['sermon_title'] ?? '') ?: null,
           trim($_POST['sermon_text']  ?? '') ?: null,
           trim($_POST['notes']        ?? '') ?: null,
           $id, current_church_id(),
       ]);
    header('Location: /pages/services/view.php?id=' . $id);
    exit;
}

$members = $db->prepare("SELECT m.id, m.name FROM members m JOIN churches ch ON ch.id=m.church_id WHERE (ch.id=? OR ch.parent_id=?) AND m.status='active' ORDER BY m.name");
$members->execute([SEDE_ID, SEDE_ID]);
$members = $members->fetchAll();

$typeOptions = ['sunday'=>'Culto de Domingo','weekday'=>'Culto de Semana','special'=>'Culto Especial','prayer'=>'Reunião de Oração'];

$pageTitle  = 'Editar · ' . $service['title'];
$activePage = 'services';
require_once __DIR__ . '/../../includes/layout.php';
?>
<form method="POST" style="max-width:700px">
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Dados do culto</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Título *</label>
        <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($service['title']) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Tipo</label>
        <select name="type" class="form-control">
          <?php foreach ($typeOptions as $k=>$v): ?>
            <option value="<?= $k ?>" <?= $service['type']===$k?'selected':''?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Data *</label>
        <input type="date" name="service_date" class="form-control" value="<?= substr($service['service_date'],0,10) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Início</label>
        <input type="time" name="time_start" class="form-control" value="<?= $service['time_start'] ? substr($service['time_start'],0,5) : '' ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Término</label>
        <input type="time" name="time_end" class="form-control" value="<?= $service['time_end'] ? substr($service['time_end'],0,5) : '' ?>">
      </div>
    </div>
  </div>
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Pregação</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Pregador</label>
        <select name="preacher_id" class="form-control">
          <option value="">Selecione</option>
          <?php foreach ($members as $m): ?>
            <option value="<?= $m['id'] ?>" <?= $service['preacher_id']==$m['id']?'selected':''?>><?= htmlspecialchars($m['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Texto bíblico</label>
        <input type="text" name="sermon_text" class="form-control" value="<?= htmlspecialchars($service['sermon_text'] ?? '') ?>">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Título da pregação</label>
      <input type="text" name="sermon_title" class="form-control" value="<?= htmlspecialchars($service['sermon_title'] ?? '') ?>">
    </div>
  </div>
  <div class="card" style="margin-bottom:24px">
    <p class="card-title">Observações</p>
    <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars($service['notes'] ?? '') ?></textarea>
  </div>
  <div style="display:flex;gap:10px">
    <button type="submit" class="btn btn-primary">Salvar</button>
    <a href="/pages/services/view.php?id=<?= $id ?>" class="btn btn-secondary">Cancelar</a>
  </div>
</form>
<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
