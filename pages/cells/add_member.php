<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db       = db();
$churchId = current_church_id();
$cellId   = (int)($_POST['cell_id']   ?? 0);
$memberId = (int)($_POST['member_id'] ?? 0);

if ($cellId && $memberId && auth_can_manage_cell($cellId)) {
    $db->prepare("UPDATE members SET cell_id = ? WHERE id = ? AND church_id = ?")
       ->execute([$cellId, $memberId, $churchId]);
}

header('Location: /pages/cells/view.php?id=' . $cellId);
exit;
