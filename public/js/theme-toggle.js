// Alterna modo escuro só nas telas de Modo Ensaio/Culto e cifra isolada —
// útil no palco, tela escura chama menos atenção. Lembra a escolha no
// aparelho de cada pessoa (mesmo esquema do tamanho de letra/velocidade).
(function () {
  const KEY = 'nd_cifra_theme';
  const btn = document.getElementById('theme-toggle');
  if (!btn) return;

  function apply(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    btn.textContent = theme === 'dark' ? '☀️' : '🌙';
    btn.title = theme === 'dark' ? 'Modo claro' : 'Modo escuro';
  }

  let saved = 'light';
  try { saved = localStorage.getItem(KEY) || 'light'; } catch (e) { /* ok ignorar */ }
  apply(saved);

  btn.addEventListener('click', function () {
    const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    apply(next);
    try { localStorage.setItem(KEY, next); } catch (e) { /* ok ignorar */ }
  });
})();
