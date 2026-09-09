<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db         = db();
$songId     = (int)($_GET['song_id']     ?? 0);
$activityId = (int)($_GET['activity_id'] ?? 0);

if ($songId && $activityId) {
    $db->prepare("DELETE FROM ministry_activity_songs WHERE id = ? AND activity_id = ?")
       ->execute([$songId, $activityId]);
}

header('Location: /pages/ministries/activity_view.php?id=' . $activityId);
exit;
