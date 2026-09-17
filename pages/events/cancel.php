<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
$db       = db();
$churchId = current_church_id();
$id       = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT requested_by FROM agenda_events WHERE id = ? AND church_id = ?");
$stmt->execute([$id, $churchId]);
$ev = $stmt->fetch();

// Só admin (approve_events) ou quem solicitou o evento pode cancelar
if ($ev && (auth_can('approve_events') || (int)$ev['requested_by'] === (int)auth_member_id())) {
    $db->prepare("UPDATE agenda_events SET status='cancelled' WHERE id=? AND church_id=?")->execute([$id, $churchId]);
}

header('Location: /pages/events/index.php');
exit;
