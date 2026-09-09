<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

// Apenas membros de ministérios podem solicitar evento
$memberId = auth_member_id();
if (auth_role() === 'member' && $memberId) {
    $count = db()->query("SELECT COUNT(*) FROM member_ministries WHERE member_id=$memberId")->fetchColumn();
    if (!$count) {
        header('Location: /dashboard.php?no_access=1');
        exit;
    }
}

$db       = db();
$churchId = current_church_id();
$errors   = [];

$locations  = $db->query("SELECT id, name, capacity FROM locations WHERE church_id = $churchId AND active = 1 ORDER BY name")->fetchAll();
$ministries = $db->prepare("SELECT id, name FROM ministries WHERE church_id = ? AND active = 1 ORDER BY name");
$ministries->execute([$churchId]);
$ministries = $ministries->fetchAll();

$colors = [
    '#1D9E75' => 'Verde (padrão)',
    '#185FA5' => 'Azul',
    '#854F0B' => 'Laranja',
    '#A32D2D' => 'Vermelho',
    '#5F5E5A' => 'Cinza',
    '#6B21A8' => 'Roxo',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = trim($_POST['title']       ?? '');
    $description = trim($_POST['description'] ?? '');
    $locationId  = trim($_POST['location_id'] ?? '') ?: null;
    $date        = trim($_POST['event_date']  ?? '');
    $timeStart   = trim($_POST['time_start']  ?? '');
    $timeEnd     = trim($_POST['time_end']    ?? '');
    $ministryId  = trim($_POST['ministry_id'] ?? '') ?: null;
    $color       = trim($_POST['color']       ?? '#1D9E75');
    $recurrence  = trim($_POST['recurrence']  ?? 'none');

    if ($title     === '') $errors[] = 'Título é obrigatório.';
    if ($date      === '') $errors[] = 'Data é obrigatória.';
    if ($timeStart === '') $errors[] = 'Horário de início é obrigatório.';
    if ($timeEnd   === '') $errors[] = 'Horário de término é obrigatório.';
    if ($timeStart && $timeEnd && $timeStart >= $timeEnd) $errors[] = 'Horário de término deve ser após o início.';

    // Verificar conflito de local
    if (empty($errors) && $locationId) {
        $conflict = $db->prepare("
            SELECT id, title, time_start, time_end FROM agenda_events
            WHERE church_id = ?
              AND location_id = ?
              AND event_date = ?
              AND status IN ('approved','pending')
              AND time_start < ? AND time_end > ?
        ");
        $conflict->execute([$churchId, $locationId, $date, $timeEnd, $timeStart]);
        $conflictEvent = $conflict->fetch();
        if ($conflictEvent) {
            $errors[] = 'Conflito de horário! "'
                . htmlspecialchars($conflictEvent['title']) . '" já está reservado neste local das '
                . substr($conflictEvent['time_start'],0,5) . ' às ' . substr($conflictEvent['time_end'],0,5) . '.';
        }
    }

    if (empty($errors)) {
        $stmt = $db->prepare("
            INSERT INTO agenda_events
              (church_id, title, description, location_id, event_date, time_start, time_end,
               ministry_id, color, recurrence, status, type, requested_by, approved_by, approved_at)
            VALUES
              (:church_id,:title,:description,:location_id,:date,:time_start,:time_end,
               :ministry_id,:color,:recurrence,:status,'event',:requested_by,:approved_by,:approved_at)
        ");
        $autoApprove = auth_event_auto_approve();
        $userId      = auth_user()['id'] ?? null;
        $memberId    = auth_member_id();

        $stmt->execute([
            ':church_id'   => $churchId,
            ':title'       => $title,
            ':description' => $description ?: null,
            ':location_id' => $locationId,
            ':date'        => $date,
            ':time_start'  => $timeStart,
            ':time_end'    => $timeEnd,
            ':ministry_id' => $ministryId,
            ':color'       => $color,
            ':recurrence'  => $recurrence,
            ':status'      => $autoApprove ? 'approved' : 'pending',
            ':requested_by'=> $memberId,
            ':approved_by' => $autoApprove ? $memberId : null,
            ':approved_at' => $autoApprove ? date('Y-m-d H:i:s') : null,
        ]);
        header('Location: /pages/events/index.php?requested=1');
        exit;
    }
}

$pageTitle  = 'Solicitar evento';
$activePage = 'events';
require_once __DIR__ . '/../../includes/layout.php';
?>

<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= $e ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="POST" style="width:100%">

  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Dados do evento</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Título *</label>
        <input type="text" name="title" class="form-control"
               placeholder="Ex: Culto de domingo, Ensaio, Reunião de líderes…"
               value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Ministério responsável</label>
        <select name="ministry_id" class="form-control">
          <option value="">Nenhum (evento geral)</option>
          <?php foreach ($ministries as $mn): ?>
            <option value="<?= $mn['id'] ?>" <?= ($_POST['ministry_id']??'')==$mn['id']?'selected':''?>>
              <?= htmlspecialchars($mn['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Descrição</label>
      <textarea name="description" class="form-control" rows="2"
                placeholder="Detalhes do evento, quem pode participar…"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Cor no calendário</label>
        <select name="color" class="form-control" id="color-select">
          <?php foreach ($colors as $hex => $label): ?>
            <option value="<?= $hex ?>" <?= ($_POST['color']??'#1D9E75')===$hex?'selected':''?>>
              <?= $label ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Recorrência</label>
        <select name="recurrence" class="form-control">
          <option value="none"    <?= ($_POST['recurrence']??'none')==='none'   ?'selected':''?>>Sem recorrência</option>
          <option value="weekly"  <?= ($_POST['recurrence']??'')==='weekly'  ?'selected':''?>>Semanal</option>
          <option value="monthly" <?= ($_POST['recurrence']??'')==='monthly' ?'selected':''?>>Mensal</option>
        </select>
      </div>
    </div>
  </div>

  <!-- Local e horário -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Local e horário</p>
    <div class="form-group">
      <label class="form-label">Local *</label>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px" id="location-grid">
        <?php foreach ($locations as $loc): ?>
          <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1.5px solid var(--border);border-radius:8px;cursor:pointer;font-size:13px;transition:border-color .15s"
                 id="loc-label-<?= $loc['id'] ?>">
            <input type="radio" name="location_id" value="<?= $loc['id'] ?>"
                   onchange="selectLocation(<?= $loc['id'] ?>)"
                   <?= ($_POST['location_id']??'')==$loc['id']?'checked':''?>>
            <div>
              <div style="font-weight:500"><?= htmlspecialchars($loc['name']) ?></div>
              <?php if ($loc['capacity']): ?>
                <div style="font-size:11px;color:var(--text-muted)">até <?= $loc['capacity'] ?> pessoas</div>
              <?php endif; ?>
            </div>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Data *</label>
        <input type="date" name="event_date" class="form-control"
               value="<?= htmlspecialchars($_POST['event_date'] ?? date('Y-m-d')) ?>"
               onchange="checkConflict()" required>
      </div>
      <div class="form-group">
        <label class="form-label">Início *</label>
        <input type="time" name="time_start" id="time_start" class="form-control"
               value="<?= htmlspecialchars($_POST['time_start'] ?? '') ?>"
               onchange="checkConflict()" required>
      </div>
      <div class="form-group">
        <label class="form-label">Término *</label>
        <input type="time" name="time_end" id="time_end" class="form-control"
               value="<?= htmlspecialchars($_POST['time_end'] ?? '') ?>"
               onchange="checkConflict()" required>
      </div>
    </div>
    <!-- Aviso de conflito em tempo real -->
    <div id="conflict-warning" style="display:none;background:#FCEBEB;border:1px solid #F09595;border-radius:7px;padding:10px 14px;font-size:13px;color:#A32D2D;margin-top:4px"></div>
  </div>

  <?php if (auth_event_auto_approve()): ?>
    <div style="background:#E1F5EE;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#0F6E56">
      ✅ Como administrador, seu evento será aprovado automaticamente.
    </div>
  <?php else: ?>
    <div style="background:#E1F5EE;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#0F6E56">
      ℹ️ Sua solicitação será enviada para aprovação. Você será notificado quando for aprovada ou recusada.
    </div>
  <?php endif; ?>

  <div style="display:flex;gap:10px">
    <button type="submit" class="btn btn-primary">Enviar solicitação</button>
    <a href="/pages/events/index.php" class="btn btn-secondary">Cancelar</a>
  </div>
</form>

<?php
$extraJs = <<<JS
// Destacar local selecionado
function selectLocation(id) {
  document.querySelectorAll('[id^="loc-label-"]').forEach(el => {
    el.style.borderColor = 'var(--border)';
    el.style.background  = '';
  });
  const label = document.getElementById('loc-label-' + id);
  if (label) {
    label.style.borderColor = 'var(--accent)';
    label.style.background  = 'var(--accent-lt)';
  }
  checkConflict();
}

// Verificar conflito via AJAX
function checkConflict() {
  const locationId = document.querySelector('[name=location_id]:checked')?.value;
  const date       = document.querySelector('[name=event_date]').value;
  const timeStart  = document.getElementById('time_start').value;
  const timeEnd    = document.getElementById('time_end').value;
  const warning    = document.getElementById('conflict-warning');

  if (!locationId || !date || !timeStart || !timeEnd) return;

  fetch('/pages/events/check_conflict.php?location_id='+locationId+'&date='+date+'&start='+timeStart+'&end='+timeEnd)
    .then(r => r.json())
    .then(d => {
      if (d.conflict) {
        warning.style.display = 'block';
        warning.innerHTML = '⚠️ Conflito: <strong>' + d.title + '</strong> já está reservado das ' + d.start + ' às ' + d.end;
      } else {
        warning.style.display = 'none';
      }
    });
}

// Inicializar local selecionado
const checked = document.querySelector('[name=location_id]:checked');
if (checked) selectLocation(checked.value);
JS;
require_once __DIR__ . '/../../includes/layout-footer.php';
?>
