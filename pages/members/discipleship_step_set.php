<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/discipleship.php';
auth_check();
if (!auth_is_discipleship_coordinator()) {
    http_response_code(403);
    include __DIR__ . '/../../includes/403.php';
    exit;
}

$db       = db();
$memberId = (int)($_POST['member_id'] ?? 0);
$step     = (int)($_POST['step'] ?? 0);
if ($step < 0 || $step > 2) $step = 0;

$m = $db->prepare("SELECT id FROM members WHERE id = ? AND church_id = ?");
$m->execute([$memberId, current_church_id()]);
if ($m->fetch()) {
    $db->prepare("UPDATE members SET discipleship_step = ? WHERE id = ?")->execute([$step, $memberId]);
}

header('Location: /pages/members/view.php?id=' . $memberId);
exit;
