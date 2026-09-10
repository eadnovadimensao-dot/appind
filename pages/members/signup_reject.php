<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
auth_check();
auth_require('manage_members');

$db = db();
$id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM member_signups WHERE id = ? AND status = 'pending'");
$stmt->execute([$id]);
$signup = $stmt->fetch();

if ($signup) {
    $db->prepare("
        UPDATE member_signups
        SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW()
        WHERE id = ?
    ")->execute([auth_member_id(), $id]);
}

header('Location: /pages/members/signups.php?rejected=1');
exit;
