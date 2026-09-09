<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db       = db();
$churchId = current_church_id();
$memberId = auth_member_id();

// Buscar ministérios que o usuário lidera
$myMinistries = $db->prepare("
    SELECT mn.id, mn.name
    FROM ministries mn
    JOIN cell_leaders cl ON cl.cell_id = mn.id
    WHERE cl.member_id = ? AND mn.church_id = ?
    UNION
    SELECT mn.id, mn.name
    FROM ministries mn
    WHERE mn.leader_id = ? AND mn.church_id = ?
");
// Simplificado: buscar todos os ministérios se admin
$isAdmin = in_array(auth_role(), ['supermaster','admin']);

if ($isAdmin) {
    $ministries = $db->prepare("SELECT id, name FROM ministries WHERE church_id=? AND active=1 ORDER BY name");
    $ministries->execute([$churchId]);
} else {
    $ministries = $db->prepare("SELECT id, name FROM ministries WHERE (leader_id=? OR id IN (SELECT ministry_id FROM member_ministries WHERE member_id=?)) AND church_id=? AND active=1 ORDER BY name");
    $ministries->execute([$memberId, $memberId, $churchId]);
}
$ministries = $ministries->fetchAll();

// Próximas atividades com status da escala
$activities = $db->prepare("
    SELECT ma.*,
           mn.name AS ministry_name,
           mn.id   AS ministry_id,
           COUNT(mam.member_id)                                           AS total_scaled,
           SUM(CASE WHEN mam.status='accepted' THEN 1 ELSE 0 END)        AS total_accepted,
           SUM(CASE WHEN mam.status='refused'  THEN 1 ELSE 0 END)        AS total_refused,
           SUM(CASE WHEN mam.status='pending'  THEN 1 ELSE 0 END)        AS total_pending
    FROM ministry_activities ma
    JOIN ministries mn ON mn.id = ma.ministry_id
    LEFT JOIN ministry_activity_members mam ON mam.activity_id = ma.id
    WHERE mn.church_id = ? AND ma.activity_date >= CURDATE()
    GROUP BY ma.id
    ORDER BY ma.activity_date ASC
    LIMIT 10
");
$activities->execute([$churchId]);
$activities = $activities->fetchAll();

// Notificações de recusa não lidas
$refusals = $db->prepare("
    SELECT sn.*, m.name AS member_name, ma.title AS activity_title,
           ma.activity_date, mn.name AS ministry_name
    FROM scale_notifications sn
    JOIN members m              ON m.id  = sn.member_id
    JOIN ministry_activities ma ON ma.id = sn.activity_id
    JOIN ministries mn          ON mn.id = ma.ministry_id
    WHERE sn.leader_id = ? AND sn.status = 'unread' AND sn.type = 'refusal'
    ORDER BY sn.created_at DESC
");
$refusals->execute([$memberId]);
$refusals = $refusals->fetchAll();

$pageTitle  = 'Escalas';
$activePage = 'worship-scale';
$topbarAction = ['href' => '/pages/worship-scale/create.php', 'label' => 'Nova escala'];
require_once __DIR__ . '/../../includes/layout.php';
?>

<!-- Recusas pendentes -->
<?php if (!empty($refusals)): ?>
<div style="background:#FCEBEB;border:1px solid #F09595;border-radius:10px;padding:14px 18px;margin-bottom:20px">
  <p style="font-size:13px;font-weight:500;color:#A32D2D;margin-bottom:10px">
    ⚠️ <?= count($refusals) ?> recusa(s) aguardando sua atenção
  </p>
  <div style="display:flex;flex-direction:column;gap:8px">
    <?php foreach ($refusals as $r): ?>
      <div style="background:white;border-radius:8px;padding:12px 16px;display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px">
        <div>
          <div style="font-size:13px;font-weight:500">
            <?= htmlspecialchars($r['member_name']) ?> recusou
            <span style="color:var(--accent)"><?= htmlspecialchars($r['activity_title']) ?></span>
          </div>
          <div style="font-size:12px;color:var(--text-muted)">
            <?= htmlspecialchars($r['ministry_name']) ?> ·
            <?= date('d/m/Y', strtotime($r['activity_date'])) ?>
          </div>
          <?php if ($r['message']): ?>
            <div style="font-size:12px;color:#A32D2D;margin-top:4px;font-style:italic">
              Motivo: <?= htmlspecialchars($r['message']) ?>
            </div>
          <?php endif; ?>
        </div>
        <div style="display:flex;gap:6px">
          <a href="/pages/ministries/activity_view.php?id=<?= $r['activity_id'] ?>"
             class="btn btn-secondary" style="font-size:12px;padding:5px 12px">Ver escala</a>
          <a href="/pages/worship-scale/dismiss_refusal.php?id=<?= $r['id'] ?>"
             class="btn btn-secondary" style="font-size:12px;padding:5px 12px;color:var(--text-muted)">Ciente</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Próximas escalas -->
<div class="card" style="padding:0;margin-bottom:16px">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
    <p style="font-weight:500;font-size:14px">Próximas atividades escaladas</p>
  </div>
  <?php if (empty($activities)): ?>
    <div class="empty-state" style="padding:32px">
      <p style="font-size:28px;margin-bottom:8px">🎵</p>
      <p>Nenhuma atividade próxima.</p>
      <a href="/pages/ministries/index.php" class="btn btn-primary" style="margin-top:16px">Ir para Ministérios</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Data</th><th>Atividade</th><th>Ministério</th><th>Escalados</th><th>Confirmados</th><th>Recusas</th><th>Pendentes</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($activities as $a):
            $deadline = strtotime($a['activity_date'] . ' -48 hours');
            $isPast48 = time() > $deadline;
          ?>
            <tr>
              <td style="white-space:nowrap">
                <div style="font-weight:500"><?= date('d/m/Y', strtotime($a['activity_date'])) ?></div>
                <?php if ($a['time_start']): ?>
                  <div style="font-size:11px;color:var(--text-muted)"><?= substr($a['time_start'],0,5) ?></div>
                <?php endif; ?>
                <?php if ($isPast48): ?>
                  <span class="badge badge-red" style="font-size:10px">Prazo encerrado</span>
                <?php endif; ?>
              </td>
              <td style="font-weight:500"><?= htmlspecialchars($a['title']) ?></td>
              <td style="color:var(--text-muted)"><?= htmlspecialchars($a['ministry_name']) ?></td>
              <td style="text-align:center"><?= $a['total_scaled'] ?></td>
              <td style="text-align:center">
                <span style="color:var(--green);font-weight:500"><?= $a['total_accepted'] ?></span>
              </td>
              <td style="text-align:center">
                <span style="color:<?= $a['total_refused']>0?'var(--red)':'var(--text-muted)' ?>;font-weight:<?= $a['total_refused']>0?'500':'400' ?>">
                  <?= $a['total_refused'] ?>
                </span>
              </td>
              <td style="text-align:center">
                <span style="color:var(--text-muted)"><?= $a['total_pending'] ?></span>
              </td>
              <td style="text-align:right">
                <a href="/pages/ministries/activity_view.php?id=<?= $a['id'] ?>"
                   class="btn btn-secondary" style="font-size:12px;padding:5px 12px">Ver</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
