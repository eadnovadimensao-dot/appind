<?php
// includes/auth.php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function auth_check(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: /login.php?next=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
    if (!empty($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 7200) {
        session_destroy();
        header('Location: /login.php?timeout=1');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

function auth_user(): array   { return $_SESSION['user'] ?? []; }
function auth_role(): string  { return $_SESSION['user']['role'] ?? ''; }
function auth_member_id(): ?int { return $_SESSION['user']['member_id'] ?? null; }

// ── Permissões por perfil ────────────────────────────────────
function auth_can(string $permission): bool {
    $role = auth_role();
    $permissions = [
        'supermaster' => ['all'],
        'admin'       => ['manage_members','manage_cells','manage_finance',
                          'manage_events','approve_events','manage_ministries',
                          'manage_users','view_all'],
        'leader'      => ['view_all','request_event','manage_own_cell',
                          'manage_own_ministry'],
        'cell_leader' => ['view_all','request_event','manage_own_cell'],
        'member'      => ['view_agenda','view_own_profile','view_communication',
                          'respond_scale','view_own_cell'],
    ];
    $userPerms = $permissions[$role] ?? [];
    if (in_array('all', $userPerms)) return true;
    return in_array($permission, $userPerms);
}

// ── Redirecionar membro para sua área restrita ───────────────
function auth_member_redirect(): void {
    $role = auth_role();
    // Supermaster, admin, leader e cell_leader têm acesso livre (filtrado pelo menu)
    if (in_array($role, ['supermaster','admin','leader','cell_leader'])) return;

    if ($role === 'member') {
        $memberId = auth_member_id();
        $db       = db();

        // Verificar se é membro de algum ministério
        $inMinistry = false;
        if ($memberId) {
            $count = $db->query("SELECT COUNT(*) FROM member_ministries WHERE member_id=$memberId")->fetchColumn();
            $inMinistry = $count > 0;
        }

        $allowed = [
            '/dashboard.php',
            '/pages/events/index.php',
            '/pages/communication/index.php',
            '/pages/devotional/',
            '/pages/discipleship/',
            '/logout.php',
            '/api/',
        ];

        // Pode ver as células com que tem alguma relação (própria, lidera,
        // supervisiona ou recebe em casa) e a lista "Minhas células"
        if ($memberId) {
            $allowed[] = '/pages/cells/my_cells.php';
            if (member_related_cells($memberId)) {
                $allowed[] = '/pages/cells/view.php';
            }
        }

        // Ministérios é um diretório aberto: qualquer um vê quais existem e entra na
        // tela de cada um (o conteúdo sensível é filtrado lá dentro, não aqui).
        $allowed[] = '/pages/ministries/index.php';
        $allowed[] = '/pages/ministries/view.php';
        $allowed[] = '/pages/ministries/interest.php';

        // Atividades e materiais continuam só pra quem participa de algum ministério
        if ($inMinistry) {
            $allowed[] = '/pages/ministries/activity_view.php';
            $allowed[] = '/pages/ministries/resources.php';
            $allowed[] = '/pages/ministries/resource_edit.php';
            $allowed[] = '/pages/ministries/items.php';
            $allowed[] = '/pages/ministries/activity_dress.php';
            $allowed[] = '/pages/availability/index.php';
        }

        // Pode solicitar evento se for de ministério
        if ($inMinistry) {
            $allowed[] = '/pages/events/create.php';
            $allowed[] = '/pages/events/check_conflict.php';
        }

        // Pode ver próprio perfil
        $allowed[] = '/pages/members/profile.php';
        $allowed[] = '/pages/members/view.php';

        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        foreach ($allowed as $path) {
            if (str_starts_with($uri, $path)) return;
        }

        header('Location: /dashboard.php?no_access=1');
        exit;
    }
}

// Verifica se o usuário pode gerenciar ESSE ministério específico (não "qualquer"
// ministério) — admin/supermaster sempre podem; qualquer outro papel só se estiver
// de fato cadastrado como líder daquele ministério (tabela ministry_leaders).
function auth_can_manage_ministry(int $ministryId): bool {
    // Ministério de outra igreja nunca, nem pra admin/supermaster: cada igreja
    // só mexe no que é dela (supermaster troca de igreja pelo seletor do topo).
    static $church = [];
    if (!array_key_exists($ministryId, $church)) {
        $q = db()->prepare("SELECT church_id FROM ministries WHERE id=?");
        $q->execute([$ministryId]);
        $church[$ministryId] = $q->fetchColumn();
    }
    if ($church[$ministryId] === false || (int)$church[$ministryId] !== current_church_id()) return false;

    if (auth_can('all') || auth_role() === 'admin') return true;
    $memberId = auth_member_id();
    if (!$memberId) return false;
    static $cache = [];
    $key = "$ministryId:$memberId";
    if (!array_key_exists($key, $cache)) {
        $stmt = db()->prepare("SELECT 1 FROM ministry_leaders WHERE ministry_id=? AND member_id=?");
        $stmt->execute([$ministryId, $memberId]);
        $cache[$key] = (bool)$stmt->fetchColumn();
    }
    return $cache[$key];
}

// Mesma lógica pra células: admin/supermaster sempre podem; qualquer outro papel só
// se estiver cadastrado como líder daquela célula específica (tabela cell_leaders).
function auth_can_manage_cell(int $cellId): bool {
    static $church = [];
    if (!array_key_exists($cellId, $church)) {
        $q = db()->prepare("SELECT church_id FROM cells WHERE id=?");
        $q->execute([$cellId]);
        $church[$cellId] = $q->fetchColumn();
    }
    if ($church[$cellId] === false || (int)$church[$cellId] !== current_church_id()) return false;

    if (auth_can('all') || auth_role() === 'admin') return true;
    $memberId = auth_member_id();
    if (!$memberId) return false;
    static $cache = [];
    $key = "$cellId:$memberId";
    if (!array_key_exists($key, $cache)) {
        $stmt = db()->prepare("SELECT 1 FROM cell_leaders WHERE cell_id=? AND member_id=?");
        $stmt->execute([$cellId, $memberId]);
        $cache[$key] = (bool)$stmt->fetchColumn();
    }
    return $cache[$key];
}

// Agenda: atividade de ministério mostra "Ministério · título" e uma cor fixa por
// ministério, pra não parecer duplicata do evento do culto com o mesmo nome.
function ministry_agenda_color(int $ministryId): string {
    $palette = ['#185FA5', '#7A4FBF', '#C0603F', '#2E9E5B', '#D63A3A', '#0F8B8D', '#B8860B', '#EC6FA0'];
    return $palette[$ministryId % count($palette)];
}
function agenda_decorate(array $ev): array {
    if (($ev['type'] ?? '') === 'ministry_activity' && !empty($ev['ministry_name'])) {
        $prefix = $ev['ministry_name'] . ' · ';
        if (strpos($ev['title'], $prefix) !== 0) $ev['title'] = $prefix . $ev['title'];
        $ev['color'] = ministry_agenda_color((int)$ev['ministry_id']);
    }
    return $ev;
}

// Quem gerencia o ministério, ou foi escolhido pelo líder como responsável pela
// vestimenta (ministry_dress_managers), pode editar/enviar a vestimenta das atividades.
function auth_can_manage_dress(int $ministryId): bool {
    if (auth_can_manage_ministry($ministryId)) return true;
    $memberId = auth_member_id();
    if (!$memberId) return false;
    $stmt = db()->prepare("SELECT 1 FROM ministry_dress_managers WHERE ministry_id=? AND member_id=?");
    $stmt->execute([$ministryId, $memberId]);
    return (bool)$stmt->fetchColumn();
}

// 403 se o usuário não puder gerenciar esse ministério específico
function auth_require_ministry(int $ministryId): void {
    auth_check();
    if (!auth_can_manage_ministry($ministryId)) {
        http_response_code(403);
        include __DIR__ . '/403.php';
        exit;
    }
}

// 403 se o usuário não puder gerenciar essa célula específica
function auth_require_cell(int $cellId): void {
    auth_check();
    if (!auth_can_manage_cell($cellId)) {
        http_response_code(403);
        include __DIR__ . '/403.php';
        exit;
    }
}

function auth_can_request_event(): bool {
    if (auth_can('request_event') || auth_can('all')) return true;
    $memberId = auth_member_id();
    if (!$memberId) return false;
    $count = db()->query("SELECT COUNT(*) FROM member_ministries WHERE member_id = $memberId")->fetchColumn();
    return $count > 0;
}

function auth_event_auto_approve(): bool {
    return in_array(auth_role(), ['supermaster', 'admin']);
}

// Edita culto: só supermaster ou membro cadastrado como supervisor ativo da
// igreja em Cultos → Supervisores e rotação (normalmente Admin/Pastor).
function auth_can_edit_services(): bool {
    if (auth_role() === 'supermaster') return true;
    $memberId = auth_member_id();
    if (!$memberId) return false;
    static $cache = [];
    $key = $memberId . ':' . current_church_id();
    if (!array_key_exists($key, $cache)) {
        $stmt = db()->prepare("SELECT 1 FROM supervisors WHERE member_id = ? AND church_id = ? AND active = 1 LIMIT 1");
        $stmt->execute([$memberId, current_church_id()]);
        $cache[$key] = (bool)$stmt->fetchColumn();
    }
    return $cache[$key];
}

function auth_require_service_editor(): void {
    auth_check();
    if (!auth_can_edit_services()) {
        http_response_code(403);
        include __DIR__ . '/403.php';
        exit;
    }
}

// Membro comum só enxerga pessoas do próprio ministério e da própria célula
// (e a si mesmo). Os demais papéis mantêm o acesso que já tinham.
function auth_member_can_view_member(int $targetId): bool {
    if (auth_role() !== 'member') return true;
    $me = auth_member_id();
    if (!$me) return false;
    if ($me === $targetId) return true;
    $db = db();
    $sameMinistry = $db->prepare("
        SELECT 1 FROM member_ministries a
        JOIN member_ministries b ON b.ministry_id = a.ministry_id
        WHERE a.member_id = ? AND b.member_id = ? LIMIT 1
    ");
    $sameMinistry->execute([$me, $targetId]);
    if ($sameMinistry->fetchColumn()) return true;
    $sameCell = $db->prepare("
        SELECT 1 FROM members a JOIN members b ON b.cell_id = a.cell_id
        WHERE a.id = ? AND b.id = ? AND a.cell_id IS NOT NULL LIMIT 1
    ");
    $sameCell->execute([$me, $targetId]);
    return (bool)$sameCell->fetchColumn();
}

function auth_member_in_ministry(int $ministryId): bool {
    if (auth_role() !== 'member') return true;
    $me = auth_member_id();
    if (!$me) return false;
    $stmt = db()->prepare("SELECT 1 FROM member_ministries WHERE member_id = ? AND ministry_id = ? LIMIT 1");
    $stmt->execute([$me, $ministryId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Todas as células com que o membro tem alguma relação: a própria (membro),
 * as que lidera, as que supervisiona e as que recebe em casa (anfitrião).
 * Uma célula pode aparecer com mais de um papel ao mesmo tempo (ex: líder
 * que também é anfitrião). Usado tanto pra liberar acesso quanto pra listar
 * "Minhas células" no menu.
 */
function member_related_cells(int $memberId): array {
    $db = db();
    $cells = [];

    $own = $db->prepare("SELECT c.id, c.name FROM members m JOIN cells c ON c.id = m.cell_id WHERE m.id = ?");
    $own->execute([$memberId]);
    $own = $own->fetch();
    if ($own) $cells[$own['id']] = ['id' => (int)$own['id'], 'name' => $own['name'], 'roles' => ['membro']];

    $rels = ['cell_leaders' => 'líder', 'cell_supervisors' => 'supervisor', 'cell_hosts' => 'anfitrião'];
    foreach ($rels as $table => $roleLabel) {
        $stmt = $db->prepare("SELECT c.id, c.name FROM $table t JOIN cells c ON c.id = t.cell_id WHERE t.member_id = ?");
        $stmt->execute([$memberId]);
        foreach ($stmt->fetchAll() as $c) {
            $id = (int)$c['id'];
            if (!isset($cells[$id])) $cells[$id] = ['id' => $id, 'name' => $c['name'], 'roles' => []];
            $cells[$id]['roles'][] = $roleLabel;
        }
    }

    return array_values($cells);
}

// Além de quem tem essa célula como a própria (members.cell_id), também vê
// quem lidera, supervisiona ou recebe (anfitrião) a célula — mesmo que não
// seja formalmente membro dela.
// Ver uma célula específica depende de ter relação de verdade com ela (ser
// membro, líder, supervisor ou anfitrião), não do papel de login. Um líder de
// ministério não é automaticamente líder de célula, e vice-versa — só
// admin/supermaster têm acesso livre a todas as células da igreja.
function auth_member_in_cell(int $cellId): bool {
    if (in_array(auth_role(), ['admin', 'supermaster'])) return true;
    $me = auth_member_id();
    if (!$me) return false;
    foreach (member_related_cells($me) as $c) {
        if ($c['id'] === $cellId) return true;
    }
    return false;
}

function auth_require(string $permission): void {
    auth_check();
    if (!auth_can($permission)) {
        http_response_code(403);
        include __DIR__ . '/403.php';
        exit;
    }
}
