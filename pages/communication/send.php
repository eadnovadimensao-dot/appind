<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/announcements.php';
auth_check();
$db = db();
$id = (int)($_GET['id'] ?? 0);

// Reivindica o envio atomicamente (status='draft' -> 'sent') antes de despachar,
// pra duplo clique / requisições simultâneas não disparem o envio duas vezes.
if (in_array(auth_role(), ['supermaster','admin'])) {
    $claim = $db->prepare("UPDATE announcements SET status='sent', sent_at=NOW() WHERE id=? AND church_id=? AND status='draft'");
    $claim->execute([$id, current_church_id()]);
} else {
    $claim = $db->prepare("UPDATE announcements SET status='sent', sent_at=NOW() WHERE id=? AND church_id=? AND created_by=? AND status='draft'");
    $claim->execute([$id, current_church_id(), auth_member_id()]);
}

if ($claim->rowCount() === 1) {
    $stmt = $db->prepare("SELECT * FROM announcements WHERE id=?");
    $stmt->execute([$id]);
    $announcement = $stmt->fetch();
    if ($announcement) dispatchAnnouncement($db, $announcement);
}

header('Location: /pages/communication/list.php');
exit;
