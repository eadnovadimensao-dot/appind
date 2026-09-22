<?php
// "Quero participar": qualquer um pode ver que um ministério existe (nome, líder,
// dia, quantos membros), mas programação, materiais e pertences só quem já
// participa (ou lidera) vê. Quem não participa pode manifestar interesse, o que
// avisa o(s) líder(es) por WhatsApp — não é uma aprovação automática, o líder
// ainda vincula manualmente quando decidir.

/** Pedido pendente desse membro pra esse ministério, se houver. */
function ministry_member_pending_interest(PDO $db, int $ministryId, int $memberId): ?array {
    $q = $db->prepare("SELECT * FROM ministry_interest WHERE ministry_id = ? AND member_id = ? AND status = 'pending'");
    $q->execute([$ministryId, $memberId]);
    return $q->fetch() ?: null;
}

/** Pedidos pendentes de um ministério, pra quem lidera ver e decidir. */
function ministry_pending_interests(PDO $db, int $ministryId): array {
    $q = $db->prepare("
        SELECT mi.*, m.name AS member_name, m.phone
        FROM ministry_interest mi JOIN members m ON m.id = mi.member_id
        WHERE mi.ministry_id = ? AND mi.status = 'pending'
        ORDER BY mi.created_at
    ");
    $q->execute([$ministryId]);
    return $q->fetchAll();
}

/** Registra o interesse (se ainda não tiver um pendente) e avisa quem lidera o ministério. */
function ministry_request_interest(PDO $db, int $ministryId, int $memberId): void {
    if (ministry_member_pending_interest($db, $ministryId, $memberId)) return;
    $db->prepare("INSERT IGNORE INTO ministry_interest (ministry_id, member_id, status) VALUES (?,?,'pending')")
       ->execute([$ministryId, $memberId]);

    $info = $db->prepare("SELECT mn.name AS ministry_name, mn.church_id, m.name AS member_name FROM ministries mn, members m WHERE mn.id = ? AND m.id = ?");
    $info->execute([$ministryId, $memberId]);
    $info = $info->fetch();
    if (!$info) return;

    $leaders = $db->prepare("
        SELECT phone FROM members WHERE id IN (SELECT member_id FROM ministry_leaders WHERE ministry_id = ?) AND phone IS NOT NULL AND phone != ''
    ");
    $leaders->execute([$ministryId]);
    $msg = "⭐ *Interesse em participar*\n\n{$info['member_name']} quer participar do ministério {$info['ministry_name']}.\n\nAcesse o sistema pra vincular:\n" . APP_URL . '/pages/ministries/view.php?id=' . $ministryId;
    foreach ($leaders->fetchAll() as $l) {
        queue_whatsapp($l['phone'], $msg, (int)$info['church_id'], null, 0, null, 'ministry_interest');
    }
}

/** Leader dispensou o pedido (a pessoa continua podendo manifestar de novo depois). */
function ministry_dismiss_interest(PDO $db, int $interestId): void {
    $db->prepare("UPDATE ministry_interest SET status = 'dismissed', resolved_at = NOW() WHERE id = ?")->execute([$interestId]);
}

/** Chamado quando o líder vincula o membro pelo fluxo normal: fecha o pedido pendente, se tiver. */
function ministry_resolve_interest_on_add(PDO $db, int $ministryId, int $memberId): void {
    $db->prepare("UPDATE ministry_interest SET status = 'added', resolved_at = NOW() WHERE ministry_id = ? AND member_id = ? AND status = 'pending'")
       ->execute([$ministryId, $memberId]);
}
