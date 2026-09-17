<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pix.php';
auth_check();
header('Content-Type: application/json; charset=utf-8');

$churchId = current_church_id();
$pixKey   = setting('pix_key', '', $churchId);
$pixName  = setting('pix_receiver_name', '', $churchId) ?: setting('church_name', 'Igreja', $churchId);
$pixCity  = setting('pix_receiver_city', '', $churchId);

if (!$pixKey) { echo json_encode(['error' => 'Pix não configurado.']); exit; }

$amountRaw = trim($_GET['amount'] ?? '');
$amount    = ($amountRaw !== '' && is_numeric($amountRaw) && (float)$amountRaw > 0) ? round((float)$amountRaw, 2) : null;

$code = pix_generate_code($pixKey, $pixName, $pixCity, $amount, 'OFERTA' . date('md'));

echo json_encode(['code' => $code]);
