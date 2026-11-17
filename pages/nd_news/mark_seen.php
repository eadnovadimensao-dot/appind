<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/nd_news.php';
auth_check();
header('Content-Type: application/json; charset=utf-8');

$memberId = auth_member_id();
if ($memberId) nd_news_mark_seen(db(), $memberId);

echo json_encode(['ok' => true]);
