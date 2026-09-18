<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_program.php';
auth_require_service_editor();

$db = db();
$id = (int)($_GET['id'] ?? 0);

$s = $db->prepare("SELECT id FROM services WHERE id = ? AND church_id = ?");
$s->execute([$id, current_church_id()]);
if (!$s->fetch()) { header('Location: /pages/services/index.php'); exit; }

$sent = queue_service_program($db, $id);
header('Location: /pages/services/view.php?id=' . $id . '&sent=' . $sent);
exit;
