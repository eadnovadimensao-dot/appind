<?php
// includes/layout.php
// Uso: inclua no topo de cada página definindo $pageTitle e $activePage

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';
auth_check();
auth_member_redirect(); // bloqueia membro de acessar páginas restritas

$navItems = [
  ['href' => '/dashboard.php',                 'icon' => 'ti-layout-dashboard', 'label' => 'Dashboard',    'key' => 'dashboard'],
  ['href' => '/pages/offering.php',            'icon' => 'ti-heart',            'label' => 'Oferta',       'key' => 'offering'],
  ['href' => '/pages/devotional/index.php',    'icon' => 'ti-book',             'label' => 'Devocional',   'key' => 'devotional'],
  ['href' => '/pages/members/index.php',        'icon' => 'ti-users',            'label' => 'Membros',      'key' => 'members'],
  ['href' => '/pages/families/index.php',       'icon' => 'ti-home',             'label' => 'Famílias',     'key' => 'families'],
  ['href' => '/pages/cells/index.php',          'icon' => 'ti-circles',          'label' => 'Células',      'key' => 'cells'],
  ['href' => '/pages/finance/index.php',        'icon' => 'ti-cash',             'label' => 'Financeiro',   'key' => 'finance'],
  ['href' => '/pages/events/index.php',         'icon' => 'ti-calendar',         'label' => 'Agenda',       'key' => 'events'],
  ['href' => '/pages/services/index.php',       'icon' => 'ti-building-church',  'label' => 'Cultos',       'key' => 'services'],
  ['href' => '/pages/communication/index.php',  'icon' => 'ti-bell',             'label' => 'Comunicação',  'key' => 'communication'],
  ['href' => '/pages/ministries/index.php',     'icon' => 'ti-star',             'label' => 'Ministérios',  'key' => 'ministries'],
  ['href' => '/pages/users/index.php',          'icon' => 'ti-lock',             'label' => 'Usuários',     'key' => 'users'],
  ['href' => '/pages/branches.php',             'icon' => 'ti-building',         'label' => 'Filiais',      'key' => 'branches'],
  ['href' => '/pages/whatsapp.php',             'icon' => 'ti-brand-whatsapp',  'label' => 'WhatsApp',     'key' => 'whatsapp'],
  ['href' => '/pages/settings.php',             'icon' => 'ti-settings',         'label' => 'Configurações','key' => 'settings'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? APP_NAME) ?> · <?= APP_NAME ?></title>
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="<?= setting('primary_color','#012a36') ?>">
  <link rel="stylesheet" href="/public/css/app.css?v=<?= @filemtime(__DIR__ . '/../public/css/app.css') ?: 1 ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
  <style>
    :root {
      --sb-bg:  <?= setting('primary_color', '#012a36') ?>;
      --accent: <?= setting('accent_color',  '#1D9E75') ?>;
      --accent-dk: <?= adjustColor(setting('accent_color','#1D9E75'), -20) ?>;
      --accent-lt: <?= hexToRgba(setting('accent_color','#1D9E75'), 0.12) ?>;
    }
  </style>
</head>
<body>
<script>
// Limpar collapsed no mobile ANTES de qualquer renderização
if (window.innerWidth <= 900) {
  localStorage.removeItem('sb_collapsed');
  document.documentElement.style.setProperty('--sb-w-sm', '0px');
}
</script>
<!-- Overlay mobile -->
<div class="sb-overlay" id="sb-overlay" onclick="closeSidebar()"></div>

<div class="app">

  <!-- Sidebar -->
  <aside class="sidebar" id="sidebar">
    <div class="sb-header">
      <?php
        $logoUrl  = setting('church_logo_url');
        $initials = setting('church_initials', 'IG');
        $name     = setting('church_name', APP_NAME);
      ?>
      <div class="sb-logo-icon" style="overflow:hidden;padding:0;flex-shrink:0">
        <?php if ($logoUrl): ?>
          <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo"
               style="width:100%;height:100%;object-fit:cover;border-radius:8px">
        <?php else: ?>
          <span style="font-size:13px;font-weight:700;color:white"><?= htmlspecialchars($initials) ?></span>
        <?php endif; ?>
      </div>
      <span class="sb-logo-text"><?= htmlspecialchars($name) ?></span>
    </div>

    <nav class="sb-nav">
      <?php
      $role     = auth_role();
      $memberId = auth_member_id();
      $inMinistry = false;
      $memberCellId = null;

      if ($memberId && $role === 'member') {
          $mInfo = db()->query("SELECT cell_id FROM members WHERE id=$memberId")->fetch();
          $memberCellId = $mInfo['cell_id'] ?? null;
          $inMinistry = (bool)db()->query("SELECT COUNT(*) FROM member_ministries WHERE member_id=$memberId")->fetchColumn();
          // Anfitrião de célula diferente da própria também precisa de um jeito de chegar lá
          if (!$memberCellId) {
              $memberCellId = db()->query("SELECT cell_id FROM cell_hosts WHERE member_id=$memberId LIMIT 1")->fetchColumn() ?: null;
          }
      }

      foreach ($navItems as $item):
        if (in_array($item['key'], ['branches','whatsapp']) && $role !== 'supermaster') continue; // Filiais e WhatsApp: só supermaster
        if ($role === 'member') {
          $memberAllowed = ['dashboard','events','communication','offering','devotional'];
          if ($memberCellId) $memberAllowed[] = 'cells';
          if ($inMinistry) $memberAllowed[] = 'ministries';
          if (!in_array($item['key'], $memberAllowed)) continue;
          // Célula: redirecionar direto para a célula do membro (ou a que ele recebe em casa)
          if ($item['key'] === 'cells') {
            $item['href'] = '/pages/cells/view.php?id=' . $memberCellId;
            $item['label'] = 'Minha Célula';
          }
        }
        if ($role === 'leader') {
          $leaderAllowed = ['dashboard','events','communication','ministries','cells','offering','devotional'];
          if (!in_array($item['key'], $leaderAllowed)) continue;
        }
        if ($role === 'cell_leader') {
          $cellLeaderAllowed = ['dashboard','events','communication','cells','ministries','offering','devotional'];
          if (!in_array($item['key'], $cellLeaderAllowed)) continue;
        }
      ?>
        <a href="<?= $item['href'] ?>"
           class="sb-item <?= ($activePage ?? '') === $item['key'] ? 'active' : '' ?>">
          <i class="ti <?= $item['icon'] ?> sb-item-icon" aria-hidden="true"></i>
          <span class="sb-item-label"><?= $item['label'] ?></span>
          <?php if (($activePage ?? '') === $item['key']): ?>
            <span class="sb-item-dot"></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="sb-footer">
      <?php
        $sbRoleLabels = ['supermaster'=>'Supermaster','admin'=>'Administrador','leader'=>'Líder','cell_leader'=>'Líder de célula','member'=>'Membro'];
      ?>
      <div class="sb-user">
        <a href="/pages/members/profile.php" class="sb-user-text" title="Meu perfil">
          <div class="sb-user-name"><?= htmlspecialchars(auth_user()['name'] ?? '') ?></div>
          <div class="sb-user-role"><?= htmlspecialchars($sbRoleLabels[auth_role()] ?? ucfirst(auth_role())) ?></div>
        </a>
        <a href="/logout.php" class="sb-logout" title="Sair" aria-label="Sair"><i class="ti ti-logout"></i></a>
      </div>
      <div class="sb-church-info">
        <span class="sb-church-name"><?= htmlspecialchars(setting('church_name', 'Igreja')) ?></span>
        <div class="sb-avatar"><?= htmlspecialchars(setting('church_initials', 'IG')) ?></div>
      </div>
      <button class="sb-toggle" id="sb-toggle" aria-label="Retrair menu">
        <i class="ti ti-chevron-left" style="font-size:16px"></i>
      </button>
    </div>
  </aside>

  <!-- Main -->
  <div class="main">
    <header class="topbar">
      <!-- Hamburguer (só mobile) -->
      <button class="sb-hamburger" onclick="openSidebar()" aria-label="Menu">☰</button>
      <span class="topbar-title"><?= htmlspecialchars($pageTitle ?? '') ?></span>
      <div class="topbar-actions">

        <?php if (auth_role() === 'supermaster'): ?>
        <!-- Seletor de filial -->
        <?php
          $branches    = get_branches();
          $viewingId   = $_SESSION['user']['viewing_branch_id'] ?? SEDE_ID;
          $viewingName = $_SESSION['user']['viewing_branch_name'] ?? null;
          if (!$viewingName) $viewingName = 'Sede';
        ?>
        <div style="position:relative" id="branch-switcher">
          <button onclick="toggleBranchMenu()"
                  style="display:flex;align-items:center;gap:6px;padding:6px 10px;border-radius:7px;border:1px solid var(--border);background:white;cursor:pointer;font-size:12px;color:var(--text)">
            🏛️ <?= htmlspecialchars($viewingName) ?> <span style="font-size:10px;color:var(--text-muted)">▼</span>
          </button>
          <div id="branch-menu"
               style="display:none;position:absolute;top:100%;right:0;margin-top:4px;background:white;border:1px solid var(--border);border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.1);min-width:200px;z-index:999;overflow:hidden">
            <?php foreach ($branches as $b): ?>
              <a href="/switch_branch.php?branch_id=<?= $b['id'] ?>&next=<?= urlencode($_SERVER['REQUEST_URI']) ?>"
                 style="display:flex;align-items:center;gap:8px;padding:10px 14px;text-decoration:none;font-size:13px;color:var(--text);border-bottom:1px solid var(--border)"
                 onmouseover="this.style.background='var(--content-bg)'" onmouseout="this.style.background=''">
                <?php if ($b['id'] == $viewingId): ?>
                  <span style="color:var(--accent)">✓</span>
                <?php else: ?>
                  <span style="width:12px;display:inline-block"></span>
                <?php endif; ?>
                <span>
                  <?= htmlspecialchars($b['name']) ?>
                  <span class="badge <?= $b['type']==='sede'?'badge-blue':'badge-green' ?>" style="font-size:10px;margin-left:4px">
                    <?= $b['type']==='sede'?'Sede':'Filial' ?>
                  </span>
                </span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <?php if (isset($topbarAction)): ?>
          <a href="<?= $topbarAction['href'] ?>" class="btn btn-primary">
            + <?= $topbarAction['label'] ?>
          </a>
        <?php endif; ?>
        <!-- Botão de ativar push (aparece só se não ativado) -->
        <button id="enable-push-btn" onclick="enablePushNotifications()" title="Ativar notificações" aria-label="Ativar notificações"
                style="display:none;align-items:center;justify-content:center;font-size:16px;width:36px;height:36px;padding:0;border-radius:7px;border:1px solid var(--border);background:white;cursor:pointer;color:var(--text)">
          🔔
        </button>
        <!-- Usuário logado -->
        <div style="display:flex;align-items:center;gap:8px;margin-left:8px">
          <?php
            // Foto de perfil do usuário logado
            $loggedMemberId = auth_member_id();
            $photoUrl = null;
            if ($loggedMemberId) {
                $photoRow = db()->query("SELECT photo_url FROM members WHERE id=$loggedMemberId")->fetch();
                $photoUrl = $photoRow['photo_url'] ?? null;
                // Remove cache buster para verificar
                $photoUrl = $photoUrl ? strtok($photoUrl, '?') : null;
                $photoUrl = $photoUrl && file_exists(__DIR__ . '/../' . $photoUrl) ? $photoRow['photo_url'] : null;
            }
            $topbarInitials = strtoupper(substr(auth_user()['name'] ?? 'U', 0, 2));
          ?>
          <a href="/pages/members/profile.php"
             style="width:36px;height:36px;border-radius:50%;overflow:hidden;display:flex;align-items:center;justify-content:center;background:var(--accent-lt);color:var(--accent-dk);font-size:12px;font-weight:600;text-decoration:none;flex-shrink:0;border:2px solid var(--border)"
             title="Meu perfil">
            <?php if ($photoUrl): ?>
              <img src="<?= htmlspecialchars($photoUrl) ?>" style="width:100%;height:100%;object-fit:cover">
            <?php else: ?>
              <?= $topbarInitials ?>
            <?php endif; ?>
          </a>
        </div>
      </div>
    </header>

    <!-- Nome da página: no celular fica nesta barra, abaixo do topo -->
    <div class="pagebar"><?= htmlspecialchars($pageTitle ?? '') ?></div>

    <div class="page">
