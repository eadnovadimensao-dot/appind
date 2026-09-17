<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db       = db();
$churchId = current_church_id();
$id       = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("
    SELECT s.*, m.name AS preacher_name
    FROM services s
    LEFT JOIN members m ON m.id = s.preacher_id
    WHERE s.id = ? AND s.church_id = ?
");
$stmt->execute([$id, $churchId]);
$service = $stmt->fetch();
if (!$service) { header('Location: /pages/services/index.php'); exit; }

// Ordem do culto
$items = $db->prepare("SELECT * FROM service_items WHERE service_id=? ORDER BY position ASC");
$items->execute([$id]);
$items = $items->fetchAll();

// Escala
$scale = $db->prepare("
    SELECT ss.*, m.name AS member_name, mn.name AS ministry_name
    FROM service_scale ss
    JOIN members m ON m.id = ss.member_id
    LEFT JOIN member_ministries mm ON mm.member_id = m.id
    LEFT JOIN ministries mn ON mn.id = mm.ministry_id
    WHERE ss.service_id = ?
    ORDER BY m.name
");
$scale->execute([$id]);
$scale = $scale->fetchAll();

// Check-in geral de culto
$checkins = $db->prepare("
    SELECT sci.checked_in_at, m.name AS member_name
    FROM service_checkins sci
    JOIN members m ON m.id = sci.member_id
    WHERE sci.service_id = ?
    ORDER BY sci.checked_in_at IS NULL, sci.checked_in_at DESC, m.name
");
$checkins->execute([$id]);
$checkins = $checkins->fetchAll();
$checkedInCount = count(array_filter($checkins, fn($c) => $c['checked_in_at']));

$pageTitle  = $service['title'];
$activePage = 'services';
require_once __DIR__ . '/../../includes/layout.php';

$typeLabels = [
    'sunday'  => ['label'=>'Culto de Domingo', 'badge'=>'badge-blue'],
    'weekday' => ['label'=>'Culto de Semana',  'badge'=>'badge-green'],
    'special' => ['label'=>'Culto Especial',   'badge'=>'badge-amber'],
    'prayer'  => ['label'=>'Reunião de Oração','badge'=>'badge-gray'],
];
$statusLabels = [
    'planning'  => ['label'=>'Planejando', 'badge'=>'badge-gray'],
    'confirmed' => ['label'=>'Confirmado', 'badge'=>'badge-green'],
    'done'      => ['label'=>'Realizado',  'badge'=>'badge-blue'],
];
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

$tl = $typeLabels[$service['type']]     ?? ['label'=>$service['type'],   'badge'=>'badge-gray'];
$sl = $statusLabels[$service['status']] ?? ['label'=>$service['status'], 'badge'=>'badge-gray'];

// Total de minutos estimado
$totalMin = array_sum(array_column($items, 'duration'));
?>

<!-- Header -->
<div class="card" style="margin-bottom:16px">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;flex-wrap:wrap">
        <h1 style="font-size:18px;font-weight:500"><?= htmlspecialchars($service['title']) ?></h1>
        <span class="badge <?= $tl['badge'] ?>"><?= $tl['label'] ?></span>
        <span class="badge <?= $sl['badge'] ?>"><?= $sl['label'] ?></span>
      </div>
      <div style="font-size:13px;color:var(--text-muted);display:flex;flex-wrap:wrap;gap:14px">
        <span>📅 <?= date('d/m/Y', strtotime($service['service_date'])) ?>
          <?= $service['time_start'] ? ' às ' . substr($service['time_start'],0,5) : '' ?>
          <?= $service['time_end']   ? ' — ' . substr($service['time_end'],0,5)   : '' ?>
        </span>
        <?php if ($service['preacher_name']): ?>
          <span>🎤 <?= htmlspecialchars($service['preacher_name']) ?></span>
        <?php endif; ?>
        <?php if ($service['sermon_text']): ?>
          <span>📖 <?= htmlspecialchars($service['sermon_text']) ?></span>
        <?php endif; ?>
        <?php if ($totalMin > 0): ?>
          <span>⏱ ~<?= $totalMin ?> min estimados</span>
        <?php endif; ?>
      </div>
      <?php if ($service['sermon_title']): ?>
        <div style="margin-top:6px;font-size:14px;font-style:italic;color:var(--text)">
          "<?= htmlspecialchars($service['sermon_title']) ?>"
        </div>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php if ($service['status'] === 'planning'): ?>
        <a href="/pages/services/status.php?id=<?= $id ?>&status=confirmed"
           class="btn btn-primary" data-confirm="Confirmar este culto?">✓ Confirmar</a>
      <?php elseif ($service['status'] === 'confirmed'): ?>
        <a href="/pages/services/status.php?id=<?= $id ?>&status=done"
           class="btn btn-secondary" data-confirm="Marcar como realizado?">✓ Realizado</a>
      <?php endif; ?>
      <a href="/pages/services/edit.php?id=<?= $id ?>" class="btn btn-secondary">Editar</a>
      <a href="/pages/services/index.php" class="btn btn-secondary">Voltar</a>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 320px;gap:16px">

  <!-- Ordem do culto -->
  <div class="card" style="padding:0">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
      <p style="font-weight:500;font-size:14px">📋 Ordem do culto</p>
    </div>
    <?php if (empty($items)): ?>
      <div class="empty-state" style="padding:24px">Nenhum item cadastrado.</div>
    <?php else: ?>
      <?php foreach ($items as $i => $item):
        $itl = $itemTypeLabels[$item['type']] ?? ['label'=>$item['type'],'icon'=>'➕'];
      ?>
        <div style="display:flex;align-items:center;gap:12px;padding:12px 18px;border-bottom:1px solid var(--border)">
          <div style="width:28px;height:28px;border-radius:50%;background:var(--content-bg);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;color:var(--text-muted);flex-shrink:0">
            <?= $i + 1 ?>
          </div>
          <div style="font-size:18px;flex-shrink:0"><?= $itl['icon'] ?></div>
          <div style="flex:1">
            <div style="font-size:13px;font-weight:500"><?= htmlspecialchars($item['title'] ?? $itl['label']) ?></div>
            <?php if ($item['type'] === 'worship' && $item['worship_count'] > 0): ?>
              <div style="font-size:12px;color:var(--accent)">🎵 <?= $item['worship_count'] ?> louvor(es)</div>
            <?php endif; ?>
            <?php if ($item['description']): ?>
              <div style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($item['description']) ?></div>
            <?php endif; ?>
          </div>
          <?php if ($item['duration']): ?>
            <span style="font-size:12px;color:var(--text-muted);flex-shrink:0">~<?= $item['duration'] ?>min</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if ($totalMin > 0): ?>
        <div style="padding:10px 18px;font-size:12px;color:var(--text-muted);text-align:right">
          Total estimado: <strong><?= $totalMin ?> minutos</strong>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- Escala + info -->
  <div style="display:flex;flex-direction:column;gap:16px">

    <!-- Escala -->
    <div class="card" style="padding:0">
      <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
        <p style="font-weight:500;font-size:14px">👥 Escala <span style="color:var(--text-muted);font-weight:400">(<?= count($scale) ?>)</span></p>
      </div>
      <?php if (empty($scale)): ?>
        <div class="empty-state" style="padding:20px;font-size:13px">Nenhuma pessoa escalada.</div>
      <?php else: ?>
        <?php foreach ($scale as $s): ?>
          <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid var(--border)">
            <div class="avatar" style="width:30px;height:30px;font-size:11px;flex-shrink:0">
              <?= strtoupper(substr($s['member_name'],0,2)) ?>
            </div>
            <div style="flex:1;min-width:0">
              <div style="font-size:13px;font-weight:500"><?= htmlspecialchars($s['member_name']) ?></div>
              <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($s['role'] ?? $s['ministry_name'] ?? '') ?></div>
            </div>
            <span class="badge <?= $s['confirmed']?'badge-green':'badge-gray' ?>">
              <?= $s['confirmed'] ? '✓' : '?' ?>
            </span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Presença -->
    <div class="card" style="padding:0">
      <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
        <p style="font-weight:500;font-size:14px">
          🙋 Presença
          <span style="color:var(--text-muted);font-weight:400">
            (<?= $checkedInCount ?> confirmado<?= $checkedInCount === 1 ? '' : 's' ?><?= count($checkins) ? ' de ' . count($checkins) . ' convidados' : '' ?>)
          </span>
        </p>
      </div>
      <?php if ($checkedInCount === 0): ?>
        <div class="empty-state" style="padding:20px;font-size:13px">
          <?= empty($checkins) ? 'Convite de check-in ainda não enviado.' : 'Ninguém confirmou presença ainda.' ?>
        </div>
      <?php else: ?>
        <?php foreach ($checkins as $c): if (!$c['checked_in_at']) continue; ?>
          <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid var(--border)">
            <div class="avatar" style="width:30px;height:30px;font-size:11px;flex-shrink:0">
              <?= strtoupper(substr($c['member_name'],0,2)) ?>
            </div>
            <div style="flex:1;min-width:0">
              <div style="font-size:13px;font-weight:500"><?= htmlspecialchars($c['member_name']) ?></div>
            </div>
            <span style="font-size:11px;color:var(--text-muted);flex-shrink:0"><?= date('H:i', strtotime($c['checked_in_at'])) ?></span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Observações -->
    <?php if ($service['notes']): ?>
      <div class="card">
        <p class="card-title">Observações</p>
        <p style="font-size:13px;color:var(--text);line-height:1.7"><?= nl2br(htmlspecialchars($service['notes'])) ?></p>
      </div>
    <?php endif; ?>

  </div>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
