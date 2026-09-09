<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db         = db();
$activityId = (int)($_POST['activity_id'] ?? 0);
$title      = trim($_POST['title'] ?? '');
$key        = trim($_POST['key_tone'] ?? '') ?: null;
$link       = trim($_POST['reference_link'] ?? '') ?: null;

if ($activityId && $title !== '') {
    $pos = (int)$db->query("SELECT COALESCE(MAX(position),-1)+1 FROM ministry_activity_songs WHERE activity_id = $activityId")->fetchColumn();
    $db->prepare("INSERT INTO ministry_activity_songs (activity_id, title, key_tone, reference_link, position) VALUES (?,?,?,?,?)")
       ->execute([$activityId, $title, $key, $link, $pos]);
}

header('Location: /pages/ministries/activity_view.php?id=' . $activityId);
exit;
