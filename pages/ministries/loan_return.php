<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db       = db();
$churchId = current_church_id();
$id       = (int)($_GET['id'] ?? 0);
$memberId = auth_member_id();

$stmt = $db->prepare("SELECT ml.*, mn.id AS ministry_id FROM ministry_loans ml JOIN ministries mn ON mn.id = ml.ministry_id WHERE ml.id = ? AND ml.church_id = ? AND ml.status = 'approved'");
$stmt->execute([$id, $churchId]);
$loan = $stmt->fetch();

if ($loan) {
    $db->prepare("UPDATE ministry_loans SET status='returned', returned_at=NOW(), return_confirmed_by=? WHERE id=?")
       ->execute([$memberId, $id]);
}

header('Location: /pages/ministries/items.php?ministry_id=' . ($loan['ministry_id'] ?? 0) . '&returned=1');
exit;
