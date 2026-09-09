<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ministry_activity.php';
auth_check();

$db         = db();
$churchId   = current_church_id();
$activityId = (int)($_POST['activity_id'] ?? 0);
$memberId   = (int)($_POST['member_id']   ?? 0);
$role       = trim($_POST['role'] ?? '');

$stmt = $db->prepare("
    SELECT ma.*, mn.name AS ministry_name
    FROM ministry_activities ma
    JOIN ministries mn ON mn.id = ma.ministry_id
    WHERE ma.id = ? AND ma.church_id = ?
");
$stmt->execute([$activityId, $churchId]);
$act = $stmt->fetch();

if ($act && $memberId) {
    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime($act['activity_date'] . ' -2 days'));

    $inserted = $db->prepare("
        INSERT IGNORE INTO ministry_activity_members
          (activity_id, member_id, role, status, confirm_token, token_expires_at, notified_at)
        VALUES (?,?,?,'pending',?,?,NOW())
    ");
    $inserted->execute([$activityId, $memberId, $role ?: null, $token, $expires]);

    if ($inserted->rowCount() > 0) {
        $mn = ['name' => $act['ministry_name']];
        notify_scale_invitation(
            $db, $mn, $activityId, $act['title'], $act['activity_date'], $act['time_start'],
            $memberId, $role, $churchId, auth_member_id()
        );
    }
}

header('Location: /pages/ministries/activity_view.php?id=' . $activityId);
exit;
