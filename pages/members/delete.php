<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
auth_require('manage_members');

$db       = db();
$churchId = current_church_id();
$id       = (int)($_GET['id'] ?? 0);

// Mesma regra de visibilidade da listagem: supermaster na sede enxerga (e pode excluir)
// membros de qualquer filial; os demais só da própria igreja.
if ($churchId === SEDE_ID && auth_role() === 'supermaster') {
    $stmt = $db->prepare("
        DELETE m FROM members m
        LEFT JOIN churches ch ON ch.id = m.church_id
        WHERE m.id = ? AND (m.church_id = ? OR ch.parent_id = ?)
    ");
    $params = [$id, SEDE_ID, SEDE_ID];
} else {
    $stmt = $db->prepare("DELETE FROM members WHERE id = ? AND church_id = ?");
    $params = [$id, $churchId];
}

try {
    $stmt->execute($params);
    if ($stmt->rowCount() > 0) {
        header('Location: /pages/members/index.php?deleted=1');
    } else {
        // Nada foi apagado: id inválido ou membro fora do escopo permitido
        header('Location: /pages/members/index.php?error=notfound');
    }
} catch (\PDOException $e) {
    // Violação de chave estrangeira (SQLSTATE 23000) — membro tem registros
    // vinculados (avisos criados, empréstimos aprovados, etc.) e não pode ser
    // apagado de verdade. Sugerir marcar como Inativo em vez de excluir.
    if ($e->getCode() === '23000') {
        header('Location: /pages/members/index.php?error=linked');
    } else {
        throw $e;
    }
}
exit;
