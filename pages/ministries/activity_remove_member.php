<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db         = db();
$churchId   = current_church_id();
$activityId = (int)($_GET['activity_id'] ?? 0);
$memberId   = (int)($_GET['member_id']   ?? 0);

$stmt = $db->prepare("SELECT * FROM ministry_activities WHERE id = ? AND church_id = ?");
$stmt->execute([$activityId, $churchId]);
$act = $stmt->fetch();

// Só tira gente da escala enquanto a atividade ainda está agendada — depois
// de realizada/cancelada, a escala vira histórico e não deve mudar.
if ($act && $memberId && $act['status'] === 'scheduled' && auth_can_manage_ministry((int)$act['ministry_id'])) {
    $db->prepare("DELETE FROM ministry_activity_members WHERE activity_id = ? AND member_id = ?")
       ->execute([$activityId, $memberId]);

    // Cancela convite de escala e lembrete de check-in já enfileirados pra essa
    // pessoa nessa atividade — senão a mensagem chega mesmo depois de ela ter
    // sido tirada da escala.
    $phoneStmt = $db->prepare("SELECT phone FROM members WHERE id = ?");
    $phoneStmt->execute([$memberId]);
    $phone = normalize_whatsapp_phone($phoneStmt->fetchColumn() ?: '');
    if ($phone) {
        $db->prepare("
            UPDATE whatsapp_queue SET status = 'expired'
            WHERE service_id = ? AND phone = ? AND status = 'pending'
              AND kind IN ('scale_invite', 'activity_checkin')
        ")->execute([$activityId, $phone]);
    }
}

header('Location: /pages/ministries/activity_view.php?id=' . $activityId);
exit;
