<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_program.php';
auth_require_service_editor();
$db = db();
$id     = (int)($_GET['id']     ?? 0);
$status = trim($_GET['status']  ?? '');

if ($status === 'confirmed') {
    // Primeira confirmação também envia a programação aos envolvidos
    $sent = confirm_service($db, $id, current_church_id());
    header('Location: /pages/services/view.php?id=' . $id . ($sent !== null ? '&sent=' . $sent : ''));
    exit;
}
if (in_array($status, ['planning','done'])) {
    $db->prepare("UPDATE services SET status=? WHERE id=? AND church_id=?")
       ->execute([$status, $id, current_church_id()]);
}
header('Location: /pages/services/view.php?id=' . $id);
exit;
