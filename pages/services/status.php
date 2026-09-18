<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_program.php';
auth_require_service_editor();
$db = db();
$id     = (int)($_GET['id']     ?? 0);
$status = trim($_GET['status']  ?? '');
if (in_array($status, ['planning','confirmed','done'])) {
    $cur = $db->prepare("SELECT status, program_sent_at FROM services WHERE id=? AND church_id=?");
    $cur->execute([$id, current_church_id()]);
    $cur = $cur->fetch();

    $db->prepare("UPDATE services SET status=? WHERE id=? AND church_id=?")
       ->execute([$status, $id, current_church_id()]);

    // Ao confirmar o culto pela primeira vez, manda a programação pros envolvidos.
    // Depois disso só reenvia pelo botão "Reenviar programação".
    if ($cur && $status === 'confirmed' && $cur['status'] !== 'confirmed' && !$cur['program_sent_at']) {
        $sent = queue_service_program($db, $id);
        header('Location: /pages/services/view.php?id=' . $id . '&sent=' . $sent);
        exit;
    }
}
header('Location: /pages/services/view.php?id=' . $id);
exit;
