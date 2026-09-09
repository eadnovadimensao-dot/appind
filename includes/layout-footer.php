    </div><!-- .page -->
  </div><!-- .main -->
</div><!-- .app -->

<script src="/public/js/app.js"></script>
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
if ('serviceWorker' in navigator && 'PushManager' in window) {
  const VAPID_PUBLIC = '<?= setting('vapid_public_key') ?>';
  navigator.serviceWorker.register('/sw.js').then(reg => {
    if (!VAPID_PUBLIC) return;
    reg.pushManager.getSubscription().then(sub => {
      if (sub) return;
      const btn = document.getElementById('enable-push-btn');
      if (btn) btn.style.display = 'flex';
    });
  }).catch(() => {});
}
function enablePushNotifications() {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
  const VAPID_PUBLIC = '<?= setting('vapid_public_key') ?>';
  navigator.serviceWorker.ready.then(reg => {
    Notification.requestPermission().then(perm => {
      if (perm !== 'granted') return;
      reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC)
      }).then(newSub => {
        fetch('/api/push/subscribe.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(newSub)
        }).then(() => {
          const btn = document.getElementById('enable-push-btn');
          if (btn) { btn.innerHTML = '🔔 Ativado!'; setTimeout(() => btn.style.display = 'none', 2000); }
        });
      }).catch(() => {});
    });
  });
}
function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - base64String.length % 4) % 4);
  const base64  = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = window.atob(base64);
  return Uint8Array.from([...rawData].map(c => c.charCodeAt(0)));
}
</script>
</body>
</html>
