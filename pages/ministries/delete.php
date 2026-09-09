<?php
require_once __DIR__ . '/../../config/database.php';
$db = db();
$id = (int)($_GET['id'] ?? 0);
$db->prepare("DELETE FROM ministries WHERE id = ? AND church_id = ?")->execute([$id, current_church_id()]);
header('Location: /pages/ministries/index.php');
exit;
