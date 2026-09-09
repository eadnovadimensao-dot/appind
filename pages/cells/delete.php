<?php
require_once __DIR__ . '/../../config/database.php';

$db       = db();
$churchId = current_church_id();
$id       = (int)($_GET['id'] ?? 0);

// Desvincula membros antes de excluir
$db->prepare("UPDATE members SET cell_id = NULL WHERE cell_id = ? AND church_id = ?")->execute([$id, $churchId]);
$db->prepare("DELETE FROM cells WHERE id = ? AND church_id = ?")->execute([$id, $churchId]);

header('Location: /pages/cells/index.php?deleted=1');
exit;
