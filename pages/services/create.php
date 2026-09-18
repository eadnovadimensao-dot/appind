<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/bible.php';
require_once __DIR__ . '/../../includes/service_checkin.php';
auth_require_service_editor();

$db       = db();
$churchId = current_church_id();
$errors   = [];

$members = $db->prepare("
    SELECT m.id, m.name FROM members m
    JOIN churches ch ON ch.id = m.church_id
    WHERE (ch.id = ? OR ch.parent_id = ?) AND m.status = 'active'
    ORDER BY m.name
");
$members->execute([SEDE_ID, SEDE_ID]);
$members = $members->fetchAll();

$typeOptions = [
    'sunday'  => 'Culto de Domingo',
    'weekday' => 'Culto de Semana',
    'special' => 'Culto Especial',
    'prayer'  => 'Reunião de Oração',
];

$itemTypes = [
    'welcome'      => ['label'=>'Boas-vindas',      'icon'=>'👋'],
    'worship'      => ['label'=>'Louvor',            'icon'=>'🎵'],
    'prayer'       => ['label'=>'Oração',            'icon'=>'🙏'],
    'reading'      => ['label'=>'Leitura Bíblica',   'icon'=>'📖'],
    'sermon'       => ['label'=>'Pregação',          'icon'=>'✝️'],
    'offering'     => ['label'=>'Oferta',            'icon'=>'💰'],
    'announcement' => ['label'=>'Avisos',            'icon'=>'📢'],
    'closing'      => ['label'=>'Encerramento',      'icon'=>'🔚'],
    'other'        => ['label'=>'Outro',             'icon'=>'➕'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = trim($_POST['title']        ?? '');
    $type        = trim($_POST['type']         ?? 'sunday');
    $date        = trim($_POST['service_date'] ?? '');
    $timeStart   = trim($_POST['time_start']   ?? '') ?: null;
    $timeEnd     = trim($_POST['time_end']     ?? '') ?: null;
    $preacherId  = (int)($_POST['preacher_id'] ?? 0) ?: null;
    $sermonTitle = trim($_POST['sermon_title'] ?? '');
    $notes       = trim($_POST['notes']        ?? '');
    $scriptureRefsRaw = array_values(array_filter(array_map('trim', $_POST['scripture_refs'] ?? []), fn($r) => $r !== ''));

    // Interpreta cada referência bíblica digitada
    $parsedRefs = [];
    foreach ($scriptureRefsRaw as $rawRef) {
        $parsedRefs[] = ['raw' => $rawRef, 'parsed' => bible_parse_reference($db, $rawRef)];
    }
    $sermonText = implode('; ', array_map(
        fn($r) => $r['parsed'] ? bible_format_reference($r['parsed']) : $r['raw'],
        $parsedRefs
    ));
    $itemTitles  = $_POST['item_titles']  ?? [];
    $itemTypes_p = $_POST['item_types']   ?? [];
    $itemDescs   = $_POST['item_descs']   ?? [];
    $itemDurs    = $_POST['item_durs']    ?? [];
    $itemCounts  = $_POST['item_counts']  ?? [];

    $supervisorId = (int)($_POST['supervisor_id'] ?? 0) ?: null;

    if ($title === '') $errors[] = 'Título é obrigatório.';
    if ($date  === '') $errors[] = 'Data é obrigatória.';

    if (empty($errors)) {
        $db->prepare("
            INSERT INTO services
              (church_id, title, type, service_date, time_start, time_end,
               preacher_id, supervisor_id, sermon_title, sermon_text, notes, status, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,'planning',?)
        ")->execute([$churchId,$title,$type,$date,$timeStart,$timeEnd,
                     $preacherId,$supervisorId,$sermonTitle?:null,$sermonText?:null,$notes?:null,
                     auth_member_id()]);
        $serviceId = $db->lastInsertId();

        // Salvar as referências bíblicas interpretadas e agendar a meditação
        if (!empty($parsedRefs)) {
            $ss = $db->prepare("
                INSERT INTO service_scriptures (service_id, raw_reference, book_abbrev, book_name, chapter, verse_start, verse_end, position)
                VALUES (?,?,?,?,?,?,?,?)
            ");
            foreach ($parsedRefs as $i => $r) {
                $p = $r['parsed'];
                $ss->execute([
                    $serviceId, $r['raw'],
                    $p['book_abbrev'] ?? null, $p['book_name'] ?? null,
                    $p['chapter'] ?? null, $p['verse_start'] ?? null, $p['verse_end'] ?? null,
                    $i,
                ]);
            }
            queue_scripture_meditation($db, $serviceId, $title, $date, $churchId);
        }

        // Salvar ordem do culto
        $si = $db->prepare("INSERT INTO service_items (service_id,position,type,title,description,duration,worship_count) VALUES (?,?,?,?,?,?,?)");
        foreach ($itemTitles as $i => $ititle) {
            if (trim($ititle) === '' && empty($itemTypes_p[$i])) continue;
            $si->execute([
                $serviceId, $i,
                $itemTypes_p[$i] ?? 'other',
                trim($ititle) ?: null,
                trim($itemDescs[$i] ?? '') ?: null,
                (int)($itemDurs[$i] ?? 0) ?: null,
                (int)($itemCounts[$i] ?? 0),
            ]);
        }

        // Escala automática: membros já escalados em atividades de ministérios nessa data
        $autoScale = $db->prepare("
            SELECT DISTINCT mam.member_id, mam.role
            FROM ministry_activity_members mam
            JOIN ministry_activities ma ON ma.id = mam.activity_id
            WHERE ma.activity_date = ? AND mam.status IN ('confirmed','pending')
              AND ma.ministry_id IN (SELECT id FROM ministries WHERE church_id = ?)
        ");
        $autoScale->execute([$date, $churchId]);
        $autoScaled = $autoScale->fetchAll();

        if (!empty($autoScaled)) {
            $ss = $db->prepare("INSERT IGNORE INTO service_scale (service_id, member_id, role) VALUES (?,?,?)");
            foreach ($autoScaled as $as) {
                $ss->execute([$serviceId, $as['member_id'], $as['role']]);
            }
        }

        // Convite de check-in geral por WhatsApp, pra toda a congregação (menos
        // quem já está escalado — esses já recebem o "Cheguei" da escala)
        queue_service_checkins($db, $serviceId, $title, $date, $timeStart, $churchId);

        header('Location: /pages/services/view.php?id=' . $serviceId);
        exit;
    }
}

$pageTitle  = 'Novo culto';
$activePage = 'services';
require_once __DIR__ . '/../../includes/layout.php';
?>

<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="POST" style="width:100%">

  <!-- Dados gerais -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Dados do culto</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Título *</label>
        <input type="text" name="title" class="form-control"
               placeholder="Ex: Culto de Domingo, Culto de Avivamento…"
               value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Tipo</label>
        <select name="type" class="form-control">
          <?php foreach ($typeOptions as $k => $v): ?>
            <option value="<?= $k ?>" <?= ($_POST['type']??'sunday')===$k?'selected':''?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Data *</label>
        <input type="date" name="service_date" class="form-control"
               value="<?= htmlspecialchars($_POST['service_date'] ?? date('Y-m-d')) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Início</label>
        <input type="time" name="time_start" class="form-control"
               value="<?= htmlspecialchars($_POST['time_start'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Término</label>
        <input type="time" name="time_end" class="form-control"
               value="<?= htmlspecialchars($_POST['time_end'] ?? '') ?>">
      </div>
    </div>
  </div>

  <!-- Pregação e Supervisor -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Pregação e responsável</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Supervisor da semana</label>
        <?php
        // Buscar supervisor da rotação para a data selecionada
        $dateForSup = $_POST['service_date'] ?? date('Y-m-d');
        $rotSup = $db->prepare("
            SELECT sr.id AS rotation_id, sr.supervisor_id, m.name AS sup_name, sv.name_alias
            FROM supervisor_rotation sr
            JOIN supervisors sv ON sv.id = sr.supervisor_id
            JOIN members m      ON m.id  = sv.member_id
            WHERE sr.church_id = ? AND sr.service_date = ?
            LIMIT 1
        ");
        $rotSup->execute([$churchId, $dateForSup]);
        $currentSup = $rotSup->fetch();

        $allSups = $db->query("
            SELECT sv.id, sv.name_alias, m.name AS member_name
            FROM supervisors sv JOIN members m ON m.id = sv.member_id
            WHERE sv.church_id = $churchId AND sv.active = 1 ORDER BY m.name
        ")->fetchAll();
        ?>
        <select name="supervisor_id" class="form-control">
          <option value="">Selecione</option>
          <?php foreach ($allSups as $sv): ?>
            <option value="<?= $sv['id'] ?>"
                    <?= ($currentSup && $currentSup['supervisor_id']==$sv['id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($sv['name_alias'] ?: $sv['member_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if ($currentSup): ?>
          <div style="margin-top:6px;font-size:12px;color:var(--accent)">
            ✓ Rotação automática: <?= htmlspecialchars($currentSup['name_alias'] ?: $currentSup['sup_name']) ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="form-group">
        <label class="form-label">Pregador</label>
        <select name="preacher_id" class="form-control">
          <option value="">Selecione</option>
          <?php foreach ($members as $m): ?>
            <option value="<?= $m['id'] ?>" <?= ($_POST['preacher_id']??'')==$m['id']?'selected':''?>>
              <?= htmlspecialchars($m['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Título da pregação</label>
        <input type="text" name="sermon_title" class="form-control"
               placeholder="Ex: O Amor de Deus"
               value="<?= htmlspecialchars($_POST['sermon_title'] ?? '') ?>">
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

  <!-- Ordem do culto -->
  <div class="card" style="margin-bottom:16px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
      <p class="card-title" style="margin-bottom:0">Ordem do culto</p>
      <button type="button" onclick="addItem()" class="btn btn-secondary" style="font-size:12px">+ Adicionar item</button>
    </div>

    <div id="items-list" style="display:flex;flex-direction:column;gap:8px">
      <!-- Itens padrão -->
      <?php
      $defaultItems = [
        ['welcome',      'Boas-vindas',    '',   5,  0],
        ['worship',      'Louvor inicial', '',  15,  3],
        ['prayer',       'Oração',         '',   5,  0],
        ['reading',      'Leitura Bíblica','',   5,  0],
        ['sermon',       'Pregação',       '',  30,  0],
        ['worship',      'Louvor pós-preg','',  10,  2],
        ['offering',     'Oferta',         '',   5,  0],
        ['announcement', 'Avisos',         '',   5,  0],
        ['closing',      'Encerramento',   '',   5,  0],
      ];
      foreach ($defaultItems as $i => $item):
      ?>
        <div class="item-row" style="display:grid;grid-template-columns:32px 160px 1fr 70px 70px 32px;gap:8px;align-items:center;background:var(--content-bg);border-radius:7px;padding:8px 10px">
          <span style="cursor:grab;color:var(--text-muted);font-size:18px;text-align:center">⠿</span>
          <select name="item_types[]" class="form-control" style="font-size:12px;padding:5px 8px" onchange="toggleWorship(this,<?= $i ?>)">
            <?php foreach ($itemTypes as $k => $v): ?>
              <option value="<?= $k ?>" <?= $item[0]===$k?'selected':''?>><?= $v['icon'] ?> <?= $v['label'] ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="item_titles[]" class="form-control" style="font-size:12px;padding:5px 8px"
                 placeholder="Descrição…" value="<?= $item[1] ?>">
          <input type="number" name="item_durs[]" class="form-control" style="font-size:12px;padding:5px 8px"
                 placeholder="min" min="0" value="<?= $item[3] ?>">
          <input type="number" name="item_counts[]" id="count-<?= $i ?>" class="form-control" style="font-size:12px;padding:5px 8px;display:<?= $item[0]==='worship'?'block':'none' ?>"
                 placeholder="qtd" min="0" value="<?= $item[4] ?>" title="Quantidade de louvores">
          <button type="button" onclick="this.closest('.item-row').remove()"
                  style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:18px">×</button>
        </div>
      <?php endforeach; ?>
    </div>

    <div style="margin-top:12px;font-size:12px;color:var(--text-muted)">
      💡 O campo de quantidade aparece apenas nos itens de Louvor.
    </div>
  </div>

  <!-- Escala -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Escala do culto</p>
    <div style="background:#E1F5EE;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#0F6E56">
      ✨ A escala é feita por ministério. Quem já foi escalado nos ministérios pra esta data
      entra automaticamente ao salvar o culto — não é possível adicionar manualmente por aqui.
    </div>
    <p style="font-weight:500;font-size:13px;margin-bottom:8px">Já escalados nesta data</p>
    <div id="scale-preview">
      <p style="font-size:13px;color:var(--text-muted)">Selecione uma data pra ver quem já está escalado.</p>
    </div>
  </div>

  <!-- Observações -->
  <div class="card" style="margin-bottom:24px">
    <p class="card-title">Observações</p>
    <textarea name="notes" class="form-control" rows="2"
              placeholder="Informações adicionais para a equipe…"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
  </div>

  <div style="display:flex;gap:10px">
    <button type="submit" class="btn btn-primary">Salvar culto</button>
    <a href="/pages/services/index.php" class="btn btn-secondary">Cancelar</a>
  </div>
</form>

<?php
$itemTypesJson = json_encode($itemTypes);
$defaultCount  = count($defaultItems);
$scriptureRefsJson = json_encode($_POST['scripture_refs'] ?? []);
$extraJs = <<<JS
const itemTypes = $itemTypesJson;
let itemCount   = $defaultCount;

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

function addItem() {
  const list = document.getElementById('items-list');
  const idx  = itemCount++;
  const opts = Object.entries(itemTypes).map(([k,v]) =>
    '<option value="'+k+'">'+v.icon+' '+v.label+'</option>'
  ).join('');

  const row = document.createElement('div');
  row.className = 'item-row';
  row.style = 'display:grid;grid-template-columns:32px 160px 1fr 70px 70px 32px;gap:8px;align-items:center;background:var(--content-bg);border-radius:7px;padding:8px 10px';
  row.innerHTML =
    '<span style="cursor:grab;color:var(--text-muted);font-size:18px;text-align:center">&#8927;</span>' +
    '<select name="item_types[]" class="form-control" style="font-size:12px;padding:5px 8px" onchange="toggleWorship(this,'+idx+')">' + opts + '</select>' +
    '<input type="text" name="item_titles[]" class="form-control" style="font-size:12px;padding:5px 8px" placeholder="Descrição…">' +
    '<input type="number" name="item_durs[]" class="form-control" style="font-size:12px;padding:5px 8px" placeholder="min" min="0">' +
    '<input type="number" name="item_counts[]" id="count-'+idx+'" class="form-control" style="font-size:12px;padding:5px 8px;display:none" placeholder="qtd" min="0">' +
    '<button type="button" onclick="this.closest(\'.item-row\').remove()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:18px">&times;</button>';
  list.appendChild(row);
}

function toggleWorship(sel, idx) {
  const countEl = document.getElementById('count-' + idx);
  if (countEl) countEl.style.display = sel.value === 'worship' ? 'block' : 'none';
}

// ── Preview da escala automática (por ministério) ──────────
function loadScalePreview() {
  const date = document.querySelector('[name=service_date]').value;
  const box  = document.getElementById('scale-preview');
  if (!date) {
    box.innerHTML = '<p style="font-size:13px;color:var(--text-muted)">Selecione uma data pra ver quem já está escalado.</p>';
    return;
  }
  box.innerHTML = '<p style="font-size:13px;color:var(--text-muted)">Carregando…</p>';
  fetch('/pages/services/scaled_preview.php?date=' + encodeURIComponent(date))
    .then(r => r.json())
    .then(d => {
      if (!d.groups || d.groups.length === 0) {
        box.innerHTML = '<p style="font-size:13px;color:var(--text-muted)">Nenhuma escala de ministério vinculada a esta data ainda.</p>';
        return;
      }
      box.innerHTML = d.groups.map(g =>
        '<div style="margin-bottom:10px">' +
          '<div style="font-size:12px;font-weight:500;color:var(--accent);margin-bottom:4px">' + g.ministry + '</div>' +
          '<div style="display:flex;flex-wrap:wrap;gap:6px">' +
            g.members.map(m =>
              '<span style="font-size:12px;background:var(--content-bg);border-radius:20px;padding:4px 10px">' +
                m.name + (m.role ? ' · ' + m.role : '') +
                (m.status === 'pending' ? ' ⏳' : '') +
              '</span>'
            ).join('') +
          '</div>' +
        '</div>'
      ).join('');
    })
    .catch(() => { box.innerHTML = '<p style="font-size:13px;color:var(--red)">Não foi possível carregar a escala.</p>'; });
}
document.querySelector('[name=service_date]').addEventListener('change', loadScalePreview);
loadScalePreview();

// ── Drag and drop ──────────────────────────────────────────
let dragSrc = null;

function initDrag() {
  document.querySelectorAll('.item-row').forEach(row => {
    row.setAttribute('draggable', true);
    row.addEventListener('dragstart', function(e) {
      dragSrc = this;
      this.style.opacity = '0.4';
      e.dataTransfer.effectAllowed = 'move';
    });
    row.addEventListener('dragend', function() {
      this.style.opacity = '1';
      document.querySelectorAll('.item-row').forEach(r => r.style.background = '');
    });
    row.addEventListener('dragover', function(e) {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      this.style.background = 'var(--accent-lt)';
    });
    row.addEventListener('dragleave', function() {
      this.style.background = '';
    });
    row.addEventListener('drop', function(e) {
      e.preventDefault();
      this.style.background = '';
      if (dragSrc !== this) {
        const list     = this.parentNode;
        const allRows  = [...list.querySelectorAll('.item-row')];
        const srcIdx   = allRows.indexOf(dragSrc);
        const destIdx  = allRows.indexOf(this);
        if (srcIdx < destIdx) {
          list.insertBefore(dragSrc, this.nextSibling);
        } else {
          list.insertBefore(dragSrc, this);
        }
      }
    });
  });
}

// Inicializar e reinicializar ao adicionar novos itens
initDrag();
const origAddItem = addItem;
window.addItem = function() {
  origAddItem();
  initDrag();
};
JS;
require_once __DIR__ . '/../../includes/layout-footer.php';
?>
