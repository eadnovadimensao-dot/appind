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
            '/pages/worship-scale/respond.php',
            '/pages/worship-scale/response.php',
            '/logout.php',
            '/api/',
        ];

        // Pode ver própria célula
        if ($memberId) {
            $cell = $db->query("SELECT cell_id FROM members WHERE id=$memberId")->fetchColumn();
            if ($cell) {
                $allowed[] = '/pages/cells/view.php';
            }
        }

        // Pode ver escalas se for de ministério
        if ($inMinistry) {
            $allowed[] = '/pages/worship-scale/index.php';
            $allowed[] = '/pages/ministries/activity_view.php';
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

function auth_require(string $permission): void {
    auth_check();
    if (!auth_can($permission)) {
        http_response_code(403);
        include __DIR__ . '/403.php';
        exit;
    }
}
