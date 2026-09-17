<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require('approve_events');

$db       = db();
$churchId = current_church_id();
$id       = (int)($_GET['id']     ?? 0);
$action   = $_GET['action'] ?? '';

if (!in_array($action, ['approve','refuse'])) {
    header('Location: /pages/events/index.php');
    exit;
}

$memberId = auth_member_id();

if ($action === 'approve') {
    $db->prepare("
        UPDATE agenda_events
        SET status = 'approved', approved_by = ?, approved_at = NOW()
        WHERE id = ? AND church_id = ? AND status = 'pending'
    ")->execute([$memberId, $id, $churchId]);
} else {
    $reason = trim($_POST['reason'] ?? 'Solicitação recusada.');
    $db->prepare("
        UPDATE agenda_events
        SET status = 'refused', refused_reason = ?, approved_by = ?
        WHERE id = ? AND church_id = ? AND status = 'pending'
    ")->execute([$reason, $memberId, $id, $churchId]);
}

header('Location: /pages/events/index.php');
exit;
