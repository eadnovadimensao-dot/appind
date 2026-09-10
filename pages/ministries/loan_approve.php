<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db       = db();
$churchId = current_church_id();
$id       = (int)($_GET['id'] ?? 0);
$memberId = auth_member_id();

$stmt = $db->prepare("SELECT ml.*, mn.id AS ministry_id FROM ministry_loans ml JOIN ministries mn ON mn.id = ml.ministry_id WHERE ml.id = ? AND ml.church_id = ? AND ml.status = 'pending'");
$stmt->execute([$id, $churchId]);
$loan = $stmt->fetch();

if ($loan && auth_can_manage_ministry((int)$loan['ministry_id'])) {
    $db->prepare("UPDATE ministry_loans SET status='approved', approved_by=?, approved_at=NOW() WHERE id=?")
       ->execute([$memberId, $id]);
}

header('Location: /pages/ministries/items.php?ministry_id=' . ($loan['ministry_id'] ?? 0) . '&approved=1');
exit;
