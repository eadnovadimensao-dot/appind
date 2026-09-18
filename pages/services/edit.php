<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/bible.php';
require_once __DIR__ . '/../../includes/service_checkin.php';
auth_require_service_editor();
$db = db();
$churchId = current_church_id();
$id = (int)($_GET['id'] ?? 0);
$s  = $db->prepare("SELECT * FROM services WHERE id=? AND church_id=?");
$s->execute([$id, $churchId]);
$service = $s->fetch();
if (!$service) { header('Location: /pages/services/index.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title     = trim($_POST['title'] ?? $service['title']);
    $date      = trim($_POST['service_date'] ?? $service['service_date']);
    $timeStart = trim($_POST['time_start'] ?? '') ?: null;

    $scriptureRefsRaw = array_values(array_filter(array_map('trim', $_POST['scripture_refs'] ?? []), fn($r) => $r !== ''));
    $parsedRefs = [];
    foreach ($scriptureRefsRaw as $rawRef) {
        $parsedRefs[] = ['raw' => $rawRef, 'parsed' => bible_parse_reference($db, $rawRef)];
    }
    $sermonText = implode('; ', array_map(
        fn($r) => $r['parsed'] ? bible_format_reference($r['parsed']) : $r['raw'],
        $parsedRefs
    ));

    $db->prepare("UPDATE services SET title=?,type=?,service_date=?,time_start=?,time_end=?,preacher_id=?,sermon_title=?,sermon_text=?,notes=? WHERE id=? AND church_id=?")
       ->execute([
           $title,
           trim($_POST['type']         ?? $service['type']),
           $date,
           $timeStart,
           trim($_POST['time_end']     ?? '') ?: null,
           (int)($_POST['preacher_id'] ?? 0) ?: null,
           trim($_POST['sermon_title'] ?? '') ?: null,
           $sermonText ?: null,
           trim($_POST['notes']        ?? '') ?: null,
           $id, $churchId,
       ]);

    // Ressincroniza as referências bíblicas e a meditação por WhatsApp
    $db->prepare("DELETE FROM service_scriptures WHERE service_id = ?")->execute([$id]);
    if (!empty($parsedRefs)) {
        $ss = $db->prepare("
            INSERT INTO service_scriptures (service_id, raw_reference, book_abbrev, book_name, chapter, verse_start, verse_end, position)
            VALUES (?,?,?,?,?,?,?,?)
        ");
        foreach ($parsedRefs as $i => $r) {
            $p = $r['parsed'];
            $ss->execute([
                $id, $r['raw'],
                $p['book_abbrev'] ?? null, $p['book_name'] ?? null,
                $p['chapter'] ?? null, $p['verse_start'] ?? null, $p['verse_end'] ?? null,
                $i,
            ]);
        }
    }
    queue_scripture_meditation($db, $id, $title, $date, $churchId);

    // Convida por WhatsApp quem ainda não foi convidado pro check-in geral
    queue_service_checkins($db, $id, $title, $date, $timeStart, $churchId);

    header('Location: /pages/services/view.php?id=' . $id);
    exit;
}

$existingRefs = $db->prepare("SELECT raw_reference FROM service_scriptures WHERE service_id = ? ORDER BY position");
$existingRefs->execute([$id]);
$existingRefs = $existingRefs->fetchAll(PDO::FETCH_COLUMN);

$members = $db->prepare("SELECT m.id, m.name FROM members m JOIN churches ch ON ch.id=m.church_id WHERE (ch.id=? OR ch.parent_id=?) AND m.status='active' ORDER BY m.name");
$members->execute([SEDE_ID, SEDE_ID]);
$members = $members->fetchAll();

$typeOptions = ['sunday'=>'Culto de Domingo','weekday'=>'Culto de Semana','special'=>'Culto Especial','prayer'=>'Reunião de Oração'];

$pageTitle  = 'Editar · ' . $service['title'];
$activePage = 'services';
require_once __DIR__ . '/../../includes/layout.php';
?>
<form method="POST" style="max-width:700px">
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Dados do culto</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Título *</label>
        <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($service['title']) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Tipo</label>
        <select name="type" class="form-control">
          <?php foreach ($typeOptions as $k=>$v): ?>
            <option value="<?= $k ?>" <?= $service['type']===$k?'selected':''?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Data *</label>
        <input type="date" name="service_date" class="form-control" value="<?= substr($service['service_date'],0,10) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Início</label>
        <input type="time" name="time_start" class="form-control" value="<?= $service['time_start'] ? substr($service['time_start'],0,5) : '' ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Término</label>
        <input type="time" name="time_end" class="form-control" value="<?= $service['time_end'] ? substr($service['time_end'],0,5) : '' ?>">
      </div>
    </div>
  </div>
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Pregação</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Pregador</label>
        <select name="preacher_id" class="form-control">
          <option value="">Selecione</option>
          <?php foreach ($members as $m): ?>
            <option value="<?= $m['id'] ?>" <?= $service['preacher_id']==$m['id']?'selected':''?>><?= htmlspecialchars($m['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Título da pregação</label>
        <input type="text" name="sermon_title" class="form-control" value="<?= htmlspecialchars($service['sermon_title'] ?? '') ?>">
      </div>
    </div>

    <div style="margin-top:8px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
        <label class="form-label" style="margin-bottom:0">Referências bíblicas</label>
        <button type="button" onclick="addScriptureRef()" class="btn btn-secondary" style="font-size:12px">+ Adicionar referência</button>
      </div>
      <p style="font-size:12px;color:var(--text-secondary,#666);margin:0 0 8px">
        Os versículos serão enviados aos membros ativos para meditação, 2 dias antes do culto.
      </p>
      <div id="scripture-refs-list" style="display:flex;flex-direction:column;gap:8px"></div>
    </div>
  </div>
  <div class="card" style="margin-bottom:24px">
    <p class="card-title">Observações</p>
    <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars($service['notes'] ?? '') ?></textarea>
  </div>
  <div style="display:flex;gap:10px">
    <button type="submit" class="btn btn-primary">Salvar</button>
    <a href="/pages/services/view.php?id=<?= $id ?>" class="btn btn-secondary">Cancelar</a>
  </div>
</form>
<?php
$scriptureRefsJson = json_encode(!empty($_POST['scripture_refs']) ? $_POST['scripture_refs'] : $existingRefs);
$extraJs = <<<JS
// ── Referências bíblicas ────────────────────────────────────
function addScriptureRef(value) {
  const list = document.getElementById('scripture-refs-list');
  const row = document.createElement('div');
  row.className = 'scripture-ref-row';
  row.style = 'background:var(--content-bg);border-radius:7px;padding:8px 10px';
  row.innerHTML =
    '<div style="display:flex;gap:8px;align-items:center">' +
      '<input type="text" name="scripture_refs[]" class="form-control" style="font-size:13px" placeholder="Ex: João 3:16, Salmos 23" value="' + (value ? value.replace(/"/g, '&quot;') : '') + '">' +
      '<button type="button" onclick="this.closest(\\'.scripture-ref-row\\').remove()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:18px">&times;</button>' +
    '</div>' +
    '<div class="scripture-preview" style="font-size:12px;color:var(--text-muted);margin-top:4px"></div>';
  list.appendChild(row);

  const input   = row.querySelector('input');
  const preview = row.querySelector('.scripture-preview');
  let timer = null;
  input.addEventListener('input', () => {
    clearTimeout(timer);
    const ref = input.value.trim();
    if (!ref) { preview.textContent = ''; return; }
    timer = setTimeout(() => {
      preview.textContent = 'Buscando…';
      fetch('/pages/services/bible_preview.php?ref=' + encodeURIComponent(ref))
        .then(r => r.json())
        .then(d => {
          if (d.ok) {
            preview.style.color = 'var(--accent)';
            preview.textContent = '✓ ' + d.reference + ' — ' + d.preview;
          } else {
            preview.style.color = 'var(--red)';
            preview.textContent = d.error || 'Não reconheci essa referência.';
          }
        })
        .catch(() => { preview.textContent = ''; });
    }, 400);
  });
  if (value) input.dispatchEvent(new Event('input'));
}

const initialScriptureRefs = $scriptureRefsJson;
if (initialScriptureRefs.length > 0) {
  initialScriptureRefs.forEach(v => addScriptureRef(v));
} else {
  addScriptureRef();
}
JS;
require_once __DIR__ . '/../../includes/layout-footer.php';
?>
