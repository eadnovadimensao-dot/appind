<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/bible.php';
auth_check();
header('Content-Type: application/json; charset=utf-8');

$db  = db();
$ref = trim($_GET['ref'] ?? '');

if ($ref === '') { echo json_encode(['ok' => false]); exit; }

$parsed = bible_parse_reference($db, $ref);
if (!$parsed) {
    echo json_encode(['ok' => false, 'error' => 'Não reconheci essa referência. Tente algo como "João 3:16" ou "Salmos 23".']);
    exit;
}

$verses = bible_get_verses($db, $parsed);
if (empty($verses)) {
    echo json_encode(['ok' => false, 'error' => 'Referência reconhecida, mas não achei esse(s) versículo(s).']);
    exit;
}

echo json_encode([
    'ok'        => true,
    'reference' => bible_format_reference($parsed),
    'preview'   => mb_substr(strip_tags(bible_format_text($verses)), 0, 220) . (mb_strlen(bible_format_text($verses)) > 220 ? '…' : ''),
]);
