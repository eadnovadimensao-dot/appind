<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
$db = db();
$ministryId = (int)($_GET['ministry_id'] ?? 0);
$memberId   = (int)($_GET['member_id']   ?? 0);
if ($ministryId && $memberId && auth_can_manage_ministry($ministryId)) {
    $db->prepare("DELETE FROM member_ministries WHERE ministry_id = ? AND member_id = ?")
       ->execute([$ministryId, $memberId]);
}
header('Location: /pages/ministries/view.php?id=' . $ministryId);
exit;
