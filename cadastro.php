<?php
require_once __DIR__ . '/config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

$db = db();

// Filial (opcional via ?filial=ID) — padrão é a sede
$branches = get_branches();
$branchIds = array_column($branches, 'id');
$churchId = (int)($_GET['filial'] ?? SEDE_ID);
if (!in_array($churchId, $branchIds, true)) $churchId = SEDE_ID;

$success = false;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Honeypot — campo invisível que só um robô preencheria
    $honeypot = trim($_POST['website'] ?? '');

    // Tempo mínimo entre carregar o formulário e enviar (evita bots instantâneos)
    $renderedAt = (int)($_SESSION['cadastro_ts'] ?? 0);
    $tooFast    = $renderedAt > 0 && (time() - $renderedAt) < 3;

    $name       = trim($_POST['name']       ?? '');
    $phone      = trim($_POST['phone']      ?? '');
    $email      = trim($_POST['email']      ?? '');
    $birthDate  = trim($_POST['birth_date'] ?? '');
    $gender     = trim($_POST['gender']     ?? '');
    $marital    = trim($_POST['marital_status'] ?? '');
    $address    = trim($_POST['address']    ?? '');
    $neighborhood = trim($_POST['neighborhood'] ?? '');
    $city       = trim($_POST['city']       ?? '');
    $zip        = trim($_POST['zip_code']   ?? '');
    $postChurchId = (int)($_POST['church_id'] ?? $churchId);
    if (in_array($postChurchId, $branchIds, true)) $churchId = $postChurchId;

    if ($honeypot !== '') {
        // Bot: finge sucesso sem gravar nada
        $success = true;
    } elseif ($tooFast) {
        $errors[] = 'Envio muito rápido, tente novamente.';
    } else {
        if ($name === '')  $errors[] = 'Informe seu nome completo.';
        if ($phone === '') $errors[] = 'Informe seu telefone / WhatsApp.';

        // Limite simples: 1 envio por IP a cada 60s
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (empty($errors) && $ip) {
            $recent = $db->prepare("SELECT COUNT(*) FROM member_signups WHERE ip_address = ? AND created_at > (NOW() - INTERVAL 60 SECOND)");
            $recent->execute([$ip]);
            if ($recent->fetchColumn() > 0) $errors[] = 'Você já enviou um cadastro há pouco. Aguarde um minuto e tente novamente.';
        }

        if (empty($errors)) {
            $ins = $db->prepare("
                INSERT INTO member_signups
                  (church_id, name, phone, email, birth_date, gender, marital_status,
                   address, neighborhood, city, zip_code, ip_address)
                VALUES
                  (:church_id,:name,:phone,:email,:birth_date,:gender,:marital,
                   :address,:neighborhood,:city,:zip,:ip)
            ");
            $ins->execute([
                ':church_id' => $churchId,
                ':name'      => $name,
                ':phone'     => $phone,
                ':email'     => $email ?: null,
                ':birth_date'=> $birthDate ?: null,
                ':gender'    => $gender ?: null,
                ':marital'   => $marital ?: null,
                ':address'   => $address ?: null,
                ':neighborhood' => $neighborhood ?: null,
                ':city'      => $city ?: null,
                ':zip'       => $zip ?: null,
                ':ip'        => $ip ?: null,
            ]);
            $success = true;
            unset($_SESSION['cadastro_ts']);
        }
    }
}

// Marca o instante em que o formulário foi renderizado (checado no envio)
if (!$success) {
    $_SESSION['cadastro_ts'] = time();
}

$churchName   = setting('church_name',    APP_NAME, $churchId);
$churchSlogan = setting('church_slogan',  'Sistema de Gestão', $churchId);
$logoUrl      = setting('church_logo_url', '', $churchId);
$primaryColor = setting('primary_color',  '#012a36', $churchId);
$accentColor  = setting('accent_color',   '#1D9E75', $churchId);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cadastro · <?= htmlspecialchars($churchName) ?></title>
  <link rel="stylesheet" href="/public/css/app.css">
  <style>
    body { display:flex; align-items:center; justify-content:center; min-height:100vh; background:var(--content-bg); padding:24px 0; }
    .signup-box { width:100%; max-width:480px; padding:0 16px; }
    .signup-card { background:white; border-radius:14px; box-shadow:0 4px 24px rgba(0,0,0,.09); overflow:hidden; }
    .signup-header { background:<?= htmlspecialchars($primaryColor) ?>; padding:32px; text-align:center; }
    .signup-logo {
      width:64px; height:64px; border-radius:14px;
      display:inline-flex; align-items:center; justify-content:center;
      font-size:28px; color:white; margin-bottom:14px; overflow:hidden;
    }
    .signup-title { font-size:20px; font-weight:600; color:white; margin-bottom:4px; }
    .signup-sub { font-size:13px; color:rgba(255,255,255,.6); }
    .signup-body { padding:28px; }
    .signup-error { background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:10px 14px;font-size:13px;color:#A32D2D;margin-bottom:16px; }
    .signup-success { text-align:center; padding:12px 0 4px; }
    .signup-success .icon { font-size:44px; margin-bottom:12px; }
    .signup-footer { text-align:center;margin-top:20px;font-size:12px;color:var(--text-muted); }
    .btn-signup { background:<?= htmlspecialchars($accentColor) ?>; color:white; width:100%; justify-content:center; padding:11px; font-size:15px; }
    .btn-signup:hover { opacity:.9; }
    .hp-field { position:absolute; left:-9999px; top:-9999px; }
  </style>
</head>
<body>
<div class="signup-box">
  <div class="signup-card">
    <div class="signup-header">
      <div class="signup-logo">
        <?php if ($logoUrl): ?>
          <img src="<?= htmlspecialchars($logoUrl) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:14px">
        <?php else: ?>
          ✝
        <?php endif; ?>
      </div>
      <div class="signup-title"><?= htmlspecialchars($churchName) ?></div>
      <div class="signup-sub">Cadastro de membro / visitante</div>
    </div>
    <div class="signup-body">

      <?php if ($success): ?>
        <div class="signup-success">
          <div class="icon">✅</div>
          <p style="font-size:15px;font-weight:500;margin-bottom:6px">Cadastro recebido!</p>
          <p style="font-size:13px;color:var(--text-muted)">
            Obrigado por se cadastrar. Nossa equipe vai entrar em contato em breve. 🙏
          </p>
        </div>
      <?php else: ?>

        <?php if (!empty($errors)): ?>
          <div class="signup-error">
            <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="POST">
          <input type="hidden" name="church_id" value="<?= $churchId ?>">
          <!-- honeypot: campo que só robôs preenchem -->
          <div class="hp-field" aria-hidden="true">
            <label>Deixe em branco</label>
            <input type="text" name="website" tabindex="-1" autocomplete="off">
          </div>

          <div class="form-group">
            <label class="form-label">Nome completo *</label>
            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" autofocus required>
          </div>
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
              <label class="form-label">Data de nascimento</label>
              <input type="date" name="birth_date" class="form-control" value="<?= htmlspecialchars($_POST['birth_date'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Gênero</label>
              <select name="gender" class="form-control">
                <option value="">Selecione</option>
                <option value="M" <?= ($_POST['gender']??'')==='M'?'selected':''?>>Masculino</option>
                <option value="F" <?= ($_POST['gender']??'')==='F'?'selected':''?>>Feminino</option>
              </select>
            </div>
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

          <button type="submit" class="btn btn-signup" style="margin-top:4px">Enviar cadastro</button>
        </form>
      <?php endif; ?>

    </div>
  </div>
  <div class="signup-footer">
    &copy; <?= date('Y') ?> <?= htmlspecialchars($churchName) ?>
  </div>
</div>
<script>
document.getElementById('zip_code')?.addEventListener('blur', function() {
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
document.getElementById('zip_code')?.addEventListener('input', function() {
  let v = this.value.replace(/\D/g,'').slice(0,8);
  if (v.length > 5) v = v.slice(0,5) + '-' + v.slice(5);
  this.value = v;
});
</script>
</body>
</html>
