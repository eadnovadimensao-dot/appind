<?php
// Log de auditoria: registra ações administrativas sensíveis (usuários, senhas,
// exclusões, configurações) pra ficar claro quem fez o quê e quando. Só isso —
// não é log geral de toda ação do sistema, só o que mexe em acesso/dado de outra pessoa.

function audit_log(string $action, string $detail = ''): void {
    $uid  = $_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? null);
    $name = $_SESSION['user']['name'] ?? '';
    $churchId = current_church_id();

    db()->prepare("
        INSERT INTO admin_audit_log (church_id, actor_user_id, actor_name, action, detail)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([$churchId, $uid, $name, $action, $detail]);
}
