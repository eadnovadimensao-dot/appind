<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ministry_activity.php';
auth_check();

$db       = db();
$churchId = current_church_id();
$id       = (int)($_GET['id'] ?? 0);
$errors   = [];

$stmt = $db->prepare("
    SELECT ma.*, mn.name AS ministry_name
    FROM ministry_activities ma
    JOIN ministries mn ON mn.id = ma.ministry_id
    WHERE ma.id = ? AND ma.church_id = ?
");
$stmt->execute([$id, $churchId]);
$act = $stmt->fetch();
if (!$act) { header('Location: /pages/ministries/index.php'); exit; }

$mn = ['name' => $act['ministry_name']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title        = trim($_POST['title']         ?? '');
    $description  = trim($_POST['description']   ?? '');
    $date         = trim($_POST['activity_date'] ?? '');
    $timeStart    = trim($_POST['time_start']    ?? '') ?: null;
    $timeEnd      = trim($_POST['time_end']      ?? '') ?: null;
    $location     = trim($_POST['location']      ?? '');
    $activityType = ($_POST['activity_type'] ?? 'culto') === 'ensaio' ? 'ensaio' : 'culto';

    if ($title === '') $errors[] = 'Título é obrigatório.';
    if ($date  === '') $errors[] = 'Data é obrigatória.';

    if (empty($errors)) {
        $dateChanged = $date !== $act['activity_date'];

        $db->prepare("
            UPDATE ministry_activities
            SET title=:title, description=:description, activity_type=:activity_type,
                activity_date=:date, time_start=:time_start, time_end=:time_end, location=:location
            WHERE id=:id AND church_id=:church_id
        ")->execute([
            ':title'         => $title,
            ':description'   => $description ?: null,
            ':activity_type' => $activityType,
            ':date'          => $date,
            ':time_start'    => $timeStart,
            ':time_end'      => $timeEnd,
            ':location'      => $location ?: null,
            ':id'            => $id,
            ':church_id'     => $churchId,
        ]);

        // Sincronizar com a Agenda (mesmo registro criado junto com a atividade)
        $locId = null;
        if ($location) {
            $locStmt = $db->prepare("SELECT id FROM locations WHERE church_id = ? AND name LIKE ? LIMIT 1");
            $locStmt->execute([$churchId, '%' . $location . '%']);
            $locRow = $locStmt->fetch();
            $locId  = $locRow ? $locRow['id'] : null;
        }
        $agendaStmt = $db->prepare("SELECT id FROM agenda_events WHERE ministry_activity_id = ?");
        $agendaStmt->execute([$id]);
        $agendaId = $agendaStmt->fetchColumn();

        if ($timeEnd) {
            if ($agendaId) {
                $db->prepare("
                    UPDATE agenda_events
                    SET title=?, description=?, location_id=?, event_date=?, time_start=?, time_end=?
                    WHERE id=?
                ")->execute([$title, $description ?: null, $locId, $date, $timeStart, $timeEnd, $agendaId]);
            } else {
                $db->prepare("
                    INSERT INTO agenda_events
                      (church_id, title, description, location_id, event_date, time_start, time_end,
                       ministry_id, ministry_activity_id, status, type, color)
                    VALUES (?,?,?,?,?,?,?,?,?,'approved','ministry_activity','#185FA5')
                ")->execute([$churchId, $title, $description ?: null, $locId, $date, $timeStart, $timeEnd, $act['ministry_id'], $id]);
            }
        } elseif ($agendaId) {
            // Sem horário de término: não faz sentido na agenda
            $db->prepare("DELETE FROM agenda_events WHERE id = ?")->execute([$agendaId]);
        }

        // Data mudou numa atividade ainda agendada: reabre confirmação e avisa os escalados
        if ($dateChanged && $act['status'] === 'scheduled') {
            notify_activity_rescheduled($db, $mn, $id, $title, $date, $timeStart, $location, $churchId, auth_member_id());
        }

        header('Location: /pages/ministries/activity_view.php?id=' . $id . '&saved=1');
        exit;
    }
}

$pageTitle  = 'Editar · ' . $act['title'];
$activePage = 'ministries';
require_once __DIR__ . '/../../includes/layout.php';
?>

<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<div style="margin-bottom:16px">
  <a href="/pages/ministries/activity_view.php?id=<?= $id ?>" style="font-size:13px;color:var(--text-muted);text-decoration:none">
    ← <?= htmlspecialchars($act['title']) ?>
  </a>
</div>

<form method="POST" style="width:100%">
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Editar atividade</p>
    <div style="background:#FFF7E6;border:1px solid #F0D595;border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:12px;color:#8A5A00">
      ⚠️ Se você mudar a data, os membros já escalados voltam a "pendente" e recebem um aviso pra confirmar de novo pra nova data.
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Título *</label>
        <input type="text" name="title" class="form-control"
               value="<?= htmlspecialchars($_POST['title'] ?? $act['title']) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Tipo</label>
        <select name="activity_type" class="form-control">
          <?php $selType = $_POST['activity_type'] ?? $act['activity_type']; ?>
          <option value="culto"  <?= $selType==='culto'  ? 'selected' : '' ?>>Culto</option>
          <option value="ensaio" <?= $selType==='ensaio' ? 'selected' : '' ?>>Ensaio</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Local</label>
        <input type="text" name="location" class="form-control"
               value="<?= htmlspecialchars($_POST['location'] ?? $act['location'] ?? '') ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Data *</label>
        <input type="date" name="activity_date" class="form-control"
               value="<?= htmlspecialchars($_POST['activity_date'] ?? $act['activity_date']) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Início</label>
        <input type="time" name="time_start" class="form-control"
               value="<?= htmlspecialchars($_POST['time_start'] ?? ($act['time_start'] ? substr($act['time_start'],0,5) : '')) ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Término</label>
        <input type="time" name="time_end" class="form-control"
               value="<?= htmlspecialchars($_POST['time_end'] ?? ($act['time_end'] ? substr($act['time_end'],0,5) : '')) ?>">
      </div>
    </div>
    <div class="form-group" style="margin-bottom:0">
      <label class="form-label">Descrição / Observações</label>
      <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($_POST['description'] ?? $act['description'] ?? '') ?></textarea>
    </div>
  </div>

  <div style="display:flex;gap:10px">
    <button type="submit" class="btn btn-primary">Salvar alterações</button>
    <a href="/pages/ministries/activity_view.php?id=<?= $id ?>" class="btn btn-secondary">Cancelar</a>
  </div>
</form>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
