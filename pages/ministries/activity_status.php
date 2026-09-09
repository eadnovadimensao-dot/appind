<?php
require_once __DIR__ . '/../../config/database.php';
$db     = db();
$id     = (int)($_GET['id']     ?? 0);
$status = $_GET['status'] ?? '';
if (in_array($status, ['done','cancelled','scheduled'])) {
    $stmt = $db->prepare("UPDATE ministry_activities SET status = ? WHERE id = ? AND church_id = ?");
    $stmt->execute([$status, $id, current_church_id()]);
}
$act = $db->prepare("SELECT ministry_id FROM ministry_activities WHERE id = ?")->execute([$id]);
$act = $db->query("SELECT ministry_id FROM ministry_activities WHERE id = $id")->fetch();
header('Location: /pages/ministries/activity_view.php?id=' . $id);
exit;
