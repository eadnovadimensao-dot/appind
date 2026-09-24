<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ministry_activity.php';
auth_check();

$db = db();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $db->prepare("SELECT ministry_id FROM ministry_activities WHERE id = ? AND church_id = ?");
$stmt->execute([$id, current_church_id()]);
$ministryId = $stmt->fetchColumn();
if (!$ministryId) { header('Location: /pages/ministries/index.php'); exit; }

auth_require_ministry((int)$ministryId);

$sent = ministry_send_repertoire($db, $id);

header('Location: /pages/ministries/activity_view.php?id=' . $id . '&songs_sent=' . $sent);
exit;
