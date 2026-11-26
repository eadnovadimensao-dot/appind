    </div><!-- .page -->
  </div><!-- .main -->
</div><!-- .app -->

<?php
  // Cache-busting automático: acrescenta ?v=<data de modificação do arquivo>
  // em scripts locais, pra nunca servir uma versão velha do cache do navegador
  // depois de um deploy.
  function asset_v(string $path): string {
      $full = __DIR__ . '/..' . $path;
      $v = @filemtime($full);
      return $path . ($v ? '?v=' . $v : '');
  }
?>
<script src="<?= asset_v('/public/js/app.js') ?>"></script>
<?php if (isset($extraScriptSrc)): foreach ((array)$extraScriptSrc as $src): ?>
  <script src="<?= htmlspecialchars(str_starts_with($src, '/') ? asset_v($src) : $src) ?>"></script>
<?php endforeach; endif; ?>
<?php if (isset($extraJs)): ?>
  <script><?= $extraJs ?></script>
<?php endif; ?>
<script>
// ── Branch switcher ──────────────────────────────────────────
function toggleBranchMenu() {
  const menu = document.getElementById('branch-menu');
  if (menu) menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
}
document.addEventListener('click', function(e) {
  const switcher = document.getElementById('branch-switcher');
  if (switcher && !switcher.contains(e.target)) {
    const menu = document.getElementById('branch-menu');
    if (menu) menu.style.display = 'none';
  }
});

// ── Sidebar mobile ──────────────────────────────────────────
function openSidebar() {
  document.getElementById('sidebar').classList.add('mobile-open');
  document.getElementById('sb-overlay').classList.add('active');
  document.body.style.overflow = 'hidden';
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('mobile-open');
  document.getElementById('sb-overlay').classList.remove('active');
  document.body.style.overflow = '';
}
// Fechar ao clicar em um item do menu (mobile)
if (window.innerWidth <= 768) {
  document.querySelectorAll('.sb-item').forEach(item => {
    item.addEventListener('click', closeSidebar);
  });
}
// Push notifications
const PUSH_VAPID_PUBLIC = '<?= setting('vapid_public_key') ?>';
if ('serviceWorker' in navigator && 'PushManager' in window && PUSH_VAPID_PUBLIC) {
  navigator.serviceWorker.register('/sw.js').then(reg => {
    reg.pushManager.getSubscription().then(sub => {
      if (sub) return; // já ativado, nunca mais incomoda essa pessoa

      if (Notification.permission === 'denied') return; // ela já recusou no navegador, nada a fazer

      if (Notification.permission === 'granted') {
        // Permissão já concedida antes mas sem subscription válida (ex: expirou) — refaz sem perguntar de novo
        subscribePush(reg);
        return;
      }

      // Notification.permission === 'default': nunca decidiu. Mostra o botão discreto
      // e o modal cheio em toda página, até ela decidir (ativar ou negar no navegador).
      const btn = document.getElementById('enable-push-btn');
      if (btn) btn.style.display = 'flex';
      showPushModal();
    });
  }).catch(() => {});
}

function showPushModal() {
  if (document.getElementById('push-modal-overlay')) return;

  const overlay = document.createElement('div');
  overlay.id = 'push-modal-overlay';
  overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px';
  overlay.innerHTML = `
    <div style="background:white;border-radius:14px;max-width:360px;width:100%;padding:24px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,.2)">
      <div style="font-size:40px;margin-bottom:8px">🔔</div>
      <p style="font-weight:600;font-size:16px;margin-bottom:8px;color:var(--text)">Ativar notificações</p>
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:20px;line-height:1.5">
        Ative para receber avisos de escala, comunicados e novidades direto no seu celular/computador, mesmo com o app fechado.
      </p>
      <button id="push-modal-accept" style="width:100%;padding:12px;border-radius:8px;border:none;background:var(--accent);color:white;font-weight:600;font-size:14px;cursor:pointer;margin-bottom:8px">
        Ativar notificações
      </button>
      <button id="push-modal-dismiss" style="width:100%;padding:10px;border-radius:8px;border:none;background:transparent;color:var(--text-muted);font-size:13px;cursor:pointer">
        Agora não
      </button>
    </div>
  `;
  document.body.appendChild(overlay);

  document.getElementById('push-modal-accept').addEventListener('click', function() {
    enablePushNotifications();
  });
  document.getElementById('push-modal-dismiss').addEventListener('click', closePushModal);
}

function closePushModal() {
  const overlay = document.getElementById('push-modal-overlay');
  if (overlay) overlay.remove();
}

function subscribePush(reg) {
  return reg.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: urlBase64ToUint8Array(PUSH_VAPID_PUBLIC)
  }).then(newSub => fetch('/api/push/subscribe.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(newSub)
  })).then(() => {
    const btn = document.getElementById('enable-push-btn');
    if (btn) btn.style.display = 'none';
  }).catch(() => {});
}

function enablePushNotifications() {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
  navigator.serviceWorker.ready.then(reg => {
    Notification.requestPermission().then(perm => {
      closePushModal();
      if (perm !== 'granted') return;
      subscribePush(reg);
    });
  });
}
function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - base64String.length % 4) % 4);
  const base64  = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = window.atob(base64);
  return Uint8Array.from([...rawData].map(c => c.charCodeAt(0)));
}
// Instalar app (PWA) — aparece sozinho quando o navegador permite (ex: Android/Chrome)
let deferredInstallPrompt = null;
window.addEventListener('beforeinstallprompt', function(e) {
  e.preventDefault();
  deferredInstallPrompt = e;
  const btn = document.getElementById('install-app-btn');
  if (btn) btn.style.display = 'flex';
});
function installApp() {
  if (!deferredInstallPrompt) return;
  deferredInstallPrompt.prompt();
  deferredInstallPrompt.userChoice.finally(() => {
    deferredInstallPrompt = null;
    const btn = document.getElementById('install-app-btn');
    if (btn) btn.style.display = 'none';
  });
}
window.addEventListener('appinstalled', function() {
  const btn = document.getElementById('install-app-btn');
  if (btn) btn.style.display = 'none';
});
</script>
</body>
</html>
