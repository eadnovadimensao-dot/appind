<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ministry_activity.php';
auth_check();

$db         = db();
$songId     = (int)($_GET['song_id']     ?? 0);
$activityId = (int)($_GET['activity_id'] ?? 0);

if ($songId && $activityId) {
    $ministryId = $db->prepare("SELECT ministry_id FROM ministry_activities WHERE id = ?");
    $ministryId->execute([$activityId]);
    $ministryId = $ministryId->fetchColumn();

    if ($ministryId && auth_can_manage_ministry((int)$ministryId)) {
        // Só apaga o arquivo do disco se for uma música "avulsa" antiga (sem resource_id) —
        // músicas do catálogo (resource_id preenchido) têm o arquivo compartilhado com o
        // catálogo em Materiais e outras atividades, então aqui só desvincula.
        $song = $db->prepare("SELECT file_path, resource_id FROM ministry_activity_songs WHERE id = ? AND activity_id = ?");
        $song->execute([$songId, $activityId]);
        $song = $song->fetch();
        if ($song && !$song['resource_id'] && $song['file_path']) {
            $full = __DIR__ . '/../../' . ltrim($song['file_path'], '/');
            if (is_file($full)) @unlink($full);
        }

        $db->prepare("DELETE FROM ministry_activity_songs WHERE id = ? AND activity_id = ?")
           ->execute([$songId, $activityId]);

        // Culto e ensaio-espelho compartilham repertório: remove lá também (só dá pra
        // saber a mesma música do lado de lá pelo resource_id, músicas avulsas ficam só na atividade)
        $partnerId = activity_song_link_partner($db, $activityId);
        if ($partnerId && $song && $song['resource_id']) {
            $db->prepare("DELETE FROM ministry_activity_songs WHERE activity_id = ? AND resource_id = ?")
               ->execute([$partnerId, $song['resource_id']]);
        }
    }
}

header('Location: /pages/ministries/activity_view.php?id=' . $activityId);
exit;
