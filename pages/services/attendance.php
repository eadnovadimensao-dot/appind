<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_checkin.php';
auth_check();
if (!auth_can_take_attendance()) {
    http_response_code(403);
    include __DIR__ . '/../../includes/403.php';
    exit;
}

$db       = db();
$churchId = current_church_id();
$id       = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM services WHERE id = ? AND church_id = ?");
$stmt->execute([$id, $churchId]);
$service = $stmt->fetch();
if (!$service) { header('Location: /pages/services/index.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    if ($action === 'visitors') {
        $n = max(0, min(9999, (int)($_POST['visitors'] ?? 0)));
        $db->prepare("UPDATE services SET visitors_count = ? WHERE id = ?")->execute([$n, $id]);
        echo json_encode(['ok' => true, 'visitors' => $n]);
        exit;
    }

    if ($action === 'toggle') {
        $mid = (int)($_POST['member_id'] ?? 0);
        $chk = $db->prepare("SELECT 1 FROM members WHERE id = ? AND church_id = ? AND status = 'active'");
        $chk->execute([$mid, $churchId]);
        if (!$chk->fetchColumn()) { echo json_encode(['ok' => false]); exit; }

        $ex = $db->prepare("SELECT id, checked_in_at FROM service_checkins WHERE service_id = ? AND member_id = ?");
        $ex->execute([$id, $mid]);
        $row = $ex->fetch();
        $by  = auth_member_id() ?: null;

        if ($row && $row['checked_in_at']) {
            $db->prepare("UPDATE service_checkins SET checked_in_at = NULL, checked_in_by = NULL WHERE id = ?")->execute([$row['id']]);
            $present = false;
        } elseif ($row) {
            $db->prepare("UPDATE service_checkins SET checked_in_at = NOW(), checkin_source = 'manual', checked_in_by = ? WHERE id = ?")->execute([$by, $row['id']]);
            $present = true;
        } else {
            $db->prepare("INSERT INTO service_checkins (service_id, member_id, checkin_token, checked_in_at, checkin_source, checked_in_by) VALUES (?,?,?,NOW(),'manual',?)")
               ->execute([$id, $mid, bin2hex(random_bytes(32)), $by]);
            $present = true;
        }
        echo json_encode(['ok' => true, 'present' => $present, 'at' => $present ? date('H:i') : null]);
        exit;
    }
    echo json_encode(['ok' => false]);
    exit;
}

$att      = service_attendance($db, $service);
$total    = count($att['rows']);
$visitors = (int)($service['visitors_count'] ?? 0);
$sourceLabels = ['whatsapp' => 'WhatsApp', 'manual' => 'Marcado', 'escala' => 'Escala'];

$pageTitle  = 'Presença: ' . $service['title'];
$activePage = 'services';
require_once __DIR__ . '/../../includes/layout.php';
?>

<div style="margin-bottom:16px">
  <a href="/pages/services/view.php?id=<?= $id ?>" style="font-size:13px;color:var(--text-muted);text-decoration:none">← <?= htmlspecialchars($service['title']) ?></a>
</div>

<div class="card" style="margin-bottom:16px">
  <div style="display:flex;flex-wrap:wrap;gap:24px;align-items:center">
    <div>
      <div style="font-size:12px;color:var(--text-muted)">Culto</div>
      <div style="font-size:15px;font-weight:500"><?= htmlspecialchars($service['title']) ?></div>
      <div style="font-size:12px;color:var(--text-muted)"><?= date('d/m/Y', strtotime($service['service_date'])) ?><?= $service['time_start'] ? ' às ' . substr($service['time_start'],0,5) : '' ?></div>
    </div>
    <div><div style="font-size:12px;color:var(--text-muted)">Membros presentes</div><div style="font-size:22px;font-weight:600;color:var(--accent)" id="cnt-present"><?= $att['present'] ?></div></div>
    <div><div style="font-size:12px;color:var(--text-muted)">Ausentes</div><div style="font-size:22px;font-weight:600" id="cnt-absent"><?= $total - $att['present'] ?></div></div>
    <div><div style="font-size:12px;color:var(--text-muted)">Frequência</div><div style="font-size:22px;font-weight:600" id="cnt-pct"><?= $total ? round($att['present'] / $total * 100) : 0 ?>%</div></div>
    <div>
      <div style="font-size:12px;color:var(--text-muted)">Visitantes</div>
      <input type="number" min="0" id="visitors" value="<?= $visitors ?>" class="form-control" style="width:90px;padding:4px 8px" onchange="saveVisitors(this.value)">
    </div>
    <div>
      <div style="font-size:12px;color:var(--text-muted)">Total no culto</div>
      <div style="font-size:22px;font-weight:600" id="cnt-total"><?= $att['present'] + $visitors ?></div>
    </div>
  </div>
</div>

<div class="card" style="padding:0">
  <div style="padding:12px 18px;border-bottom:1px solid var(--border);display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="text" id="q" class="form-control" placeholder="Buscar pelo nome…" style="flex:1;min-width:180px" oninput="applyFilter()">
    <button type="button" class="btn btn-secondary flt" data-f="all" onclick="setFilter('all')">Todos</button>
    <button type="button" class="btn btn-secondary flt" data-f="present" onclick="setFilter('present')">Presentes</button>
    <button type="button" class="btn btn-secondary flt" data-f="absent" onclick="setFilter('absent')">Ausentes</button>
  </div>

  <?php foreach ($att['rows'] as $r):
    $isPresent = $r['status'] === 'present';
    $viaEscala = $r['source'] === 'escala';
  ?>
    <div class="att-row" data-id="<?= $r['id'] ?>" data-present="<?= $isPresent ? 1 : 0 ?>" data-name="<?= htmlspecialchars(mb_strtolower($r['name'])) ?>"
         style="display:flex;align-items:center;gap:10px;padding:10px 18px;border-bottom:1px solid var(--border)">
      <div class="avatar" style="width:30px;height:30px;font-size:11px;flex-shrink:0"><?= htmlspecialchars(mb_strtoupper(mb_substr($r['name'],0,2))) ?></div>
      <div style="flex:1;min-width:0">
        <div style="font-size:13px;font-weight:500"><?= htmlspecialchars($r['name']) ?></div>
        <div class="att-info" style="font-size:11px;color:var(--text-muted)">
          <?php if ($isPresent): ?>✓ <?= date('H:i', strtotime($r['at'])) ?> · <?= $sourceLabels[$r['source']] ?? '' ?>
          <?php elseif ($r['status'] === 'invited'): ?>convite enviado, sem resposta
          <?php elseif ($r['scaled']): ?>escalado
          <?php endif; ?>
        </div>
      </div>
      <?php if ($viaEscala): ?>
        <span class="badge badge-green">Presente</span>
      <?php else: ?>
        <button type="button" class="btn att-btn <?= $isPresent ? 'btn-secondary' : 'btn-primary' ?>" style="font-size:12px;padding:5px 12px"
                onclick="toggle(this)"><?= $isPresent ? 'Desmarcar' : 'Marcar presente' ?></button>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if (!$total): ?><div class="empty-state" style="padding:24px">Nenhum membro ativo cadastrado.</div><?php endif; ?>
</div>

<script>
var curFilter = 'all';
function updateCounts() {
  var rows = document.querySelectorAll('.att-row'), p = 0;
  rows.forEach(function(r){ if (r.dataset.present === '1') p++; });
  document.getElementById('cnt-present').textContent = p;
  document.getElementById('cnt-absent').textContent = rows.length - p;
  document.getElementById('cnt-pct').textContent = (rows.length ? Math.round(p / rows.length * 100) : 0) + '%';
  document.getElementById('cnt-total').textContent = p + (parseInt(document.getElementById('visitors').value, 10) || 0);
}
function applyFilter() {
  var q = document.getElementById('q').value.trim().toLowerCase();
  document.querySelectorAll('.att-row').forEach(function(r){
    var okName = !q || r.dataset.name.indexOf(q) !== -1;
    var okF = curFilter === 'all' || (curFilter === 'present') === (r.dataset.present === '1');
    r.style.display = (okName && okF) ? 'flex' : 'none';
  });
  document.querySelectorAll('.flt').forEach(function(b){ b.style.fontWeight = b.dataset.f === curFilter ? '700' : '400'; });
}
function setFilter(f) { curFilter = f; applyFilter(); }
function post(data) {
  var fd = new FormData();
  for (var k in data) fd.append(k, data[k]);
  return fetch(location.href, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function(r){ return r.json(); });
}
function toggle(btn) {
  var row = btn.closest('.att-row');
  btn.disabled = true;
  post({action: 'toggle', member_id: row.dataset.id}).then(function(res){
    btn.disabled = false;
    if (!res.ok) { alert('Não foi possível salvar. Tente de novo.'); return; }
    row.dataset.present = res.present ? '1' : '0';
    btn.textContent = res.present ? 'Desmarcar' : 'Marcar presente';
    btn.className = 'btn att-btn ' + (res.present ? 'btn-secondary' : 'btn-primary');
    row.querySelector('.att-info').textContent = res.present ? '✓ ' + res.at + ' · Marcado' : '';
    updateCounts(); applyFilter();
  }).catch(function(){ btn.disabled = false; alert('Sem conexão. Tente de novo.'); });
}
function saveVisitors(v) { post({action: 'visitors', visitors: v}).then(updateCounts); }
applyFilter();
</script>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
