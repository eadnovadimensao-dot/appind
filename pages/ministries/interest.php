<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ministry_interest.php';
auth_check();

$db         = db();
$ministryId = (int)($_POST['ministry_id'] ?? 0);
$action     = $_POST['action'] ?? 'request';

$stmt = $db->prepare("SELECT id FROM ministries WHERE id = ? AND church_id = ?");
$stmt->execute([$ministryId, current_church_id()]);
if (!$stmt->fetchColumn()) { header('Location: /pages/ministries/index.php'); exit; }

if ($action === 'dismiss') {
    // Só quem gerencia o ministério dispensa um pedido dele
    if (auth_can_manage_ministry($ministryId)) {
        $interestId = (int)($_POST['interest_id'] ?? 0);
        $chk = $db->prepare("SELECT 1 FROM ministry_interest WHERE id = ? AND ministry_id = ?");
        $chk->execute([$interestId, $ministryId]);
        if ($chk->fetchColumn()) ministry_dismiss_interest($db, $interestId);
    }
} else {
    $memberId = auth_member_id();
    if ($memberId && !auth_member_in_ministry($ministryId) && !auth_can_manage_ministry($ministryId)) {
        ministry_request_interest($db, $ministryId, $memberId);
    }
}

header('Location: /pages/ministries/view.php?id=' . $ministryId);
exit;
