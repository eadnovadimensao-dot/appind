<?php
require_once __DIR__ . '/../../config/database.php';

$db       = db();
$churchId = current_church_id();
$cellId   = (int)($_POST['cell_id']   ?? 0);
$memberId = (int)($_POST['member_id'] ?? 0);

if ($cellId && $memberId) {
    $db->prepare("UPDATE members SET cell_id = ? WHERE id = ? AND church_id = ?")
       ->execute([$cellId, $memberId, $churchId]);
}

header('Location: /pages/cells/view.php?id=' . $cellId);
exit;
