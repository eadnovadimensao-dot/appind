<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db         = db();
$activityId = (int)($_GET['activity_id'] ?? 0);
$memberId   = (int)($_GET['member_id']   ?? 0);
$confirmed  = (int)($_GET['confirmed']   ?? 0);

$ministryId = $db->prepare("SELECT ministry_id FROM ministry_activities WHERE id = ?");
$ministryId->execute([$activityId]);
$ministryId = $ministryId->fetchColumn();

// Toggle manual do líder — mantém 'confirmed' (legado) e 'status' sincronizados,
// pra bater com o que o membro vê/responde pelo link de e-mail (respond.php).
if ($ministryId && auth_can_manage_ministry((int)$ministryId)) {
    $newStatus = $confirmed ? 'confirmed' : 'pending';
    $db->prepare("UPDATE ministry_activity_members SET confirmed = ?, status = ? WHERE activity_id = ? AND member_id = ?")
       ->execute([$confirmed, $newStatus, $activityId, $memberId]);
}

header('Location: /pages/ministries/activity_view.php?id=' . $activityId);
exit;
