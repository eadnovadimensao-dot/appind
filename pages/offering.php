<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pix.php';
auth_check();

$churchId = current_church_id();
$pixKey   = setting('pix_key', '', $churchId);
$pixType  = setting('pix_key_type', '', $churchId);
$pixName  = setting('pix_receiver_name', '', $churchId);
$pixCity  = setting('pix_receiver_city', '', $churchId);
$churchName = setting('church_name', 'Igreja', $churchId);

$typeLabels = ['cpf'=>'CPF','cnpj'=>'CNPJ','email'=>'E-mail','phone'=>'Telefone','random'=>'Chave aleatória'];

$pageTitle  = 'Oferta e dízimo';
$activePage = 'offering';
require_once __DIR__ . '/../includes/layout.php';
?>

<?php if (!$pixKey): ?>
  <div class="card">
    <p style="font-size:13px;color:var(--text-muted)">
      A chave Pix da igreja ainda não foi configurada.
      <?php if (auth_can('manage_finance') || auth_role() === 'admin' || auth_role() === 'supermaster'): ?>
        <a href="/pages/settings.php">Configure em Configurações</a>.
      <?php else: ?>
        Fale com a liderança.
      <?php endif; ?>
    </p>
  </div>
<?php else: ?>

<div style="max-width:440px;margin:0 auto">
  <div class="card" style="text-align:center">
    <p class="card-title" style="text-align:left">🙏 Oferta e dízimo</p>
    <p style="font-size:13px;color:var(--text-muted);margin-bottom:20px;text-align:left">
      Escaneie o QR code com o app do seu banco, ou copie o código Pix abaixo.
    </p>

    <div class="form-group" style="text-align:left;margin-bottom:16px">
      <label class="form-label">Valor (opcional)</label>
      <input type="text" id="pix-amount" class="form-control" placeholder="Ex: 50,00 — deixe em branco pra digitar no banco" inputmode="decimal">
    </div>

    <div id="pix-qr" style="display:flex;justify-content:center;margin-bottom:16px;padding:16px;background:#fff;border-radius:10px"></div>

    <div style="display:flex;gap:8px;margin-bottom:8px">
      <input type="text" id="pix-code" class="form-control" readonly style="font-size:11px">
      <button type="button" id="pix-copy-btn" class="btn btn-primary" style="flex-shrink:0">Copiar</button>
    </div>

    <p style="font-size:11px;color:var(--text-muted);margin-top:16px">
      <?= htmlspecialchars($pixName ?: $churchName) ?> · <?= $typeLabels[$pixType] ?? 'Chave Pix' ?>
      <br>Este código não confirma o pagamento automaticamente no sistema — a igreja confere pelo extrato do banco.
    </p>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
<?php
$extraJs = <<<JS
let qrTimer = null;

function renderPix() {
  const amountRaw = document.getElementById('pix-amount').value.trim();
  const amount = amountRaw ? parseFloat(amountRaw.replace(/\\./g,'').replace(',', '.')) : null;

  fetch('/pages/offering_code.php?amount=' + encodeURIComponent(amount || ''))
    .then(r => r.json())
    .then(data => {
      if (!data.code) return;
      document.getElementById('pix-code').value = data.code;
      const qrBox = document.getElementById('pix-qr');
      qrBox.innerHTML = '';
      QRCode.toCanvas(data.code, { width: 220, margin: 1 }, function (err, canvas) {
        if (!err) qrBox.appendChild(canvas);
      });
    });
}

document.getElementById('pix-amount').addEventListener('input', function() {
  clearTimeout(qrTimer);
  qrTimer = setTimeout(renderPix, 500);
});

document.getElementById('pix-copy-btn').addEventListener('click', function() {
  const input = document.getElementById('pix-code');
  navigator.clipboard.writeText(input.value).then(() => {
    this.textContent = 'Copiado!';
    setTimeout(() => this.textContent = 'Copiar', 1500);
  });
});

renderPix();
JS;
require_once __DIR__ . '/../includes/layout-footer.php';
?>
<?php endif; ?>
