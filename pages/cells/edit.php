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

$pageTitle = 'Editar · ' . $cell['name'];
$leaders   = $db->query("SELECT id, name FROM members WHERE church_id = $churchId AND status = 'active' ORDER BY name")->fetchAll();

$days = ['monday'=>'Segunda-feira','tuesday'=>'Terça-feira','wednesday'=>'Quarta-feira',
         'thursday'=>'Quinta-feira','friday'=>'Sexta-feira','saturday'=>'Sábado','sunday'=>'Domingo'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']        ?? '');
    $leaderId = trim($_POST['leader_id']   ?? '') ?: null;
    $day      = trim($_POST['day_of_week'] ?? '') ?: null;
    $time     = trim($_POST['time_start']  ?? '') ?: null;
    $address  = trim($_POST['address']     ?? '');
    $active   = isset($_POST['active']) ? 1 : 0;

    if ($name === '') $errors[] = 'Nome da célula é obrigatório.';

    if (empty($errors)) {
        $stmt = $db->prepare("
            UPDATE cells SET
              name=:name, leader_id=:leader_id, day_of_week=:day,
              time_start=:time, address=:address, active=:active
            WHERE id=:id AND church_id=:church_id
        ");
        $stmt->execute([
            ':name'=>$name,':leader_id'=>$leaderId,':day'=>$day,
            ':time'=>$time,':address'=>$address?:null,':active'=>$active,
            ':id'=>$id,':church_id'=>$churchId,
        ]);
        header('Location: /pages/cells/view.php?id='.$id.'&saved=1');
        exit;
    }
    $cell = array_merge($cell, $_POST);
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

    <div class="form-group">
      <label class="form-label">Líder responsável</label>
      <select name="leader_id" class="form-control">
        <option value="">Selecione um líder</option>
        <?php foreach ($leaders as $l): ?>
          <option value="<?= $l['id'] ?>" <?= $cell['leader_id']==$l['id']?'selected':''?>>
            <?= htmlspecialchars($l['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
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

    <div class="form-group">
      <label class="form-label">Endereço de reunião</label>
      <input type="text" name="address" class="form-control"
             value="<?= htmlspecialchars($cell['address'] ?? '') ?>">
    </div>

    <div class="form-group" style="margin-bottom:0">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
        <input type="checkbox" name="active" value="1" <?= $cell['active']?'checked':''?>>
        Célula ativa
      </label>
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
