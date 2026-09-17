<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require('approve_events');
$db       = db();
$churchId = current_church_id();
$id       = (int)($_GET['id'] ?? 0);

// Local com evento vinculado (agenda_events.location_id é RESTRICT) não pode
// ser excluído direto — avisa em vez de quebrar com erro fatal de FK.
$count = $db->prepare("SELECT COUNT(*) FROM agenda_events WHERE location_id = ?");
$count->execute([$id]);
if ((int)$count->fetchColumn() > 0) {
    header('Location: /pages/events/locations.php?error=inuse');
    exit;
}

$db->prepare("DELETE FROM locations WHERE id = ? AND church_id = ?")->execute([$id, $churchId]);
header('Location: /pages/events/locations.php');
exit;
