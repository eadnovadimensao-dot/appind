<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();
$memberId = auth_member_id();

if (!$memberId) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['endpoint']) || empty($data['keys']['p256dh']) || empty($data['keys']['auth'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid subscription data']);
    exit;
}

$db = db();

// Salvar ou atualizar subscription
$db->prepare("
    INSERT INTO push_subscriptions (member_id, endpoint, p256dh, auth_key)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE endpoint=VALUES(endpoint), p256dh=VALUES(p256dh), auth_key=VALUES(auth_key)
")->execute([$memberId, $data['endpoint'], $data['keys']['p256dh'], $data['keys']['auth']]);

echo json_encode(['success' => true]);
