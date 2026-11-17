<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db       = db();
$memberId = auth_member_id();
$id       = (int)($_POST['id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM nd_news WHERE id = ?");
$stmt->execute([$id]);
$item = $stmt->fetch();

if ($item && ($item['created_by'] == $memberId || auth_role() === 'supermaster')) {
    if ($item['video_path']) {
        $full = __DIR__ . '/../..' . $item['video_path'];
        if (file_exists($full)) @unlink($full);
    }
    $db->prepare("DELETE FROM nd_news WHERE id = ?")->execute([$id]);
}

header('Location: /pages/nd_news/index.php?deleted=1');
exit;
