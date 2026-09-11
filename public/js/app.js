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

// ── Máscara de telefone/WhatsApp — (DD) 9XXXX-XXXX ou (DD) XXXX-XXXX ──────
function applyPhoneMask(input) {
  if (!input) return;
  input.addEventListener('input', function () {
    let v = this.value.replace(/\D/g, '').slice(0, 11);
    if (v.length > 10) {
      v = v.replace(/^(\d{2})(\d{5})(\d{0,4}).*/, '($1) $2-$3');
    } else if (v.length > 6) {
      v = v.replace(/^(\d{2})(\d{4})(\d{0,4}).*/, '($1) $2-$3');
    } else if (v.length > 2) {
      v = v.replace(/^(\d{2})(\d{0,5})/, '($1) $2');
    } else if (v.length > 0) {
      v = v.replace(/^(\d{0,2})/, '($1');
    }
    this.value = v;
  });
}
document.querySelectorAll('input[name="phone"]').forEach(applyPhoneMask);
