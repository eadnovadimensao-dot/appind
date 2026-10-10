// Transposição de cifra no cliente — acordes marcados como <span class="chord">
// dentro de um container [data-chord-sheet], com os controles +/- em
// [data-transpose-up]/[data-transpose-down] e o label do tom em [data-current-key].
// Convenção de acorde: raiz A-G, acidente opcional #/b, sufixo livre (m, 7, maj7,
// sus4, dim, aug, 9…), baixo opcional depois de "/".
(function () {
  const NOTES = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
  const FLAT_TO_SHARP = { Db: 'C#', Eb: 'D#', Gb: 'F#', Ab: 'G#', Bb: 'A#' };

  function normalizeRoot(root) {
    return FLAT_TO_SHARP[root] || root;
  }

  function parseChord(chord) {
    const m = chord.match(/^([A-G])([#b]?)([^/]*)(\/([A-G])([#b]?))?$/);
    if (!m) return null;
    return {
      root: normalizeRoot(m[1] + (m[2] || '')),
      suffix: m[3] || '',
      bass: m[5] ? normalizeRoot(m[5] + (m[6] || '')) : null,
    };
  }

  function shiftNote(note, semitones) {
    const idx = NOTES.indexOf(note);
    if (idx === -1) return note;
    return NOTES[(idx + semitones + 1200) % 12];
  }

  function transposeChord(chord, semitones) {
    if (!semitones) return chord;
    const p = parseChord(chord);
    if (!p) return chord;
    let out = shiftNote(p.root, semitones) + p.suffix;
    if (p.bass) out += '/' + shiftNote(p.bass, semitones);
    return out;
  }

  // Se alguma linha (em qualquer seção) não couber na tela, encolhe TODAS
  // as seções da música junto, no mesmo tamanho — nunca só o trecho
  // problemático, pra não ficar com letra de tamanho diferente de uma
  // parte pra outra. O tamanho final é ditado pela linha mais exigente.
  function fitChordSheets() {
    const sheets = document.querySelectorAll('.chord-sheet');
    if (!sheets.length) return;

    let base = null;
    sheets.forEach(function (el) {
      if (!el.dataset.baseFontSize) el.dataset.baseFontSize = window.getComputedStyle(el).fontSize;
      const b = parseFloat(el.dataset.baseFontSize);
      if (base === null || b < base) base = b;
    });
    if (base === null) return;

    function setAll(size) {
      sheets.forEach(function (el) { el.style.fontSize = size + 'px'; });
    }
    function anyOverflow() {
      let overflow = false;
      sheets.forEach(function (el) { if (el.scrollWidth > el.clientWidth + 1) overflow = true; });
      return overflow;
    }

    let size = base;
    setAll(size);
    let guard = 0;
    while (anyOverflow() && size > 11 && guard < 40) {
      size -= 0.5;
      setAll(size);
      guard++;
    }
  }

  function updateCapoPicker(container) {
    const picker = container.querySelector('[data-capo-picker]');
    if (!picker) return;
    const capo = -parseInt(container.dataset.offset, 10);
    picker.value = (capo >= 0 && capo <= 11) ? String(capo) : '0';
  }

  function applyTranspose(container, semitones) {
    container.querySelectorAll('.chord').forEach(function (el) {
      if (!el.dataset.original) el.dataset.original = el.textContent;
      el.textContent = transposeChord(el.dataset.original, semitones);
    });
    const keyLabel = container.querySelector('[data-current-key]');
    if (keyLabel) {
      if (!keyLabel.dataset.original) keyLabel.dataset.original = keyLabel.textContent;
      keyLabel.textContent = transposeChord(keyLabel.dataset.original, semitones);
    }
    container.dataset.offset = semitones;
    updateCapoPicker(container);
  }

  // Seletor de capotraste: independe de qualquer sugestão cadastrada na
  // edição — existe em toda música, escolhe a casa, aplica na hora.
  // Reaproveita o mesmo mecanismo dos botões +/- de tom (capo na 2ª casa
  // é só transpor pra baixo 2 semitons), nunca aplica nada sozinho.
  document.querySelectorAll('[data-chord-sheet]').forEach(function (container) {
    container.dataset.offset = container.dataset.offset || '0';
    const up = container.querySelector('[data-transpose-up]');
    const down = container.querySelector('[data-transpose-down]');
    const reset = container.querySelector('[data-transpose-reset]');
    const capoPicker = container.querySelector('[data-capo-picker]');
    if (up) up.addEventListener('click', function () {
      applyTranspose(container, parseInt(container.dataset.offset, 10) + 1);
    });
    if (down) down.addEventListener('click', function () {
      applyTranspose(container, parseInt(container.dataset.offset, 10) - 1);
    });
    if (reset) reset.addEventListener('click', function () {
      applyTranspose(container, 0);
    });
    if (capoPicker) capoPicker.addEventListener('change', function () {
      applyTranspose(container, -parseInt(capoPicker.value, 10));
    });
  });

  // ── Tamanho da letra da cifra (A-/A+), lembrado no navegador de cada pessoa ──
  const FONT_KEY = 'nd_chord_font_size';
  const FONT_MIN = 14, FONT_MAX = 30, FONT_STEP = 2, FONT_DEFAULT = 18;

  function getSavedFontSize() {
    try {
      const v = parseInt(localStorage.getItem(FONT_KEY), 10);
      if (!isNaN(v) && v >= FONT_MIN && v <= FONT_MAX) return v;
    } catch (e) { /* storage bloqueado (modo privado etc.) — usa o padrão */ }
    return FONT_DEFAULT;
  }

  // Clique em A+/A- é uma escolha explícita da pessoa — sempre muda de
  // verdade pro tamanho pedido. Se uma linha não couber depois disso, ela
  // rola de lado só ali (CSS overflow-x:auto), não fica mais fácil de
  // ficar "sem efeito" encolhendo escondido de volta.
  function applyFontSize(size) {
    document.querySelectorAll('.chord-sheet').forEach(function (el) {
      el.style.fontSize = size + 'px';
      el.dataset.baseFontSize = size;
    });
    try { localStorage.setItem(FONT_KEY, size); } catch (e) { /* ok ignorar */ }
  }

  let fontSize = getSavedFontSize();
  applyFontSize(fontSize);
  fitChordSheets(); // só na carga da página: encolhe pra caber sem pedir nada à pessoa

  document.querySelectorAll('[data-font-up]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      fontSize = Math.min(FONT_MAX, fontSize + FONT_STEP);
      applyFontSize(fontSize);
    });
  });
  document.querySelectorAll('[data-font-down]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      fontSize = Math.max(FONT_MIN, fontSize - FONT_STEP);
      applyFontSize(fontSize);
    });
  });

  // Reencolhe se a tela girar/mudar de tamanho (ex: rotacionar o celular).
  // Parte sempre do tamanho que a pessoa pediu (fontSize), não do que
  // ficou depois de um encolhimento anterior — senão nunca cresce de
  // volta ao virar pra paisagem, por exemplo.
  let resizeTimer = null;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
      applyFontSize(fontSize);
      fitChordSheets();
    }, 200);
  });
})();
