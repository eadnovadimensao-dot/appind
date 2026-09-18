<?php
// Gera os cultos de domingo a partir do modelo de cada igreja (Cultos →
// Configurações do culto). Roda pela rotina de todo minuto (cron), então os
// cultos existem sem depender de alguém abrir a tela de Cultos. Idempotente:
// só cria o domingo que ainda não existe.

// Quantos domingos à frente ficam criados (o supervisor da rotação é avisado
// quando o culto é gerado, então ~3 semanas de antecedência)
const SERVICE_GENERATE_SUNDAYS_AHEAD = 4;

function generate_upcoming_services(PDO $db): void {
    $templates = $db->query("SELECT * FROM service_templates WHERE active = 1")->fetchAll();
    foreach ($templates as $template) {
        generate_services_for_template($db, $template);
    }
}

function generate_services_for_template(PDO $db, array $template): void {
    $churchId = (int)$template['church_id'];

    $templateItems = $db->prepare("SELECT * FROM service_template_items WHERE template_id = ? ORDER BY position");
    $templateItems->execute([$template['id']]);
    $templateItems = $templateItems->fetchAll();

    $sundays = [];
    $d = date('N') == 7 ? strtotime('today') : strtotime('next Sunday');
    for ($i = 0; $i < SERVICE_GENERATE_SUNDAYS_AHEAD; $i++) {
        $sundays[] = date('Y-m-d', $d);
        $d = strtotime('+7 days', $d);
    }

    foreach ($sundays as $sunday) {
        $exists = $db->prepare("SELECT id FROM services WHERE church_id = ? AND service_date = ? AND type = 'sunday'");
        $exists->execute([$churchId, $sunday]);
        if ($exists->fetch()) continue;

        // Supervisor da rotação
        $rotSup = $db->prepare("
            SELECT sr.supervisor_id, sv.member_id
            FROM supervisor_rotation sr
            JOIN supervisors sv ON sv.id = sr.supervisor_id
            WHERE sr.church_id = ? AND sr.service_date = ? LIMIT 1
        ");
        $rotSup->execute([$churchId, $sunday]);
        $sup = $rotSup->fetch();

        $db->prepare("
            INSERT INTO services
              (church_id, title, type, service_date, time_start, time_end,
               supervisor_id, status, from_template, template_id)
            VALUES (?,?,?,?,?,?,?,'planning',1,?)
        ")->execute([
            $churchId,
            $template['name'] . ' — ' . date('d/m/Y', strtotime($sunday)),
            'sunday', $sunday,
            $template['time_start'],
            $template['time_end'],
            $sup['supervisor_id'] ?? null,
            $template['id'],
        ]);
        $serviceId = (int)$db->lastInsertId();

        $si = $db->prepare("INSERT INTO service_items (service_id,position,type,title,duration,worship_count) VALUES (?,?,?,?,?,?)");
        foreach ($templateItems as $item) {
            $si->execute([$serviceId, $item['position'], $item['type'], $item['title'], $item['duration'], $item['worship_count']]);
        }

        // Agenda
        $db->prepare("
            INSERT IGNORE INTO agenda_events
              (church_id, title, event_date, time_start, time_end, location_id, status, type, color, created_at)
            VALUES (?,?,?,?,?,?,'approved','event','#012a36',NOW())
        ")->execute([$churchId, $template['name'], $sunday, $template['time_start'], $template['time_end'], $template['location_id']]);

        if (!empty($sup['supervisor_id'])) notify_service_supervisor($db, $churchId, (int)$sup['member_id'], $sunday);
    }
}

function notify_service_supervisor(PDO $db, int $churchId, int $supMemberId, string $sunday): void {
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

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) return;
    require_once $autoload;

    // Chaves VAPID são do sistema: usa as da própria igreja, senão as da sede
    $vapidPublic  = setting('vapid_public_key', '', $churchId)  ?: setting('vapid_public_key', '', SEDE_ID);
    $vapidPrivate = setting('vapid_private_key', '', $churchId) ?: setting('vapid_private_key', '', SEDE_ID);
    if (!$vapidPublic || !$vapidPrivate) return;

    $subs = $db->prepare("SELECT * FROM push_subscriptions WHERE member_id = ?");
    $subs->execute([$supMemberId]);
    $subscriptions = $subs->fetchAll();
    if (empty($subscriptions)) return;

    $webPush = new \Minishlink\WebPush\WebPush(['VAPID' => [
        'subject'    => setting('vapid_subject', 'mailto:admin@igrejanovadimensao.com.br', SEDE_ID),
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
            $db->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?")
               ->execute([$report->getRequest()->getUri()->__toString()]);
        }
    }
}
