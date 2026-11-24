<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/bible_reading.php';
auth_check();
header('Content-Type: application/json; charset=utf-8');

$db       = db();
$churchId = current_church_id();
$memberId = auth_member_id();

$bookAbbrev = trim($_REQUEST['book_abbrev'] ?? '');
$chapter    = (int)($_REQUEST['chapter'] ?? 0);

$bookRow = null;
foreach (bible_reading_books($db) as $b) if ($b['book_abbrev'] === $bookAbbrev) { $bookRow = $b; break; }
if (!$bookRow || $chapter < 1 || $chapter > (int)$bookRow['chapters']) {
    echo json_encode(['ok' => false]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $comment = trim($_POST['comment'] ?? '');
    if ($comment === '' || !$memberId) {
        echo json_encode(['ok' => false]);
        exit;
    }
    $comment = mb_substr($comment, 0, 1000);
    $db->prepare("
        INSERT INTO bible_reading_comments (church_id, member_id, book_abbrev, chapter, comment, created_at)
        VALUES (?,?,?,?,?,NOW())
    ")->execute([$churchId, $memberId, $bookAbbrev, $chapter, $comment]);
}

$stmt = $db->prepare("
    SELECT bc.comment, bc.created_at, m.name AS member_name
    FROM bible_reading_comments bc
    JOIN members m ON m.id = bc.member_id
    WHERE bc.church_id = ? AND bc.book_abbrev = ? AND bc.chapter = ?
    ORDER BY bc.created_at ASC
");
$stmt->execute([$churchId, $bookAbbrev, $chapter]);

echo json_encode(['ok' => true, 'comments' => $stmt->fetchAll()]);
