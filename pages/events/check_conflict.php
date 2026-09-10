<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
header('Content-Type: application/json');

$db         = db();
$churchId   = CHURCH_ID;
$locationId = (int)($_GET['location_id'] ?? 0);
$date       = $_GET['date']  ?? '';
$start      = $_GET['start'] ?? '';
$end        = $_GET['end']   ?? '';
$excludeId  = (int)($_GET['exclude_id'] ?? 0);

if (!$locationId || !$date || !$start || !$end) {
    echo json_encode(['conflict' => false]);
    exit;
}

$sql = "SELECT id, title, time_start, time_end FROM agenda_events
        WHERE church_id = ? AND location_id = ? AND event_date = ?
          AND status IN ('approved','pending')
          AND time_start < ? AND time_end > ?";
$params = [$churchId, $locationId, $date, $end, $start];

if ($excludeId) {
    $sql .= ' AND id != ?';
    $params[] = $excludeId;
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$conflict = $stmt->fetch();

if ($conflict) {
    echo json_encode([
        'conflict' => true,
        'title'    => $conflict['title'],
        'start'    => substr($conflict['time_start'],0,5),
        'end'      => substr($conflict['time_end'],0,5),
    ]);
} else {
    echo json_encode(['conflict' => false]);
}
