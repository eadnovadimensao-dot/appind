<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
$db = db();
$id     = (int)($_GET['id']     ?? 0);
$status = trim($_GET['status']  ?? '');
if (in_array($status, ['planning','confirmed','done'])) {
    $db->prepare("UPDATE services SET status=? WHERE id=? AND church_id=?")
       ->execute([$status, $id, current_church_id()]);
}
header('Location: /pages/services/view.php?id=' . $id);
exit;
