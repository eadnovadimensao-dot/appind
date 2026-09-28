<?php
// Recebe as mensagens que chegam no WhatsApp conectado (webhook "ao receber" do
// Z-API). Hoje só trata resposta de check-in por texto ("presente" etc);
// qualquer outra mensagem é ignorada sem erro.
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/service_checkin.php';

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Content-Type: application/json; charset=utf-8');

if (!hash_equals('8e2c6a1f9d34b70c5e8a2f6d1b9c4730', (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data) || ($data['type'] ?? '') !== 'ReceivedCallback'
    || !empty($data['fromMe']) || !empty($data['isGroup']) || !empty($data['isNewsletter'])) {
    echo json_encode(['ok' => true, 'skipped' => true]);
    exit;
}

$phone = preg_replace('/\D/', '', (string)($data['phone'] ?? ''));
// Resposta a botão de resposta rápida (buttonsResponseMessage) tem prioridade;
// senão, texto digitado normal (text.message).
$text  = trim((string)($data['buttonsResponseMessage']['message'] ?? $data['text']['message'] ?? ''));

if ($phone === '' || $text === '') {
    echo json_encode(['ok' => true, 'skipped' => true]);
    exit;
}

$db = db();

try {
    $reply = try_checkin_by_reply($db, $phone, $text);
    if ($reply !== null) {
        send_whatsapp($phone, $reply, SEDE_ID);
    }
    echo json_encode(['ok' => true, 'matched' => $reply !== null]);
} catch (\Throwable $e) {
    error_log('webhook_zapi: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
