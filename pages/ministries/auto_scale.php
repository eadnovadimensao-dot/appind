<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/music_roles.php';
auth_check();

header('Content-Type: application/json; charset=utf-8');

$db           = db();
$ministryId   = (int)($_GET['ministry_id'] ?? 0);
$activityType = ($_GET['activity_type'] ?? 'culto') === 'ensaio' ? 'ensaio' : 'culto';

$stmt = $db->prepare("
    SELECT mn.*, ch.id AS branch_church_id
    FROM ministries mn
    JOIN churches ch ON ch.id = mn.church_id
    WHERE mn.id = ? AND (ch.id = ? OR ch.parent_id = ?)
");
$stmt->execute([$ministryId, SEDE_ID, SEDE_ID]);
$mn = $stmt->fetch();

if (!$mn) {
    echo json_encode(['error' => 'Ministério não encontrado.']);
    exit;
}
if (!is_music_ministry($mn['name'])) {
    echo json_encode(['error' => 'Este ministério não tem uma composição automática definida.']);
    exit;
}

$churchId = $mn['church_id'];

// Membros do ministério agrupados por função
$members = $db->prepare("
    SELECT m.id, m.name, mm.role
    FROM member_ministries mm
    JOIN members m ON m.id = mm.member_id
    WHERE mm.ministry_id = ? AND m.church_id = ?
");
$members->execute([$ministryId, $churchId]);
$pool = [];
foreach ($members->fetchAll() as $m) {
    if (!$m['role']) continue;
    $pool[$m['role']][] = ['id' => (int)$m['id'], 'name' => $m['name']];
}

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

$assignments = []; // member_id => role
$warnings    = [];

// Funções que sempre entram, sem rodízio: escala todo mundo que tem essa função
foreach (MUSIC_ALWAYS_INCLUDE_ROLES as $role) {
    $candidates = $pool[$role] ?? [];
    if (empty($candidates)) {
        $warnings[] = "$role: nenhum membro cadastrado nessa função.";
        continue;
    }
    foreach ($candidates as $c) {
        $assignments[$c['id']] = $role;
    }
}

foreach (MUSIC_ROLE_COMPOSITION as $role => $needed) {
    $candidates = $pool[$role] ?? [];

    // Ministros de louvor que não foram escalados como o ministro da semana
    // entram também na disputa pelas vagas de Backing Vocal.
    if ($role === 'Backing Vocal') {
        $extraMinistros = array_filter(
            $pool['Ministro(a) de Louvor'] ?? [],
            fn($c) => !isset($assignments[$c['id']])
        );
        $candidates = array_merge($candidates, array_values($extraMinistros));
    }

    $fresh  = array_values(array_filter($candidates, fn($c) => !in_array($c['id'], $prevMemberIds, true)));
    $recent = array_values(array_filter($candidates, fn($c) =>  in_array($c['id'], $prevMemberIds, true)));

    shuffle($fresh);
    shuffle($recent);

    $chosen = array_slice($fresh, 0, $needed);
    if (count($chosen) < $needed) {
        $chosen = array_merge($chosen, array_slice($recent, 0, $needed - count($chosen)));
    }

    foreach ($chosen as $c) {
        $assignments[$c['id']] = $role;
    }

    if (count($chosen) < $needed) {
        $warnings[] = "$role: só " . count($chosen) . " de $needed disponível(is).";
    }
}

echo json_encode(['assignments' => $assignments, 'warnings' => $warnings]);
