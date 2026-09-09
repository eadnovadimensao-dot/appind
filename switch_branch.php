<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
auth_check();

// Só supermaster pode trocar de filial
if (auth_role() !== 'supermaster') {
    header('Location: /dashboard.php');
    exit;
}

$branchId = (int)($_GET['branch_id'] ?? 0);
$db = db();

// Validar se a filial existe e pertence à sede
if ($branchId > 0) {
    $branch = $db->prepare("SELECT id, name FROM churches WHERE (id=? AND id=?) OR (id=? AND parent_id=?)");
    $branch->execute([$branchId, SEDE_ID, $branchId, SEDE_ID]);
    $branch = $branch->fetch();
    if ($branch) {
        $_SESSION['user']['branch_id'] = $branchId === SEDE_ID ? null : $branchId;
        $_SESSION['user']['viewing_branch_id'] = $branchId;
        $_SESSION['user']['viewing_branch_name'] = $branch['name'];
    }
} else {
    // Voltar para sede
    $_SESSION['user']['branch_id'] = null;
    $_SESSION['user']['viewing_branch_id'] = SEDE_ID;
    $_SESSION['user']['viewing_branch_name'] = null;
}

$next = $_GET['next'] ?? '/dashboard.php';
header('Location: ' . $next);
exit;
