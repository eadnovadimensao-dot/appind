<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db       = db();
$churchId = current_church_id();

$services = $db->prepare("
    SELECT s.*, m.name AS preacher_name,
           COUNT(DISTINCT ss.id) AS scale_count
    FROM services s
    LEFT JOIN members m  ON m.id = s.preacher_id
    LEFT JOIN service_scale ss ON ss.service_id = s.id
    WHERE s.church_id = ?
    GROUP BY s.id
    ORDER BY s.service_date DESC
    LIMIT 20
");
$services->execute([$churchId]);
$services = $services->fetchAll();


$pageTitle    = 'Cultos';
$activePage   = 'services';
$canEditServices = auth_can_edit_services();
if ($canEditServices) $topbarAction = ['href' => '/pages/services/create.php', 'label' => 'Novo culto'];
require_once __DIR__ . '/../../includes/layout.php';

$typeLabels = [
    'sunday'  => ['label'=>'Domingo',  'badge'=>'badge-blue'],
    'weekday' => ['label'=>'Semana',   'badge'=>'badge-green'],
    'special' => ['label'=>'Especial', 'badge'=>'badge-amber'],
    'prayer'  => ['label'=>'Oração',   'badge'=>'badge-gray'],
];
$statusLabels = [
    'planning'  => ['label'=>'Planejando', 'badge'=>'badge-gray'],
    'confirmed' => ['label'=>'Confirmado', 'badge'=>'badge-green'],
    'done'      => ['label'=>'Realizado',  'badge'=>'badge-blue'],
];
?>

<?php if ($canEditServices): ?>
<div style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:16px">
  <a href="/pages/services/template.php" class="btn btn-secondary">⚙ Configurações do culto</a>
  <a href="/pages/services/supervisors.php" class="btn btn-secondary">👥 Supervisores e rotação</a>
</div>
<?php endif; ?>

<div class="card" style="padding:0">
  <div style="padding:16px 20px;border-bottom:1px solid var(--border)">
    <span style="font-size:13px;color:var(--text-muted)"><?= count($services) ?> culto(s)</span>
  </div>

  <?php if (empty($services)): ?>
    <div class="empty-state" style="padding:40px">
      <p style="font-size:32px;margin-bottom:8px">✝️</p>
      <p>Nenhum culto cadastrado ainda.</p>
      <?php if ($canEditServices): ?>
        <a href="/pages/services/create.php" class="btn btn-primary" style="margin-top:16px">+ Planejar culto</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Data</th><th>Culto</th><th>Tipo</th><th>Pregador</th><th>Escala</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($services as $s):
            $tl = $typeLabels[$s['type']]     ?? ['label'=>$s['type'],   'badge'=>'badge-gray'];
            $sl = $statusLabels[$s['status']] ?? ['label'=>$s['status'], 'badge'=>'badge-gray'];
          ?>
            <tr>
              <td style="white-space:nowrap;font-weight:500">
                <?= date('d/m/Y', strtotime($s['service_date'])) ?>
                <?php if ($s['time_start']): ?>
                  <div style="font-size:11px;color:var(--text-muted)"><?= substr($s['time_start'],0,5) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <div style="font-weight:500"><?= htmlspecialchars($s['title']) ?></div>
                <?php if ($s['sermon_title']): ?>
                  <div style="font-size:12px;color:var(--text-muted)">📖 <?= htmlspecialchars($s['sermon_title']) ?></div>
                <?php endif; ?>
              </td>
              <td><span class="badge <?= $tl['badge'] ?>"><?= $tl['label'] ?></span></td>
              <td style="color:var(--text-muted);font-size:13px"><?= htmlspecialchars($s['preacher_name'] ?? '—') ?></td>
              <td>
                <span style="font-weight:500"><?= $s['scale_count'] ?></span>
                <span style="font-size:12px;color:var(--text-muted)"> pessoas</span>
              </td>
              <td><span class="badge <?= $sl['badge'] ?>"><?= $sl['label'] ?></span></td>
              <td style="text-align:right">
                <a href="/pages/services/view.php?id=<?= $s['id'] ?>" class="btn btn-secondary" style="font-size:12px;padding:5px 12px">Ver</a>
                <?php if ($canEditServices): ?>
                <a href="/pages/services/supervisor_view.php?id=<?= $s['id'] ?>" class="btn btn-secondary" style="font-size:12px;padding:5px 12px">📋 Programação</a>
                <a href="/pages/services/edit.php?id=<?= $s['id'] ?>" class="btn btn-secondary" style="font-size:12px;padding:5px 12px">Editar</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
