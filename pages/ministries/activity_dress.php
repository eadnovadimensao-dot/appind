<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ministry_activity.php';
auth_check();

$db = db();
$id = (int)($_POST['id'] ?? 0);

$stmt = $db->prepare("SELECT ministry_id FROM ministry_activities WHERE id = ? AND church_id = ?");
$stmt->execute([$id, current_church_id()]);
$ministryId = $stmt->fetchColumn();
if (!$ministryId || $_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /pages/ministries/index.php'); exit; }

if (!auth_can_manage_dress((int)$ministryId)) {
    http_response_code(403);
    include __DIR__ . '/../../includes/403.php';
    exit;
}

$colors = dress_colors_parse(implode(',', (array)($_POST['colors'] ?? [])));
$note   = trim($_POST['note'] ?? '');

$db->prepare("UPDATE ministry_activities SET dress_note = ?, dress_colors = ? WHERE id = ?")
   ->execute([$note !== '' ? mb_substr($note, 0, 255) : null, $colors ? implode(',', $colors) : null, $id]);

$qs = 'dress_saved=1';
if (($_POST['action'] ?? '') === 'send') {
    $qs = 'dress_sent=' . ministry_send_dress($db, $id);
}

header('Location: /pages/ministries/activity_view.php?id=' . $id . '&' . $qs);
exit;
