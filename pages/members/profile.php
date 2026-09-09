<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db       = db();
$memberId = auth_member_id();
$errors   = [];

if (!$memberId) {
    header('Location: /dashboard.php');
    exit;
}

// Buscar dados do membro
$stmt = $db->prepare("
    SELECT m.*, c.name AS cell_name, ch.name AS branch_name, f.name AS family_name
    FROM members m
    LEFT JOIN cells c     ON c.id  = m.cell_id
    LEFT JOIN churches ch ON ch.id = m.church_id
    LEFT JOIN families f  ON f.id  = m.family_id
    WHERE m.id = ?
");
$stmt->execute([$memberId]);
$m = $stmt->fetch();

if (!$m) { header('Location: /dashboard.php'); exit; }

// Processar atualização
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update';

    if ($action === 'update') {
        $phone     = trim($_POST['phone']     ?? '');
        $email     = trim($_POST['email']     ?? '');
        $address   = trim($_POST['address']   ?? '');
        $neighborhood = trim($_POST['neighborhood'] ?? '');
        $city      = trim($_POST['city']      ?? '');
        $zip       = trim($_POST['zip_code']  ?? '');

        // Upload de foto
        $photoUrl = $m['photo_url'];
        if (!empty($_FILES['photo']['tmp_name'])) {
            $file    = $_FILES['photo'];
            $allowed = ['image/jpeg','image/png','image/webp'];
            $maxSize = 3 * 1024 * 1024; // 3MB

            if (!in_array($file['type'], $allowed)) {
                $errors[] = 'Formato inválido. Use JPG, PNG ou WebP.';
            } elseif ($file['size'] > $maxSize) {
                $errors[] = 'Imagem muito grande. Máximo 3MB.';
            } else {
                $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
                $dir      = __DIR__ . '/../../public/img/members/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $filename = 'member_' . $memberId . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $dir . $filename)) {
                    $photoUrl = '/public/img/members/' . $filename . '?v=' . time();
                } else {
                    $errors[] = 'Erro ao salvar a foto.';
                }
            }
        }

        if (empty($errors)) {
            $db->prepare("
                UPDATE members SET
                  phone=?, email=?, address=?, neighborhood=?, city=?, zip_code=?, photo_url=?
                WHERE id=?
            ")->execute([
                $phone?:null, $email?:null, $address?:null,
                $neighborhood?:null, $city?:null, $zip?:null,
                $photoUrl, $memberId
            ]);
            header('Location: /pages/members/profile.php?saved=1');
            exit;
        }
    }

    // Remover foto
    if ($action === 'remove_photo') {
        $db->prepare("UPDATE members SET photo_url=NULL WHERE id=?")->execute([$memberId]);
        header('Location: /pages/members/profile.php');
        exit;
    }
}

$pageTitle  = 'Meu Perfil';
$activePage = 'members';
require_once __DIR__ . '/../../includes/layout.php';

$initials = strtoupper(implode('', array_map(fn($p) => $p[0], array_slice(explode(' ', $m['name']), 0, 2))));
?>

<?php if (isset($_GET['saved'])): ?>
  <div style="background:#E1F5EE;border:1px solid var(--accent-border);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#0F6E56">
    ✓ Perfil atualizado com sucesso!
  </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" style="max-width:720px">
  <input type="hidden" name="action" value="update">

  <!-- Foto e nome -->
  <div class="card" style="margin-bottom:16px">
    <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap">

      <!-- Avatar / Foto atual -->
      <div style="position:relative;flex-shrink:0">
        <div id="photo-preview"
             style="width:80px;height:80px;border-radius:50%;overflow:hidden;background:var(--accent-lt);display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:600;color:var(--accent-dk)">
          <?php if ($m['photo_url']): ?>
            <img src="<?= htmlspecialchars($m['photo_url']) ?>" style="width:100%;height:100%;object-fit:cover">
          <?php else: ?>
            <?= $initials ?>
          <?php endif; ?>
        </div>
        <!-- Botão trocar foto -->
        <label for="photo-input"
               style="position:absolute;bottom:0;right:0;width:26px;height:26px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:13px;border:2px solid white"
               title="Trocar foto">📷</label>
        <input type="file" name="photo" id="photo-input" accept="image/*" style="display:none"
               onchange="previewPhoto(this)">
      </div>

      <div style="flex:1">
        <div style="font-size:18px;font-weight:500;margin-bottom:4px"><?= htmlspecialchars($m['name']) ?></div>
        <div style="font-size:13px;color:var(--text-muted);display:flex;flex-wrap:wrap;gap:10px">
          <?php if ($m['cell_name']): ?><span>🔗 <?= htmlspecialchars($m['cell_name']) ?></span><?php endif; ?>
          <?php if ($m['branch_name']): ?><span>🏛️ <?= htmlspecialchars($m['branch_name']) ?></span><?php endif; ?>
          <?php if ($m['family_name']): ?><span>👨‍👩‍👧‍👦 <?= htmlspecialchars($m['family_name']) ?></span><?php endif; ?>
        </div>
        <?php if ($m['photo_url']): ?>
          <form method="POST" style="display:inline;margin-top:8px">
            <input type="hidden" name="action" value="remove_photo">
            <button type="submit" style="background:none;border:none;color:var(--red);font-size:12px;cursor:pointer;padding:0"
                    onclick="return confirm('Remover foto?')">× Remover foto</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <div style="margin-top:10px;font-size:12px;color:var(--text-muted)">
      Clique no 📷 para trocar sua foto. JPG, PNG ou WebP até 3MB.
    </div>
  </div>

  <!-- Contato -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Contato</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Telefone / WhatsApp</label>
        <input type="text" name="phone" class="form-control"
               placeholder="(11) 99999-9999"
               value="<?= htmlspecialchars($m['phone'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">E-mail</label>
        <input type="email" name="email" class="form-control"
               placeholder="seu@email.com"
               value="<?= htmlspecialchars($m['email'] ?? '') ?>">
      </div>
    </div>
  </div>

  <!-- Endereço -->
  <div class="card" style="margin-bottom:24px">
    <p class="card-title">Endereço</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">CEP</label>
        <input type="text" name="zip_code" id="zip_code" class="form-control"
               maxlength="9" placeholder="00000-000"
               value="<?= htmlspecialchars($m['zip_code'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Cidade</label>
        <input type="text" name="city" id="city" class="form-control"
               value="<?= htmlspecialchars($m['city'] ?? '') ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Endereço</label>
        <input type="text" name="address" id="address" class="form-control"
               placeholder="Rua, número"
               value="<?= htmlspecialchars($m['address'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Bairro</label>
        <input type="text" name="neighborhood" id="neighborhood" class="form-control"
               value="<?= htmlspecialchars($m['neighborhood'] ?? '') ?>">
      </div>
    </div>
  </div>

  <!-- Info somente leitura -->
  <div class="card" style="margin-bottom:24px;background:var(--content-bg)">
    <p class="card-title" style="color:var(--text-muted)">Informações pastorais</p>
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">
      Esses dados são gerenciados pela liderança da igreja.
    </p>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px">
      <div><span style="color:var(--text-muted)">Status:</span> <strong><?= $m['status'] ?? '—' ?></strong></div>
      <div><span style="color:var(--text-muted)">Ingresso:</span> <strong><?= $m['join_date'] ? date('d/m/Y', strtotime($m['join_date'])) : '—' ?></strong></div>
      <div><span style="color:var(--text-muted)">Batismo:</span> <strong><?= $m['baptism_date'] ? date('d/m/Y', strtotime($m['baptism_date'])) : '—' ?></strong></div>
      <div><span style="color:var(--text-muted)">Célula:</span> <strong><?= htmlspecialchars($m['cell_name'] ?? '—') ?></strong></div>
    </div>
  </div>

  <div style="display:flex;gap:10px">
    <button type="submit" class="btn btn-primary">Salvar alterações</button>
    <a href="/dashboard.php" class="btn btn-secondary">Cancelar</a>
  </div>
</form>

<?php
$extraJs = <<<JS
// Preview da foto
function previewPhoto(input) {
  if (!input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    const preview = document.getElementById('photo-preview');
    preview.innerHTML = '<img src="'+e.target.result+'" style="width:100%;height:100%;object-fit:cover">';
  };
  reader.readAsDataURL(input.files[0]);
}

// CEP autocomplete
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
