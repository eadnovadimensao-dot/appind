<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
auth_check();

$db         = db();
$ministryId = (int)($_GET['ministry_id'] ?? 0);
$memberId   = (int)($_GET['member_id'] ?? 0);

$stmt = $db->prepare("
    SELECT mn.*, ch.name AS branch_name
    FROM ministries mn
    LEFT JOIN churches ch ON ch.id = mn.church_id
    WHERE mn.id = ? AND ch.id = ?
");
$stmt->execute([$ministryId, current_church_id()]);
$mn = $stmt->fetch();
if (!$mn) { header('Location: /pages/ministries/index.php'); exit; }

$mStmt = $db->prepare("SELECT mm.role, m.* FROM member_ministries mm JOIN members m ON m.id = mm.member_id WHERE mm.ministry_id = ? AND mm.member_id = ?");
$mStmt->execute([$ministryId, $memberId]);
$member = $mStmt->fetch();
if (!$member) { header('Location: /pages/ministries/view.php?id=' . $ministryId); exit; }

// Só pode ver quem gerencia o ministério, ou a própria pessoa
$canManage = auth_can_manage_ministry($ministryId);
$isSelf    = auth_member_id() && (int)auth_member_id() === $memberId;
if (!$canManage && !$isSelf) {
    http_response_code(403);
    include __DIR__ . '/../../includes/403.php';
    exit;
}

$pageTitle  = $member['name'] . ' · ' . $mn['name'];
$activePage = 'ministries';
require_once __DIR__ . '/../../includes/layout.php';

// Histórico de escalas desse membro nesse ministério
$history = $db->prepare("
    SELECT mam.role, mam.status, mam.confirmed, mam.refuse_reason,
           ma.id AS activity_id, ma.activity_date, ma.activity_type, ma.title, ma.status AS activity_status
    FROM ministry_activity_members mam
    JOIN ministry_activities ma ON ma.id = mam.activity_id
    WHERE mam.member_id = ? AND ma.ministry_id = ?
    ORDER BY ma.activity_date DESC, ma.id DESC
");
$history->execute([$memberId, $ministryId]);
$history = $history->fetchAll();

$total     = count($history);
$accepted  = 0;
$refused   = 0;
$pending   = 0;
$roleCount = [];
foreach ($history as $h) {
    if ($h['status'] === 'confirmed') $accepted++;
    elseif ($h['status'] === 'refused') $refused++;
    else $pending++;
    if ($h['role']) $roleCount[$h['role']] = ($roleCount[$h['role']] ?? 0) + 1;
}
arsort($roleCount);
$answered    = $accepted + $refused;
$confirmRate = $answered > 0 ? round($accepted / $answered * 100) : null;

$statusLabels = [
    'confirmed' => ['label' => 'Confirmou',  'badge' => 'badge-green'],
    'refused'   => ['label' => 'Recusou',    'badge' => 'badge-red'],
    'pending'   => ['label' => 'Aguardando', 'badge' => 'badge-gray'],
];
$typeLabels = ['culto' => 'Culto', 'ensaio' => 'Ensaio'];

$initials = strtoupper(implode('', array_map(fn($p) => $p[0], array_slice(explode(' ', $member['name']), 0, 2))));
?>

<div style="margin-bottom:16px">
  <a href="/pages/ministries/view.php?id=<?= $ministryId ?>" style="font-size:13px;color:var(--text-muted);text-decoration:none">
    ← <?= htmlspecialchars($mn['name']) ?>
  </a>
</div>

<!-- Header -->
<div class="card" style="margin-bottom:16px">
  <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
    <div class="avatar" style="width:52px;height:52px;font-size:18px"><?= $initials ?></div>
    <div style="flex:1;min-width:0">
      <h1 style="font-size:18px;font-weight:500"><?= htmlspecialchars($member['name']) ?></h1>
      <div style="font-size:13px;color:var(--text-muted);margin-top:2px">
        🎵 <?= $member['role'] ? htmlspecialchars($member['role']) : 'Sem função definida' ?>
      </div>
    </div>
    <a href="/pages/members/view.php?id=<?= $memberId ?>" class="btn btn-secondary">Ver cadastro completo</a>
  </div>
</div>

<!-- Estatísticas -->
<div class="kpi-grid" style="margin-bottom:16px">
  <div class="kpi">
    <div class="kpi-icon">📋</div>
    <div class="kpi-label">Vezes escalado(a)</div>
    <div class="kpi-value"><?= $total ?></div>
  </div>
  <div class="kpi">
    <div class="kpi-icon">✅</div>
    <div class="kpi-label">Taxa de confirmação</div>
    <div class="kpi-value"><?= $confirmRate !== null ? $confirmRate . '%' : '—' ?></div>
  </div>
  <div class="kpi">
    <div class="kpi-icon">❌</div>
    <div class="kpi-label">Recusas</div>
    <div class="kpi-value"><?= $refused ?></div>
  </div>
  <div class="kpi">
    <div class="kpi-icon">⏳</div>
    <div class="kpi-label">Aguardando resposta</div>
    <div class="kpi-value"><?= $pending ?></div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1.5fr;gap:16px">

  <!-- Funções mais comuns -->
  <div class="card" style="padding:0">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
      <p style="font-weight:500;font-size:14px">Funções que já exerceu</p>
    </div>
    <?php if (empty($roleCount)): ?>
      <div class="empty-state" style="padding:24px">Nenhum histórico ainda.</div>
    <?php else: ?>
      <?php foreach ($roleCount as $role => $count): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 18px;border-bottom:1px solid var(--border);font-size:13px">
          <span><?= htmlspecialchars($role) ?></span>
          <span class="badge badge-gray"><?= $count ?>×</span>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Histórico -->
  <div class="card" style="padding:0">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
      <p style="font-weight:500;font-size:14px">Histórico de escalas <span style="color:var(--text-muted);font-weight:400">(<?= count($history) ?>)</span></p>
    </div>
    <?php if (empty($history)): ?>
      <div class="empty-state" style="padding:24px">Ainda não foi escalado(a) nesse ministério.</div>
    <?php else: ?>
      <?php foreach (array_slice($history, 0, 40) as $h):
        $st = $statusLabels[$h['status']] ?? ['label' => $h['status'], 'badge' => 'badge-gray'];
      ?>
        <a href="/pages/ministries/activity_view.php?id=<?= $h['activity_id'] ?>"
           style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:10px 18px;border-bottom:1px solid var(--border);text-decoration:none;color:var(--text)">
          <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:500"><?= htmlspecialchars($h['title']) ?></div>
            <div style="font-size:11px;color:var(--text-muted)">
              <?= $typeLabels[$h['activity_type']] ?? $h['activity_type'] ?> ·
              <?= date('d/m/Y', strtotime($h['activity_date'])) ?>
              <?= $h['role'] ? ' · ' . htmlspecialchars($h['role']) : '' ?>
            </div>
            <?php if ($h['status'] === 'refused' && $h['refuse_reason']): ?>
              <div style="font-size:11px;color:var(--text-muted);font-style:italic;margin-top:2px">"<?= htmlspecialchars($h['refuse_reason']) ?>"</div>
            <?php endif; ?>
          </div>
          <span class="badge <?= $st['badge'] ?>" style="flex-shrink:0"><?= $st['label'] ?></span>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
