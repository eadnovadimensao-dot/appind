<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db       = db();
$churchId = current_church_id();
$id       = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT ministry_id FROM ministry_activities WHERE id = ? AND church_id = ?");
$stmt->execute([$id, $churchId]);
$ministryId = $stmt->fetchColumn();

if ($ministryId && auth_can_manage_ministry((int)$ministryId)) {
    // Cascateia pra ministry_activity_members, ministry_activity_songs e agenda_events
    $db->prepare("DELETE FROM ministry_activities WHERE id = ? AND church_id = ?")
       ->execute([$id, $churchId]);
    header('Location: /pages/ministries/view.php?id=' . $ministryId . '&deleted=1');
    exit;
}

header('Location: /pages/ministries/index.php');
exit;
