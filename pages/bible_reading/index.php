<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/bible_reading.php';
auth_check();

$db       = db();
$memberId = auth_member_id();
if (!$memberId) { header('Location: /dashboard.php'); exit; }

$books      = bible_reading_books($db);
$progress   = bible_reading_progress_set($db, $memberId);
$total      = bible_reading_total_chapters($db);
$readCount  = count($progress);
$percent    = $total ? round($readCount / $total * 100) : 0;
$suggestion = bible_reading_daily_suggestion($db, $progress);

/** Mesma estrutura usada no JS (ver renderSuggestionBox) pra ficar igual depois de marcar/desmarcar. */
function bible_reading_suggestion_box_html(array $suggestion): string {
    if (!$suggestion) {
        return '<div style="padding:12px 14px;border:1px solid var(--accent);border-radius:8px;font-size:13px;color:var(--accent)">'
             . '🎉 Você já marcou a Bíblia inteira como lida!</div>';
    }
    $html = '<p style="font-size:13px;font-weight:600;margin-bottom:10px">📖 Sugestão de hoje</p>';
    foreach ($suggestion as $s) {
        $html .= '<div onclick="openChapterModal(\'' . $s['book_abbrev'] . '\', ' . (int)$s['chapter'] . ')" '
                . 'style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border:1px solid var(--border);border-radius:8px;margin-bottom:8px;cursor:pointer">'
                . '<span style="font-size:13px">📖 ' . htmlspecialchars($s['book_name']) . ' ' . (int)$s['chapter'] . '</span>'
                . '<span style="font-size:12px;color:var(--accent);font-weight:600">Ler →</span>'
                . '</div>';
    }
    return $html;
}

$pageTitle  = 'Leitura Bíblica';
$activePage = 'bible_reading';
require_once __DIR__ . '/../../includes/layout.php';
?>

<div class="card" style="margin-bottom:16px">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:12px">
    <div>
      <div style="font-size:24px;font-weight:700"><span id="total-read"><?= $readCount ?></span> <span style="font-size:14px;font-weight:400;color:var(--text-muted)">/ <?= $total ?> capítulos</span></div>
      <div style="font-size:13px;color:var(--text-muted)">Sua Bíblia inteira, no seu ritmo. Sem data nem prazo.</div>
    </div>
    <div style="text-align:right">
      <div style="font-size:28px;font-weight:700;color:var(--accent)"><span id="total-percent"><?= $percent ?></span>%</div>
      <a href="/pages/bible_reading/ranking.php" style="font-size:12px;color:var(--accent);font-weight:600">🏆 Ver ranking</a>
    </div>
  </div>
  <div style="height:8px;border-radius:4px;background:var(--border);overflow:hidden">
    <div id="progress-bar" style="height:100%;border-radius:4px;background:var(--accent);width:<?= $percent ?>%"></div>
  </div>

  <div id="suggestion-box" style="margin-top:16px"><?= bible_reading_suggestion_box_html($suggestion) ?></div>
</div>

<?php
$testaments = ['AT' => ['label' => 'Antigo Testamento', 'books' => []], 'NT' => ['label' => 'Novo Testamento', 'books' => []]];
foreach ($books as $b) {
    $t = (int)$b['book_order'] < 39 ? 'AT' : 'NT';
    $testaments[$t]['books'][] = $b;
}
$suggestedBooks = array_column($suggestion, 'book_abbrev');
?>

<p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">💡 Clique em qualquer capítulo pra ler o texto, mesmo fora da sugestão de hoje.</p>

<?php foreach ($testaments as $t): ?>
  <div class="card" style="margin-bottom:16px">
    <p class="card-title"><?= $t['label'] ?></p>
    <?php foreach ($t['books'] as $b):
        $bookRead  = 0;
        for ($c = 1; $c <= (int)$b['chapters']; $c++) if (isset($progress[$b['book_abbrev'] . ':' . $c])) $bookRead++;
        $bookDone  = $bookRead === (int)$b['chapters'];
        $isCurrent = in_array($b['book_abbrev'], $suggestedBooks, true);
    ?>
      <details <?= $isCurrent ? 'open' : '' ?> style="border-bottom:1px solid var(--border)">
        <summary style="padding:10px 0;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:13px">
          <span><span id="book-icon-<?= $b['book_abbrev'] ?>"><?= $bookDone ? '✅' : '📖' ?></span> <?= htmlspecialchars($b['book_name']) ?></span>
          <span id="book-count-<?= $b['book_abbrev'] ?>" style="color:var(--text-muted);font-size:12px"><?= $bookRead ?>/<?= $b['chapters'] ?></span>
        </summary>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(38px,1fr));gap:6px;padding:4px 0 14px" data-book-total="<?= $b['chapters'] ?>" id="book-grid-<?= $b['book_abbrev'] ?>">
          <?php for ($c = 1; $c <= (int)$b['chapters']; $c++):
              $isRead = isset($progress[$b['book_abbrev'] . ':' . $c]);
          ?>
            <button type="button"
                    class="chapter-cell <?= $isRead ? 'done' : '' ?>"
                    data-book="<?= $b['book_abbrev'] ?>" data-chapter="<?= $c ?>"
                    onclick="openChapterModal('<?= $b['book_abbrev'] ?>', <?= $c ?>)"
                    style="height:34px;border-radius:6px;border:1px solid var(--border);background:<?= $isRead ? 'var(--accent)' : 'white' ?>;color:<?= $isRead ? 'white' : 'var(--text)' ?>;font-size:12px;cursor:pointer">
              <?= $c ?>
            </button>
          <?php endfor; ?>
        </div>
      </details>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>

<!-- Leitor de capítulo: abre pra qualquer capítulo, lido ou não -->
<div id="chapter-modal-overlay" onclick="if (event.target === this) closeChapterModal()"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;padding:16px">
  <div style="background:white;border-radius:10px;max-width:620px;width:100%;max-height:85vh;display:flex;flex-direction:column;overflow:hidden">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 18px;border-bottom:1px solid var(--border)">
      <strong id="modal-title" style="font-size:15px"></strong>
      <button type="button" onclick="closeChapterModal()" style="border:none;background:none;font-size:22px;line-height:1;cursor:pointer;color:var(--text-muted)">×</button>
    </div>
    <div id="modal-body" style="padding:16px 18px;overflow-y:auto;font-size:16px;line-height:1.7;color:var(--text);flex:1">
      <div id="modal-verses"></div>
      <div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--border)">
        <p style="font-size:13px;font-weight:600;margin-bottom:8px">💬 Comentários</p>
        <div id="modal-comments" style="margin-bottom:10px"></div>
        <div style="display:flex;gap:8px">
          <input type="text" id="modal-comment-input" placeholder="Compartilhe o que esse capítulo falou com você…" style="flex:1;font-size:13px;padding:8px 10px;border:1px solid var(--border);border-radius:6px">
          <button type="button" class="btn btn-secondary" style="font-size:12px" onclick="postChapterComment()">Comentar</button>
        </div>
      </div>
    </div>
    <div style="padding:12px 18px;border-top:1px solid var(--border);display:flex;justify-content:flex-end">
      <button type="button" id="modal-toggle-btn" class="btn btn-primary" style="font-size:12px" onclick="toggleFromModal()"></button>
    </div>
  </div>
</div>

<?php
$extraJs = <<<JS
function escapeHtml(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function renderSuggestionBox(suggestion) {
  if (!suggestion || !suggestion.length) {
    return '<div style="padding:12px 14px;border:1px solid var(--accent);border-radius:8px;font-size:13px;color:var(--accent)">'
         + '🎉 Você já marcou a Bíblia inteira como lida!</div>';
  }
  let html = '<p style="font-size:13px;font-weight:600;margin-bottom:10px">📖 Sugestão de hoje</p>';
  suggestion.forEach(s => {
    html += '<div onclick="openChapterModal(\\'' + s.book_abbrev + '\\', ' + s.chapter + ')" '
          + 'style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border:1px solid var(--border);border-radius:8px;margin-bottom:8px;cursor:pointer">'
          + '<span style="font-size:13px">📖 ' + escapeHtml(s.book_name) + ' ' + s.chapter + '</span>'
          + '<span style="font-size:12px;color:var(--accent);font-weight:600">Ler →</span>'
          + '</div>';
  });
  return html;
}

let modalState = { book: null, chapter: null };

function openChapterModal(book, chapter) {
  modalState = { book: book, chapter: chapter };
  document.getElementById('modal-title').textContent = 'Carregando…';
  document.getElementById('modal-verses').innerHTML = '';
  document.getElementById('modal-comments').innerHTML = '';
  document.getElementById('modal-comment-input').value = '';
  document.getElementById('chapter-modal-overlay').style.display = 'flex';
  fetch('/pages/bible_reading/chapter_text.php?book_abbrev=' + encodeURIComponent(book) + '&chapter=' + chapter)
    .then(r => r.json())
    .then(data => {
      if (!data.ok) { document.getElementById('modal-title').textContent = 'Não encontrado'; return; }
      document.getElementById('modal-title').textContent = data.book_name + ' ' + data.chapter;
      let html = '';
      data.verses.forEach(v => {
        html += '<sup style="color:var(--accent);font-weight:700">' + v.verse + '</sup> ' + escapeHtml(v.text) + ' ';
      });
      document.getElementById('modal-verses').innerHTML = html;
      updateModalButton(data.is_read);
    });
  loadChapterComments();
}

function renderComments(comments) {
  if (!comments || !comments.length) {
    document.getElementById('modal-comments').innerHTML = '<p style="font-size:12px;color:var(--text-muted)">Nenhum comentário ainda. Seja o primeiro.</p>';
    return;
  }
  let html = '';
  comments.forEach(c => {
    html += '<div style="padding:8px 10px;border:1px solid var(--border);border-radius:8px;margin-bottom:6px">'
          + '<div style="font-size:12px;font-weight:600">' + escapeHtml(c.member_name) + '</div>'
          + '<div style="font-size:13px;margin-top:2px">' + escapeHtml(c.comment) + '</div>'
          + '</div>';
  });
  document.getElementById('modal-comments').innerHTML = html;
}

function loadChapterComments() {
  fetch('/pages/bible_reading/comments.php?book_abbrev=' + encodeURIComponent(modalState.book) + '&chapter=' + modalState.chapter)
    .then(r => r.json())
    .then(data => { if (data.ok) renderComments(data.comments); });
}

function postChapterComment() {
  const input = document.getElementById('modal-comment-input');
  const text  = input.value.trim();
  if (!text) return;
  const fd = new FormData();
  fd.append('book_abbrev', modalState.book);
  fd.append('chapter', modalState.chapter);
  fd.append('comment', text);
  fetch('/pages/bible_reading/comments.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) return;
      input.value = '';
      renderComments(data.comments);
    });
}

function closeChapterModal() {
  document.getElementById('chapter-modal-overlay').style.display = 'none';
}

function updateModalButton(isRead) {
  const btn = document.getElementById('modal-toggle-btn');
  btn.textContent = isRead ? '✓ Lido (clique p/ desmarcar)' : '✓ Marcar como lido';
  btn.className = 'btn ' + (isRead ? 'btn-secondary' : 'btn-primary');
  btn.dataset.read = isRead ? '1' : '0';
}

function toggleFromModal() {
  const btn = document.getElementById('modal-toggle-btn');
  const willRead = btn.dataset.read !== '1';
  const fd = new FormData();
  fd.append('book_abbrev', modalState.book);
  fd.append('chapter', modalState.chapter);
  fd.append('done', willRead ? '1' : '0');
  fetch('/pages/bible_reading/toggle.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) return;
      updateModalButton(willRead);

      document.getElementById('total-read').textContent = data.read_count;
      document.getElementById('total-percent').textContent = data.percent;
      document.getElementById('progress-bar').style.width = data.percent + '%';

      const countEl = document.getElementById('book-count-' + modalState.book);
      const iconEl  = document.getElementById('book-icon-' + modalState.book);
      if (countEl) countEl.textContent = data.book_read + '/' + data.book_total;
      if (iconEl)   iconEl.textContent = data.book_read === data.book_total ? '✅' : '📖';

      const cell = document.querySelector('.chapter-cell[data-book="' + modalState.book + '"][data-chapter="' + modalState.chapter + '"]');
      if (cell) {
        cell.classList.toggle('done', willRead);
        cell.style.background = willRead ? 'var(--accent)' : 'white';
        cell.style.color = willRead ? 'white' : 'var(--text)';
      }

      document.getElementById('suggestion-box').innerHTML = renderSuggestionBox(data.suggestion);
    });
}
JS;
require_once __DIR__ . '/../../includes/layout-footer.php';
?>
