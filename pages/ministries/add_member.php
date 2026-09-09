<?php
require_once __DIR__ . '/../../config/database.php';
$db = db();
$ministryId = (int)($_POST['ministry_id'] ?? 0);
$memberId   = (int)($_POST['member_id']   ?? 0);
if ($ministryId && $memberId) {
    $db->prepare("INSERT IGNORE INTO member_ministries (member_id, ministry_id, joined_at) VALUES (?,?,CURDATE())")
       ->execute([$memberId, $ministryId]);
}
header('Location: /pages/ministries/view.php?id=' . $ministryId);
exit;
