<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ministry_activity.php';
auth_check();

header('Content-Type: application/json');

$db         = db();
$activityId = (int)($_POST['activity_id'] ?? 0);
$orderIds   = array_values(array_filter(array_map('intval', explode(',', $_POST['order'] ?? ''))));

if (!$activityId || !$orderIds) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'dados inválidos']);
    exit;
}

$ministryId = $db->prepare("SELECT ministry_id FROM ministry_activities WHERE id = ?");
$ministryId->execute([$activityId]);
$ministryId = $ministryId->fetchColumn();

if (!$ministryId || !auth_can_reorder_songs($db, $activityId, (int)$ministryId)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'sem permissão']);
    exit;
}

// Confere que a lista enviada bate exatamente com as músicas dessa
// atividade — evita mexer em posição de atividade alheia por engano/má-fé.
$placeholders = implode(',', array_fill(0, count($orderIds), '?'));
$stmt = $db->prepare("SELECT id, resource_id FROM ministry_activity_songs WHERE activity_id = ? AND id IN ($placeholders)");
$stmt->execute(array_merge([$activityId], $orderIds));
$rows = $stmt->fetchAll();
$resourceById = [];
foreach ($rows as $r) $resourceById[(int)$r['id']] = $r['resource_id'] ? (int)$r['resource_id'] : null;

if (count($rows) !== count($orderIds)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'lista não bate com a atividade']);
    exit;
}

// Culto e ensaio-espelho compartilham repertório: replica a nova ordem lá,
// casando pelas mesmas músicas do catálogo (resource_id) — mesma lógica já
// usada no reorder por setas.
$partnerId = activity_song_link_partner($db, $activityId);
$partnerSongByResource = [];
if ($partnerId) {
    $partnerRows = $db->prepare("SELECT id, resource_id FROM ministry_activity_songs WHERE activity_id = ? AND resource_id IS NOT NULL");
    $partnerRows->execute([$partnerId]);
    foreach ($partnerRows->fetchAll() as $pr) {
        $partnerSongByResource[(int)$pr['resource_id']] = (int)$pr['id'];
    }
}

$db->beginTransaction();
try {
    $posStmt = $db->prepare("UPDATE ministry_activity_songs SET position = ? WHERE id = ? AND activity_id = ?");
    $partnerPosStmt = $db->prepare("UPDATE ministry_activity_songs SET position = ? WHERE id = ?");

    foreach ($orderIds as $pos => $songId) {
        $posStmt->execute([$pos, $songId, $activityId]);

        $resourceId = $resourceById[$songId] ?? null;
        if ($resourceId && isset($partnerSongByResource[$resourceId])) {
            $partnerPosStmt->execute([$pos, $partnerSongByResource[$resourceId]]);
        }
    }

    $db->commit();
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'erro ao salvar']);
}
