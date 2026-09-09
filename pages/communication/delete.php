<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
$db = db();
$id = (int)($_GET['id'] ?? 0);
$db->prepare("DELETE FROM announcements WHERE id=? AND church_id=?")->execute([$id, current_church_id()]);
header('Location: /pages/communication/list.php');
exit;
