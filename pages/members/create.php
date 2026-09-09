<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db       = db();
$churchId = current_church_id();
$errors   = [];

$cells      = $db->query("SELECT id, name FROM cells WHERE church_id = $churchId AND active = 1 ORDER BY name")->fetchAll();
$ministries = $db->query("SELECT id, name FROM ministries WHERE church_id = $churchId AND active = 1 ORDER BY name")->fetchAll();
$families   = $db->query("SELECT id, name FROM families WHERE church_id = $churchId ORDER BY name")->fetchAll();
$branches   = get_branches();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name       = trim($_POST['name']       ?? '');
    $phone      = trim($_POST['phone']      ?? '');
    $email      = trim($_POST['email']      ?? '');
    $cpf        = trim($_POST['cpf']        ?? '');
    $birthDate  = trim($_POST['birth_date'] ?? '');
    $gender     = trim($_POST['gender']     ?? '');
    $marital    = trim($_POST['marital_status'] ?? '');
    $status     = trim($_POST['status']     ?? 'active');
    $cellId     = trim($_POST['cell_id']    ?? '') ?: null;
    $joinDate   = trim($_POST['join_date']  ?? '') ?: null;
    $baptism    = trim($_POST['baptism_date'] ?? '') ?: null;
    $conversion = trim($_POST['conversion_date'] ?? '') ?: null;
    $origin     = trim($_POST['origin_church'] ?? '');
    $address    = trim($_POST['address']    ?? '');
    $neighborhood = trim($_POST['neighborhood'] ?? '');
    $city       = trim($_POST['city']       ?? '');
    $zip        = trim($_POST['zip_code']   ?? '');
    $notes      = trim($_POST['notes']      ?? '');
    $familyId   = trim($_POST['family_id']  ?? '') ?: null;
    $familyRole = trim($_POST['family_role'] ?? '') ?: null;
    $newFamily  = trim($_POST['new_family'] ?? '');
    $ministryIds = $_POST['ministry_ids'] ?? [];

    if ($name === '')  $errors[] = 'Nome é obrigatório.';
    if ($phone === '') $errors[] = 'Telefone é obrigatório.';

    if (empty($errors)) {
        // Criar nova família se solicitado
        if ($newFamily !== '') {
            $sf = $db->prepare("INSERT INTO families (church_id, name) VALUES (?, ?)");
            $sf->execute([$churchId, $newFamily]);
            $familyId = $db->lastInsertId();
        }

        $stmt = $db->prepare("
            INSERT INTO members
              (church_id, name, phone, email, cpf, birth_date, gender, marital_status,
               status, cell_id, join_date, baptism_date, conversion_date, origin_church,
               address, neighborhood, city, zip_code, notes, family_id, family_role)
            VALUES
              (:church_id,:name,:phone,:email,:cpf,:birth_date,:gender,:marital,
               :status,:cell_id,:join_date,:baptism,:conversion,:origin,
               :address,:neighborhood,:city,:zip,:notes,:family_id,:family_role)
        ");
        $stmt->execute([
            ':church_id'=>$churchId,':name'=>$name,':phone'=>$phone,
            ':email'=>$email?:null,':cpf'=>$cpf?:null,
            ':birth_date'=>$birthDate?:null,':gender'=>$gender?:null,':marital'=>$marital?:null,
            ':status'=>$status,':cell_id'=>$cellId,':join_date'=>$joinDate,
            ':baptism'=>$baptism?:null,':conversion'=>$conversion?:null,':origin'=>$origin?:null,
            ':address'=>$address?:null,':neighborhood'=>$neighborhood?:null,
            ':city'=>$city?:null,':zip'=>$zip?:null,':notes'=>$notes?:null,
            ':family_id'=>$familyId,':family_role'=>$familyRole,
        ]);
        $churchId  = (int)($_POST['church_id'] ?? $churchId);
        $memberId  = $db->lastInsertId();

        // Vincular ministérios
        if (!empty($ministryIds)) {
            $sm = $db->prepare("INSERT IGNORE INTO member_ministries (member_id, ministry_id, joined_at) VALUES (?, ?, CURDATE())");
            foreach ($ministryIds as $mid) {
                $sm->execute([$memberId, (int)$mid]);
            }
        }

        header('Location: /pages/members/index.php?saved=1');
        exit;
    }
}

$pageTitle  = 'Novo membro';
$activePage = 'members';
require_once __DIR__ . '/../../includes/layout.php';
?>

<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="POST" style="width:100%">

  <!-- Dados pessoais -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Dados pessoais</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Nome completo *</label>
        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Filial / Sede *</label>
        <select name="church_id" class="form-control">
          <?php foreach ($branches as $b): ?>
            <option value="<?= $b['id'] ?>"
                    <?= ($_POST['church_id'] ?? $churchId) == $b['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($b['name']) ?>
              <?= $b['type'] === 'sede' ? '(Sede)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Status *</label>
        <select name="status" class="form-control">
          <option value="active"      <?= ($_POST['status']??'active')==='active'      ?'selected':''?>>Ativo</option>
          <option value="visitor"     <?= ($_POST['status']??'')==='visitor'            ?'selected':''?>>Visitante</option>
          <option value="inactive"    <?= ($_POST['status']??'')==='inactive'           ?'selected':''?>>Afastado</option>
          <option value="discipline"  <?= ($_POST['status']??'')==='discipline'         ?'selected':''?>>Em disciplina</option>
          <option value="transferred" <?= ($_POST['status']??'')==='transferred'        ?'selected':''?>>Transferido</option>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">CPF</label>
        <input type="text" name="cpf" class="form-control" placeholder="000.000.000-00" value="<?= htmlspecialchars($_POST['cpf'] ?? '') ?>" maxlength="14">
      </div>
      <div class="form-group">
        <label class="form-label">Data de nascimento</label>
        <input type="date" name="birth_date" class="form-control" value="<?= htmlspecialchars($_POST['birth_date'] ?? '') ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Gênero</label>
        <select name="gender" class="form-control">
          <option value="">Selecione</option>
          <option value="M" <?= ($_POST['gender']??'')==='M'?'selected':''?>>Masculino</option>
          <option value="F" <?= ($_POST['gender']??'')==='F'?'selected':''?>>Feminino</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Estado civil</label>
        <select name="marital_status" class="form-control">
          <option value="">Selecione</option>
          <option value="single"   <?= ($_POST['marital_status']??'')==='single'   ?'selected':''?>>Solteiro(a)</option>
          <option value="married"  <?= ($_POST['marital_status']??'')==='married'  ?'selected':''?>>Casado(a)</option>
          <option value="divorced" <?= ($_POST['marital_status']??'')==='divorced' ?'selected':''?>>Divorciado(a)</option>
          <option value="widowed"  <?= ($_POST['marital_status']??'')==='widowed'  ?'selected':''?>>Viúvo(a)</option>
        </select>
      </div>
    </div>
  </div>

  <!-- Contato -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Contato</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Telefone / WhatsApp *</label>
        <input type="text" name="phone" class="form-control" placeholder="(00) 00000-0000" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">E-mail</label>
        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">CEP</label>
        <div style="position:relative">
          <input type="text" name="zip_code" id="zip_code" class="form-control" placeholder="00000-000" maxlength="9" value="<?= htmlspecialchars($_POST['zip_code'] ?? '') ?>">
          <span id="cep-loading" style="display:none;position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:11px;color:var(--text-muted)">buscando…</span>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Cidade</label>
        <input type="text" name="city" id="city" class="form-control" value="<?= htmlspecialchars($_POST['city'] ?? '') ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Endereço</label>
        <input type="text" name="address" id="address" class="form-control" value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Bairro</label>
        <input type="text" name="neighborhood" id="neighborhood" class="form-control" value="<?= htmlspecialchars($_POST['neighborhood'] ?? '') ?>">
      </div>
    </div>
  </div>

  <!-- Vida espiritual -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Vida espiritual</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Data de ingresso</label>
        <input type="date" name="join_date" class="form-control" value="<?= htmlspecialchars($_POST['join_date'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Data de conversão</label>
        <input type="date" name="conversion_date" class="form-control" value="<?= htmlspecialchars($_POST['conversion_date'] ?? '') ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Data de batismo <span style="font-weight:400;color:var(--text-muted)">(opcional)</span></label>
        <input type="date" name="baptism_date" class="form-control" value="<?= htmlspecialchars($_POST['baptism_date'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Igreja de origem</label>
        <input type="text" name="origin_church" class="form-control" value="<?= htmlspecialchars($_POST['origin_church'] ?? '') ?>">
      </div>
    </div>
  </div>

  <!-- Vínculos -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Vínculos</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Célula</label>
        <select name="cell_id" class="form-control">
          <option value="">Sem célula</option>
          <?php foreach ($cells as $c): ?>
            <option value="<?= $c['id'] ?>" <?= ($_POST['cell_id']??'')==$c['id']?'selected':''?>>
              <?= htmlspecialchars($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Ministério(s)</label>
        <select name="ministry_ids[]" class="form-control" multiple style="height:80px">
          <?php foreach ($ministries as $min): ?>
            <option value="<?= $min['id'] ?>" <?= in_array($min['id'], $_POST['ministry_ids']??[])?'selected':''?>>
              <?= htmlspecialchars($min['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <span style="font-size:11px;color:var(--text-muted)">Segure Ctrl para selecionar mais de um</span>
      </div>
    </div>

    <!-- Família -->
    <div style="border-top:1px solid var(--border);padding-top:16px;margin-top:4px">
      <label class="form-label" style="margin-bottom:10px;display:block">Família</label>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Vincular a família existente</label>
          <select name="family_id" class="form-control" id="family_select">
            <option value="">Nenhuma</option>
            <?php foreach ($families as $f): ?>
              <option value="<?= $f['id'] ?>" <?= ($_POST['family_id']??'')==$f['id']?'selected':''?>>
                <?= htmlspecialchars($f['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Papel na família</label>
          <select name="family_role" class="form-control">
            <option value="">Selecione</option>
            <option value="head"   <?= ($_POST['family_role']??'')==='head'   ?'selected':''?>>Cabeça / Responsável</option>
            <option value="spouse" <?= ($_POST['family_role']??'')==='spouse' ?'selected':''?>>Cônjuge</option>
            <option value="child"  <?= ($_POST['family_role']??'')==='child'  ?'selected':''?>>Filho(a)</option>
            <option value="other"  <?= ($_POST['family_role']??'')==='other'  ?'selected':''?>>Outro</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">— ou criar nova família com este membro</label>
        <input type="text" name="new_family" class="form-control" placeholder="Ex: Família Silva" value="<?= htmlspecialchars($_POST['new_family'] ?? '') ?>">
      </div>
    </div>
  </div>

  <!-- Observações -->
  <div class="card" style="margin-bottom:24px">
    <p class="card-title">Observações</p>
    <div class="form-group" style="margin-bottom:0">
      <textarea name="notes" class="form-control" rows="3" placeholder="Anotações pastorais…"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
    </div>
  </div>

  <div style="display:flex;gap:10px">
    <button type="submit" class="btn btn-primary">Salvar membro</button>
    <a href="/pages/members/index.php" class="btn btn-secondary">Cancelar</a>
  </div>

</form>

<?php
$extraJs = <<<JS
// ── CEP autocomplete via ViaCEP ──
document.getElementById('zip_code').addEventListener('blur', function() {
  const cep = this.value.replace(/\D/g,'');
  if (cep.length !== 8) return;
  const loading = document.getElementById('cep-loading');
  loading.style.display = 'inline';
  fetch('https://viacep.com.br/ws/' + cep + '/json/')
    .then(r => r.json())
    .then(d => {
      loading.style.display = 'none';
      if (d.erro) return;
      document.getElementById('address').value      = d.logradouro || '';
      document.getElementById('neighborhood').value = d.bairro     || '';
      document.getElementById('city').value         = d.localidade || '';
    })
    .catch(() => { loading.style.display = 'none'; });
});

// Máscara CEP
document.getElementById('zip_code').addEventListener('input', function() {
  let v = this.value.replace(/\D/g,'').slice(0,8);
  if (v.length > 5) v = v.slice(0,5) + '-' + v.slice(5);
  this.value = v;
});
JS;
require_once __DIR__ . '/../../includes/layout-footer.php';
