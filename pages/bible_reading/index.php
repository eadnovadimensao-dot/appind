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
        $verses = '';
        foreach ($s['verses'] as $v) {
            $verses .= '<sup style="color:var(--accent);font-weight:700">' . (int)$v['verse'] . '</sup> ' . htmlspecialchars($v['text']) . ' ';
        }
        $html .= '<div style="border:1px solid var(--border);border-radius:8px;padding:12px 14px;margin-bottom:10px">'
                . '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:8px">'
                . '<strong style="font-size:13px">' . htmlspecialchars($s['book_name']) . ' ' . (int)$s['chapter'] . '</strong>'
                . '<button type="button" class="btn btn-primary" style="font-size:12px" onclick="toggleChapter(\'' . $s['book_abbrev'] . '\', ' . (int)$s['chapter'] . ', this)">✓ Marcar como lido</button>'
                . '</div>'
                . '<div style="font-size:16px;line-height:1.7;color:var(--text);max-height:320px;overflow-y:auto">' . $verses . '</div>'
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
    <div style="font-size:28px;font-weight:700;color:var(--accent)"><span id="total-percent"><?= $percent ?></span>%</div>
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
                    onclick="toggleChapter('<?= $b['book_abbrev'] ?>', <?= $c ?>, this)"
                    style="height:34px;border-radius:6px;border:1px solid var(--border);background:<?= $isRead ? 'var(--accent)' : 'white' ?>;color:<?= $isRead ? 'white' : 'var(--text)' ?>;font-size:12px;cursor:pointer">
              <?= $c ?>
            </button>
          <?php endfor; ?>
        </div>
      </details>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>

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
    let verses = '';
    (s.verses || []).forEach(v => {
      verses += '<sup style="color:var(--accent);font-weight:700">' + v.verse + '</sup> ' + escapeHtml(v.text) + ' ';
    });
    html += '<div style="border:1px solid var(--border);border-radius:8px;padding:12px 14px;margin-bottom:10px">'
          + '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:8px">'
          + '<strong style="font-size:13px">' + escapeHtml(s.book_name) + ' ' + s.chapter + '</strong>'
          + '<button type="button" class="btn btn-primary" style="font-size:12px" onclick="toggleChapter(\\'' + s.book_abbrev + '\\', ' + s.chapter + ', this)">✓ Marcar como lido</button>'
          + '</div>'
          + '<div style="font-size:16px;line-height:1.7;color:var(--text);max-height:320px;overflow-y:auto">' + verses + '</div>'
          + '</div>';
  });
  return html;
}

function toggleChapter(book, chapter, btn) {
  const willRead = !btn.classList.contains('done');
  const fd = new FormData();
  fd.append('book_abbrev', book);
  fd.append('chapter', chapter);
  fd.append('done', willRead ? '1' : '0');
  fetch('/pages/bible_reading/toggle.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) return;

      btn.classList.toggle('done', willRead);
      btn.style.background = willRead ? 'var(--accent)' : 'white';
      btn.style.color = willRead ? 'white' : 'var(--text)';

      document.getElementById('total-read').textContent = data.read_count;
      document.getElementById('total-percent').textContent = data.percent;
      document.getElementById('progress-bar').style.width = data.percent + '%';

      const countEl = document.getElementById('book-count-' + book);
      const iconEl  = document.getElementById('book-icon-' + book);
      if (countEl) countEl.textContent = data.book_read + '/' + data.book_total;
      if (iconEl)   iconEl.textContent = data.book_read === data.book_total ? '✅' : '📖';

      document.getElementById('suggestion-box').innerHTML = renderSuggestionBox(data.suggestion);
    });
}
JS;
require_once __DIR__ . '/../../includes/layout-footer.php';
?>
