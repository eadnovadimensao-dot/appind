<?php
require_once __DIR__ . '/../../config/database.php';
$db         = db();
$activityId = (int)($_GET['activity_id'] ?? 0);
$memberId   = (int)($_GET['member_id']   ?? 0);
$confirmed  = (int)($_GET['confirmed']   ?? 0);
$db->prepare("UPDATE ministry_activity_members SET confirmed = ? WHERE activity_id = ? AND member_id = ?")
   ->execute([$confirmed, $activityId, $memberId]);
header('Location: /pages/ministries/activity_view.php?id=' . $activityId);
exit;
