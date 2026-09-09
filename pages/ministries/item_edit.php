<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
auth_check();
// Redireciona para item_create com id
$id = (int)($_GET['id'] ?? 0);
header('Location: /pages/ministries/item_create.php?id=' . $id);
exit;
