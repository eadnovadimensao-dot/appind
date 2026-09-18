<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require_service_editor();

$db       = db();
$churchId = current_church_id();
$errors   = [];

// Buscar template existente
$template = $db->query("SELECT * FROM service_templates WHERE church_id=$churchId LIMIT 1")->fetch();
if (!$template) {
    // Criar template padrão se não existir
    $db->prepare("INSERT INTO service_templates (church_id,name,type,day_of_week,time_start,time_end) VALUES (?,?,?,?,?,?)")
       ->execute([$churchId,'Culto de Domingo','sunday',0,'18:30:00','20:30:00']);
    $template = $db->query("SELECT * FROM service_templates WHERE church_id=$churchId LIMIT 1")->fetch();
}

$templateId = $template['id'];
$locations  = $db->query("SELECT id, name FROM locations WHERE church_id=$churchId AND active=1 ORDER BY name")->fetchAll();

// Buscar itens do template
$items = $db->query("SELECT * FROM service_template_items WHERE template_id=$templateId ORDER BY position")->fetchAll();

$itemTypeLabels = [
    'welcome'      => ['label'=>'Boas-vindas',    'icon'=>'👋'],
    'worship'      => ['label'=>'Louvor',          'icon'=>'🎵'],
    'prayer'       => ['label'=>'Oração',          'icon'=>'🙏'],
    'reading'      => ['label'=>'Leitura Bíblica', 'icon'=>'📖'],
    'sermon'       => ['label'=>'Pregação',        'icon'=>'✝️'],
    'offering'     => ['label'=>'Oferta',          'icon'=>'💰'],
    'announcement' => ['label'=>'Avisos',          'icon'=>'📢'],
    'closing'      => ['label'=>'Encerramento',    'icon'=>'🔚'],
    'other'        => ['label'=>'Outro',           'icon'=>'➕'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_template') {
        $name       = trim($_POST['name']        ?? '');
        $timeStart  = trim($_POST['time_start']  ?? '18:30');
        $timeEnd    = trim($_POST['time_end']     ?? '20:30');
        $locationId = (int)($_POST['location_id'] ?? 0) ?: null;

        if ($name === '') $errors[] = 'Nome é obrigatório.';

        if (empty($errors)) {
            $db->prepare("UPDATE service_templates SET name=?,time_start=?,time_end=?,location_id=? WHERE id=?")
               ->execute([$name, $timeStart, $timeEnd, $locationId, $templateId]);

            // Salvar itens da ordem
            $db->prepare("DELETE FROM service_template_items WHERE template_id=?")->execute([$templateId]);
            $si = $db->prepare("INSERT INTO service_template_items (template_id,position,type,title,duration,worship_count) VALUES (?,?,?,?,?,?)");
            $itemTypes  = $_POST['item_types']  ?? [];
            $itemTitles = $_POST['item_titles'] ?? [];
            $itemDurs   = $_POST['item_durs']   ?? [];
            $itemCounts = $_POST['item_counts'] ?? [];
            foreach ($itemTypes as $i => $type) {
                if (!$type) continue;
                $si->execute([$templateId, $i+1, $type,
                    trim($itemTitles[$i] ?? '') ?: null,
                    (int)($itemDurs[$i]   ?? 0) ?: null,
                    (int)($itemCounts[$i] ?? 0),
                ]);
            }

            header('Location: /pages/services/template.php?saved=1');
            exit;
        }
    }
}

$pageTitle  = 'Configurações do culto';
$activePage = 'services';
require_once __DIR__ . '/../../includes/layout.php';
?>

<?php if (isset($_GET['saved'])): ?>
  <div style="background:#E1F5EE;border:1px solid var(--accent-border);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#0F6E56">
    ✓ Configurações salvas! Os próximos domingos serão gerados com este template.
  </div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="POST" style="width:100%">
  <input type="hidden" name="action" value="save_template">

  <!-- Configurações gerais -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Culto padrão de domingo</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Nome padrão</label>
        <input type="text" name="name" class="form-control"
               value="<?= htmlspecialchars($template['name']) ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Local padrão</label>
        <select name="location_id" class="form-control">
          <option value="">Sem local definido</option>
          <?php foreach ($locations as $l): ?>
            <option value="<?= $l['id'] ?>" <?= $template['location_id']==$l['id']?'selected':''?>>
              <?= htmlspecialchars($l['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Horário de início</label>
        <input type="time" name="time_start" class="form-control"
               value="<?= substr($template['time_start'],0,5) ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Horário de término</label>
        <input type="time" name="time_end" class="form-control"
               value="<?= substr($template['time_end'],0,5) ?>">
      </div>
    </div>
    <div style="background:#E1F5EE;border-radius:8px;padding:10px 14px;font-size:12px;color:#0F6E56">
      ✨ O sistema gerará automaticamente os cultos dos próximos domingos usando estas configurações.
      Cada culto pode ser personalizado individualmente pelo supervisor da semana.
    </div>
  </div>

  <!-- Ordem padrão -->
  <div class="card" style="margin-bottom:24px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
      <p class="card-title" style="margin-bottom:0">Ordem padrão do culto</p>
      <button type="button" onclick="addItem()" class="btn btn-secondary" style="font-size:12px">+ Adicionar item</button>
    </div>
    <div id="items-list" style="display:flex;flex-direction:column;gap:8px">
      <?php foreach ($items as $i => $item): ?>
        <div class="item-row" style="display:grid;grid-template-columns:32px 160px 1fr 70px 70px 32px;gap:8px;align-items:center;background:var(--content-bg);border-radius:7px;padding:8px 10px">
          <span style="cursor:grab;color:var(--text-muted);font-size:18px;text-align:center">⠿</span>
          <select name="item_types[]" class="form-control" style="font-size:12px;padding:5px 8px" onchange="toggleWorship(this,<?= $i ?>)">
            <?php foreach ($itemTypeLabels as $k => $v): ?>
              <option value="<?= $k ?>" <?= $item['type']===$k?'selected':''?>><?= $v['icon'] ?> <?= $v['label'] ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="item_titles[]" class="form-control" style="font-size:12px;padding:5px 8px"
                 value="<?= htmlspecialchars($item['title'] ?? '') ?>" placeholder="Descrição…">
          <input type="number" name="item_durs[]" class="form-control" style="font-size:12px;padding:5px 8px"
                 placeholder="min" min="0" value="<?= $item['duration'] ?? '' ?>">
          <input type="number" name="item_counts[]" id="count-<?= $i ?>" class="form-control"
                 style="font-size:12px;padding:5px 8px;display:<?= $item['type']==='worship'?'block':'none' ?>"
                 placeholder="qtd" min="0" value="<?= $item['worship_count'] ?? 0 ?>">
          <button type="button" onclick="this.closest('.item-row').remove()"
                  style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:18px">×</button>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div style="display:flex;gap:10px">
    <button type="submit" class="btn btn-primary">Salvar configurações</button>
    <a href="/pages/services/index.php" class="btn btn-secondary">Cancelar</a>
  </div>
</form>

<?php
$itemTypesJson = json_encode($itemTypeLabels);
$defaultCount  = count($items);
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
  initDrag();
}

function toggleWorship(sel, idx) {
  const el = document.getElementById('count-' + idx);
  if (el) el.style.display = sel.value === 'worship' ? 'block' : 'none';
}

// Drag and drop
let dragSrc = null;
function initDrag() {
  document.querySelectorAll('.item-row').forEach(row => {
    row.setAttribute('draggable', true);
    row.addEventListener('dragstart', function(e) { dragSrc = this; this.style.opacity = '0.4'; e.dataTransfer.effectAllowed = 'move'; });
    row.addEventListener('dragend',   function()  { this.style.opacity = '1'; document.querySelectorAll('.item-row').forEach(r => r.style.background = ''); });
    row.addEventListener('dragover',  function(e) { e.preventDefault(); this.style.background = 'var(--accent-lt)'; });
    row.addEventListener('dragleave', function()  { this.style.background = ''; });
    row.addEventListener('drop',      function(e) {
      e.preventDefault(); this.style.background = '';
      if (dragSrc !== this) {
        const list = this.parentNode;
        const all  = [...list.querySelectorAll('.item-row')];
        list.insertBefore(dragSrc, all.indexOf(dragSrc) < all.indexOf(this) ? this.nextSibling : this);
      }
    });
  });
}
initDrag();
JS;
require_once __DIR__ . '/../../includes/layout-footer.php';
?>
