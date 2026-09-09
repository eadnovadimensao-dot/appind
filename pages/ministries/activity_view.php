<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
auth_check();
$db       = db();
$churchId = current_church_id();
$id       = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("
    SELECT ma.*, mn.name AS ministry_name, mn.id AS ministry_id
    FROM ministry_activities ma
    JOIN ministries mn ON mn.id = ma.ministry_id
    WHERE ma.id = ? AND ma.church_id = ?
");
$stmt->execute([$id, $churchId]);
$act = $stmt->fetch();
if (!$act) { header('Location: /pages/ministries/index.php'); exit; }

$activePage = 'ministries';
require_once __DIR__ . '/../../includes/layout.php';



$pageTitle = $act['title'];

// Escala
$scaled = $db->prepare("
    SELECT m.id, m.name, m.phone, mam.role, mam.confirmed
    FROM ministry_activity_members mam
    JOIN members m ON m.id = mam.member_id
    WHERE mam.activity_id = ?
    ORDER BY m.name
");
$scaled->execute([$id]);
$scaled = $scaled->fetchAll();

$statusLabels = [
    'scheduled' => ['label'=>'Agendada',  'badge'=>'badge-blue'],
    'done'      => ['label'=>'Realizada', 'badge'=>'badge-green'],
    'cancelled' => ['label'=>'Cancelada', 'badge'=>'badge-gray'],
];
$st = $statusLabels[$act['status']] ?? ['label'=>$act['status'],'badge'=>'badge-gray'];
?>

<div style="margin-bottom:16px">
  <a href="/pages/ministries/view.php?id=<?= $act['ministry_id'] ?>" style="font-size:13px;color:var(--text-muted);text-decoration:none">
    ← <?= htmlspecialchars($act['ministry_name']) ?>
  </a>
</div>

<!-- Header -->
<div class="card" style="margin-bottom:16px">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
        <h1 style="font-size:18px;font-weight:500"><?= htmlspecialchars($act['title']) ?></h1>
        <span class="badge <?= $st['badge'] ?>"><?= $st['label'] ?></span>
      </div>
      <div style="font-size:13px;color:var(--text-muted);display:flex;flex-wrap:wrap;gap:12px">
        <span>📅 <?= date('d/m/Y', strtotime($act['activity_date'])) ?>
          <?= $act['time_start'] ? ' às ' . substr($act['time_start'],0,5) : '' ?>
          <?= $act['time_end']   ? ' — '  . substr($act['time_end'],0,5)   : '' ?>
        </span>
        <?php if ($act['location']): ?>
          <span>📍 <?= htmlspecialchars($act['location']) ?></span>
        <?php endif; ?>
      </div>
      <?php if ($act['description']): ?>
        <p style="margin-top:8px;font-size:13px;color:var(--text);line-height:1.6"><?= nl2br(htmlspecialchars($act['description'])) ?></p>
      <?php endif; ?>
    </div>
    <!-- Ações de status -->
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php if ($act['status'] === 'scheduled'): ?>
        <a href="/pages/ministries/activity_status.php?id=<?= $id ?>&status=done"
           class="btn btn-primary"
           data-confirm="Marcar esta atividade como realizada?">✓ Realizada</a>
        <a href="/pages/ministries/activity_status.php?id=<?= $id ?>&status=cancelled"
           class="btn btn-secondary" style="color:var(--red)"
           data-confirm="Cancelar esta atividade?">Cancelar</a>
      <?php endif; ?>
      <a href="/pages/ministries/view.php?id=<?= $act['ministry_id'] ?>" class="btn btn-secondary">Voltar</a>
    </div>
  </div>
</div>

<!-- Escala -->
<div class="card" style="padding:0">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
    <p style="font-weight:500;font-size:14px">Escala <span style="color:var(--text-muted);font-weight:400">(<?= count($scaled) ?> pessoas)</span></p>
  </div>
  <?php if (empty($scaled)): ?>
    <div class="empty-state" style="padding:24px">Nenhuma pessoa escalada.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Nome</th>
            <th>Função</th>
            <th>Telefone</th>
            <th>Confirmado</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($scaled as $s):
            $initials = strtoupper(implode('', array_map(fn($p) => $p[0], array_slice(explode(' ',$s['name']),0,2))));
          ?>
            <tr>
              <td>
                <div style="display:flex;align-items:center;gap:10px">
                  <div class="avatar"><?= $initials ?></div>
                  <a href="/pages/members/view.php?id=<?= $s['id'] ?>" style="color:var(--text);text-decoration:none;font-weight:500">
                    <?= htmlspecialchars($s['name']) ?>
                  </a>
                </div>
              </td>
              <td style="color:var(--text-muted)"><?= htmlspecialchars($s['role'] ?? '—') ?></td>
              <td style="color:var(--text-muted)"><?= htmlspecialchars($s['phone'] ?? '—') ?></td>
              <td>
                <a href="/pages/ministries/activity_confirm.php?activity_id=<?= $id ?>&member_id=<?= $s['id'] ?>&confirmed=<?= $s['confirmed'] ? 0 : 1 ?>"
                   class="badge <?= $s['confirmed'] ? 'badge-green' : 'badge-gray' ?>"
                   style="cursor:pointer;text-decoration:none">
                  <?= $s['confirmed'] ? '✓ Confirmado' : 'Pendente' ?>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
