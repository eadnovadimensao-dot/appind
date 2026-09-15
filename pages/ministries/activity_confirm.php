<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ministry_activity.php';
auth_check();

$db         = db();
$activityId = (int)($_GET['activity_id'] ?? 0);
$memberId   = (int)($_GET['member_id']   ?? 0);
$confirmed  = (int)($_GET['confirmed']   ?? 0);

$act = $db->prepare("SELECT ma.ministry_id, mn.church_id FROM ministry_activities ma JOIN ministries mn ON mn.id = ma.ministry_id WHERE ma.id = ?");
$act->execute([$activityId]);
$act = $act->fetch();
$ministryId = $act['ministry_id'] ?? null;

// Toggle manual do líder — mantém 'confirmed' (legado) e 'status' sincronizados,
// pra bater com o que o membro vê/responde pelo link de e-mail (respond.php).
if ($ministryId && auth_can_manage_ministry((int)$ministryId)) {
    $newStatus = $confirmed ? 'confirmed' : 'pending';
    $db->prepare("UPDATE ministry_activity_members SET confirmed = ?, status = ? WHERE activity_id = ? AND member_id = ?")
       ->execute([$confirmed, $newStatus, $activityId, $memberId]);

    if ($newStatus === 'confirmed') {
        queue_checkin_reminder($db, $activityId, $memberId, (int)$act['church_id']);
    }
}

header('Location: /pages/ministries/activity_view.php?id=' . $activityId);
exit;
