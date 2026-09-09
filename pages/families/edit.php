<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require('manage_members');

$db = db();
$id = (int)($_GET['id'] ?? 0);
$f  = $db->prepare("SELECT * FROM families WHERE id=?");
$f->execute([$id]);
$family = $f->fetch();
if (!$family) { header('Location: /pages/families/index.php'); exit; }

$members = $db->prepare("SELECT m.id, m.name FROM members m JOIN churches ch ON ch.id=m.church_id WHERE (ch.id=? OR ch.parent_id=?) AND m.status='active' ORDER BY m.name");
$members->execute([SEDE_ID, SEDE_ID]);
$members = $members->fetchAll();
$errors  = [];

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

    if ($name === '') $errors[] = 'Nome é obrigatório.';

    if (empty($errors)) {
        $db->prepare("UPDATE families SET name=?,father_id=?,mother_id=?,address=?,neighborhood=?,city=?,zip_code=?,phone=?,notes=? WHERE id=?")
           ->execute([$name,$fatherId,$motherId,$address?:null,$neighborhood?:null,$city?:null,$zip?:null,$phone?:null,$notes?:null,$id]);
        if ($fatherId) $db->prepare("UPDATE members SET family_id=? WHERE id=?")->execute([$id,$fatherId]);
        if ($motherId) $db->prepare("UPDATE members SET family_id=? WHERE id=?")->execute([$id,$motherId]);
        header('Location: /pages/families/view.php?id='.$id.'&saved=1');
        exit;
    }
}

$pageTitle  = 'Editar · ' . $family['name'];
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
      <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($family['name']) ?>" required>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">👨 Pai / responsável</label>
        <select name="father_id" class="form-control">
          <option value="">Selecione</option>
          <?php foreach ($members as $m): ?>
            <option value="<?= $m['id'] ?>" <?= $family['father_id']==$m['id']?'selected':''?>><?= htmlspecialchars($m['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">👩 Mãe / responsável</label>
        <select name="mother_id" class="form-control">
          <option value="">Selecione</option>
          <?php foreach ($members as $m): ?>
            <option value="<?= $m['id'] ?>" <?= $family['mother_id']==$m['id']?'selected':''?>><?= htmlspecialchars($m['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-group" style="margin-bottom:0">
      <label class="form-label">Telefone de contato</label>
      <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($family['phone']??'') ?>">
    </div>
  </div>
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Endereço</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">CEP</label>
        <input type="text" name="zip_code" id="zip_code" class="form-control" maxlength="9" value="<?= htmlspecialchars($family['zip_code']??'') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Cidade</label>
        <input type="text" name="city" id="city" class="form-control" value="<?= htmlspecialchars($family['city']??'') ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Endereço</label>
        <input type="text" name="address" id="address" class="form-control" value="<?= htmlspecialchars($family['address']??'') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Bairro</label>
        <input type="text" name="neighborhood" id="neighborhood" class="form-control" value="<?= htmlspecialchars($family['neighborhood']??'') ?>">
      </div>
    </div>
  </div>
  <div class="card" style="margin-bottom:24px">
    <p class="card-title">Observações pastorais</p>
    <textarea name="notes" class="form-control" rows="4"><?= htmlspecialchars($family['notes']??'') ?></textarea>
  </div>
  <div style="display:flex;gap:10px">
    <button type="submit" class="btn btn-primary">Salvar</button>
    <a href="/pages/families/view.php?id=<?= $id ?>" class="btn btn-secondary">Cancelar</a>
  </div>
</form>
<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
