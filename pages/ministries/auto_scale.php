<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/music_roles.php';
auth_check();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$db           = db();
$ministryId   = (int)($_GET['ministry_id'] ?? 0);
$activityType = ($_GET['activity_type'] ?? 'culto') === 'ensaio' ? 'ensaio' : 'culto';

if (!auth_can_manage_ministry($ministryId)) {
    echo json_encode(['error' => 'Você não tem permissão pra gerenciar esse ministério.']);
    exit;
}

$stmt = $db->prepare("
    SELECT mn.*, ch.id AS branch_church_id
    FROM ministries mn
    JOIN churches ch ON ch.id = mn.church_id
    WHERE mn.id = ? AND ch.id = ?
");
$stmt->execute([$ministryId, current_church_id()]);
$mn = $stmt->fetch();

if (!$mn) {
    echo json_encode(['error' => 'Ministério não encontrado.']);
    exit;
}
if (empty($mn['auto_scale_enabled'])) {
    echo json_encode(['error' => 'Este ministério não tem escala automática ativada (configure em Editar ministério).']);
    exit;
}

$roles = get_ministry_roles($db, $ministryId);
if (empty($roles)) {
    echo json_encode(['error' => 'Nenhuma função configurada ainda pra esse ministério (configure em Funções da escala).']);
    exit;
}

$churchId = $mn['church_id'];
$pool     = build_scale_pool($db, $ministryId, $churchId);

// Última escala do mesmo tipo para este ministério (pra poupar quem já serviu)
$prev = $db->prepare("
    SELECT id FROM ministry_activities
    WHERE ministry_id = ? AND activity_type = ? AND status != 'cancelled'
    ORDER BY activity_date DESC, id DESC
    LIMIT 1
");
$prev->execute([$ministryId, $activityType]);
$prevActivityId = $prev->fetchColumn();

$prevMemberIds = [];
if ($prevActivityId) {
    $pm = $db->prepare("SELECT member_id FROM ministry_activity_members WHERE activity_id = ?");
    $pm->execute([$prevActivityId]);
    $prevMemberIds = array_map('intval', $pm->fetchAll(PDO::FETCH_COLUMN));
}

echo json_encode(draw_scale($db, $ministryId, $pool, $prevMemberIds));
