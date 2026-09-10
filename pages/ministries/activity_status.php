<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db     = db();
$id     = (int)($_GET['id']     ?? 0);
$status = $_GET['status'] ?? '';

$act = $db->prepare("SELECT ministry_id FROM ministry_activities WHERE id = ? AND church_id = ?");
$act->execute([$id, current_church_id()]);
$ministryId = $act->fetchColumn();

if ($ministryId && auth_can_manage_ministry((int)$ministryId) && in_array($status, ['done','cancelled','scheduled'])) {
    $db->prepare("UPDATE ministry_activities SET status = ? WHERE id = ? AND church_id = ?")
       ->execute([$status, $id, current_church_id()]);
}

header('Location: /pages/ministries/activity_view.php?id=' . $id);
exit;
