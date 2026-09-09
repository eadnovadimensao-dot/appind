<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
auth_check();
$db       = db();
$churchId = current_church_id();
$id       = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM cells WHERE id = ? AND church_id = ?");
$stmt->execute([$id, $churchId]);
$cell = $stmt->fetch();
if (!$cell) { header('Location: /pages/cells/index.php'); exit; }

$activePage = 'cells';
require_once __DIR__ . '/../../includes/layout.php';



$pageTitle = $cell['name'];

// Líderes
$leaders = $db->prepare("
    SELECT m.id, m.name, cl.role
    FROM cell_leaders cl
    JOIN members m ON m.id = cl.member_id
    WHERE cl.cell_id = ?
    ORDER BY cl.role ASC
");
$leaders->execute([$id]);
$leaders = $leaders->fetchAll();

// Membros vinculados
$members = $db->prepare("
    SELECT id, name, phone, status
    FROM members
    WHERE cell_id = ? AND church_id = ?
    ORDER BY name ASC
");
$members->execute([$id, $churchId]);
$members = $members->fetchAll();

// Relatórios recentes
$reports = $db->prepare("
    SELECT cr.*, m.name AS author_name
    FROM cell_reports cr
    LEFT JOIN members m ON m.id = cr.created_by
    WHERE cr.cell_id = ?
    ORDER BY cr.report_date DESC
    LIMIT 5
");
$reports->execute([$id]);
$reports = $reports->fetchAll();

// Total de ofertas arrecadadas
$totalOffering = $db->prepare("SELECT COALESCE(SUM(offering), 0) FROM cell_reports WHERE cell_id = ?");
$totalOffering->execute([$id]);
$totalOffering = (float)$totalOffering->fetchColumn();

$days = ['monday'=>'Segunda-feira','tuesday'=>'Terça-feira','wednesday'=>'Quarta-feira',
         'thursday'=>'Quinta-feira','friday'=>'Sexta-feira','saturday'=>'Sábado','sunday'=>'Domingo'];

$statusLabels = [
    'active'=>['label'=>'Ativo','badge'=>'badge-green'],
    'visitor'=>['label'=>'Visitante','badge'=>'badge-blue'],
    'inactive'=>['label'=>'Afastado','badge'=>'badge-gray'],
    'discipline'=>['label'=>'Disciplina','badge'=>'badge-amber'],
    'transferred'=>['label'=>'Transferido','badge'=>'badge-gray'],
    'deceased'=>['label'=>'Falecido','badge'=>'badge-red'],
];

$address = array_filter([
    $cell['street'] ?? '',
    $cell['number'] ? 'nº ' . $cell['number'] : '',
    $cell['neighborhood'] ?? '',
    $cell['city'] ?? '',
]);
$addressStr = implode(', ', $address);
?>

<!-- Header -->
<div class="card" style="margin-bottom:16px">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
        <h1 style="font-size:18px;font-weight:500"><?= htmlspecialchars($cell['name']) ?></h1>
        <span class="badge <?= $cell['active'] ? 'badge-green' : 'badge-gray' ?>">
          <?= $cell['active'] ? 'Ativa' : 'Inativa' ?>
        </span>
      </div>
      <div style="font-size:13px;color:var(--text-muted);display:flex;flex-direction:column;gap:4px">
        <?php if (!empty($leaders)): ?>
          <span>👤
            <?php foreach ($leaders as $i => $l): ?>
              <strong style="color:var(--text)"><?= htmlspecialchars($l['name']) ?></strong>
              <span style="font-size:11px">(<?= $l['role']==='leader'?'Líder':'Co-líder' ?>)</span>
              <?= $i < count($leaders)-1 ? ' · ' : '' ?>
            <?php endforeach; ?>
          </span>
        <?php endif; ?>
        <?php if ($cell['day_of_week']): ?>
          <span>📅 <?= $days[$cell['day_of_week']] ?? $cell['day_of_week'] ?>
            <?= $cell['time_start'] ? ' às ' . substr($cell['time_start'],0,5) : '' ?></span>
        <?php endif; ?>
        <?php if ($addressStr): ?>
          <span>📍 <?= htmlspecialchars($addressStr) ?>
            <?= $cell['zip_code'] ? ' — CEP ' . $cell['zip_code'] : '' ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a href="/pages/cells/report_create.php?cell_id=<?= $cell['id'] ?>" class="btn btn-primary">+ Relatório</a>
      <a href="/pages/cells/edit.php?id=<?= $cell['id'] ?>" class="btn btn-secondary">Editar</a>
      <a href="/pages/cells/index.php" class="btn btn-secondary">Voltar</a>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">

  <!-- KPIs -->
  <div class="card" style="padding:16px 20px">
    <div style="display:flex;justify-content:space-around;text-align:center">
      <div>
        <div style="font-size:28px;font-weight:500;color:var(--accent)"><?= count($members) ?></div>
        <div style="font-size:12px;color:var(--text-muted)">Membros</div>
      </div>
      <div style="border-left:1px solid var(--border)"></div>
      <div>
        <div style="font-size:28px;font-weight:500;color:var(--accent)"><?= count($reports) ?></div>
        <div style="font-size:12px;color:var(--text-muted)">Relatórios</div>
      </div>
      <div style="border-left:1px solid var(--border)"></div>
      <div>
        <?php
          $lastReport = $reports[0] ?? null;
          $lastDate = $lastReport ? date('d/m', strtotime($lastReport['report_date'])) : '—';
        ?>
        <div style="font-size:28px;font-weight:500;color:var(--accent)"><?= $lastDate ?></div>
        <div style="font-size:12px;color:var(--text-muted)">Último relatório</div>
      </div>
    </div>
  </div>

  <!-- Ofertas -->
  <div class="card" style="padding:16px 20px">
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px;font-weight:500;text-transform:uppercase;letter-spacing:.05em">Ofertas</p>
    <div style="display:flex;gap:0;justify-content:space-around;text-align:center">
      <div>
        <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px">Último relatório</div>
        <div style="font-size:22px;font-weight:500;color:var(--accent)">
          R$ <?= $lastReport ? number_format($lastReport['offering'], 2, ',', '.') : '0,00' ?>
        </div>
        <?php if ($lastReport): ?>
          <div style="font-size:11px;color:var(--text-muted);margin-top:2px">
            <?= $lastReport['total_present'] ?> presentes · <?= $lastReport['visitors'] ?> visitante(s)
          </div>
        <?php endif; ?>
      </div>
      <div style="border-left:1px solid var(--border)"></div>
      <div>
        <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px">Total acumulado</div>
        <div style="font-size:22px;font-weight:500;color:var(--accent)">
          R$ <?= number_format($totalOffering, 2, ',', '.') ?>
        </div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:2px">
          em <?= count($reports) ?> relatório(s)
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Membros + Relatórios -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

  <!-- Membros -->
  <div class="card" style="padding:0">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
      <p style="font-weight:500;font-size:14px">Membros <span style="color:var(--text-muted);font-weight:400">(<?= count($members) ?>)</span></p>
      <button onclick="var f=document.getElementById('add-member-form');f.style.display=f.style.display==='none'?'block':'none'"
              class="btn btn-secondary" style="font-size:12px;padding:5px 12px">+ Vincular</button>
    </div>
    <!-- Vincular membro existente -->
    <div id="add-member-form" style="display:none;padding:12px 18px;border-bottom:1px solid var(--border);background:#fafafa">
      <?php
        $avail = $db->prepare("SELECT id, name FROM members WHERE church_id = ? AND (cell_id IS NULL OR cell_id != ?) AND status='active' ORDER BY name");
        $avail->execute([$churchId, $id]);
        $avail = $avail->fetchAll();
      ?>
      <form method="POST" action="/pages/cells/add_member.php" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="cell_id" value="<?= $id ?>">
        <div style="flex:1;min-width:200px">
          <label class="form-label">Selecione o membro</label>
          <select name="member_id" class="form-control">
            <option value="">Escolha…</option>
            <?php foreach ($avail as $av): ?>
              <option value="<?= $av['id'] ?>"><?= htmlspecialchars($av['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-bottom:16px">Vincular</button>
      </form>
    </div>
    <?php if (empty($members)): ?>
      <div class="empty-state" style="padding:24px">Nenhum membro vinculado.</div>
    <?php else: ?>
      <?php foreach ($members as $m):
        $initials = strtoupper(implode('', array_map(fn($p) => $p[0], array_slice(explode(' ',$m['name']),0,2))));
        $st = $statusLabels[$m['status']] ?? ['label'=>$m['status'],'badge'=>'badge-gray'];
      ?>
        <div style="display:flex;align-items:center;gap:10px;padding:10px 18px;border-bottom:1px solid var(--border)">
          <div class="avatar"><?= $initials ?></div>
          <div style="flex:1">
            <a href="/pages/members/view.php?id=<?= $m['id'] ?>" style="font-size:13px;font-weight:500;color:var(--text);text-decoration:none">
              <?= htmlspecialchars($m['name']) ?>
            </a>
            <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($m['phone'] ?? '') ?></div>
          </div>
          <span class="badge <?= $st['badge'] ?>"><?= $st['label'] ?></span>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Relatórios recentes -->
  <div class="card" style="padding:0">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
      <p style="font-weight:500;font-size:14px">Relatórios recentes</p>
      <a href="/pages/cells/reports.php?cell_id=<?= $id ?>" style="font-size:12px;color:var(--accent);text-decoration:none">Ver todos</a>
    </div>
    <?php if (empty($reports)): ?>
      <div class="empty-state" style="padding:24px">Nenhum relatório enviado ainda.</div>
    <?php else: ?>
      <?php foreach ($reports as $r): ?>
        <a href="/pages/cells/report_view.php?id=<?= $r['id'] ?>"
           style="display:block;padding:12px 18px;border-bottom:1px solid var(--border);text-decoration:none;color:var(--text)">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2px">
            <span style="font-size:13px;font-weight:500">
              <?= date('d/m/Y', strtotime($r['report_date'])) ?>
            </span>
            <span style="font-size:12px;color:var(--text-muted)"><?= $r['total_present'] ?> presentes</span>
          </div>
          <?php if ($r['subject']): ?>
            <div style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($r['subject']) ?></div>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
