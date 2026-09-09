<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require('manage_members');

$db       = db();
$churchId = current_church_id();
$errors   = [];

$members = $db->prepare("
    SELECT m.id, m.name FROM members m
    JOIN churches ch ON ch.id = m.church_id
    WHERE (ch.id = ? OR ch.parent_id = ?) AND m.status = 'active'
    ORDER BY m.name
");
$members->execute([SEDE_ID, SEDE_ID]);
$members = $members->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name         = trim($_POST['name']         ?? '');
    $fatherId     = (int)($_POST['father_id']   ?? 0) ?: null;
    $motherId     = (int)($_POST['mother_id']   ?? 0) ?: null;
    $address      = trim($_POST['address']      ?? '');
    $neighborhood = trim($_POST['neighborhood'] ?? '');
    $city         = trim($_POST['city']         ?? '');
    $zip          = trim($_POST['zip_code']     ?? '');
    $phone        = trim($_POST['phone']        ?? '');
    $notes        = trim($_POST['notes']        ?? '');

    if ($name === '') $errors[] = 'Nome da família é obrigatório.';

    if (empty($errors)) {
        $db->prepare("
            INSERT INTO families
              (church_id, name, father_id, mother_id, address, neighborhood,
               city, zip_code, phone, notes)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ")->execute([$churchId,$name,$fatherId,$motherId,
                     $address?:null,$neighborhood?:null,$city?:null,
                     $zip?:null,$phone?:null,$notes?:null]);
        $familyId = $db->lastInsertId();

        // Vincular o pai e mãe à família automaticamente
        if ($fatherId) $db->prepare("UPDATE members SET family_id=? WHERE id=?")->execute([$familyId,$fatherId]);
        if ($motherId) $db->prepare("UPDATE members SET family_id=? WHERE id=?")->execute([$familyId,$motherId]);

        header('Location: /pages/families/view.php?id=' . $familyId . '&saved=1');
        exit;
    }
}

$pageTitle  = 'Nova família';
$activePage = 'families';
require_once __DIR__ . '/../../includes/layout.php';
?>

<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="POST" style="max-width:720px">

  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Identificação</p>
    <div class="form-group">
      <label class="form-label">Nome da família *</label>
      <input type="text" name="name" class="form-control"
             placeholder="Ex: Família Silva, Família Oliveira"
             value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">👨 Pai / responsável</label>
        <select name="father_id" class="form-control">
          <option value="">Selecione</option>
          <?php foreach ($members as $m): ?>
            <option value="<?= $m['id'] ?>" <?= ($_POST['father_id']??'')==$m['id']?'selected':''?>>
              <?= htmlspecialchars($m['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">👩 Mãe / responsável</label>
        <select name="mother_id" class="form-control">
          <option value="">Selecione</option>
          <?php foreach ($members as $m): ?>
            <option value="<?= $m['id'] ?>" <?= ($_POST['mother_id']??'')==$m['id']?'selected':''?>>
              <?= htmlspecialchars($m['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-group" style="margin-bottom:0">
      <label class="form-label">Telefone de contato</label>
      <input type="text" name="phone" class="form-control"
             placeholder="(11) 99999-9999"
             value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
    </div>
  </div>

  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Endereço</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">CEP</label>
        <input type="text" name="zip_code" id="zip_code" class="form-control"
               placeholder="00000-000" maxlength="9"
               value="<?= htmlspecialchars($_POST['zip_code'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Cidade</label>
        <input type="text" name="city" id="city" class="form-control"
               value="<?= htmlspecialchars($_POST['city'] ?? '') ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Endereço</label>
        <input type="text" name="address" id="address" class="form-control"
               placeholder="Rua, número"
               value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Bairro</label>
        <input type="text" name="neighborhood" id="neighborhood" class="form-control"
               value="<?= htmlspecialchars($_POST['neighborhood'] ?? '') ?>">
      </div>
    </div>
  </div>

  <div class="card" style="margin-bottom:24px">
    <p class="card-title">Observações pastorais</p>
    <textarea name="notes" class="form-control" rows="4"
              placeholder="Anotações pastorais, situação familiar, pedidos de oração…"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
  </div>

  <div style="display:flex;gap:10px">
    <button type="submit" class="btn btn-primary">Salvar família</button>
    <a href="/pages/families/index.php" class="btn btn-secondary">Cancelar</a>
  </div>
</form>

<?php
$extraJs = <<<JS
document.getElementById('zip_code').addEventListener('blur', function() {
  const cep = this.value.replace(/\D/g,'');
  if (cep.length !== 8) return;
  fetch('https://viacep.com.br/ws/' + cep + '/json/')
    .then(r => r.json()).then(d => {
      if (d.erro) return;
      document.getElementById('address').value      = d.logradouro || '';
      document.getElementById('neighborhood').value = d.bairro     || '';
      document.getElementById('city').value         = d.localidade || '';
    });
});
document.getElementById('zip_code').addEventListener('input', function() {
  let v = this.value.replace(/\D/g,'').slice(0,8);
  if (v.length > 5) v = v.slice(0,5) + '-' + v.slice(5);
  this.value = v;
});
JS;
require_once __DIR__ . '/../../includes/layout-footer.php';
?>
