<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
$pageTitle    = 'Todos os eventos';
$activePage   = 'events';
$topbarAction = ['href' => '/pages/events/create.php', 'label' => 'Solicitar evento'];
require_once __DIR__ . '/../../includes/layout.php';

$db       = db();
$churchId = current_church_id();

$status = $_GET['status'] ?? 'approved';
$where  = ['ae.church_id = :church_id'];
$params = [':church_id' => $churchId];

if ($status !== 'all') {
    $where[]          = 'ae.status = :status';
    $params[':status'] = $status;
}

$events = $db->prepare("
    SELECT ae.*, l.name AS location_name, mn.name AS ministry_name
    FROM agenda_events ae
    LEFT JOIN locations l   ON l.id  = ae.location_id
    LEFT JOIN ministries mn ON mn.id = ae.ministry_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY ae.event_date DESC, ae.time_start DESC
");
$events->execute($params);
$events = array_map('agenda_decorate', $events->fetchAll());

$statusLabels = [
    'approved'  => ['label'=>'Aprovado',  'badge'=>'badge-green'],
    'pending'   => ['label'=>'Pendente',  'badge'=>'badge-amber'],
    'refused'   => ['label'=>'Recusado',  'badge'=>'badge-red'],
    'cancelled' => ['label'=>'Cancelado', 'badge'=>'badge-gray'],
];
$typeIcons = ['event'=>'📅','ministry_activity'=>'✝️','cell_meeting'=>'🔗'];
?>

<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap">
  <?php foreach(['approved'=>'Aprovados','pending'=>'Pendentes','refused'=>'Recusados','all'=>'Todos'] as $k=>$v): ?>
    <a href="?status=<?= $k ?>"
       class="btn <?= $status===$k?'btn-primary':'btn-secondary' ?>"
       style="font-size:13px">
      <?= $v ?>
    </a>
  <?php endforeach; ?>
</div>

<div class="card" style="padding:0">
  <?php if (empty($events)): ?>
    <div class="empty-state" style="padding:40px">
      <p style="font-size:28px;margin-bottom:8px">📅</p>
      <p>Nenhum evento encontrado.</p>
    </div>
  <?php else: ?>
    <?php foreach ($events as $ev):
      $st   = $statusLabels[$ev['status']] ?? ['label'=>$ev['status'],'badge'=>'badge-gray'];
      $icon = $typeIcons[$ev['type']] ?? '📅';
    ?>
      <div style="padding:12px 20px;border-bottom:1px solid var(--border)">
        <div style="display:flex;align-items:flex-start;gap:10px">
          <span style="font-size:18px;flex-shrink:0"><?= $icon ?></span>
          <div style="flex:1;min-width:0">
            <div style="font-size:14px;font-weight:500">
              <?= htmlspecialchars($ev['title']) ?>
              <span class="badge <?= $st['badge'] ?>" style="font-size:10px"><?= $st['label'] ?></span>
            </div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:4px;display:flex;align-items:center;gap:6px;flex-wrap:wrap">
              <span>
                <?= date('d/m/Y', strtotime($ev['event_date'])) ?>
                · <?= substr($ev['time_start'],0,5) ?> – <?= substr($ev['time_end'],0,5) ?>
              </span>
              <span>·</span>
              <span><?= htmlspecialchars($ev['location_name'] ?? 'Sem local definido') ?></span>
              <?php if ($ev['ministry_name']): ?>
                <span>·</span>
                <span><?= htmlspecialchars($ev['ministry_name']) ?></span>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;margin-left:28px">
          <a href="/pages/events/view.php?id=<?= $ev['id'] ?>" class="btn btn-secondary" style="font-size:12px;padding:5px 12px">Ver</a>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
