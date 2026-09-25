<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
auth_check();
$db       = db();
$churchId = current_church_id();
$id       = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("
    SELECT ae.*, l.name AS location_name, l.capacity,
           mn.name AS ministry_name, m.name AS requester_name, m2.name AS approver_name
    FROM agenda_events ae
    LEFT JOIN locations l   ON l.id  = ae.location_id
    LEFT JOIN ministries mn ON mn.id = ae.ministry_id
    LEFT JOIN members m     ON m.id  = ae.requested_by
    LEFT JOIN members m2    ON m2.id = ae.approved_by
    WHERE ae.id = ? AND ae.church_id = ?
");
$stmt->execute([$id, $churchId]);
$ev = $stmt->fetch();
if (!$ev) { header('Location: /pages/events/index.php'); exit; }
$ev = agenda_decorate($ev);

$activePage = 'events';
require_once __DIR__ . '/../../includes/layout.php';



$pageTitle = $ev['title'];

$statusLabels = [
    'approved'  => ['label'=>'Aprovado',  'badge'=>'badge-green'],
    'pending'   => ['label'=>'Pendente',  'badge'=>'badge-amber'],
    'refused'   => ['label'=>'Recusado',  'badge'=>'badge-red'],
    'cancelled' => ['label'=>'Cancelado', 'badge'=>'badge-gray'],
];
$st = $statusLabels[$ev['status']] ?? ['label'=>$ev['status'],'badge'=>'badge-gray'];

$recurrenceLabels = ['none'=>'Sem recorrência','weekly'=>'Semanal','monthly'=>'Mensal'];
?>

<div style="margin-bottom:16px">
  <a href="/pages/events/index.php" style="font-size:13px;color:var(--text-muted);text-decoration:none">← Agenda</a>
</div>

<div class="card" style="margin-bottom:16px">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
        <div style="width:14px;height:14px;border-radius:3px;background:<?= htmlspecialchars($ev['color']) ?>;flex-shrink:0"></div>
        <h1 style="font-size:18px;font-weight:500"><?= htmlspecialchars($ev['title']) ?></h1>
        <span class="badge <?= $st['badge'] ?>"><?= $st['label'] ?></span>
      </div>
      <div style="font-size:13px;color:var(--text-muted);display:flex;flex-direction:column;gap:4px">
        <span>📅 <?= date('d/m/Y', strtotime($ev['event_date'])) ?>
          · <?= substr($ev['time_start'],0,5) ?> – <?= substr($ev['time_end'],0,5) ?>
          <?= $ev['recurrence'] !== 'none' ? ' · ' . $recurrenceLabels[$ev['recurrence']] : '' ?>
        </span>
        <?php if ($ev['location_name']): ?>
          <span>📍 <?= htmlspecialchars($ev['location_name']) ?>
            <?= $ev['capacity'] ? ' (até ' . $ev['capacity'] . ' pessoas)' : '' ?>
          </span>
        <?php endif; ?>
        <?php if ($ev['ministry_name']): ?>
          <span>✝️ <?= htmlspecialchars($ev['ministry_name']) ?></span>
        <?php endif; ?>
        <?php if ($ev['description']): ?>
          <p style="margin-top:6px;color:var(--text);line-height:1.6"><?= nl2br(htmlspecialchars($ev['description'])) ?></p>
        <?php endif; ?>
      </div>
    </div>
    <?php
      $canApproveEvents = auth_can('approve_events');
      $isOwnRequest     = (int)($ev['requested_by'] ?? 0) === (int)auth_member_id();
    ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php if ($ev['status'] === 'pending' && $canApproveEvents): ?>
        <a href="/pages/events/approve.php?id=<?= $id ?>&action=approve"
           class="btn btn-primary" data-confirm="Aprovar este evento?">✓ Aprovar</a>
        <a href="/pages/events/approve.php?id=<?= $id ?>&action=refuse"
           class="btn btn-secondary" style="color:var(--red)" data-confirm="Recusar este evento?">✗ Recusar</a>
      <?php endif; ?>
      <?php if (in_array($ev['status'], ['approved','pending']) && ($canApproveEvents || $isOwnRequest)): ?>
        <a href="/pages/events/edit.php?id=<?= $id ?>" class="btn btn-secondary">Editar</a>
      <?php endif; ?>
      <a href="/pages/events/index.php" class="btn btn-secondary">Voltar</a>
    </div>
  </div>

  <?php if ($ev['status'] === 'refused' && $ev['refused_reason']): ?>
    <div style="margin-top:12px;background:#FCEBEB;border-radius:7px;padding:10px 14px;font-size:13px;color:#A32D2D">
      Motivo da recusa: <?= htmlspecialchars($ev['refused_reason']) ?>
    </div>
  <?php endif; ?>
</div>

<!-- Informações adicionais -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
  <div class="card">
    <p class="card-title">Detalhes</p>
    <?php
    $rows = [
      ['Solicitado por', $ev['requester_name'] ?? 'Sistema'],
      ['Aprovado por',   $ev['approver_name']  ?? '—'],
      ['Aprovado em',    $ev['approved_at'] ? date('d/m/Y H:i', strtotime($ev['approved_at'])) : '—'],
      ['Tipo',           $ev['type'] === 'ministry_activity' ? 'Atividade de ministério' : 'Evento geral'],
    ];
    foreach ($rows as [$label, $value]):
    ?>
      <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px">
        <span style="color:var(--text-muted)"><?= $label ?></span>
        <span style="font-weight:500"><?= htmlspecialchars($value) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
