<?php
require_once __DIR__ . '/../../config/database.php';
$db = db();
$ministryId = (int)($_GET['ministry_id'] ?? 0);
$memberId   = (int)($_GET['member_id']   ?? 0);
if ($ministryId && $memberId) {
    $db->prepare("DELETE FROM member_ministries WHERE ministry_id = ? AND member_id = ?")
       ->execute([$ministryId, $memberId]);
}
header('Location: /pages/ministries/view.php?id=' . $ministryId);
exit;
