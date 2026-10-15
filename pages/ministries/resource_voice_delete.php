<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
auth_check();

$db      = db();
$voiceId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("
    SELECT v.*, r.ministry_id
    FROM ministry_resource_voices v
    JOIN ministry_resources r ON r.id = v.resource_id
    WHERE v.id = ?
");
$stmt->execute([$voiceId]);
$voice = $stmt->fetch();
if (!$voice) { header('Location: /pages/ministries/index.php'); exit; }

auth_require_ministry((int)$voice['ministry_id']);

$full = __DIR__ . '/../../' . ltrim($voice['file_path'], '/');
if (is_file($full)) @unlink($full);

$db->prepare("DELETE FROM ministry_resource_voices WHERE id = ?")->execute([$voiceId]);

header('Location: /pages/ministries/resource_edit.php?id=' . $voice['resource_id']);
exit;
