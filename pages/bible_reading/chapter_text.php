<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/bible_reading.php';
auth_check();
header('Content-Type: application/json; charset=utf-8');

$db       = db();
$memberId = auth_member_id();

$bookAbbrev = trim($_GET['book_abbrev'] ?? '');
$chapter    = (int)($_GET['chapter'] ?? 0);

$bookRow = null;
foreach (bible_reading_books($db) as $b) if ($b['book_abbrev'] === $bookAbbrev) { $bookRow = $b; break; }
if (!$bookRow || $chapter < 1 || $chapter > (int)$bookRow['chapters']) {
    echo json_encode(['ok' => false]);
    exit;
}

$verses = bible_get_verses($db, ['book_abbrev' => $bookAbbrev, 'chapter' => $chapter, 'verse_start' => null, 'verse_end' => null]);

$isRead = false;
if ($memberId) {
    $stmt = $db->prepare("SELECT 1 FROM bible_reading_progress WHERE member_id = ? AND book_abbrev = ? AND chapter = ?");
    $stmt->execute([$memberId, $bookAbbrev, $chapter]);
    $isRead = (bool)$stmt->fetchColumn();
}

echo json_encode([
    'ok'          => true,
    'book_abbrev' => $bookAbbrev,
    'book_name'   => $bookRow['book_name'],
    'chapter'     => $chapter,
    'verses'      => $verses,
    'is_read'     => $isRead,
]);
