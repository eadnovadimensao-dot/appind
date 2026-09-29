<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db         = db();
$songId     = (int)($_GET['song_id']     ?? 0);
$activityId = (int)($_GET['activity_id'] ?? 0);
$dir        = ($_GET['dir'] ?? '') === 'up' ? 'up' : 'down';

if ($songId && $activityId) {
    $ministryId = $db->prepare("SELECT ministry_id FROM ministry_activities WHERE id = ?");
    $ministryId->execute([$activityId]);
    $ministryId = $ministryId->fetchColumn();

    if ($ministryId && auth_can_manage_ministry((int)$ministryId)) {
        $current = $db->prepare("SELECT id, position FROM ministry_activity_songs WHERE id = ? AND activity_id = ?");
        $current->execute([$songId, $activityId]);
        $current = $current->fetch();

        if ($current) {
            $cmp  = $dir === 'up' ? '<' : '>';
            $ord  = $dir === 'up' ? 'DESC' : 'ASC';
            $neighbor = $db->prepare("
                SELECT id, position FROM ministry_activity_songs
                WHERE activity_id = ? AND position $cmp ?
                ORDER BY position $ord LIMIT 1
            ");
            $neighbor->execute([$activityId, $current['position']]);
            $neighbor = $neighbor->fetch();

            if ($neighbor) {
                $db->prepare("UPDATE ministry_activity_songs SET position = ? WHERE id = ?")
                   ->execute([$neighbor['position'], $current['id']]);
                $db->prepare("UPDATE ministry_activity_songs SET position = ? WHERE id = ?")
                   ->execute([$current['position'], $neighbor['id']]);
            }
        }
    }
}

header('Location: /pages/ministries/activity_view.php?id=' . $activityId);
exit;
