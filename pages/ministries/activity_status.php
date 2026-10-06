<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ministry_activity.php';
auth_check();

$db     = db();
$src    = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$id     = (int)($src['id']     ?? 0);
$status = $src['status'] ?? '';
$reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 500);

$act = $db->prepare("SELECT ministry_id FROM ministry_activities WHERE id = ? AND church_id = ?");
$act->execute([$id, current_church_id()]);
$ministryId = $act->fetchColumn();

// Cancelar exige motivo (ele vai pra liderança)
$invalidCancel = $status === 'cancelled' && $reason === '';

if ($ministryId && auth_can_manage_ministry((int)$ministryId) && in_array($status, ['done','cancelled','scheduled']) && !$invalidCancel) {
    $wasScheduled = $db->prepare("SELECT status FROM ministry_activities WHERE id = ? AND church_id = ?");
    $wasScheduled->execute([$id, current_church_id()]);
    $wasScheduled = $wasScheduled->fetchColumn() === 'scheduled';

    $db->prepare("UPDATE ministry_activities SET status = ?, cancel_reason = ? WHERE id = ? AND church_id = ?")
       ->execute([$status, $status === 'cancelled' ? $reason : null, $id, current_church_id()]);

    if ($status === 'cancelled') {
        $db->prepare("UPDATE agenda_events SET status = 'cancelled' WHERE ministry_activity_id = ?")->execute([$id]);
    } elseif ($status === 'scheduled') {
        $db->prepare("UPDATE agenda_events SET status = 'approved' WHERE ministry_activity_id = ? AND status = 'cancelled'")->execute([$id]);
    }

    if ($status === 'cancelled' && $wasScheduled) {
        notify_activity_cancelled($db, $id, $reason);
    }
}

header('Location: /pages/ministries/activity_view.php?id=' . $id . ($invalidCancel ? '&cancel_error=1' : ''));
exit;
