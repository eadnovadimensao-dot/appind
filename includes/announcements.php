<?php
// Funções de despacho de avisos (Comunicados) — usadas tanto no envio
// imediato (create.php) quanto no envio de rascunhos (send.php).

function getRecipients(PDO $db, int $churchId, string $targetType, ?int $targetId): array {
    // Sempre só a igreja selecionada: "todos", célula e ministério nunca alcançam outra igreja
    $sql    = "SELECT m.id, m.name, m.email, m.phone FROM members m WHERE m.status = 'active' AND m.church_id = ?";
    $params = [$churchId];

    if ($targetType === 'cell') {
        $sql .= " AND m.cell_id = ?";
        $params[] = $targetId;
    } elseif ($targetType === 'ministry') {
        $sql .= " AND m.id IN (SELECT member_id FROM member_ministries WHERE ministry_id = ?)";
        $params[] = $targetId;
    } elseif ($targetType !== 'all') {
        return [];
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function sendEmails(PDO $db, int $announcementId, array $recipients, string $title, string $content): void {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) return;
    require_once $autoload;

    $churchName = setting('church_name', 'Igreja Manager');
    $smtpHost   = setting('smtp_host',   'localhost');
    $smtpPort   = (int)setting('smtp_port', '587');
    $smtpUser   = setting('smtp_user',   '');
    $smtpPass   = setting('smtp_pass',   '');
    $smtpFrom   = setting('church_email') ?: $smtpUser;

    $sl = $db->prepare("INSERT IGNORE INTO announcement_sends (announcement_id, member_id, channel) VALUES (?,?,'email')");

    foreach ($recipients as $r) {
        if (empty($r['email'])) continue;
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $smtpHost;
            $mail->Port       = $smtpPort;
            $mail->SMTPAuth   = !empty($smtpUser);
            $mail->Username   = $smtpUser;
            $mail->Password   = $smtpPass;
            $mail->SMTPSecure = $smtpPort == 465
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->CharSet    = 'UTF-8';
            $mail->setFrom($smtpFrom, $churchName);
            $mail->addAddress($r['email'], $r['name']);
            $mail->Subject    = $title;
            $mail->Body       = "Olá, {$r['name']}!\n\n{$content}\n\n— {$churchName}";
            $mail->send();
            $sl->execute([$announcementId, $r['id']]);
        } catch (\Exception $e) {
            // Log silencioso — não interrompe o fluxo
        }
    }
}

function sendPushNotifications(PDO $db, int $announcementId, array $recipients, string $title, string $content): void {
    $vapidPublic  = setting('vapid_public_key');
    $vapidPrivate = setting('vapid_private_key');
    $vapidSubject = setting('vapid_subject', 'mailto:admin@igrejanovadimensao.com.br');

    if (!$vapidPublic || !$vapidPrivate) return;

    // Verificar se web-push está instalado
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) return;
    require_once $autoload;

    $webPush = new \Minishlink\WebPush\WebPush([
        'VAPID' => [
            'subject'    => $vapidSubject,
            'publicKey'  => $vapidPublic,
            'privateKey' => $vapidPrivate,
        ],
    ]);

    $recipientIds = array_column($recipients, 'id');
    if (empty($recipientIds)) return;

    $placeholders = implode(',', array_fill(0, count($recipientIds), '?'));
    $subs = $db->prepare("SELECT * FROM push_subscriptions WHERE member_id IN ($placeholders)");
    $subs->execute($recipientIds);
    $subscriptions = $subs->fetchAll();

    $sl = $db->prepare("INSERT IGNORE INTO announcement_sends (announcement_id, member_id, channel) VALUES (?,?,'push')");

    $payload = json_encode([
        'title' => $title,
        'body'  => mb_substr(strip_tags($content), 0, 120),
        'url'   => '/pages/communication/index.php',
        'tag'   => 'announcement-' . $announcementId,
    ]);

    foreach ($subscriptions as $sub) {
        $subscription = \Minishlink\WebPush\Subscription::create([
            'endpoint'        => $sub['endpoint'],
            'keys'            => ['p256dh' => $sub['p256dh'], 'auth' => $sub['auth_key']],
            'contentEncoding' => 'aesgcm',
        ]);
        $webPush->queueNotification($subscription, $payload);
        $sl->execute([$announcementId, $sub['member_id']]);
    }

    // Enviar todas as notificações
    foreach ($webPush->flush() as $report) {
        if ($report->isSubscriptionExpired()) {
            // Remover subscription expirada
            $db->prepare("DELETE FROM push_subscriptions WHERE endpoint=?")
               ->execute([$report->getRequest()->getUri()->__toString()]);
        }
    }
}

function sendWhatsappAnnouncement(PDO $db, int $announcementId, array $recipients, string $title, string $content, int $churchId): void {
    $churchName = setting('church_name', 'Igreja', $churchId);
    $message    = "*{$churchName}*\n\n*{$title}*\n\n{$content}";

    $sl = $db->prepare("INSERT IGNORE INTO announcement_sends (announcement_id, member_id, channel) VALUES (?,?,'whatsapp')");

    foreach ($recipients as $r) {
        if (empty($r['phone'])) continue;
        queue_whatsapp($r['phone'], $message, $churchId);
        $sl->execute([$announcementId, $r['id']]);
    }
}

// Despacha um aviso em todos os canais marcados (exceto 'internal', que não
// precisa de ação — já aparece no mural por estar na tabela announcements).
function dispatchAnnouncement(PDO $db, array $announcement): void {
    $channels = array_filter(explode(',', $announcement['channels'] ?? ''));
    if (empty($channels)) return;

    $recipients = getRecipients(
        $db,
        (int)$announcement['church_id'],
        $announcement['target_type'],
        $announcement['target_id'] !== null ? (int)$announcement['target_id'] : null
    );

    $title   = $announcement['title'];
    $content = $announcement['content'];

    if (in_array('email', $channels)) {
        sendEmails($db, (int)$announcement['id'], $recipients, $title, $content);
    }
    if (in_array('push', $channels)) {
        sendPushNotifications($db, (int)$announcement['id'], $recipients, $title, $content);
    }
    if (in_array('whatsapp', $channels)) {
        sendWhatsappAnnouncement($db, (int)$announcement['id'], $recipients, $title, $content, (int)$announcement['church_id']);
    }
}
