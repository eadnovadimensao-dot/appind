<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
auth_check();

$db    = db();
$resId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM ministry_resources WHERE id = ?");
$stmt->execute([$resId]);
$res = $stmt->fetch();
if (!$res) { header('Location: /pages/ministries/index.php'); exit; }

auth_require_ministry((int)$res['ministry_id']);

if ($res['file_path']) {
    $full = __DIR__ . '/../../' . ltrim($res['file_path'], '/');
    if (is_file($full)) @unlink($full);
}

$db->prepare("DELETE FROM ministry_resources WHERE id = ?")->execute([$resId]);

header('Location: /pages/ministries/resources.php?ministry_id=' . $res['ministry_id'] . '&deleted=1');
exit;
