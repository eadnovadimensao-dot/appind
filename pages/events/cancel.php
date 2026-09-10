<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
$db = db();
$id = (int)($_GET['id'] ?? 0);
$db->prepare("UPDATE agenda_events SET status='cancelled' WHERE id=? AND church_id=?")->execute([$id, current_church_id()]);
header('Location: /pages/events/index.php');
exit;
