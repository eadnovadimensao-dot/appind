<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
$db = db();
$id = (int)($_GET['id'] ?? 0);
$db->prepare("UPDATE scale_notifications SET status='read' WHERE id=? AND leader_id=?")
   ->execute([$id, auth_member_id()]);
header('Location: /pages/worship-scale/index.php');
exit;
