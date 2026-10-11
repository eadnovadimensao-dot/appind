// Arrastar-e-soltar pra reordenar o repertório — funciona com toque
// (celular) e mouse via Pointer Events (drag-and-drop nativo do HTML5
// não funciona bem em touch na maioria dos navegadores mobile).
// Arrasta pelo "alça" (⠿), solta na posição, salva a ordem inteira de
// uma vez em song_reorder_bulk.php.
(function () {
  const list = document.getElementById('repertoire-list');
  if (!list) return;

  const activityId = list.dataset.activityId;
  let dragging = null;
  let placeholder = null;
  let startY = 0;
  let rowStartTop = 0;

  function rowsInOrder() {
    return Array.from(list.querySelectorAll('[data-song-row]'));
  }

  function onPointerDown(e) {
    const handle = e.target.closest('[data-drag-handle]');
    if (!handle) return;
    const row = handle.closest('[data-song-row]');
    if (!row) return;

    e.preventDefault();
    dragging = row;
    startY = e.clientY;
    rowStartTop = row.getBoundingClientRect().top;

    placeholder = document.createElement('div');
    placeholder.className = 'song-row-placeholder';
    placeholder.style.height = row.offsetHeight + 'px';
    row.parentNode.insertBefore(placeholder, row.nextSibling);

    row.classList.add('dragging');
    row.style.position = 'fixed';
    row.style.left = row.getBoundingClientRect().left + 'px';
    row.style.width = row.getBoundingClientRect().width + 'px';
    row.style.top = rowStartTop + 'px';
    row.style.zIndex = '50';

    document.addEventListener('pointermove', onPointerMove);
    document.addEventListener('pointerup', onPointerUp);
    document.addEventListener('pointercancel', onPointerUp);
  }

  function onPointerMove(e) {
    if (!dragging) return;
    const dy = e.clientY - startY;
    dragging.style.top = (rowStartTop + dy) + 'px';

    // Acha a primeira linha (na ordem atual) cujo meio já ficou abaixo do
    // meio da linha arrastada, e põe o placeholder logo antes dela. Se
    // nenhuma, a linha arrastada já passou de todas — placeholder vai pro fim.
    const draggingMid = dragging.getBoundingClientRect().top + dragging.offsetHeight / 2;
    const others = rowsInOrder().filter(function (r) { return r !== dragging; });

    let target = null;
    for (const other of others) {
      const rect = other.getBoundingClientRect();
      const mid = rect.top + rect.height / 2;
      if (draggingMid < mid) { target = other; break; }
    }

    if (target) {
      if (target.previousElementSibling !== placeholder) list.insertBefore(placeholder, target);
    } else if (list.lastElementChild !== placeholder) {
      list.appendChild(placeholder);
    }
  }

  function onPointerUp() {
    if (!dragging) return;
    document.removeEventListener('pointermove', onPointerMove);
    document.removeEventListener('pointerup', onPointerUp);
    document.removeEventListener('pointercancel', onPointerUp);

    placeholder.parentNode.insertBefore(dragging, placeholder);
    placeholder.remove();
    dragging.classList.remove('dragging');
    dragging.style.position = '';
    dragging.style.left = '';
    dragging.style.width = '';
    dragging.style.top = '';
    dragging.style.zIndex = '';
    dragging = null;
    placeholder = null;

    renumber();
    saveOrder();
  }

  function renumber() {
    rowsInOrder().forEach(function (row, i) {
      const num = row.querySelector('[data-song-number]');
      if (num) num.textContent = (i + 1) + '.';
    });
  }

  function saveOrder() {
    const ids = rowsInOrder().map(function (r) { return r.dataset.songId; });
    fetch('/pages/ministries/song_reorder_bulk.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'activity_id=' + encodeURIComponent(activityId) + '&order=' + encodeURIComponent(ids.join(',')),
    }).catch(function () { /* se a rede falhar, a ordem visual fica, mas não foi salva — tenta de novo ao arrastar outra vez */ });
  }

  list.addEventListener('pointerdown', onPointerDown);
})();
