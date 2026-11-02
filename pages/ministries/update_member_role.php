<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/music_roles.php';
auth_check();

$db         = db();
$ministryId = (int)($_POST['ministry_id'] ?? 0);
$memberId   = (int)($_POST['member_id']   ?? 0);

// Música manda várias funções marcadas (checkboxes, uma pessoa pode ter mais
// de uma, ex: Guitarrista + Diretor Musical); outros ministérios mandam um
// texto livre só (campo "role" simples).
if (isset($_POST['roles'])) {
    $role = role_names_to_string((array)$_POST['roles']);
} else {
    $role = trim($_POST['role'] ?? '') ?: null;
}

// Naipe só faz sentido se alguma das funções marcadas usar naipe (ex: Backing Vocal)
$naipeApplies = false;
foreach (get_ministry_roles($db, $ministryId) as $r) {
    if ($r['use_naipe'] && in_array($r['name'], role_names_from_string($role), true)) {
        $naipeApplies = true;
        break;
    }
}
$naipe = $naipeApplies ? (trim($_POST['naipe'] ?? '') ?: null) : null;

if ($ministryId && $memberId && auth_can_manage_ministry($ministryId)) {
    $db->prepare("UPDATE member_ministries SET role = ?, naipe = ? WHERE ministry_id = ? AND member_id = ?")
       ->execute([$role, $naipe, $ministryId, $memberId]);
}

header('Location: /pages/ministries/view.php?id=' . $ministryId);
exit;
