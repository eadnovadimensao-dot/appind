<?php
// Processar ANTES de incluir o layout (evita headers already sent)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
auth_check();

$db       = db();
$churchId = current_church_id();
$errors   = [];

// Remover logo
if (isset($_GET['remove_logo'])) {
    $db->prepare("UPDATE church_settings SET `value`='' WHERE church_id=? AND `key`='church_logo_url'")
       ->execute([$churchId]);
    header('Location: /pages/settings.php?saved=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = [
        'church_name', 'church_initials', 'church_logo_url',
        'church_address', 'church_phone', 'church_email', 'church_website',
        'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass',
        'primary_color', 'accent_color',
    ];

    $initials = strtoupper(trim($_POST['church_initials'] ?? ''));
    if (strlen($initials) > 3) $errors[] = 'Sigla deve ter no máximo 3 letras.';
    if (trim($_POST['church_name'] ?? '') === '') $errors[] = 'Nome da igreja é obrigatório.';

    // Upload do logo
    if (!empty($_FILES['church_logo']['tmp_name'])) {
        $file    = $_FILES['church_logo'];
        $allowed = ['image/jpeg','image/png','image/svg+xml','image/webp'];
        $maxSize = 2 * 1024 * 1024;

        if (!in_array($file['type'], $allowed)) {
            $errors[] = 'Formato inválido. Use JPG, PNG, SVG ou WebP.';
        } elseif ($file['size'] > $maxSize) {
            $errors[] = 'Imagem muito grande. Máximo 2MB.';
        } else {
            $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
            $dir      = __DIR__ . '/../public/img/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $filename = 'logo_' . $churchId . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $dir . $filename)) {
                $_POST['church_logo_url'] = '/public/img/' . $filename . '?v=' . time();
            } else {
                $errors[] = 'Erro ao salvar o logo. Verifique as permissões da pasta.';
            }
        }
    }

    if (empty($errors)) {
        $stmt = $db->prepare("
            INSERT INTO church_settings (church_id, `key`, `value`)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()
        ");
        foreach ($fields as $field) {
            $val = trim($_POST[$field] ?? '');
            if ($field === 'church_initials') $val = strtoupper($val);
            $stmt->execute([$churchId, $field, $val]);
        }
        $db->prepare("UPDATE churches SET name=? WHERE id=?")
           ->execute([trim($_POST['church_name']), $churchId]);

        header('Location: /pages/settings.php?saved=1');
        exit;
    }
}

$s = church_settings();

// Incluir layout DEPOIS de todo processamento
$pageTitle  = 'Configurações';
$activePage = 'settings';
require_once __DIR__ . '/../includes/layout.php';
?>

<?php if (isset($_GET['saved'])): ?>
  <div class="flash" style="background:#E1F5EE;border:1px solid var(--accent-border);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#0F6E56">
    ✓ Configurações salvas! Recarregue a página para ver as mudanças no menu.
  </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" style="width:100%">

  <!-- Identidade -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Identidade da igreja</p>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Nome da igreja *</label>
        <input type="text" name="church_name" class="form-control"
               placeholder="Ex: Igreja Nova Dimensão"
               value="<?= htmlspecialchars($s['church_name'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Sigla <span style="font-weight:400;color:var(--text-muted)">(até 3 letras — aparece no avatar)</span></label>
        <input type="text" name="church_initials" class="form-control"
               placeholder="Ex: ND, IND"
               maxlength="3"
               style="text-transform:uppercase"
               value="<?= htmlspecialchars($s['church_initials'] ?? '') ?>">
      </div>
    </div>

    <div class="form-group" style="margin-bottom:0">
      <label class="form-label">Logo da igreja</label>

      <!-- Upload direto -->
      <div style="border:2px dashed var(--border);border-radius:10px;padding:20px;text-align:center;cursor:pointer;transition:border-color .15s"
           id="drop-area"
           onclick="document.getElementById('logo-upload').click()"
           ondragover="event.preventDefault();this.style.borderColor='var(--accent)'"
           ondragleave="this.style.borderColor='var(--border)'"
           ondrop="handleDrop(event)">
        <input type="file" name="church_logo" id="logo-upload" accept="image/*"
               style="display:none" onchange="previewFile(this)">
        <div id="upload-placeholder">
          <div style="font-size:28px;margin-bottom:8px">🖼️</div>
          <div style="font-size:13px;font-weight:500;color:var(--text)">Clique ou arraste uma imagem</div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:4px">JPG, PNG, SVG ou WebP · máx. 2MB</div>
        </div>
        <img id="upload-preview" src="" alt="" style="display:none;max-height:80px;max-width:200px;border-radius:8px;margin:0 auto">
      </div>

      <!-- URL alternativa -->
      <div style="margin-top:10px">
        <label class="form-label" style="font-size:11px;color:var(--text-muted)">ou informe uma URL de imagem</label>
        <input type="url" name="church_logo_url" class="form-control"
               placeholder="https://seusite.com.br/logo.png"
               value="<?= htmlspecialchars($s['church_logo_url'] ?? '') ?>"
               id="logo-url-input">
      </div>

      <!-- Preview atual -->
      <div style="margin-top:14px;display:flex;align-items:center;gap:12px">
        <div id="logo-preview"
             style="width:48px;height:48px;border-radius:10px;background:var(--accent);color:white;font-size:16px;font-weight:600;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0">
          <?php
            $logoUrl = $s['church_logo_url'] ?? '';
            $logoUrl = strtok($logoUrl, '?'); // remove ?v=timestamp
          ?>
          <?php if ($logoUrl): ?>
            <img src="<?= htmlspecialchars($s['church_logo_url']) ?>" style="width:100%;height:100%;object-fit:cover">
          <?php else: ?>
            <?= htmlspecialchars($s['church_initials'] ?? 'IG') ?>
          <?php endif; ?>
        </div>
        <div>
          <div style="font-size:12px;color:var(--text-muted)">Preview do ícone na sidebar</div>
          <?php if ($logoUrl): ?>
            <a href="/pages/settings.php?remove_logo=1" style="font-size:11px;color:var(--red);text-decoration:none"
               onclick="return confirm('Remover o logo atual?')">× Remover logo</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Contato -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Contato e localização</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Endereço</label>
        <input type="text" name="church_address" class="form-control"
               placeholder="Rua, número, bairro, cidade"
               value="<?= htmlspecialchars($s['church_address'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Telefone / WhatsApp</label>
        <input type="text" name="church_phone" class="form-control"
               placeholder="(11) 99999-9999"
               value="<?= htmlspecialchars($s['church_phone'] ?? '') ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">E-mail</label>
        <input type="email" name="church_email" class="form-control"
               placeholder="contato@suaigreja.com.br"
               value="<?= htmlspecialchars($s['church_email'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Site</label>
        <input type="url" name="church_website" class="form-control"
               placeholder="https://suaigreja.com.br"
               value="<?= htmlspecialchars($s['church_website'] ?? '') ?>">
      </div>
    </div>
  </div>

  <!-- SMTP -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Configurações de e-mail (SMTP)</p>
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">
      Use as credenciais de e-mail da sua hospedagem. No cPanel → Contas de e-mail, crie uma conta como <code>noreply@igrejanovadimensao.com.br</code>.
    </p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Servidor SMTP</label>
        <input type="text" name="smtp_host" class="form-control"
               placeholder="mail.igrejanovadimensao.com.br"
               value="<?= htmlspecialchars($s['smtp_host'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Porta</label>
        <select name="smtp_port" class="form-control">
          <option value="587" <?= ($s['smtp_port']??'587')==='587'?'selected':''?>>587 (TLS — recomendado)</option>
          <option value="465" <?= ($s['smtp_port']??'')==='465'?'selected':''?>>465 (SSL)</option>
          <option value="25"  <?= ($s['smtp_port']??'')==='25' ?'selected':''?>>25 (sem criptografia)</option>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Usuário (e-mail remetente)</label>
        <input type="text" name="smtp_user" class="form-control"
               placeholder="noreply@igrejanovadimensao.com.br"
               value="<?= htmlspecialchars($s['smtp_user'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Senha</label>
        <input type="password" name="smtp_pass" class="form-control"
               placeholder="••••••••"
               value="<?= htmlspecialchars($s['smtp_pass'] ?? '') ?>">
      </div>
    </div>
  </div>

  <!-- Cores -->
  <div class="card" style="margin-bottom:24px">
    <p class="card-title">Cores do sistema</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Cor da sidebar</label>
        <div style="display:flex;align-items:center;gap:10px">
          <input type="color" name="primary_color"
                 value="<?= htmlspecialchars($s['primary_color'] ?? '#012a36') ?>"
                 style="width:48px;height:38px;padding:2px;border:1px solid var(--border);border-radius:7px;cursor:pointer">
          <input type="text" id="primary-text"
                 value="<?= htmlspecialchars($s['primary_color'] ?? '#012a36') ?>"
                 class="form-control" style="max-width:120px" readonly>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Cor de destaque</label>
        <div style="display:flex;align-items:center;gap:10px">
          <input type="color" name="accent_color"
                 value="<?= htmlspecialchars($s['accent_color'] ?? '#1D9E75') ?>"
                 style="width:48px;height:38px;padding:2px;border:1px solid var(--border);border-radius:7px;cursor:pointer"
                 oninput="document.getElementById('accent-text').value=this.value">
          <input type="text" id="accent-text"
                 value="<?= htmlspecialchars($s['accent_color'] ?? '#1D9E75') ?>"
                 class="form-control" style="max-width:120px" readonly>
        </div>
      </div>
    </div>
    <p style="font-size:12px;color:var(--text-muted);margin-top:4px">
      ⚠️ As cores entram em efeito após salvar e recarregar a página.
    </p>
  </div>

  <div style="display:flex;gap:10px">
    <button type="submit" class="btn btn-primary">Salvar configurações</button>
    <a href="/dashboard.php" class="btn btn-secondary">Cancelar</a>
  </div>

</form>

<!-- Link para templates -->
<div class="card" style="margin-top:16px;display:flex;align-items:center;justify-content:space-between">
  <div>
    <div style="font-weight:500;font-size:14px">🔔 Templates de Notificação</div>
    <div style="font-size:13px;color:var(--text-muted)">Personalize os textos das notificações enviadas pelo sistema</div>
  </div>
  <a href="/pages/settings/notification_templates.php" class="btn btn-secondary">Gerenciar templates</a>
</div>

<?php
$extraJs = <<<JS
// Preview do arquivo selecionado
function previewFile(input) {
  if (!input.files[0]) return;
  const reader = new FileReader();
  reader.onload = function(e) {
    showUploadPreview(e.target.result);
  };
  reader.readAsDataURL(input.files[0]);
}

function handleDrop(e) {
  e.preventDefault();
  document.getElementById('drop-area').style.borderColor = 'var(--border)';
  const file = e.dataTransfer.files[0];
  if (!file || !file.type.startsWith('image/')) return;
  const dt = new DataTransfer();
  dt.items.add(file);
  document.getElementById('logo-upload').files = dt.files;
  const reader = new FileReader();
  reader.onload = ev => showUploadPreview(ev.target.result);
  reader.readAsDataURL(file);
}

function showUploadPreview(src) {
  document.getElementById('upload-placeholder').style.display = 'none';
  const prev = document.getElementById('upload-preview');
  prev.src = src;
  prev.style.display = 'block';
  // Atualizar preview do ícone
  document.getElementById('logo-preview').innerHTML = '<img src="'+src+'" style="width:100%;height:100%;object-fit:cover">';
}

// Sync color inputs
document.querySelector('[name=primary_color]').addEventListener('input', function() {
  document.getElementById('primary-text').value = this.value;
});
document.querySelector('[name=accent_color]').addEventListener('input', function() {
  document.getElementById('accent-text').value = this.value;
});

// Atualizar preview ao digitar sigla
document.querySelector('[name=church_initials]').addEventListener('input', function() {
  const hasUpload = document.getElementById('logo-upload').files[0];
  const hasUrl    = document.getElementById('logo-url-input').value;
  if (!hasUpload && !hasUrl) {
    document.getElementById('logo-preview').textContent = this.value.toUpperCase().slice(0,3) || 'IG';
  }
});
JS;
require_once __DIR__ . '/../includes/layout-footer.php';
?>
