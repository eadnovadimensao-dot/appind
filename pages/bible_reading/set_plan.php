<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/bible_reading.php';
auth_check();

$db       = db();
$memberId = auth_member_id();
$planId   = trim($_POST['plan_id'] ?? '');

if ($memberId && isset(BIBLE_READING_PLAN_LABELS[$planId])) {
    $db->prepare("UPDATE members SET bible_reading_plan_id = ? WHERE id = ?")->execute([$planId, $memberId]);
}

header('Location: /pages/bible_reading/index.php');
exit;
