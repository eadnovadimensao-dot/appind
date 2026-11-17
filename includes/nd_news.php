<?php
// ND News: stories em vídeo (evento/novidade), gravados direto da câmera do
// celular por membros autorizados (qualquer tipo de membro, desde que
// liberado em nd_news_authors). Cada story tem validade própria, escolhida
// por quem cria; ao vencer, o vídeo some do servidor e o registro é
// apagado por completo, sem deixar histórico.

const ND_NEWS_MAX_VIDEO_BYTES = 80 * 1024 * 1024; // 80MB — folga pra um story de 30-60s

/** Qualquer tipo de membro pode ser liberado a criar ND News (lista própria, igual devocional). */
function auth_can_create_nd_news(): bool {
    if (auth_role() === 'supermaster') return true;
    $memberId = auth_member_id();
    if (!$memberId) return false;
    static $cache = [];
    if (!array_key_exists($memberId, $cache)) {
        $q = db()->prepare("SELECT 1 FROM nd_news_authors WHERE member_id = ?");
        $q->execute([$memberId]);
        $cache[$memberId] = (bool)$q->fetchColumn();
    }
    return $cache[$memberId];
}

/**
 * Stories ainda válidos, mais recentes primeiro. Sem $viewerChurchId, traz
 * todos (uso administrativo, pra gerenciar). Com $viewerChurchId, filtra pela
 * visibilidade (vale pro carrossel do Dashboard, que é o feed de verdade):
 * visibility='all' aparece pra qualquer igreja; visibility='sede' só aparece
 * pra quem está vendo como a Sede (independe de qual igreja criou o story).
 */
function nd_news_active_items(PDO $db, ?int $viewerChurchId = null): array {
    $sql    = "SELECT n.*, m.name AS author_name FROM nd_news n JOIN members m ON m.id = n.created_by WHERE n.expires_at > NOW()";
    $params = [];
    if ($viewerChurchId !== null) {
        $sql     .= " AND (n.visibility = 'all' OR (n.visibility = 'sede' AND ? = " . SEDE_ID . "))";
        $params[] = $viewerChurchId;
    }
    $sql .= " ORDER BY n.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Chamada pelo cron a cada minuto: apaga do servidor e do banco todo story
 * que já passou da validade. Sem soft-delete — é apagar mesmo, por decisão
 * explícita do usuário.
 */
function nd_news_expire_due(PDO $db): void {
    $rows = $db->query("SELECT id, video_path FROM nd_news WHERE expires_at <= NOW()")->fetchAll();
    foreach ($rows as $r) {
        if ($r['video_path']) {
            $full = __DIR__ . '/..' . $r['video_path'];
            if (file_exists($full)) @unlink($full);
        }
        $db->prepare("DELETE FROM nd_news WHERE id = ?")->execute([$r['id']]);
    }
}

/** Última vez que esse membro abriu o visualizador de stories (null = nunca). */
function nd_news_last_seen(PDO $db, int $memberId): ?string {
    $stmt = $db->prepare("SELECT last_seen_at FROM nd_news_views WHERE member_id = ?");
    $stmt->execute([$memberId]);
    return $stmt->fetchColumn() ?: null;
}

/** Marca "visto agora" — chamado assim que o visualizador abre (tela cheia), não por story individual. */
function nd_news_mark_seen(PDO $db, int $memberId): void {
    $db->prepare("
        INSERT INTO nd_news_views (member_id, last_seen_at) VALUES (?, NOW())
        ON DUPLICATE KEY UPDATE last_seen_at = NOW()
    ")->execute([$memberId]);
}

/** Algum story da lista é mais novo que a última vez que o membro viu? */
function nd_news_has_unseen(array $items, ?string $lastSeenAt): bool {
    if (!$items) return false;
    if ($lastSeenAt === null) return true;
    $lastSeenTs = strtotime($lastSeenAt);
    foreach ($items as $it) {
        if (strtotime($it['created_at']) > $lastSeenTs) return true;
    }
    return false;
}

/**
 * Push notification de story novo pra quem tem notificação ativada — respeita
 * a visibilidade do story (só Sede, ou todas as igrejas). Mesmo padrão de
 * push já usado em includes/announcements.php.
 */
function nd_news_notify_new_story(PDO $db, array $item): void {
    $vapidPublic  = setting('vapid_public_key');
    $vapidPrivate = setting('vapid_private_key');
    $vapidSubject = setting('vapid_subject', 'mailto:admin@igrejanovadimensao.com.br');
    if (!$vapidPublic || !$vapidPrivate) return;

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) return;
    require_once $autoload;

    $sql = "SELECT ps.* FROM push_subscriptions ps JOIN members m ON m.id = ps.member_id WHERE 1=1";
    if (($item['visibility'] ?? 'all') === 'sede') $sql .= " AND m.church_id = " . SEDE_ID;
    $subscriptions = $db->query($sql)->fetchAll();
    if (!$subscriptions) return;

    $webPush = new \Minishlink\WebPush\WebPush([
        'VAPID' => ['subject' => $vapidSubject, 'publicKey' => $vapidPublic, 'privateKey' => $vapidPrivate],
    ]);

    $payload = json_encode([
        'title' => '📰 Novo story no ND News',
        'body'  => mb_substr($item['title'], 0, 120),
        'url'   => '/dashboard.php?story=' . (int)$item['id'],
        'tag'   => 'nd-news-' . $item['id'],
    ]);

    foreach ($subscriptions as $sub) {
        $subscription = \Minishlink\WebPush\Subscription::create([
            'endpoint'        => $sub['endpoint'],
            'keys'            => ['p256dh' => $sub['p256dh'], 'auth' => $sub['auth_key']],
            'contentEncoding' => 'aesgcm',
        ]);
        $webPush->queueNotification($subscription, $payload);
    }

    foreach ($webPush->flush() as $report) {
        if ($report->isSubscriptionExpired()) {
            $db->prepare("DELETE FROM push_subscriptions WHERE endpoint=?")
               ->execute([$report->getRequest()->getUri()->__toString()]);
        }
    }
}
