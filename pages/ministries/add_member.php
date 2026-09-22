<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ministry_interest.php';
auth_check();
$db = db();
$ministryId = (int)($_POST['ministry_id'] ?? 0);
$memberId   = (int)($_POST['member_id']   ?? 0);
$role       = trim($_POST['role'] ?? '') ?: null;
if ($ministryId && $memberId && auth_can_manage_ministry($ministryId)) {
    $db->prepare("INSERT IGNORE INTO member_ministries (member_id, ministry_id, joined_at, role) VALUES (?,?,CURDATE(),?)")
       ->execute([$memberId, $ministryId, $role]);
    ministry_resolve_interest_on_add($db, $ministryId, $memberId);
}
header('Location: /pages/ministries/view.php?id=' . $ministryId);
exit;
