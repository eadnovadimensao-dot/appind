<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/devotional.php';
auth_check();

$db = db();
$id = (int)($_GET['id'] ?? 0);
$q  = $db->prepare("SELECT * FROM devotionals WHERE id = ?");
$q->execute([$id]);
$dev = $q->fetch();
if (!$dev || !auth_can_edit_devotional($dev)) { header('Location: /pages/devotional/index.php'); exit; }

// Teste só pro próprio autor, com o mesmo formato que os membros recebem
$m = null;
if (auth_member_id()) {
    $q = $db->prepare("SELECT id, church_id, name, phone FROM members WHERE id = ?");
    $q->execute([auth_member_id()]);
    $m = $q->fetch();
}
if ($m && $m['phone']) {
    devotional_queue_for_member($db, $dev, $m);
    header('Location: /pages/devotional/view.php?id=' . $id . '&test=ok');
} else {
    header('Location: /pages/devotional/view.php?id=' . $id . '&test=nophone');
}
exit;
