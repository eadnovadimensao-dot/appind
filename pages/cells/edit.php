<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
auth_check();
$db       = db();
$churchId = current_church_id();
$id       = (int)($_GET['id'] ?? 0);
$errors   = [];

$stmt = $db->prepare("SELECT * FROM cells WHERE id = ? AND church_id = ?");
$stmt->execute([$id, $churchId]);
$cell = $stmt->fetch();
if (!$cell) { header('Location: /pages/cells/index.php'); exit; }
auth_require_cell($id);

$pageTitle    = 'Editar · ' . $cell['name'];
$canSetSupervisor = auth_can('manage_cells');
$members_list = $db->query("SELECT id, name FROM members WHERE church_id = $churchId AND status = 'active' ORDER BY name")->fetchAll();

$curLeaders = $db->prepare("SELECT member_id FROM cell_leaders WHERE cell_id = ? ORDER BY (role='leader') DESC");
$curLeaders->execute([$id]);
$curLeaderIds = $curLeaders->fetchAll(PDO::FETCH_COLUMN);

$curSupervisors = $db->prepare("SELECT member_id FROM cell_supervisors WHERE cell_id = ?");
$curSupervisors->execute([$id]);
$curSupervisorIds = $curSupervisors->fetchAll(PDO::FETCH_COLUMN);

$curHosts = $db->prepare("SELECT member_id FROM cell_hosts WHERE cell_id = ?");
$curHosts->execute([$id]);
$curHostIds = $curHosts->fetchAll(PDO::FETCH_COLUMN);

$days = ['monday'=>'Segunda-feira','tuesday'=>'Terça-feira','wednesday'=>'Quarta-feira',
         'thursday'=>'Quinta-feira','friday'=>'Sexta-feira','saturday'=>'Sábado','sunday'=>'Domingo'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name         = trim($_POST['name']         ?? '');
    $leaderIds    = $_POST['leader_ids']         ?? [];
    $hostIds      = $_POST['host_ids']           ?? [];
    $day          = trim($_POST['day_of_week']   ?? '') ?: null;
    $time         = trim($_POST['time_start']    ?? '') ?: null;
    $zip          = trim($_POST['zip_code']      ?? '');
    $street       = trim($_POST['street']        ?? '');
    $number       = trim($_POST['number']        ?? '');
    $neighborhood = trim($_POST['neighborhood']  ?? '');
    $city         = trim($_POST['city']          ?? '');
    $active       = isset($_POST['active']) ? 1 : 0;

    if ($name === '') $errors[] = 'Nome da célula é obrigatório.';

    if (empty($errors)) {
        $stmt = $db->prepare("
            UPDATE cells SET
              name=:name, day_of_week=:day, time_start=:time,
              zip_code=:zip, street=:street, number=:number, neighborhood=:neighborhood, city=:city,
              active=:active, leader_id=:leader_id
            WHERE id=:id AND church_id=:church_id
        ");
        $stmt->execute([
            ':name'=>$name, ':day'=>$day, ':time'=>$time,
            ':zip'=>$zip?:null, ':street'=>$street?:null, ':number'=>$number?:null,
            ':neighborhood'=>$neighborhood?:null, ':city'=>$city?:null,
            ':active'=>$active, ':leader_id'=>$leaderIds ? (int)$leaderIds[0] : null,
            ':id'=>$id, ':church_id'=>$churchId,
        ]);

        // Ressincroniza a liderança (cell_leaders é a fonte usada no resto do sistema)
        $db->prepare("DELETE FROM cell_leaders WHERE cell_id = ?")->execute([$id]);
        if (!empty($leaderIds)) {
            $sl = $db->prepare("INSERT IGNORE INTO cell_leaders (cell_id, member_id, role) VALUES (?, ?, ?)");
            foreach ($leaderIds as $i => $mid) $sl->execute([$id, (int)$mid, $i === 0 ? 'leader' : 'co-leader']);
        }

        // Anfitriões: quem edita a célula pode escolher
        $db->prepare("DELETE FROM cell_hosts WHERE cell_id = ?")->execute([$id]);
        if (!empty($hostIds)) {
            $hi = $db->prepare("INSERT IGNORE INTO cell_hosts (cell_id, member_id) VALUES (?, ?)");
            foreach ($hostIds as $mid) $hi->execute([$id, (int)$mid]);
        }

        // Supervisores: só quem gerencia células (admin/supermaster) altera
        if ($canSetSupervisor) {
            $supervisorIds = $_POST['supervisor_ids'] ?? [];
            $db->prepare("DELETE FROM cell_supervisors WHERE cell_id = ?")->execute([$id]);
            if (!empty($supervisorIds)) {
                $si = $db->prepare("INSERT IGNORE INTO cell_supervisors (cell_id, member_id) VALUES (?, ?)");
                foreach ($supervisorIds as $mid) $si->execute([$id, (int)$mid]);
            }
        }

        header('Location: /pages/cells/view.php?id='.$id.'&saved=1');
        exit;
    }
    $cell = array_merge($cell, $_POST);
    $curLeaderIds     = $leaderIds;
    $curHostIds       = $hostIds;
    if ($canSetSupervisor) $curSupervisorIds = $_POST['supervisor_ids'] ?? [];
}

$activePage = 'cells';
require_once __DIR__ . '/../../includes/layout.php';
?>

<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="POST" style="width:100%">
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Dados da célula</p>

    <div class="form-group">
      <label class="form-label">Nome da célula *</label>
      <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($cell['name']) ?>" required>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Dia da semana</label>
        <select name="day_of_week" class="form-control">
          <option value="">Selecione</option>
          <?php foreach ($days as $k => $v): ?>
            <option value="<?= $k ?>" <?= ($cell['day_of_week']??'')===$k?'selected':''?>>
              <?= $v ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Horário</label>
        <input type="time" name="time_start" class="form-control"
               value="<?= $cell['time_start'] ? substr($cell['time_start'],0,5) : '' ?>">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">CEP</label>
        <input type="text" name="zip_code" class="form-control" maxlength="9" value="<?= htmlspecialchars($cell['zip_code'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Cidade</label>
        <input type="text" name="city" class="form-control" value="<?= htmlspecialchars($cell['city'] ?? '') ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group" style="flex:2">
        <label class="form-label">Rua / Logradouro</label>
        <input type="text" name="street" class="form-control" value="<?= htmlspecialchars($cell['street'] ?? '') ?>">
      </div>
      <div class="form-group" style="flex:0 0 100px">
        <label class="form-label">Número</label>
        <input type="text" name="number" class="form-control" value="<?= htmlspecialchars($cell['number'] ?? '') ?>">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Bairro</label>
      <input type="text" name="neighborhood" class="form-control" value="<?= htmlspecialchars($cell['neighborhood'] ?? '') ?>">
    </div>

    <div class="form-group" style="margin-bottom:0">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
        <input type="checkbox" name="active" value="1" <?= $cell['active']?'checked':''?>>
        Célula ativa
      </label>
    </div>
  </div>

  <!-- Líderes -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Liderança</p>
    <div class="form-group" style="margin-bottom:4px">
      <label class="form-label">Líderes <span style="font-weight:400;color:var(--text-muted)">(selecione um ou mais — ex: casal)</span></label>
      <div style="border:1px solid var(--border);border-radius:7px;overflow:hidden;max-height:200px;overflow-y:auto">
        <?php foreach ($members_list as $m): ?>
          <label style="display:flex;align-items:center;gap:10px;padding:9px 12px;cursor:pointer;border-bottom:1px solid var(--border);font-size:13px">
            <input type="checkbox" name="leader_ids[]" value="<?= $m['id'] ?>" <?= in_array($m['id'], $curLeaderIds) ? 'checked' : '' ?>>
            <div class="avatar" style="width:26px;height:26px;font-size:10px;flex-shrink:0"><?= strtoupper(substr($m['name'],0,2)) ?></div>
            <?= htmlspecialchars($m['name']) ?>
          </label>
        <?php endforeach; ?>
      </div>
      <span style="font-size:11px;color:var(--text-muted)">O primeiro selecionado será o líder principal</span>
    </div>

    <div class="form-group" style="margin-bottom:0">
      <label class="form-label">Supervisores <span style="font-weight:400;color:var(--text-muted)">(quem acompanha essa célula — pode ser mais de um, ex: casal)</span></label>
      <?php if ($canSetSupervisor): ?>
        <div style="border:1px solid var(--border);border-radius:7px;overflow:hidden;max-height:200px;overflow-y:auto">
          <?php foreach ($members_list as $m): ?>
            <label style="display:flex;align-items:center;gap:10px;padding:9px 12px;cursor:pointer;border-bottom:1px solid var(--border);font-size:13px">
              <input type="checkbox" name="supervisor_ids[]" value="<?= $m['id'] ?>" <?= in_array($m['id'], $curSupervisorIds) ? 'checked' : '' ?>>
              <div class="avatar" style="width:26px;height:26px;font-size:10px;flex-shrink:0"><?= strtoupper(substr($m['name'],0,2)) ?></div>
              <?= htmlspecialchars($m['name']) ?>
            </label>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <?php
          $supNames = [];
          if ($curSupervisorIds) {
              foreach ($members_list as $m) if (in_array($m['id'], $curSupervisorIds)) $supNames[] = $m['name'];
          }
        ?>
        <div style="font-size:13px;color:var(--text-muted)"><?= $supNames ? htmlspecialchars(implode(', ', $supNames)) : 'Sem supervisor definido' ?> <span style="font-size:11px">(somente admin/supermaster altera)</span></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Anfitriões -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Anfitriões</p>
    <div class="form-group" style="margin-bottom:0">
      <label class="form-label">Quem recebe a célula <span style="font-weight:400;color:var(--text-muted)">(dono(a) da casa — pode ser mais de um, ex: casal)</span></label>
      <div style="border:1px solid var(--border);border-radius:7px;overflow:hidden;max-height:200px;overflow-y:auto">
        <?php foreach ($members_list as $m): ?>
          <label style="display:flex;align-items:center;gap:10px;padding:9px 12px;cursor:pointer;border-bottom:1px solid var(--border);font-size:13px">
            <input type="checkbox" name="host_ids[]" value="<?= $m['id'] ?>" <?= in_array($m['id'], $curHostIds) ? 'checked' : '' ?>>
            <div class="avatar" style="width:26px;height:26px;font-size:10px;flex-shrink:0"><?= strtoupper(substr($m['name'],0,2)) ?></div>
            <?= htmlspecialchars($m['name']) ?>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div style="display:flex;gap:10px;align-items:center">
    <button type="submit" class="btn btn-primary">Salvar alterações</button>
    <a href="/pages/cells/view.php?id=<?= $id ?>" class="btn btn-secondary">Cancelar</a>
    <a href="/pages/cells/delete.php?id=<?= $id ?>"
       class="btn btn-secondary"
       style="margin-left:auto;color:var(--red)"
       data-confirm="Tem certeza? Os membros desta célula ficarão sem célula.">
      Excluir célula
    </a>
  </div>
</form>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
