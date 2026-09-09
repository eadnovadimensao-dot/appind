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

// ── Gerar cultos de domingo automaticamente ──────────────────
$template = $db->query("SELECT * FROM service_templates WHERE church_id=$churchId AND active=1 LIMIT 1")->fetch();
if ($template) {
    $templateItems = $db->query("SELECT * FROM service_template_items WHERE template_id={$template['id']} ORDER BY position")->fetchAll();

    // Próximos 8 domingos
    $nextSundays = [];
    $d = strtotime('next Sunday');
    if (date('N') == 7) $d = strtotime('today');
    for ($i = 0; $i < 8; $i++) {
        $nextSundays[] = date('Y-m-d', $d);
        $d = strtotime('+7 days', $d);
    }

    foreach ($nextSundays as $sunday) {
        // Verificar se já existe culto neste domingo
        $exists = $db->prepare("SELECT id FROM services WHERE church_id=? AND service_date=? AND type='sunday'");
        $exists->execute([$churchId, $sunday]);
        if ($exists->fetch()) continue;

        // Buscar supervisor da rotação
        $rotSup = $db->prepare("
            SELECT sr.supervisor_id, sv.member_id
            FROM supervisor_rotation sr
            JOIN supervisors sv ON sv.id = sr.supervisor_id
            WHERE sr.church_id=? AND sr.service_date=? LIMIT 1
        ");
        $rotSup->execute([$churchId, $sunday]);
        $sup = $rotSup->fetch();

        // Criar culto
        $db->prepare("
            INSERT INTO services
              (church_id, title, type, service_date, time_start, time_end,
               supervisor_id, status, from_template, template_id, created_by)
            VALUES (?,?,?,?,?,?,?,'planning',1,?,1)
        ")->execute([
            $churchId,
            $template['name'] . ' — ' . date('d/m/Y', strtotime($sunday)),
            'sunday', $sunday,
            $template['time_start'],
            $template['time_end'],
            $sup['supervisor_id'] ?? null,
            $template['id'],
        ]);
        $serviceId = $db->lastInsertId();

        // Copiar itens do template
        $si = $db->prepare("INSERT INTO service_items (service_id,position,type,title,duration,worship_count) VALUES (?,?,?,?,?,?)");
        foreach ($templateItems as $item) {
            $si->execute([$serviceId, $item['position'], $item['type'],
                          $item['title'], $item['duration'], $item['worship_count']]);
        }

        // Inserir na agenda automaticamente
        $db->prepare("
            INSERT IGNORE INTO agenda_events
              (church_id, title, event_date, time_start, time_end,
               location_id, status, type, color, created_at)
            VALUES (?,?,?,?,?,?,'approved','event','#012a36',NOW())
        ")->execute([
            $churchId,
            $template['name'],
            $sunday,
            $template['time_start'],
            $template['time_end'],
            $template['location_id'],
        ]);

        // Notificar supervisor da semana
        if (!empty($sup['supervisor_id'])) {
            $supMemberId   = $sup['member_id'];
            $dateFormatted = date('d/m/Y', strtotime($sunday));
            $db->prepare("
                INSERT INTO announcements
                  (church_id, title, content, type, target_type, channels, status, created_by, sent_at)
                VALUES (?,?,?,'general','all','internal,push','sent',1,NOW())
            ")->execute([
                $churchId,
                "📋 Você é o responsável pelo culto de $dateFormatted",
                "Olá!\n\nVocê foi designado como supervisor responsável pelo culto do domingo $dateFormatted.\n\nAcesse o sistema para organizar a programação:\n" . APP_URL . '/pages/services/index.php',
            ]);

            // Push para o supervisor
            $autoload = __DIR__ . '/../../vendor/autoload.php';
            if (file_exists($autoload)) {
                require_once $autoload;
                $vapidPublic  = setting('vapid_public_key');
                $vapidPrivate = setting('vapid_private_key');
                if ($vapidPublic && $vapidPrivate) {
                    $subs = $db->prepare("SELECT * FROM push_subscriptions WHERE member_id=?");
                    $subs->execute([$supMemberId]);
                    $subscriptions = $subs->fetchAll();
                    if (!empty($subscriptions)) {
                        $webPush = new \Minishlink\WebPush\WebPush(['VAPID' => [
                            'subject'    => setting('vapid_subject', 'mailto:admin@igrejanovadimensao.com.br'),
                            'publicKey'  => $vapidPublic,
                            'privateKey' => $vapidPrivate,
                        ]]);
                        $payload = json_encode([
                            'title' => "📋 Culto de $dateFormatted",
                            'body'  => 'Você é o supervisor responsável. Acesse para organizar.',
                            'url'   => APP_URL . '/pages/services/index.php',
                            'tag'   => 'service-' . $sunday,
                        ]);
                        foreach ($subscriptions as $sub) {
                            $webPush->queueNotification(
                                \Minishlink\WebPush\Subscription::create([
                                    'endpoint' => $sub['endpoint'],
                                    'keys'     => ['p256dh' => $sub['p256dh'], 'auth' => $sub['auth_key']],
                                    'contentEncoding' => 'aesgcm',
                                ]),
                                $payload
                            );
                        }
                        foreach ($webPush->flush() as $report) {
                            if ($report->isSubscriptionExpired()) {
                                $db->prepare("DELETE FROM push_subscriptions WHERE endpoint=?")
                                   ->execute([$report->getRequest()->getUri()->__toString()]);
                            }
                        }
                    }
                }
            }
        }
    }

    // Recarregar lista após geração
    $stmt = $db->prepare("
        SELECT s.*, m.name AS preacher_name, COUNT(DISTINCT ss.id) AS scale_count
        FROM services s
        LEFT JOIN members m ON m.id = s.preacher_id
        LEFT JOIN service_scale ss ON ss.service_id = s.id
        WHERE s.church_id = ?
        GROUP BY s.id
        ORDER BY s.service_date DESC
        LIMIT 20
    ");
    $stmt->execute([$churchId]);
    $services = $stmt->fetchAll();
}

$pageTitle    = 'Cultos';
$activePage   = 'services';
$topbarAction = ['href' => '/pages/services/create.php', 'label' => 'Novo culto'];
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

<div style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:16px">
  <a href="/pages/services/template.php" class="btn btn-secondary">⚙ Configurações do culto</a>
  <a href="/pages/services/supervisors.php" class="btn btn-secondary">👥 Supervisores e rotação</a>
</div>

<div class="card" style="padding:0">
  <div style="padding:16px 20px;border-bottom:1px solid var(--border)">
    <span style="font-size:13px;color:var(--text-muted)"><?= count($services) ?> culto(s)</span>
  </div>

  <?php if (empty($services)): ?>
    <div class="empty-state" style="padding:40px">
      <p style="font-size:32px;margin-bottom:8px">✝️</p>
      <p>Nenhum culto cadastrado ainda.</p>
      <a href="/pages/services/create.php" class="btn btn-primary" style="margin-top:16px">+ Planejar culto</a>
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
                <a href="/pages/services/supervisor_view.php?id=<?= $s['id'] ?>" class="btn btn-secondary" style="font-size:12px;padding:5px 12px">📋 Programação</a>
                <?php if (auth_can('manage_members')): ?>
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
