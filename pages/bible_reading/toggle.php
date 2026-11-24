<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/bible_reading.php';
auth_check();
header('Content-Type: application/json; charset=utf-8');

$db       = db();
$memberId = auth_member_id();
if (!$memberId) { echo json_encode(['ok' => false]); exit; }

$bookAbbrev = trim($_POST['book_abbrev'] ?? '');
$chapter    = (int)($_POST['chapter'] ?? 0);
$done       = ($_POST['done'] ?? '') === '1';

$books   = bible_reading_books($db);
$bookRow = null;
foreach ($books as $b) if ($b['book_abbrev'] === $bookAbbrev) { $bookRow = $b; break; }
if (!$bookRow || $chapter < 1 || $chapter > (int)$bookRow['chapters']) {
    echo json_encode(['ok' => false]);
    exit;
}

$planId = bible_reading_member_plan($db, $memberId);

// Pra avisar só no exato momento da conclusão (nunca de novo depois), confere
// se o plano AINDA tinha capítulo não lido antes deste toggle.
$progressBefore = bible_reading_progress_set($db, $memberId);
$wasAlreadyDone = $done && empty(bible_reading_daily_suggestion($db, $progressBefore, 1, $planId));

if ($done) {
    $db->prepare("INSERT IGNORE INTO bible_reading_progress (member_id, book_abbrev, chapter, read_at) VALUES (?,?,?,NOW())")
       ->execute([$memberId, $bookAbbrev, $chapter]);
} else {
    $db->prepare("DELETE FROM bible_reading_progress WHERE member_id = ? AND book_abbrev = ? AND chapter = ?")
       ->execute([$memberId, $bookAbbrev, $chapter]);
}

$progress   = bible_reading_progress_set($db, $memberId);
$total      = bible_reading_total_chapters($db);
$readCount  = count($progress);
$percent    = $total ? round($readCount / $total * 100) : 0;
$suggestion = bible_reading_daily_suggestion($db, $progress, BIBLE_READING_DAILY_CHAPTERS, $planId);

if ($done && !$wasAlreadyDone && empty($suggestion)) {
    bible_reading_notify_plan_completed($db, $memberId, $planId);
}

$bookRead = 0;
for ($c = 1; $c <= (int)$bookRow['chapters']; $c++) if (isset($progress[$bookAbbrev . ':' . $c])) $bookRead++;

echo json_encode([
    'ok'         => true,
    'read_count' => $readCount,
    'percent'    => $percent,
    'book_read'  => $bookRead,
    'book_total' => (int)$bookRow['chapters'],
    'suggestion' => $suggestion,
    'whole_done' => $readCount >= $total,
]);
