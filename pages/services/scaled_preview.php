<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$db       = db();
$churchId = current_church_id();
$date     = $_GET['date'] ?? '';

if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['groups' => []]);
    exit;
}

$stmt = $db->prepare("
    SELECT mn.name AS ministry_name, m.name AS member_name, mam.role, mam.status
    FROM ministry_activity_members mam
    JOIN ministry_activities ma ON ma.id = mam.activity_id
    JOIN ministries mn          ON mn.id = ma.ministry_id
    JOIN members m              ON m.id  = mam.member_id
    WHERE ma.activity_date = ? AND mam.status IN ('confirmed','pending')
      AND mn.church_id = ?
    ORDER BY mn.name, m.name
");
$stmt->execute([$date, $churchId]);
$rows = $stmt->fetchAll();

$groups = [];
foreach ($rows as $r) {
    $groups[$r['ministry_name']][] = [
        'name'   => $r['member_name'],
        'role'   => $r['role'],
        'status' => $r['status'],
    ];
}

$out = [];
foreach ($groups as $ministry => $members) {
    $out[] = ['ministry' => $ministry, 'members' => $members];
}

echo json_encode(['groups' => $out]);
