<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/bible.php';
require_once __DIR__ . '/../../includes/service_checkin.php';
auth_require_service_editor();
$db = db();
$churchId = current_church_id();
$id = (int)($_GET['id'] ?? 0);
$s  = $db->prepare("SELECT * FROM services WHERE id=? AND church_id=?");
$s->execute([$id, $churchId]);
$service = $s->fetch();
if (!$service) { header('Location: /pages/services/index.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title     = trim($_POST['title'] ?? $service['title']);
    $date      = trim($_POST['service_date'] ?? $service['service_date']);
    $timeStart = trim($_POST['time_start'] ?? '') ?: null;

    // Pregador, tema e referências bíblicas ficam em Programação (supervisor_view.php)
    $db->prepare("UPDATE services SET title=?,type=?,service_date=?,time_start=?,time_end=?,notes=? WHERE id=? AND church_id=?")
       ->execute([
           $title,
           trim($_POST['type']         ?? $service['type']),
           $date,
           $timeStart,
           trim($_POST['time_end']     ?? '') ?: null,
           trim($_POST['notes']        ?? '') ?: null,
           $id, $churchId,
       ]);

    // Título/data podem ter mudado: reagenda a meditação com as referências já salvas
    queue_scripture_meditation($db, $id, $title, $date, $churchId);

    // Convida por WhatsApp quem ainda não foi convidado pro check-in geral
    queue_service_checkins($db, $id, $title, $date, $timeStart, $churchId);

    header('Location: /pages/services/view.php?id=' . $id);
    exit;
}

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

  <div class="card" style="margin-bottom:16px;background:#F5F5F5;border:none">
    <p style="font-size:13px;line-height:1.7">
      🎤 Pregador, tema da pregação e referências bíblicas agora ficam em
      <a href="/pages/services/supervisor_view.php?id=<?= $id ?>">Programação</a>.
    </p>
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
