<?php
// Discipulado um a um dentro da célula: o membro escolhe um discipulador da
// própria célula (que já tenha concluído o 1º Passo), o líder da célula dá o
// aval e a coordenação do discipulado confirma. Só depois disso começa.
// Fluxo de status: pending_leader -> pending_coordination -> active -> (completed | cancelled)
// Pode ser recusado em qualquer uma das duas aprovações: rejected.

const DISCIPLESHIP_STEP_LABELS = [0 => 'Nenhum', 1 => '1º Passo concluído', 2 => '2º Passo concluído'];

/** Coordenação do discipulado: supermaster sempre; senão, quem estiver cadastrado. */
function auth_is_discipleship_coordinator(): bool {
    if (auth_role() === 'supermaster') return true;
    $memberId = auth_member_id();
    if (!$memberId) return false;
    static $cache = [];
    if (!array_key_exists($memberId, $cache)) {
        $q = db()->prepare("SELECT 1 FROM discipleship_coordinators WHERE member_id = ?");
        $q->execute([$memberId]);
        $cache[$memberId] = (bool)$q->fetchColumn();
    }
    return $cache[$memberId];
}

/** Quem pode dar o aval de líder numa célula específica: reaproveita a mesma regra de gerenciar a célula. */
function auth_can_decide_discipleship_as_leader(int $cellId): bool {
    return auth_can_manage_cell($cellId);
}

/** Discipulado ativo ou em andamento do membro como discípulo (só pode ter um por vez). */
function member_current_discipleship_as_disciple(PDO $db, int $memberId): ?array {
    $q = $db->prepare("
        SELECT d.*, c.name AS cell_name, ds.name AS discipler_name
        FROM discipleships d
        JOIN cells c ON c.id = d.cell_id
        JOIN members ds ON ds.id = d.discipler_member_id
        WHERE d.disciple_member_id = ? AND d.status IN ('pending_leader','pending_coordination','active')
        ORDER BY d.id DESC LIMIT 1
    ");
    $q->execute([$memberId]);
    return $q->fetch() ?: null;
}

/** Pessoas que esse membro está discipulando (em andamento ou ativas). */
function member_current_disciples(PDO $db, int $memberId): array {
    $q = $db->prepare("
        SELECT d.*, c.name AS cell_name, dc.name AS disciple_name
        FROM discipleships d
        JOIN cells c ON c.id = d.cell_id
        JOIN members dc ON dc.id = d.disciple_member_id
        WHERE d.discipler_member_id = ? AND d.status IN ('pending_leader','pending_coordination','active')
        ORDER BY d.requested_at DESC
    ");
    $q->execute([$memberId]);
    return $q->fetchAll();
}

/** Pedidos aguardando o aval do líder, nas células que o usuário atual gerencia. */
function discipleships_pending_leader(PDO $db, int $churchId): array {
    $q = $db->prepare("
        SELECT d.*, c.name AS cell_name, dc.name AS disciple_name, ds.name AS discipler_name
        FROM discipleships d
        JOIN cells c ON c.id = d.cell_id
        JOIN members dc ON dc.id = d.disciple_member_id
        JOIN members ds ON ds.id = d.discipler_member_id
        WHERE d.church_id = ? AND d.status = 'pending_leader'
        ORDER BY d.requested_at
    ");
    $q->execute([$churchId]);
    return array_values(array_filter($q->fetchAll(), fn($d) => auth_can_decide_discipleship_as_leader((int)$d['cell_id'])));
}

/** Pedidos já aprovados pelo líder, aguardando confirmação da coordenação. */
function discipleships_pending_coordination(PDO $db, int $churchId): array {
    $q = $db->prepare("
        SELECT d.*, c.name AS cell_name, dc.name AS disciple_name, ds.name AS discipler_name
        FROM discipleships d
        JOIN cells c ON c.id = d.cell_id
        JOIN members dc ON dc.id = d.disciple_member_id
        JOIN members ds ON ds.id = d.discipler_member_id
        WHERE d.church_id = ? AND d.status = 'pending_coordination'
        ORDER BY d.leader_decided_at
    ");
    $q->execute([$churchId]);
    return $q->fetchAll();
}

/** Visão geral pra coordenação: tudo que está ativo hoje. */
function discipleships_active(PDO $db, int $churchId): array {
    $q = $db->prepare("
        SELECT d.*, c.name AS cell_name, dc.name AS disciple_name, ds.name AS discipler_name
        FROM discipleships d
        JOIN cells c ON c.id = d.cell_id
        JOIN members dc ON dc.id = d.disciple_member_id
        JOIN members ds ON ds.id = d.discipler_member_id
        WHERE d.church_id = ? AND d.status = 'active'
        ORDER BY c.name, dc.name
    ");
    $q->execute([$churchId]);
    return $q->fetchAll();
}

/**
 * Cria o pedido: precisa ser da mesma célula do discípulo, o discipulador
 * precisa ter concluído pelo menos o 1º Passo, e o discípulo não pode ter
 * outro discipulado em andamento. Devolve mensagem de erro ou null se ok.
 */
function discipleship_request(PDO $db, int $discipleId, int $disciplerId): ?string {
    if ($discipleId === $disciplerId) return 'Você não pode escolher a si mesmo.';

    $disciple = $db->prepare("SELECT id, name, church_id, cell_id FROM members WHERE id = ?");
    $disciple->execute([$discipleId]);
    $disciple = $disciple->fetch();
    if (!$disciple || !$disciple['cell_id']) return 'Você precisa estar em uma célula pra pedir um discipulado.';

    if (member_current_discipleship_as_disciple($db, $discipleId)) return 'Você já tem um discipulado em andamento.';

    $discipler = $db->prepare("SELECT id, name, cell_id, discipleship_step FROM members WHERE id = ? AND church_id = ?");
    $discipler->execute([$disciplerId, $disciple['church_id']]);
    $discipler = $discipler->fetch();
    if (!$discipler) return 'Pessoa não encontrada.';
    if ((int)$discipler['cell_id'] !== (int)$disciple['cell_id']) return 'O discipulador precisa ser da sua própria célula.';
    if ((int)$discipler['discipleship_step'] < 1) return htmlspecialchars($discipler['name']) . ' ainda não concluiu o 1º Passo.';

    $db->prepare("
        INSERT INTO discipleships (church_id, cell_id, disciple_member_id, discipler_member_id, status)
        VALUES (?,?,?,?,'pending_leader')
    ")->execute([$disciple['church_id'], $disciple['cell_id'], $discipleId, $disciplerId]);

    $id = (int)$db->lastInsertId();
    discipleship_notify_leader($db, $id);
    return null;
}

/** Avisa por WhatsApp quem gerencia a célula (líder/admin) que há um pedido pra avaliar. */
function discipleship_notify_leader(PDO $db, int $discipleshipId): void {
    $d = $db->prepare("
        SELECT d.*, c.name AS cell_name, dc.name AS disciple_name, ds.name AS discipler_name
        FROM discipleships d
        JOIN cells c ON c.id = d.cell_id
        JOIN members dc ON dc.id = d.disciple_member_id
        JOIN members ds ON ds.id = d.discipler_member_id
        WHERE d.id = ?
    ");
    $d->execute([$discipleshipId]);
    $d = $d->fetch();
    if (!$d) return;

    $leaders = $db->prepare("
        SELECT m.phone, m.name FROM cell_leaders cl JOIN members m ON m.id = cl.member_id
        WHERE cl.cell_id = ? AND m.phone IS NOT NULL AND m.phone != ''
    ");
    $leaders->execute([$d['cell_id']]);
    $msg = "🤝 *Pedido de discipulado*\n\nNa célula {$d['cell_name']}, {$d['disciple_name']} escolheu {$d['discipler_name']} como discipulador(a).\n\nAcesse o sistema pra aprovar ou recusar:\n" . APP_URL . '/pages/discipleship/index.php';
    foreach ($leaders->fetchAll() as $l) {
        queue_whatsapp($l['phone'], $msg, (int)$d['church_id'], null, 0, null, 'discipleship');
    }
}

/** Avisa a coordenação que um pedido já foi aprovado pelo líder e aguarda confirmação. */
function discipleship_notify_coordination(PDO $db, int $discipleshipId): void {
    $d = $db->prepare("
        SELECT d.*, c.name AS cell_name, dc.name AS disciple_name, ds.name AS discipler_name
        FROM discipleships d
        JOIN cells c ON c.id = d.cell_id
        JOIN members dc ON dc.id = d.disciple_member_id
        JOIN members ds ON ds.id = d.discipler_member_id
        WHERE d.id = ?
    ");
    $d->execute([$discipleshipId]);
    $d = $d->fetch();
    if (!$d) return;

    $coords = $db->prepare("
        SELECT m.phone FROM discipleship_coordinators dc JOIN members m ON m.id = dc.member_id
        WHERE m.church_id = ? AND m.phone IS NOT NULL AND m.phone != ''
    ");
    $coords->execute([$d['church_id']]);
    $msg = "🤝 *Discipulado aguardando confirmação*\n\nNa célula {$d['cell_name']}, o líder já aprovou: {$d['disciple_name']} sendo discipulado(a) por {$d['discipler_name']}.\n\nAcesse o sistema pra confirmar:\n" . APP_URL . '/pages/discipleship/index.php';
    foreach ($coords->fetchAll() as $c) {
        queue_whatsapp($c['phone'], $msg, (int)$d['church_id'], null, 0, null, 'discipleship');
    }
}

/** Avisa discípulo e discipulador que o discipulado começou de verdade, ou que foi recusado. */
function discipleship_notify_decision(PDO $db, array $d, bool $started, ?string $reason = null): void {
    $people = $db->prepare("SELECT id, phone, name FROM members WHERE id IN (?,?)");
    $people->execute([$d['disciple_member_id'], $d['discipler_member_id']]);
    foreach ($people->fetchAll() as $p) {
        if (!$p['phone']) continue;
        $first = explode(' ', trim($p['name']))[0];
        $msg = $started
            ? "🤝 Olá, {$first}! Seu discipulado na célula {$d['cell_name']} foi confirmado e já pode começar. 🙏"
            : "Olá, {$first}. O pedido de discipulado na célula {$d['cell_name']} não foi confirmado" . ($reason ? ": $reason" : '.');
        queue_whatsapp($p['phone'], $msg, (int)$d['church_id'], null, 0, null, 'discipleship');
    }
}
