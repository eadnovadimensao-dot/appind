<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/devotional.php';
auth_check();

$db = db();
$q  = $db->prepare("SELECT * FROM devotionals WHERE id = ?");
$q->execute([(int)($_GET['id'] ?? 0)]);
$dev = $q->fetch();
if ($dev && auth_can_edit_devotional($dev)) {
    $db->prepare("DELETE FROM devotionals WHERE id = ?")->execute([$dev['id']]);
    // Se ainda tinha envio pendente na fila, cancela também
    $db->prepare("DELETE FROM whatsapp_queue WHERE kind = 'devotional' AND status = 'pending' AND message LIKE ?")
       ->execute(['%' . $dev['title'] . '%']);
    header('Location: /pages/devotional/index.php?deleted=1');
    exit;
}
header('Location: /pages/devotional/index.php');
exit;
