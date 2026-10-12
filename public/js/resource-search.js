// Filtra a lista de materiais ao vivo por título/tema/mensagem central —
// sem recarregar a página. Tira acento na comparação (NFD + remove marks)
// pra "comunhao" encontrar "comunhão" e vice-versa.
(function () {
  const input = document.getElementById('resource-search');
  if (!input) return;

  function normalize(str) {
    return (str || '')
      .toLowerCase()
      .normalize('NFD')
      .replace(/[̀-ͯ]/g, '');
  }

  const items = Array.from(document.querySelectorAll('.resource-item[data-search]'));
  const categories = Array.from(document.querySelectorAll('.resource-category'));
  const emptyMsg = document.getElementById('resource-search-empty');

  function applyFilter() {
    const query = normalize(input.value.trim());
    let anyVisible = false;

    items.forEach(function (item) {
      const match = !query || normalize(item.dataset.search).includes(query);
      item.classList.toggle('rs-hidden', !match);
      if (match) anyVisible = true;
    });

    categories.forEach(function (cat) {
      const hasVisible = cat.querySelector('.resource-item:not(.rs-hidden)');
      cat.classList.toggle('rs-hidden', !hasVisible);
    });

    if (emptyMsg) emptyMsg.style.display = anyVisible ? 'none' : 'block';
  }

  input.addEventListener('input', applyFilter);

  document.querySelectorAll('[data-theme-chip]').forEach(function (chip) {
    chip.addEventListener('click', function () {
      input.value = chip.dataset.themeChip;
      applyFilter();
      input.scrollIntoView({ behavior: 'smooth', block: 'start' });
      input.focus();
    });
  });
})();
