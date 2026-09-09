<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db = db();
$id = (int)($_GET['id'] ?? 0);

// Busca membro em qualquer filial da sede
$stmt = $db->prepare("
    SELECT m.*, ch.name AS branch_name, ch.type AS branch_type
    FROM members m
    JOIN churches ch ON ch.id = m.church_id
    WHERE m.id = ? AND (ch.id = ? OR ch.parent_id = ?)
");
$stmt->execute([$id, SEDE_ID, SEDE_ID]);
$m = $stmt->fetch();
if (!$m) { header('Location: /pages/members/index.php'); exit; }

$pageTitle  = 'Editar · ' . $m['name'];
$activePage = 'members';
$errors     = [];
$churchId   = $m['church_id'];

$cells = $db->query("SELECT id, name FROM cells WHERE church_id = $churchId AND active = 1 ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name           = trim($_POST['name']            ?? '');
    $phone          = trim($_POST['phone']           ?? '');
    $email          = trim($_POST['email']           ?? '');
    $cpf            = trim($_POST['cpf']             ?? '');
    $birthDate      = trim($_POST['birth_date']      ?? '');
    $gender         = trim($_POST['gender']          ?? '');
    $marital        = trim($_POST['marital_status']  ?? '');
    $status         = trim($_POST['status']          ?? 'active');
    $cellId         = trim($_POST['cell_id']         ?? '') ?: null;
    $newChurchId    = (int)($_POST['church_id']      ?? $churchId);
    $joinDate       = trim($_POST['join_date']       ?? '') ?: null;
    $baptism        = trim($_POST['baptism_date']    ?? '') ?: null;
    $conversion     = trim($_POST['conversion_date'] ?? '') ?: null;
    $origin         = trim($_POST['origin_church']   ?? '');
    $address        = trim($_POST['address']         ?? '');
    $neighborhood   = trim($_POST['neighborhood']    ?? '');
    $city           = trim($_POST['city']            ?? '');
    $zip            = trim($_POST['zip_code']        ?? '');
    $notes          = trim($_POST['notes']           ?? '');
    $transferReason = trim($_POST['transfer_reason'] ?? '');

    if ($name === '') $errors[] = 'Nome é obrigatório.';
    if ($phone === '') $errors[] = 'Telefone é obrigatório.';

    if (empty($errors)) {
        // Upload de foto
        $photoUrl = $m['photo_url'];
        if (!empty($_FILES['photo']['tmp_name'])) {
            $file    = $_FILES['photo'];
            $allowed = ['image/jpeg','image/png','image/webp'];
            if (in_array($file['type'], $allowed) && $file['size'] <= 3*1024*1024) {
                $ext  = pathinfo($file['name'], PATHINFO_EXTENSION);
                $dir  = __DIR__ . '/../../public/img/members/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $filename = 'member_' . $id . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $dir . $filename)) {
                    $photoUrl = '/public/img/members/' . $filename . '?v=' . time();
                }
            }
        }
        if (($_POST['remove_photo'] ?? '0') === '1') {
            $photoUrl = null;
        }
        // Registrar transferência se filial mudou
        if ($newChurchId !== $churchId) {
            $db->prepare("
                INSERT INTO member_visits (member_id, from_church, to_church, visit_date, notes, created_by)
                VALUES (?, ?, ?, CURDATE(), ?, ?)
            ")->execute([$id, $churchId, $newChurchId,
                         $transferReason ?: 'Transferência registrada pelo sistema',
                         auth_member_id()]);
            // Limpar célula ao trocar de filial (célula pertence à filial antiga)
            $cellId = null;
        }

        $db->prepare("
            UPDATE members SET
              church_id=:church_id, name=:name, phone=:phone, email=:email, cpf=:cpf,
              birth_date=:birth_date, gender=:gender, marital_status=:marital,
              status=:status, cell_id=:cell_id, join_date=:join_date,
              baptism_date=:baptism, conversion_date=:conversion,
              origin_church=:origin, address=:address, neighborhood=:neighborhood,
              city=:city, zip_code=:zip, notes=:notes, photo_url=:photo_url
            WHERE id=:id
        ")->execute([
            ':church_id'=>$newChurchId,':name'=>$name,':phone'=>$phone,
            ':email'=>$email?:null,':cpf'=>$cpf?:null,
            ':birth_date'=>$birthDate?:null,':gender'=>$gender?:null,':marital'=>$marital?:null,
            ':status'=>$status,':cell_id'=>$cellId,':join_date'=>$joinDate,
            ':baptism'=>$baptism?:null,':conversion'=>$conversion?:null,':origin'=>$origin?:null,
            ':address'=>$address?:null,':neighborhood'=>$neighborhood?:null,
            ':city'=>$city?:null,':zip'=>$zip?:null,':notes'=>$notes?:null,
            ':photo_url'=>$photoUrl,
            ':id'=>$id,
        ]);
        header('Location: /pages/members/view.php?id='.$id.'&saved=1');
        exit;
    }
    $m = array_merge($m, $_POST);
}

require_once __DIR__ . '/../../includes/layout.php';
?>

<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" style="width:100%">

  <!-- Foto de perfil -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Foto de perfil</p>
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
      <?php
        $initials = strtoupper(implode('', array_map(fn($p)=>$p[0], array_slice(explode(' ',$m['name']),0,2))));
      ?>
      <div id="photo-preview"
           style="width:64px;height:64px;border-radius:50%;overflow:hidden;background:var(--accent-lt);display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:600;color:var(--accent-dk);flex-shrink:0">
        <?php if (!empty($m['photo_url'])): ?>
          <img src="<?= htmlspecialchars($m['photo_url']) ?>" style="width:100%;height:100%;object-fit:cover">
        <?php else: ?>
          <?= $initials ?>
        <?php endif; ?>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <label class="btn btn-secondary" style="font-size:12px;cursor:pointer">
          📷 <?= empty($m['photo_url']) ? 'Adicionar foto' : 'Trocar foto' ?>
          <input type="file" name="photo" accept="image/*" style="display:none" onchange="previewMemberPhoto(this)">
        </label>
        <?php if (!empty($m['photo_url'])): ?>
          <button type="button" onclick="removePhoto()"
                  class="btn btn-secondary" style="font-size:12px;color:var(--red)">
            × Remover foto
          </button>
          <input type="hidden" name="remove_photo" id="remove_photo_field" value="0">
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Dados pessoais</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Nome completo *</label>
        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($m['name']) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Status *</label>
        <select name="status" class="form-control">
          <?php foreach(['active'=>'Ativo','visitor'=>'Visitante','inactive'=>'Afastado','discipline'=>'Em disciplina','transferred'=>'Transferido','deceased'=>'Falecido'] as $k=>$v): ?>
            <option value="<?=$k?>" <?=$m['status']===$k?'selected':''?>><?=$v?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">CPF</label>
        <input type="text" name="cpf" class="form-control" value="<?= htmlspecialchars($m['cpf'] ?? '') ?>" maxlength="14">
      </div>
      <div class="form-group">
        <label class="form-label">Data de nascimento</label>
        <input type="date" name="birth_date" class="form-control" value="<?= $m['birth_date'] ? substr($m['birth_date'],0,10) : '' ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Gênero</label>
        <select name="gender" class="form-control">
          <option value="">Selecione</option>
          <option value="M" <?= ($m['gender']??'')==='M'?'selected':'' ?>>Masculino</option>
          <option value="F" <?= ($m['gender']??'')==='F'?'selected':'' ?>>Feminino</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Estado civil</label>
        <select name="marital_status" class="form-control">
          <option value="">Selecione</option>
          <?php foreach(['single'=>'Solteiro(a)','married'=>'Casado(a)','divorced'=>'Divorciado(a)','widowed'=>'Viúvo(a)'] as $k=>$v): ?>
            <option value="<?=$k?>" <?=($m['marital_status']??'')===$k?'selected':''?>><?=$v?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>

  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Contato</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Telefone / WhatsApp *</label>
        <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($m['phone'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">E-mail</label>
        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($m['email'] ?? '') ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">CEP</label>
        <input type="text" name="zip_code" id="zip_code" class="form-control" value="<?= htmlspecialchars($m['zip_code'] ?? '') ?>" maxlength="9">
      </div>
      <div class="form-group">
        <label class="form-label">Cidade</label>
        <input type="text" name="city" id="city" class="form-control" value="<?= htmlspecialchars($m['city'] ?? '') ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Endereço</label>
        <input type="text" name="address" id="address" class="form-control" value="<?= htmlspecialchars($m['address'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Bairro</label>
        <input type="text" name="neighborhood" id="neighborhood" class="form-control" value="<?= htmlspecialchars($m['neighborhood'] ?? '') ?>">
      </div>
    </div>
  </div>

  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Vida espiritual</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Data de ingresso</label>
        <input type="date" name="join_date" class="form-control" value="<?= $m['join_date'] ? substr($m['join_date'],0,10) : '' ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Data de conversão</label>
        <input type="date" name="conversion_date" class="form-control" value="<?= $m['conversion_date'] ? substr($m['conversion_date'],0,10) : '' ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Data de batismo</label>
        <input type="date" name="baptism_date" class="form-control" value="<?= $m['baptism_date'] ? substr($m['baptism_date'],0,10) : '' ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Igreja de origem</label>
        <input type="text" name="origin_church" class="form-control" value="<?= htmlspecialchars($m['origin_church'] ?? '') ?>">
      </div>
    </div>
  </div>

  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Vínculos</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Filial / Sede</label>
        <select name="church_id" class="form-control" id="branch-select" onchange="updateCells(this.value)">
          <?php foreach (get_branches() as $b): ?>
            <option value="<?= $b['id'] ?>"
                    <?= $m['church_id'] == $b['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($b['name']) ?>
              <?= $b['type'] === 'sede' ? '(Sede)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php
          // Mostrar filial atual com badge
          $currentBranch = null;
          foreach (get_branches() as $b) {
              if ($b['id'] == $m['church_id']) { $currentBranch = $b; break; }
          }
        ?>
        <?php if ($currentBranch): ?>
          <div style="margin-top:6px;font-size:12px;color:var(--text-muted)">
            Atualmente em:
            <span class="badge <?= $currentBranch['type']==='sede'?'badge-blue':'badge-green' ?>">
              <?= htmlspecialchars($currentBranch['name']) ?>
            </span>
          </div>
        <?php endif; ?>
      </div>
      <div class="form-group">
        <label class="form-label">Célula</label>
        <select name="cell_id" class="form-control" id="cell-select">
          <option value="">Sem célula</option>
          <?php foreach ($cells as $c): ?>
            <option value="<?=$c['id']?>" <?=$m['cell_id']==$c['id']?'selected':''?>><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <!-- Motivo da transferência (aparece quando muda de filial) -->
    <div id="transfer-reason-group" style="display:none">
      <div class="form-group" style="margin-bottom:0">
        <label class="form-label">
          Motivo da transferência
          <span style="font-weight:400;color:var(--text-muted)">(ficará registrado no histórico)</span>
        </label>
        <input type="text" name="transfer_reason" class="form-control"
               placeholder="Ex: Mudou de bairro, convite do pastor, retorno à sede…">
      </div>
    </div>
  </div>

  <div class="card" style="margin-bottom:24px">
    <p class="card-title">Observações</p>
    <div class="form-group" style="margin-bottom:0">
      <textarea name="notes" class="form-control" rows="3"><?= htmlspecialchars($m['notes'] ?? '') ?></textarea>
    </div>
  </div>

  <div style="display:flex;gap:10px;align-items:center">
    <button type="submit" class="btn btn-primary">Salvar alterações</button>
    <a href="/pages/members/view.php?id=<?= $id ?>" class="btn btn-secondary">Cancelar</a>
    <a href="/pages/members/delete.php?id=<?= $id ?>"
       class="btn btn-secondary"
       style="margin-left:auto;color:var(--red)"
       data-confirm="Tem certeza que deseja excluir este membro?">
      Excluir
    </a>
  </div>

</form>

<?php
$churchIdJs = (int)$m['church_id'];
$extraJs = <<<JS
function previewMemberPhoto(input) {
  if (!input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    document.getElementById('photo-preview').innerHTML =
      '<img src="'+e.target.result+'" style="width:100%;height:100%;object-fit:cover">';
  };
  reader.readAsDataURL(input.files[0]);
}
function removePhoto() {
  document.getElementById('photo-preview').innerHTML = '<?= $initials ?? "?" ?>';
  const field = document.getElementById('remove_photo_field');
  if (field) field.value = '1';
}
const originalBranch = $churchIdJs;
document.getElementById('branch-select').addEventListener('change', function() {
  const changed = parseInt(this.value) !== originalBranch;
  document.getElementById('transfer-reason-group').style.display = changed ? 'block' : 'none';
  if (changed) {
    document.getElementById('cell-select').innerHTML = '<option value="">Sem célula (escolha após salvar)</option>';
  }
});
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
?>
<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
