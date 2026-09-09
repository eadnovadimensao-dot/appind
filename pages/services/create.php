<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

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

// Membros de ministérios para escala
$ministryMembers = $db->prepare("
    SELECT DISTINCT m.id, m.name, mn.name AS ministry_name
    FROM member_ministries mm
    JOIN members m     ON m.id  = mm.member_id
    JOIN ministries mn ON mn.id = mm.ministry_id
    JOIN churches ch   ON ch.id = m.church_id
    WHERE (ch.id = ? OR ch.parent_id = ?) AND m.status = 'active'
    ORDER BY m.name
");
$ministryMembers->execute([SEDE_ID, SEDE_ID]);
$ministryMembers = $ministryMembers->fetchAll();

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
    $sermonText  = trim($_POST['sermon_text']  ?? '');
    $notes       = trim($_POST['notes']        ?? '');
    $scaleIds    = $_POST['scale_ids']  ?? [];
    $scaleRoles  = $_POST['scale_roles'] ?? [];
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

        // Buscar escalados em atividades de ministérios na mesma data (aceitos)
        $autoScale = $db->prepare("
            SELECT DISTINCT mam.member_id, mam.role
            FROM ministry_activity_members mam
            JOIN ministry_activities ma ON ma.id = mam.activity_id
            WHERE ma.activity_date = ? AND mam.status IN ('accepted','pending')
              AND ma.ministry_id IN (SELECT id FROM ministries WHERE church_id = ?)
        ");
        $autoScale->execute([$date, $churchId]);
        $autoScaled = $autoScale->fetchAll();

        // Mesclar com escala manual
        $allScaled = [];
        foreach ($autoScaled as $as) {
            $allScaled[$as['member_id']] = $as['role'];
        }
        foreach ($scaleIds as $mid) {
            $allScaled[(int)$mid] = trim($scaleRoles[$mid] ?? '') ?: ($allScaled[(int)$mid] ?? null);
        }

        // Salvar escala mesclada
        if (!empty($allScaled)) {
            $ss = $db->prepare("INSERT IGNORE INTO service_scale (service_id, member_id, role) VALUES (?,?,?)");
            foreach ($allScaled as $mid => $role) {
                $ss->execute([$serviceId, $mid, $role]);
            }
        }

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
        <label class="form-label">Texto bíblico</label>
        <input type="text" name="sermon_text" class="form-control"
               placeholder="Ex: João 3:16, Salmos 23"
               value="<?= htmlspecialchars($_POST['sermon_text'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Título da pregação</label>
        <input type="text" name="sermon_title" class="form-control"
               placeholder="Ex: O Amor de Deus"
               value="<?= htmlspecialchars($_POST['sermon_title'] ?? '') ?>">
      </div>
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
      ✨ Ao salvar, membros escalados nos ministérios nesta data serão adicionados automaticamente.
    </div>
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">Adicione membros extras manualmente se necessário:</p>
    <?php if (empty($ministryMembers)): ?>
      <p style="font-size:13px;color:var(--text-muted)">Nenhum membro de ministério cadastrado ainda.</p>
    <?php else: ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:8px">
        <?php foreach ($ministryMembers as $m): ?>
          <div style="border:1px solid var(--border);border-radius:7px;padding:10px 12px;display:flex;align-items:center;gap:10px">
            <input type="checkbox" name="scale_ids[]" value="<?= $m['id'] ?>"
                   id="sm<?= $m['id'] ?>" class="scale-cb" data-id="<?= $m['id'] ?>"
                   onchange="toggleScaleRole(<?= $m['id'] ?>)">
            <div class="avatar" style="width:28px;height:28px;font-size:10px;flex-shrink:0">
              <?= strtoupper(substr($m['name'],0,2)) ?>
            </div>
            <div style="flex:1;min-width:0">
              <label for="sm<?= $m['id'] ?>" style="font-size:13px;font-weight:500;cursor:pointer;display:block">
                <?= htmlspecialchars($m['name']) ?>
              </label>
              <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($m['ministry_name']) ?></div>
              <input type="text" name="scale_roles[<?= $m['id'] ?>]"
                     id="sr<?= $m['id'] ?>" class="form-control"
                     placeholder="Função (vocal, guitarra, som…)"
                     style="margin-top:4px;font-size:12px;padding:5px 8px;display:none">
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
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
$extraJs = <<<JS
const itemTypes = $itemTypesJson;
let itemCount   = $defaultCount;

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

function toggleScaleRole(id) {
  const cb   = document.getElementById('sm' + id);
  const role = document.getElementById('sr' + id);
  role.style.display = cb.checked ? 'block' : 'none';
}

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
