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

/** Stories ainda válidos, mais recentes primeiro. */
function nd_news_active_items(PDO $db): array {
    return $db->query("
        SELECT n.*, m.name AS author_name
        FROM nd_news n JOIN members m ON m.id = n.created_by
        WHERE n.expires_at > NOW()
        ORDER BY n.created_at DESC
    ")->fetchAll();
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
