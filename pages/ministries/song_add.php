<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db         = db();
$activityId = (int)($_POST['activity_id'] ?? 0);
$resourceIds = array_map('intval', $_POST['resource_ids'] ?? []);

if ($activityId && !empty($resourceIds)) {
    $ministryId = $db->prepare("SELECT ministry_id FROM ministry_activities WHERE id = ?");
    $ministryId->execute([$activityId]);
    $ministryId = $ministryId->fetchColumn();

    if ($ministryId && auth_can_manage_ministry((int)$ministryId)) {
        // Só permite anexar músicas que realmente pertencem a esse ministério
        $placeholders = implode(',', array_fill(0, count($resourceIds), '?'));
        $valid = $db->prepare("SELECT id FROM ministry_resources WHERE ministry_id = ? AND type = 'song' AND id IN ($placeholders)");
        $valid->execute(array_merge([$ministryId], $resourceIds));
        $validIds = array_map('intval', $valid->fetchAll(PDO::FETCH_COLUMN));

        // Já anexadas — evita duplicar
        $already = $db->prepare("SELECT resource_id FROM ministry_activity_songs WHERE activity_id = ? AND resource_id IS NOT NULL");
        $already->execute([$activityId]);
        $alreadyIds = array_map('intval', $already->fetchAll(PDO::FETCH_COLUMN));

        $pos = (int)$db->query("SELECT COALESCE(MAX(position),-1)+1 FROM ministry_activity_songs WHERE activity_id = $activityId")->fetchColumn();
        $sg  = $db->prepare("INSERT INTO ministry_activity_songs (activity_id, resource_id, position) VALUES (?,?,?)");
        foreach ($validIds as $rid) {
            if (in_array($rid, $alreadyIds, true)) continue;
            $sg->execute([$activityId, $rid, $pos++]);
        }
    }
}

header('Location: /pages/ministries/activity_view.php?id=' . $activityId);
exit;
