<?php
require_once __DIR__ . '/../../config/database.php';

$db       = db();
$churchId = current_church_id();
$id       = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("DELETE FROM members WHERE id = ? AND church_id = ?");
$stmt->execute([$id, $churchId]);

header('Location: /pages/members/index.php?deleted=1');
exit;
