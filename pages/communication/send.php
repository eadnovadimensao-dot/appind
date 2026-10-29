<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/announcements.php';
auth_check();
$db = db();
$id = (int)($_GET['id'] ?? 0);

if (in_array(auth_role(), ['supermaster','admin'])) {
    $stmt = $db->prepare("SELECT * FROM announcements WHERE id=? AND church_id=? AND status='draft'");
    $stmt->execute([$id, current_church_id()]);
} else {
    $stmt = $db->prepare("SELECT * FROM announcements WHERE id=? AND church_id=? AND created_by=? AND status='draft'");
    $stmt->execute([$id, current_church_id(), auth_member_id()]);
}
$announcement = $stmt->fetch();

if ($announcement) {
    dispatchAnnouncement($db, $announcement);
    $db->prepare("UPDATE announcements SET status='sent', sent_at=NOW() WHERE id=?")->execute([$id]);
}

header('Location: /pages/communication/list.php');
exit;
