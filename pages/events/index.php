<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
$pageTitle    = 'Agenda';
$activePage   = 'events';
$topbarAction = ['href' => '/pages/events/create.php', 'label' => 'Solicitar evento'];
require_once __DIR__ . '/../../includes/layout.php';

$db       = db();
$churchId = current_church_id();

// Mês/ano navegação
$month = (int)($_GET['month'] ?? date('n'));
$year  = (int)($_GET['year']  ?? date('Y'));
if ($month < 1)  { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }

$prevMonth = $month - 1; $prevYear = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $month + 1; $nextYear = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

$firstDay   = mktime(0,0,0,$month,1,$year);
$daysInMonth= (int)date('t', $firstDay);
$startDow   = (int)date('N', $firstDay); // 1=Mon ... 7=Sun

// Eventos do mês (aprovados + pendentes)
$events = $db->prepare("
    SELECT ae.*, l.name AS location_name, l.id AS loc_id
    FROM agenda_events ae
    LEFT JOIN locations l ON l.id = ae.location_id
    WHERE ae.church_id = ?
      AND ae.status IN ('approved','pending')
      AND MONTH(ae.event_date) = ?
      AND YEAR(ae.event_date)  = ?
    ORDER BY ae.event_date, ae.time_start
");
$events->execute([$churchId, $month, $year]);
$events = $events->fetchAll();

// Agrupar por dia
$byDay = [];
foreach ($events as $ev) {
    $day = (int)date('j', strtotime($ev['event_date']));
    $byDay[$day][] = $ev;
}

// Próximos eventos (lista)
$upcoming = $db->prepare("
    SELECT ae.*, l.name AS location_name, mn.name AS ministry_name
    FROM agenda_events ae
    LEFT JOIN locations l  ON l.id  = ae.location_id
    LEFT JOIN ministries mn ON mn.id = ae.ministry_id
    WHERE ae.church_id = ? AND ae.status = 'approved' AND ae.event_date >= CURDATE()
    ORDER BY ae.event_date, ae.time_start
    LIMIT 8
");
$upcoming->execute([$churchId]);
$upcoming = $upcoming->fetchAll();

// Pendentes de aprovação
$pending = $db->prepare("
    SELECT ae.*, l.name AS location_name, m.name AS requester_name
    FROM agenda_events ae
    LEFT JOIN locations l ON l.id = ae.location_id
    LEFT JOIN members m   ON m.id = ae.requested_by
    WHERE ae.church_id = ? AND ae.status = 'pending'
    ORDER BY ae.event_date, ae.time_start
");
$pending->execute([$churchId]);
$pending = $pending->fetchAll();

$monthNames = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho',
               'Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];

$statusColors = [
    'approved' => '#1D9E75',
    'pending'  => '#BA7517',
    'refused'  => '#A32D2D',
    'cancelled'=> '#6B7280',
];
$typeIcons = [
    'event'              => '📅',
    'ministry_activity'  => '✝️',
    'cell_meeting'       => '🔗',
];
?>

<!-- Pendentes de aprovação -->
<?php if (!empty($pending) && auth_can('approve_events')): ?>
<div style="background:#FEF3C7;border:1px solid #FCD34D;border-radius:10px;padding:14px 18px;margin-bottom:20px">
  <p style="font-size:13px;font-weight:500;color:#854F0B;margin-bottom:10px">
    ⏳ <?= count($pending) ?> solicitação(ões) aguardando aprovação
  </p>
  <div style="display:flex;flex-direction:column;gap:6px">
    <?php foreach ($pending as $p): ?>
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;background:white;border-radius:7px;padding:10px 14px">
        <div>
          <span style="font-size:13px;font-weight:500"><?= htmlspecialchars($p['title']) ?></span>
          <span style="font-size:12px;color:#6B7280;margin-left:8px">
            <?= date('d/m/Y', strtotime($p['event_date'])) ?>
            <?= substr($p['time_start'],0,5) ?> – <?= substr($p['time_end'],0,5) ?>
            <?= $p['location_name'] ? ' · ' . htmlspecialchars($p['location_name']) : '' ?>
          </span>
          <?php if ($p['requester_name']): ?>
            <span style="font-size:11px;color:#9CA3AF;margin-left:8px">por <?= htmlspecialchars($p['requester_name']) ?></span>
          <?php endif; ?>
        </div>
        <div style="display:flex;gap:6px">
          <a href="/pages/events/approve.php?id=<?= $p['id'] ?>&action=approve"
             class="btn btn-primary" style="font-size:12px;padding:5px 12px"
             data-confirm="Aprovar este evento?">✓ Aprovar</a>
          <a href="/pages/events/approve.php?id=<?= $p['id'] ?>&action=refuse"
             class="btn btn-secondary" style="font-size:12px;padding:5px 12px;color:var(--red)"
             data-confirm="Recusar este evento?">✗ Recusar</a>
          <a href="/pages/events/view.php?id=<?= $p['id'] ?>"
             class="btn btn-secondary" style="font-size:12px;padding:5px 12px">Ver</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:16px;align-items:start">

  <!-- Calendário -->
  <div class="card" style="padding:0">
    <!-- Navegação do mês -->
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
      <a href="?month=<?= $prevMonth ?>&year=<?= $prevYear ?>"
         style="color:var(--text-muted);text-decoration:none;font-size:20px;padding:4px 8px;border-radius:6px"
         onmouseover="this.style.background='var(--content-bg)'" onmouseout="this.style.background=''">‹</a>
      <h2 style="font-size:16px;font-weight:500"><?= $monthNames[$month] ?> <?= $year ?></h2>
      <a href="?month=<?= $nextMonth ?>&year=<?= $nextYear ?>"
         style="color:var(--text-muted);text-decoration:none;font-size:20px;padding:4px 8px;border-radius:6px"
         onmouseover="this.style.background='var(--content-bg)'" onmouseout="this.style.background=''">›</a>
    </div>

    <!-- Grade do calendário -->
    <div style="padding:12px">
      <!-- Cabeçalho dias da semana -->
      <div style="display:grid;grid-template-columns:repeat(7,1fr);margin-bottom:4px">
        <?php foreach(['Seg','Ter','Qua','Qui','Sex','Sáb','Dom'] as $d): ?>
          <div style="text-align:center;font-size:11px;font-weight:500;color:var(--text-muted);padding:6px 0"><?= $d ?></div>
        <?php endforeach; ?>
      </div>

      <!-- Dias -->
      <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px">
        <?php
        // Células vazias antes do dia 1
        for ($i = 1; $i < $startDow; $i++):
        ?>
          <div style="min-height:80px"></div>
        <?php endfor; ?>

        <?php for ($day = 1; $day <= $daysInMonth; $day++):
          $isToday   = ($day == date('j') && $month == date('n') && $year == date('Y'));
          $dayEvents = $byDay[$day] ?? [];
        ?>
          <div style="min-height:80px;border-radius:8px;padding:4px;background:<?= $isToday ? 'var(--accent-lt)' : 'transparent' ?>;border:1px solid <?= $isToday ? 'var(--accent-border)' : 'transparent' ?>">
            <div style="font-size:12px;font-weight:<?= $isToday ? '600' : '400' ?>;color:<?= $isToday ? 'var(--accent-dk)' : 'var(--text-muted)' ?>;text-align:right;padding:2px 4px;margin-bottom:2px">
              <?= $day ?>
            </div>
            <?php foreach (array_slice($dayEvents, 0, 3) as $ev):
              $color = $statusColors[$ev['status']] ?? '#1D9E75';
              $opacity = $ev['status'] === 'pending' ? '0.7' : '1';
            ?>
              <a href="/pages/events/view.php?id=<?= $ev['id'] ?>"
                 title="<?= htmlspecialchars($ev['title']) ?>"
                 style="display:block;background:<?= $color ?>;opacity:<?= $opacity ?>;color:white;border-radius:4px;padding:2px 5px;font-size:10px;margin-bottom:2px;text-decoration:none;overflow:hidden;white-space:nowrap;text-overflow:ellipsis">
                <?= htmlspecialchars($ev['title']) ?>
              </a>
            <?php endforeach; ?>
            <?php if (count($dayEvents) > 3): ?>
              <div style="font-size:10px;color:var(--text-muted);padding:0 4px">+<?= count($dayEvents)-3 ?> mais</div>
            <?php endif; ?>
          </div>
        <?php endfor; ?>
      </div>
    </div>

    <!-- Legenda -->
    <div style="padding:12px 20px;border-top:1px solid var(--border);display:flex;gap:16px;flex-wrap:wrap">
      <span style="font-size:11px;display:flex;align-items:center;gap:5px;color:var(--text-muted)">
        <span style="width:10px;height:10px;border-radius:2px;background:#1D9E75;display:inline-block"></span>Aprovado
      </span>
      <span style="font-size:11px;display:flex;align-items:center;gap:5px;color:var(--text-muted)">
        <span style="width:10px;height:10px;border-radius:2px;background:#BA7517;display:inline-block"></span>Pendente
      </span>
    </div>
  </div>

  <!-- Lista lateral -->
  <div style="display:flex;flex-direction:column;gap:16px">

    <!-- Próximos eventos -->
    <div class="card" style="padding:0">
      <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
        <p style="font-weight:500;font-size:14px">Próximos eventos</p>
      </div>
      <?php if (empty($upcoming)): ?>
        <div class="empty-state" style="padding:24px">Nenhum evento aprovado.</div>
      <?php else: ?>
        <?php foreach ($upcoming as $ev):
          $icon = $typeIcons[$ev['type']] ?? '📅';
        ?>
          <a href="/pages/events/view.php?id=<?= $ev['id'] ?>"
             style="display:block;padding:12px 18px;border-bottom:1px solid var(--border);text-decoration:none;color:var(--text)">
            <div style="display:flex;align-items:flex-start;gap:8px">
              <span style="font-size:16px;flex-shrink:0;margin-top:1px"><?= $icon ?></span>
              <div style="flex:1;min-width:0">
                <div style="font-size:13px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                  <?= htmlspecialchars($ev['title']) ?>
                </div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:2px">
                  <?= date('d/m', strtotime($ev['event_date'])) ?>
                  · <?= substr($ev['time_start'],0,5) ?>–<?= substr($ev['time_end'],0,5) ?>
                </div>
                <?php if ($ev['location_name']): ?>
                  <div style="font-size:11px;color:var(--text-muted)">📍 <?= htmlspecialchars($ev['location_name']) ?></div>
                <?php endif; ?>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
        <div style="padding:10px 18px">
          <a href="/pages/events/list.php" style="font-size:12px;color:var(--accent);text-decoration:none">Ver todos os eventos →</a>
        </div>
      <?php endif; ?>
    </div>

    <!-- Atalhos -->
    <div class="card">
      <p class="card-title">Ações rápidas</p>
      <div style="display:flex;flex-direction:column;gap:8px">
        <a href="/pages/events/create.php" class="btn btn-primary" style="justify-content:center">+ Solicitar evento</a>
        <a href="/pages/events/locations.php" class="btn btn-secondary" style="justify-content:center">⚙ Gerenciar locais</a>
        <a href="/pages/events/list.php" class="btn btn-secondary" style="justify-content:center">📋 Lista completa</a>
      </div>
    </div>

  </div>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
