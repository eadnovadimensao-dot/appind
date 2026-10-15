// Rolagem automática da tela — pra ler a cifra sem precisar tocar no
// celular com a mão ocupada no instrumento. Velocidade lembrada no
// navegador de cada pessoa. Só para de verdade quando clica em Pausar
// (nunca sozinha ao tocar na tela) ou ao chegar no fim do conteúdo —
// mas cede o controle pro dedo durante um toque (senão a rolagem
// automática briga com o gesto de arrastar) e retoma sozinha ao soltar.
(function () {
  const bar = document.getElementById('autoscroll-bar');
  if (!bar) return;

  // O layout do app geralmente rola dentro de um container interno
  // (.page), não na janela do navegador — mas isso pode variar por
  // navegador/dispositivo. Importante: NUNCA testar empurrando o
  // scrollTop de verdade pra ver o que se move — o CSS tem
  // "html { scroll-behavior: smooth }", então mexer no scroll do
  // <html>/<body> (document.scrollingElement/documentElement) dispara
  // uma animação suave que briga com o "desfaz o teste" logo em
  // seguida, piscando a tela e cancelando o toque no meio do caminho.
  // Em vez disso, só lê (sem escrever nada): exige que o elemento
  // realmente tenha overflow:auto/scroll configurado E conteúdo
  // sobrando — sem esse primeiro filtro, documentElement/body podem
  // dar falso positivo de scrollHeight > clientHeight por causa de
  // barra de endereço dinâmica/zoom, mesmo não sendo quem rola de fato.
  function pickScrollElement() {
    const candidates = [
      document.querySelector('.page'),
      document.scrollingElement,
      document.documentElement,
      document.body,
    ].filter(Boolean);
    for (let i = 0; i < candidates.length; i++) {
      const el = candidates[i];
      const style = getComputedStyle(el);
      const isScrollable = el === document.scrollingElement
        || style.overflowY === 'auto' || style.overflowY === 'scroll';
      if (isScrollable && el.scrollHeight > el.clientHeight + 10) return el;
    }
    return candidates[0] || document.documentElement;
  }
  let scrollEl = pickScrollElement();

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
  let yielding = false; // true durante um toque na tela — cede o controle pro dedo

  function updateSpeedLabel() {
    if (speedLabel) speedLabel.textContent = speed;
  }
  updateSpeedLabel();

  function pxPerMs() {
    return speed * 0.012; // escala ajustada à mão: 1 = bem devagar, 10 = rápido
  }

  function step(timestamp) {
    if (!running || yielding) return;
    if (lastTime !== null) {
      scrollEl.scrollTop += pxPerMs() * (timestamp - lastTime);
      const atBottom = scrollEl.scrollTop + scrollEl.clientHeight >= scrollEl.scrollHeight - 2;
      if (atBottom) { stop(); return; }
    }
    lastTime = timestamp;
    rafId = requestAnimationFrame(step);
  }

  function start() {
    scrollEl = pickScrollElement(); // reavalia na hora, layout pode ter mudado
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

  // Escrever scrollTop a cada frame briga com o gesto nativo de arrastar
  // o dedo (o toque "trava"). Em vez de parar a rolagem automática (só o
  // botão Pausar deve fazer isso), cede o controle durante o toque e
  // retoma sozinho de onde a pessoa soltou, sem precisar clicar em nada.
  function onTouchStart() {
    if (running) {
      yielding = true;
      if (rafId) cancelAnimationFrame(rafId);
    }
  }
  function onTouchEnd() {
    if (running && yielding) {
      yielding = false;
      lastTime = null; // evita um pulo grande — recomeça a contagem de tempo
      rafId = requestAnimationFrame(step);
    }
  }
  window.addEventListener('touchstart', onTouchStart, { passive: true });
  window.addEventListener('touchend', onTouchEnd, { passive: true });
  window.addEventListener('touchcancel', onTouchEnd, { passive: true });
})();
