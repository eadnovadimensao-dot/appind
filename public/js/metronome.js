// Metrônomo com Web Audio API, usando o padrão de "lookahead scheduler"
// (agenda os cliques com antecedência usando o relógio do AudioContext, não
// setInterval/setTimeout direto) — isso evita que o clique vá desafinando
// do tempo real aos poucos, essencial pra quem está dirigindo a música ao
// vivo. BPM vem pré-carregado da música (data-bpm), mas pode ser ajustado
// na hora sem precisar editar nada. Compasso, volume e tap tempo também
// ajustáveis; contagem de entrada toca o compasso antes de "valer".
(function () {
  const AudioCtx = window.AudioContext || window.webkitAudioContext;
  if (!AudioCtx) return;

  document.querySelectorAll('[data-metronome]').forEach(function (widget) {
    const toggleBtn    = widget.querySelector('[data-metronome-toggle]');
    const pulsesEl      = widget.querySelector('[data-metronome-pulses]');
    const beatNumberEl  = widget.querySelector('[data-metronome-beat-number]');
    const bpmValue      = widget.querySelector('[data-metronome-bpm-value]');
    const bpmUp         = widget.querySelector('[data-metronome-bpm-up]');
    const bpmDown       = widget.querySelector('[data-metronome-bpm-down]');
    const tapBtn        = widget.querySelector('[data-metronome-tap]');
    const doubleBtn     = widget.querySelector('[data-metronome-double]');
    const beatsInput    = widget.querySelector('[data-metronome-beats]');
    const volumeInput   = widget.querySelector('[data-metronome-volume]');
    const countInBox    = widget.querySelector('[data-metronome-countin]');
    if (!toggleBtn) return;

    let bpm = parseInt(widget.dataset.bpm, 10) || 120;
    if (bpmValue) bpmValue.textContent = bpm;

    const LOOKAHEAD_MS = 25;       // de quanto em quanto tempo o agendador acorda
    const SCHEDULE_AHEAD_S = 0.1;  // quanto tempo à frente ele agenda cliques

    let beatsPerMeasure = beatsInput ? (parseInt(beatsInput.value, 10) || 4) : 4;
    let volume = volumeInput ? (parseInt(volumeInput.value, 10) / 100) * 0.6 : 0.35;
    let doubleTime = false;
    let pulseDots = [];

    let audioCtx = null;
    let isPlaying = false;
    let currentBeat = 0;
    let nextNoteTime = 0;
    let timerId = null;
    let countInRemaining = 0;

    // Desenha um "pulso" (bolinha) por tempo do compasso — o primeiro
    // sempre marcado como acento, pra mostrar visualmente onde cai o "1".
    function buildPulses() {
      if (!pulsesEl) return;
      pulsesEl.innerHTML = '';
      pulseDots = [];
      for (let i = 0; i < beatsPerMeasure; i++) {
        const dot = document.createElement('span');
        dot.className = 'metronome-pulse' + (i === 0 ? ' accent' : '');
        pulsesEl.appendChild(dot);
        pulseDots.push(dot);
      }
    }
    buildPulses();

    function scheduleNote(beatNumber, time, isCountIn) {
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.connect(gain);
      gain.connect(audioCtx.destination);
      osc.frequency.value = isCountIn ? 1200 : (beatNumber === 0 ? 1000 : 780);
      gain.gain.setValueAtTime(volume, time);
      gain.gain.exponentialRampToValueAtTime(0.001, time + 0.045);
      osc.start(time);
      osc.stop(time + 0.05);

      const delayMs = Math.max(0, (time - audioCtx.currentTime) * 1000);
      setTimeout(function () {
        if (beatNumberEl) beatNumberEl.textContent = (beatNumber + 1) + ' / ' + beatsPerMeasure;
        const dot = pulseDots[beatNumber];
        if (dot) {
          dot.classList.add('active');
          setTimeout(function () { dot.classList.remove('active'); }, 90);
        }
      }, delayMs);
    }

    function advanceNote() {
      nextNoteTime += 60.0 / (doubleTime ? bpm * 2 : bpm);
      currentBeat = (currentBeat + 1) % beatsPerMeasure;
    }

    function scheduler() {
      while (nextNoteTime < audioCtx.currentTime + SCHEDULE_AHEAD_S) {
        const isCountIn = countInRemaining > 0;
        scheduleNote(currentBeat, nextNoteTime, isCountIn);
        if (isCountIn) {
          countInRemaining--;
          if (countInRemaining === 0) {
            const delayMs = Math.max(0, (nextNoteTime - audioCtx.currentTime) * 1000);
            setTimeout(function () { widget.classList.remove('counting-in'); }, delayMs);
          }
        }
        advanceNote();
      }
      timerId = setTimeout(scheduler, LOOKAHEAD_MS);
    }

    function start() {
      if (isPlaying) return;
      if (!audioCtx) audioCtx = new AudioCtx();
      if (audioCtx.state === 'suspended') audioCtx.resume();
      isPlaying = true;
      currentBeat = 0;
      nextNoteTime = audioCtx.currentTime + 0.05;
      if (countInBox && countInBox.checked) {
        countInRemaining = beatsPerMeasure;
        widget.classList.add('counting-in');
      } else {
        countInRemaining = 0;
      }
      scheduler();
      toggleBtn.textContent = '⏸';
      widget.classList.add('playing');
    }

    function stop() {
      isPlaying = false;
      clearTimeout(timerId);
      toggleBtn.textContent = '▶';
      widget.classList.remove('playing');
      widget.classList.remove('counting-in');
      pulseDots.forEach(function (d) { d.classList.remove('active'); });
      if (beatNumberEl) beatNumberEl.textContent = '';
    }

    toggleBtn.addEventListener('click', function () {
      isPlaying ? stop() : start();
    });

    function setBpm(newBpm) {
      bpm = Math.max(30, Math.min(300, Math.round(newBpm)));
      if (bpmValue) bpmValue.textContent = bpm;
    }
    if (bpmUp) bpmUp.addEventListener('click', function () { setBpm(bpm + 1); });
    if (bpmDown) bpmDown.addEventListener('click', function () { setBpm(bpm - 1); });

    // Compasso (quantos tempos por compasso — aceita qualquer número, cobre
    // 2/4, 3/4, 4/4, 6/8 etc.; o primeiro tempo de cada compasso é acentuado).
    if (beatsInput) {
      beatsInput.addEventListener('change', function () {
        const v = parseInt(beatsInput.value, 10);
        beatsPerMeasure = (v >= 1 && v <= 16) ? v : 4;
        beatsInput.value = beatsPerMeasure;
        currentBeat = 0;
        buildPulses();
      });
    }

    // Volume do clique (0-100 na tela, mapeado pra um ganho razoável).
    if (volumeInput) {
      volumeInput.addEventListener('input', function () {
        volume = (parseInt(volumeInput.value, 10) / 100) * 0.6;
      });
    }

    // Dobra o tempo (clica 2x mais rápido que o BPM salvo) — útil em música
    // muito lenta, onde um clique mais espaçado é difícil de sentir o groove.
    // Não altera o BPM salvo nem a contagem de tempos, só a velocidade do clique.
    if (doubleBtn) {
      doubleBtn.addEventListener('click', function () {
        doubleTime = !doubleTime;
        doubleBtn.classList.toggle('active', doubleTime);
      });
    }

    // Tap tempo: bate no botão no ritmo da música, calcula o BPM pela média
    // dos últimos intervalos. Pausa de mais de 2s entre toques reinicia a
    // contagem (começando um tempo novo do zero).
    if (tapBtn) {
      let tapTimes = [];
      tapBtn.addEventListener('click', function () {
        const now = Date.now();
        if (tapTimes.length && now - tapTimes[tapTimes.length - 1] > 2000) {
          tapTimes = [];
        }
        tapTimes.push(now);
        if (tapTimes.length > 6) tapTimes.shift(); // só os últimos toques contam
        if (tapTimes.length >= 2) {
          const intervals = [];
          for (let i = 1; i < tapTimes.length; i++) intervals.push(tapTimes[i] - tapTimes[i - 1]);
          const avgMs = intervals.reduce(function (a, b) { return a + b; }, 0) / intervals.length;
          setBpm(60000 / avgMs);
        }
      });
    }
  });
})();
