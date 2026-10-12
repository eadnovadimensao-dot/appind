// Metrônomo com Web Audio API, usando o padrão de "lookahead scheduler"
// (agenda os cliques com antecedência usando o relógio do AudioContext, não
// setInterval/setTimeout direto) — isso evita que o clique vá desafinando
// do tempo real aos poucos, essencial pra quem está dirigindo a música ao
// vivo. BPM vem pré-carregado da música (data-bpm), mas pode ser ajustado
// na hora sem precisar editar nada.
(function () {
  const AudioCtx = window.AudioContext || window.webkitAudioContext;
  if (!AudioCtx) return;

  document.querySelectorAll('[data-metronome]').forEach(function (widget) {
    const toggleBtn = widget.querySelector('[data-metronome-toggle]');
    const beatDot   = widget.querySelector('[data-metronome-beat]');
    const bpmValue  = widget.querySelector('[data-metronome-bpm-value]');
    const bpmUp     = widget.querySelector('[data-metronome-bpm-up]');
    const bpmDown   = widget.querySelector('[data-metronome-bpm-down]');
    if (!toggleBtn) return;

    let bpm = parseInt(widget.dataset.bpm, 10) || 120;
    if (bpmValue) bpmValue.textContent = bpm;

    const BEATS_PER_MEASURE = 4;
    const LOOKAHEAD_MS = 25;       // de quanto em quanto tempo o agendador acorda
    const SCHEDULE_AHEAD_S = 0.1;  // quanto tempo à frente ele agenda cliques

    let audioCtx = null;
    let isPlaying = false;
    let currentBeat = 0;
    let nextNoteTime = 0;
    let timerId = null;

    function scheduleNote(beatNumber, time) {
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.connect(gain);
      gain.connect(audioCtx.destination);
      osc.frequency.value = beatNumber === 0 ? 1000 : 780; // acento no 1º tempo do compasso
      gain.gain.setValueAtTime(0.35, time);
      gain.gain.exponentialRampToValueAtTime(0.001, time + 0.045);
      osc.start(time);
      osc.stop(time + 0.05);

      if (beatDot) {
        const delayMs = Math.max(0, (time - audioCtx.currentTime) * 1000);
        setTimeout(function () {
          beatDot.classList.add('active');
          setTimeout(function () { beatDot.classList.remove('active'); }, 90);
        }, delayMs);
      }
    }

    function advanceNote() {
      nextNoteTime += 60.0 / bpm;
      currentBeat = (currentBeat + 1) % BEATS_PER_MEASURE;
    }

    function scheduler() {
      while (nextNoteTime < audioCtx.currentTime + SCHEDULE_AHEAD_S) {
        scheduleNote(currentBeat, nextNoteTime);
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
      scheduler();
      toggleBtn.textContent = '⏸';
      widget.classList.add('playing');
    }

    function stop() {
      isPlaying = false;
      clearTimeout(timerId);
      toggleBtn.textContent = '▶';
      widget.classList.remove('playing');
      if (beatDot) beatDot.classList.remove('active');
    }

    toggleBtn.addEventListener('click', function () {
      isPlaying ? stop() : start();
    });

    function setBpm(newBpm) {
      bpm = Math.max(30, Math.min(300, newBpm));
      if (bpmValue) bpmValue.textContent = bpm;
    }
    if (bpmUp) bpmUp.addEventListener('click', function () { setBpm(bpm + 1); });
    if (bpmDown) bpmDown.addEventListener('click', function () { setBpm(bpm - 1); });
  });
})();
