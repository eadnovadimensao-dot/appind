// Rolagem automática da tela — pra ler a cifra sem precisar tocar no
// celular com a mão ocupada no instrumento. Velocidade lembrada no
// navegador de cada pessoa. Pausa sozinha se a pessoa tocar na tela
// (scroll manual via toque/mouse) ou ao chegar no fim da página.
(function () {
  const bar = document.getElementById('autoscroll-bar');
  if (!bar) return;

  const toggleBtn = document.getElementById('autoscroll-toggle');
  const slowerBtn = document.getElementById('autoscroll-slower');
  const fasterBtn = document.getElementById('autoscroll-faster');
  const speedLabel = document.getElementById('autoscroll-speed-label');

  const SPEED_KEY = 'nd_autoscroll_speed';
  const SPEED_MIN = 1, SPEED_MAX = 10, SPEED_DEFAULT = 4;

  let speed = SPEED_DEFAULT;
  try {
    const saved = parseInt(localStorage.getItem(SPEED_KEY), 10);
    if (!isNaN(saved) && saved >= SPEED_MIN && saved <= SPEED_MAX) speed = saved;
  } catch (e) { /* ok ignorar */ }

  let running = false;
  let rafId = null;
  let lastTime = null;

  function updateSpeedLabel() {
    if (speedLabel) speedLabel.textContent = speed;
  }
  updateSpeedLabel();

  function pxPerMs() {
    return speed * 0.012; // escala ajustada à mão: 1 = bem devagar, 10 = rápido
  }

  function step(timestamp) {
    if (!running) return;
    if (lastTime !== null) {
      window.scrollBy(0, pxPerMs() * (timestamp - lastTime));
      const atBottom = window.innerHeight + window.scrollY >= document.body.scrollHeight - 2;
      if (atBottom) { stop(); return; }
    }
    lastTime = timestamp;
    rafId = requestAnimationFrame(step);
  }

  function start() {
    running = true;
    lastTime = null;
    toggleBtn.textContent = '⏸ Pausar';
    toggleBtn.classList.add('active');
    rafId = requestAnimationFrame(step);
  }

  function stop() {
    running = false;
    if (rafId) cancelAnimationFrame(rafId);
    toggleBtn.textContent = '▶ Rolar sozinho';
    toggleBtn.classList.remove('active');
  }

  toggleBtn.addEventListener('click', function () {
    running ? stop() : start();
  });
  slowerBtn.addEventListener('click', function () {
    speed = Math.max(SPEED_MIN, speed - 1);
    updateSpeedLabel();
    try { localStorage.setItem(SPEED_KEY, speed); } catch (e) { /* ok ignorar */ }
  });
  fasterBtn.addEventListener('click', function () {
    speed = Math.min(SPEED_MAX, speed + 1);
    updateSpeedLabel();
    try { localStorage.setItem(SPEED_KEY, speed); } catch (e) { /* ok ignorar */ }
  });

  // Toque/arraste manual na tela interrompe a rolagem automática (scroll
  // programático via JS não dispara touchmove, só o dedo de verdade dispara).
  window.addEventListener('touchmove', function () { if (running) stop(); }, { passive: true });
  window.addEventListener('wheel', function () { if (running) stop(); }, { passive: true });
})();
