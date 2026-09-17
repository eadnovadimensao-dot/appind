<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require('manage_members');

$db       = db();
$memberId = (int)($_POST['member_id'] ?? 0);
$stepId   = (int)($_POST['step_id'] ?? 0);
$done     = ($_POST['done'] ?? '') === '1';

$m = $db->prepare("
    SELECT m.id FROM members m LEFT JOIN churches ch ON ch.id = m.church_id
    WHERE m.id = ? AND (ch.id = ? OR ch.parent_id = ?)
");
$m->execute([$memberId, SEDE_ID, SEDE_ID]);
if (!$m->fetch()) { header('Location: /pages/members/index.php'); exit; }

$s = $db->prepare("SELECT id FROM growth_steps WHERE id = ?");
$s->execute([$stepId]);
if ($s->fetch()) {
    if ($done) {
        $db->prepare("INSERT IGNORE INTO member_growth_progress (member_id, step_id, completed_at, marked_by) VALUES (?,?,CURDATE(),?)")
           ->execute([$memberId, $stepId, auth_member_id()]);
    } else {
        $db->prepare("DELETE FROM member_growth_progress WHERE member_id = ? AND step_id = ?")->execute([$memberId, $stepId]);
    }
}

header('Location: /pages/members/view.php?id=' . $memberId);
exit;
