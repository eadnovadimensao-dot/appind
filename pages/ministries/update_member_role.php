<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db         = db();
$ministryId = (int)($_POST['ministry_id'] ?? 0);
$memberId   = (int)($_POST['member_id']   ?? 0);
$role       = trim($_POST['role'] ?? '') ?: null;

if ($ministryId && $memberId && auth_can_manage_ministry($ministryId)) {
    $db->prepare("UPDATE member_ministries SET role = ? WHERE ministry_id = ? AND member_id = ?")
       ->execute([$role, $ministryId, $memberId]);
}

header('Location: /pages/ministries/view.php?id=' . $ministryId);
exit;
