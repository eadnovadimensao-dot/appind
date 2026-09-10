<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
auth_require('manage_cells');
$db       = db();
$churchId = current_church_id();
$errors   = [];

$members_list = $db->query("SELECT id, name FROM members WHERE church_id = $churchId AND status IN ('active') ORDER BY name")->fetchAll();

$days = ['monday'=>'Segunda-feira','tuesday'=>'Terça-feira','wednesday'=>'Quarta-feira',
         'thursday'=>'Quinta-feira','friday'=>'Sexta-feira','saturday'=>'Sábado','sunday'=>'Domingo'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name         = trim($_POST['name']         ?? '');
    $leaderIds    = $_POST['leader_ids']         ?? [];
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
            INSERT INTO cells (church_id, name, day_of_week, time_start, zip_code, street, number, neighborhood, city, active)
            VALUES (:church_id,:name,:day,:time,:zip,:street,:number,:neighborhood,:city,:active)
        ");
        $stmt->execute([
            ':church_id'    => $churchId,
            ':name'         => $name,
            ':day'          => $day,
            ':time'         => $time,
            ':zip'          => $zip ?: null,
            ':street'       => $street ?: null,
            ':number'       => $number ?: null,
            ':neighborhood' => $neighborhood ?: null,
            ':city'         => $city ?: null,
            ':active'       => $active,
        ]);
        $cellId = $db->lastInsertId();

        // Vincular líderes
        if (!empty($leaderIds)) {
            $sl = $db->prepare("INSERT IGNORE INTO cell_leaders (cell_id, member_id, role) VALUES (?, ?, ?)");
            foreach ($leaderIds as $i => $mid) {
                $sl->execute([$cellId, (int)$mid, $i === 0 ? 'leader' : 'co-leader']);
            }
            // Manter compatibilidade com campo leader_id
            $db->prepare("UPDATE cells SET leader_id = ? WHERE id = ?")->execute([(int)$leaderIds[0], $cellId]);
        }

        header('Location: /pages/cells/index.php?saved=1');
        exit;


    }
}

$pageTitle  = 'Nova célula';
$activePage = 'cells';
require_once __DIR__ . '/../../includes/layout.php';
?>

<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="POST" style="width:100%">

  <!-- Dados principais -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Dados da célula</p>

    <div class="form-group">
      <label class="form-label">Nome da célula *</label>
      <input type="text" name="name" class="form-control"
             placeholder="Ex: Célula Zona Sul, Célula Jovens…"
             value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Dia da semana</label>
        <select name="day_of_week" class="form-control">
          <option value="">Selecione</option>
          <?php foreach ($days as $k => $v): ?>
            <option value="<?= $k ?>" <?= ($_POST['day_of_week']??'')===$k?'selected':''?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Horário</label>
        <input type="time" name="time_start" class="form-control" value="<?= htmlspecialchars($_POST['time_start'] ?? '') ?>">
      </div>
    </div>

    <div class="form-group" style="margin-bottom:0">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
        <input type="checkbox" name="active" value="1" <?= !isset($_POST['name']) || isset($_POST['active']) ? 'checked' : '' ?>>
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
          <label style="display:flex;align-items:center;gap:10px;padding:9px 12px;cursor:pointer;border-bottom:1px solid var(--border);font-size:13px;transition:background .1s"
                 onmouseover="this.style.background='#f9f9f9'" onmouseout="this.style.background=''">
            <input type="checkbox" name="leader_ids[]" value="<?= $m['id'] ?>"
                   <?= in_array($m['id'], $_POST['leader_ids']??[]) ? 'checked' : '' ?>>
            <div class="avatar" style="width:26px;height:26px;font-size:10px;flex-shrink:0">
              <?= strtoupper(substr($m['name'],0,2)) ?>
            </div>
            <?= htmlspecialchars($m['name']) ?>
          </label>
        <?php endforeach; ?>
      </div>
      <span style="font-size:11px;color:var(--text-muted)">O primeiro selecionado será o líder principal</span>
    </div>
  </div>

  <!-- Endereço -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Local de reunião</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">CEP</label>
        <div style="position:relative">
          <input type="text" name="zip_code" id="zip_code" class="form-control"
                 placeholder="00000-000" maxlength="9"
                 value="<?= htmlspecialchars($_POST['zip_code'] ?? '') ?>">
          <span id="cep-loading" style="display:none;position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:11px;color:var(--text-muted)">buscando…</span>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Cidade</label>
        <input type="text" name="city" id="city" class="form-control" value="<?= htmlspecialchars($_POST['city'] ?? '') ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group" style="flex:2">
        <label class="form-label">Rua / Logradouro</label>
        <input type="text" name="street" id="street" class="form-control" value="<?= htmlspecialchars($_POST['street'] ?? '') ?>">
      </div>
      <div class="form-group" style="flex:0 0 100px">
        <label class="form-label">Número</label>
        <input type="text" name="number" id="number" class="form-control" value="<?= htmlspecialchars($_POST['number'] ?? '') ?>">
      </div>
    </div>
    <div class="form-group" style="margin-bottom:0">
      <label class="form-label">Bairro</label>
      <input type="text" name="neighborhood" id="neighborhood" class="form-control" value="<?= htmlspecialchars($_POST['neighborhood'] ?? '') ?>">
    </div>
  </div>

  <div style="display:flex;gap:10px">
    <button type="submit" class="btn btn-primary">Salvar célula</button>
    <a href="/pages/cells/index.php" class="btn btn-secondary">Cancelar</a>
  </div>
</form>

<?php
$extraJs = <<<JS
document.getElementById('zip_code').addEventListener('blur', function() {
  const cep = this.value.replace(/\D/g,'');
  if (cep.length !== 8) return;
  document.getElementById('cep-loading').style.display = 'inline';
  fetch('https://viacep.com.br/ws/' + cep + '/json/')
    .then(r => r.json())
    .then(d => {
      document.getElementById('cep-loading').style.display = 'none';
      if (d.erro) return;
      document.getElementById('street').value       = d.logradouro || '';
      document.getElementById('neighborhood').value = d.bairro     || '';
      document.getElementById('city').value         = d.localidade || '';
      document.getElementById('number').focus();
    })
    .catch(() => { document.getElementById('cep-loading').style.display = 'none'; });
});
document.getElementById('zip_code').addEventListener('input', function() {
  let v = this.value.replace(/\D/g,'').slice(0,8);
  if (v.length > 5) v = v.slice(0,5) + '-' + v.slice(5);
  this.value = v;
});
JS;
require_once __DIR__ . '/../../includes/layout-footer.php';
