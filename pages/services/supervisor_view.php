<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require_service_editor();

$db       = db();
$churchId = current_church_id();
$memberId = auth_member_id();
$errors   = [];

// Buscar a semana do supervisor logado (ou a semana solicitada por admin)
$serviceId = (int)($_GET['id'] ?? 0);

if ($serviceId) {
    $stmt = $db->prepare("SELECT s.*, m.name AS preacher_name FROM services s LEFT JOIN members m ON m.id=s.preacher_id WHERE s.id=? AND s.church_id=?");
    $stmt->execute([$serviceId, $churchId]);
    $service = $stmt->fetch();
} else {
    // Buscar próximo culto onde o usuário é supervisor
    $stmt = $db->prepare("
        SELECT s.*, m.name AS preacher_name
        FROM services s
        JOIN supervisors sup ON sup.id = s.supervisor_id
        LEFT JOIN members m ON m.id = s.preacher_id
        WHERE s.church_id = ? AND sup.member_id = ? AND s.service_date >= CURDATE()
        ORDER BY s.service_date ASC
        LIMIT 1
    ");
    $stmt->execute([$churchId, $memberId]);
    $service = $stmt->fetch();
}

if (!$service) {
    // Sem culto encontrado (id inválido, ou nenhum culto futuro pro supervisor) — não há o que exibir
    header('Location: /pages/services/index.php');
    exit;
}

// Itens do culto
$items = $db->prepare("
    SELECT si.*, m.name AS responsible_name
    FROM service_items si
    LEFT JOIN members m ON m.id = si.member_id
    WHERE si.service_id = ?
    ORDER BY si.position ASC
");
$items->execute([$service['id']]);
$items = $items->fetchAll();

// Membros para responsável
$members = $db->prepare("SELECT id, name FROM members WHERE church_id=? AND status='active' ORDER BY name");
$members->execute([$churchId]);
$members = $members->fetchAll();

// Processar POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Salvar ordem do culto
    if ($action === 'save_order') {
        $db->prepare("DELETE FROM service_items WHERE service_id=?")->execute([$service['id']]);
        $itemNames   = $_POST['item_name']   ?? [];
        $itemTypes   = $_POST['item_type']   ?? [];
        $itemResps   = $_POST['item_resp']   ?? [];
        $itemNotes   = $_POST['item_notes']  ?? [];
        $itemDurs    = $_POST['item_dur']    ?? [];

        $si = $db->prepare("INSERT INTO service_items (service_id, position, title, type, member_id, description, duration) VALUES (?,?,?,?,?,?,?)");
        foreach ($itemNames as $i => $name) {
            if (trim($name) === '') continue;
            $si->execute([
                $service['id'], $i+1,
                trim($name),
                $itemTypes[$i] ?? 'other',
                (int)($itemResps[$i] ?? 0) ?: null,
                trim($itemNotes[$i] ?? '') ?: null,
                (int)($itemDurs[$i] ?? 0) ?: null,
            ]);
        }

        // Atualizar pregador e status
        if (isset($_POST['preacher_id'])) {
            $db->prepare("UPDATE services SET preacher_id=?, sermon_title=?, status=? WHERE id=?")
               ->execute([
                   (int)$_POST['preacher_id'] ?: null,
                   trim($_POST['sermon_title'] ?? '') ?: null,
                   $_POST['status'] ?? $service['status'],
                   $service['id']
               ]);
        }

        header('Location: /pages/services/supervisor_view.php?id='.$service['id'].'&saved=1');
        exit;
    }

    // Confirmar/recusar supervisão
    if ($action === 'confirm_supervision') {
        $db->prepare("UPDATE services SET supervisor_confirmed=1, supervisor_notes=? WHERE id=?")->execute([trim($_POST['notes']??''), $service['id']]);
        header('Location: /pages/services/supervisor_view.php?id='.$service['id'].'&confirmed=1');
        exit;
    }
}

$pageTitle  = 'Culto · ' . date('d/m/Y', strtotime($service['service_date']));
$activePage = 'services';
require_once __DIR__ . '/../../includes/layout.php';

$itemTypes = [
    'worship'      => '🎵 Louvor',
    'prayer'       => '🙏 Oração',
    'offering'     => '💰 Oferta',
    'announcement' => '📢 Anúncio',
    'sermon'       => '📖 Pregação',
    'communion'    => '🍷 Santa Ceia',
    'other'        => '📋 Outro',
];
?>

<?php if (isset($_GET['saved'])): ?>
  <div style="background:#E1F5EE;border:1px solid var(--accent);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#0F6E56">✓ Programação salva com sucesso!</div>
<?php endif; ?>
<?php if (isset($_GET['confirmed'])): ?>
  <div style="background:#E1F5EE;border:1px solid var(--accent);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#0F6E56">✓ Supervisão confirmada!</div>
<?php endif; ?>

<!-- Cabeçalho do culto -->
<div class="card" style="margin-bottom:16px">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <h1 style="font-size:18px;font-weight:500;margin-bottom:6px"><?= htmlspecialchars($service['title']) ?></h1>
      <div style="display:flex;flex-wrap:wrap;gap:12px;font-size:13px;color:var(--text-muted)">
        <span>📅 <?= date_pt($service['service_date']) ?></span>
        <?php if ($service['time_start']): ?>
          <span>🕐 <?= substr($service['time_start'],0,5) ?> — <?= substr($service['time_end']??'',0,5) ?></span>
        <?php endif; ?>
        <?php if ($service['preacher_name']): ?>
          <span>🎤 <?= htmlspecialchars($service['preacher_name']) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <?php if (!$service['supervisor_confirmed']): ?>
      <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="confirm_supervision">
        <button type="submit" class="btn btn-primary" style="font-size:13px">
          ✅ Confirmar minha supervisão
        </button>
      </form>
    <?php else: ?>
      <span class="badge badge-green">✓ Supervisão confirmada</span>
    <?php endif; ?>
  </div>
</div>

<!-- Ordem do culto -->
<form method="POST">
  <input type="hidden" name="action" value="save_order">

  <!-- Pregação -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Pregação</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Pregador</label>
        <select name="preacher_id" class="form-control">
          <option value="">Selecione</option>
          <?php foreach ($members as $m): ?>
            <option value="<?= $m['id'] ?>" <?= $service['preacher_id']==$m['id']?'selected':''?>>
              <?= htmlspecialchars($m['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Título da mensagem</label>
        <input type="text" name="sermon_title" class="form-control"
               placeholder="Título da pregação…"
               value="<?= htmlspecialchars($service['sermon_title'] ?? '') ?>">
      </div>
    </div>
  </div>

  <!-- Itens da programação -->
  <div class="card" style="margin-bottom:16px;padding:0">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
      <p style="font-weight:500;font-size:14px">Programação</p>
      <button type="button" onclick="addItem()" class="btn btn-secondary" style="font-size:12px">+ Adicionar item</button>
    </div>

    <div id="items-list">
      <?php foreach ($items as $i => $item): ?>
        <div class="item-row" style="padding:12px 18px;border-bottom:1px solid var(--border);display:grid;grid-template-columns:auto 1fr 160px 160px 80px auto;gap:10px;align-items:center">
          <div style="cursor:grab;color:var(--text-muted);font-size:18px;padding:0 4px">⠿</div>
          <input type="text" name="item_name[]" class="form-control" style="font-size:13px"
                 placeholder="Nome do item…" value="<?= htmlspecialchars($item['title'] ?? '') ?>">
          <select name="item_type[]" class="form-control" style="font-size:13px">
            <?php foreach ($itemTypes as $k => $v): ?>
              <option value="<?= $k ?>" <?= $item['type']===$k?'selected':''?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
          <select name="item_resp[]" class="form-control" style="font-size:13px">
            <option value="">Responsável…</option>
            <?php foreach ($members as $m): ?>
              <option value="<?= $m['id'] ?>" <?= $item['member_id']==$m['id']?'selected':''?>>
                <?= htmlspecialchars($m['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <input type="number" name="item_dur[]" class="form-control" style="font-size:13px"
                 placeholder="min" min="1" max="120" value="<?= $item['duration'] ?? '' ?>">
          <button type="button" onclick="this.closest('.item-row').remove()"
                  style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:18px;padding:0 4px">×</button>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Total de tempo -->
    <div style="padding:12px 18px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;font-size:13px;color:var(--text-muted)">
      Duração total: <strong style="margin-left:6px;color:var(--text)" id="total-duration">0 min</strong>
    </div>
  </div>

  <div style="display:flex;gap:10px">
    <button type="submit" class="btn btn-primary">Salvar programação</button>
    <a href="/pages/services/index.php" class="btn btn-secondary">Voltar</a>
  </div>
</form>

<?php
$membersJson = json_encode(array_map(fn($m) => ['id'=>$m['id'],'name'=>$m['name']], $members));
$typesJson   = json_encode($itemTypes);
$extraJs = <<<JS
const members   = {$membersJson};
const itemTypes = {$typesJson};

function addItem() {
  const list = document.getElementById('items-list');
  const memberOpts = members.map(m => `<option value="\${m.id}">\${m.name}</option>`).join('');
  const typeOpts   = Object.entries(itemTypes).map(([k,v]) => `<option value="\${k}">\${v}</option>`).join('');
  const row = document.createElement('div');
  row.className = 'item-row';
  row.style = 'padding:12px 18px;border-bottom:1px solid var(--border);display:grid;grid-template-columns:auto 1fr 160px 160px 80px auto;gap:10px;align-items:center';
  row.innerHTML = `
    <div style="cursor:grab;color:var(--text-muted);font-size:18px;padding:0 4px">⠿</div>
    <input type="text" name="item_name[]" class="form-control" style="font-size:13px" placeholder="Nome do item…">
    <select name="item_type[]" class="form-control" style="font-size:13px">\${typeOpts}</select>
    <select name="item_resp[]" class="form-control" style="font-size:13px"><option value="">Responsável…</option>\${memberOpts}</select>
    <input type="number" name="item_dur[]" class="form-control" style="font-size:13px" placeholder="min" min="1" max="120">
    <button type="button" onclick="this.closest('.item-row').remove();calcTotal()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:18px;padding:0 4px">×</button>
  `;
  list.appendChild(row);
  row.querySelector('input[name="item_name[]"]').focus();
}

function calcTotal() {
  const durs = [...document.querySelectorAll('input[name="item_dur[]"]')]
    .map(i => parseInt(i.value)||0).reduce((a,b) => a+b, 0);
  document.getElementById('total-duration').textContent = durs + ' min';
}

document.addEventListener('input', e => {
  if (e.target.name === 'item_dur[]') calcTotal();
});
calcTotal();
JS;
require_once __DIR__ . '/../../includes/layout-footer.php';
?>
