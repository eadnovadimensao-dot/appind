// ── Sidebar toggle ──────────────────────────────────────────────
const sidebar  = document.getElementById('sidebar');
const sbToggle = document.getElementById('sb-toggle');

const COLLAPSED_KEY = 'sb_collapsed';
const isMobile = () => window.innerWidth <= 900;

// No mobile: remover collapsed e garantir sidebar escondida
if (isMobile()) {
  sidebar.classList.remove('collapsed');
  localStorage.removeItem(COLLAPSED_KEY);
} else if (localStorage.getItem(COLLAPSED_KEY) === '1') {
  sidebar.classList.add('collapsed');
  if (sbToggle) sbToggle.textContent = '›';
}

if (sbToggle) {
  sbToggle.addEventListener('click', () => {
    if (isMobile()) return;
    const collapsed = sidebar.classList.toggle('collapsed');
    localStorage.setItem(COLLAPSED_KEY, collapsed ? '1' : '0');
  });
}

// ── Flash messages ───────────────────────────────────────────────
document.querySelectorAll('.flash').forEach(el => {
  setTimeout(() => el.style.opacity = '0', 3500);
  setTimeout(() => el.remove(), 4000);
});

// ── Confirm delete ───────────────────────────────────────────────
document.querySelectorAll('[data-confirm]').forEach(el => {
  el.addEventListener('click', e => {
    if (!confirm(el.dataset.confirm)) e.preventDefault();
  });
});
