<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
auth_check();
if (auth_role() !== 'supermaster') {
    http_response_code(403);
    include __DIR__ . '/../includes/403.php';
    exit;
}

$db = db();

$actionLabels = [
    'user_create'         => ['label' => 'Convidou usuário',        'icon' => '👤'],
    'user_activate'       => ['label' => 'Ativou usuário',          'icon' => '✅'],
    'user_deactivate'     => ['label' => 'Desativou usuário',       'icon' => '⛔'],
    'user_change_role'    => ['label' => 'Mudou perfil de usuário', 'icon' => '🔧'],
    'user_set_password'   => ['label' => 'Definiu senha de alguém', 'icon' => '🔑'],
    'user_self_password'  => ['label' => 'Trocou a própria senha',  'icon' => '🔑'],
    'user_delete'         => ['label' => 'Excluiu usuário',         'icon' => '🗑️'],
    'member_delete'       => ['label' => 'Excluiu membro',          'icon' => '🗑️'],
    'settings_update'     => ['label' => 'Atualizou configurações', 'icon' => '⚙️'],
];

$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$total = (int)$db->query("SELECT COUNT(*) FROM admin_audit_log")->fetchColumn();
$rows  = $db->query("SELECT * FROM admin_audit_log ORDER BY created_at DESC, id DESC LIMIT $limit OFFSET $offset")->fetchAll();

$pageTitle  = 'Log de auditoria';
$activePage = 'audit_log';
require_once __DIR__ . '/../includes/layout.php';
?>

<div class="card" style="padding:0">
  <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
    <span style="font-size:13px;color:var(--text-muted)"><?= $total ?> registro(s)</span>
  </div>
  <?php if (empty($rows)): ?>
    <div class="empty-state" style="padding:24px">Nenhum registro ainda.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Quando</th><th>Quem</th><th>Ação</th><th>Detalhe</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): $a = $actionLabels[$r['action']] ?? ['label' => $r['action'], 'icon' => '📋']; ?>
            <tr>
              <td style="white-space:nowrap;color:var(--text-muted)"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
              <td><?= htmlspecialchars($r['actor_name'] ?: '—') ?></td>
              <td><?= $a['icon'] ?> <?= htmlspecialchars($a['label']) ?></td>
              <td style="color:var(--text-muted)"><?= htmlspecialchars($r['detail'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if ($total > $limit): ?>
  <div style="display:flex;gap:8px;margin-top:16px;justify-content:center">
    <?php if ($page > 1): ?><a href="?page=<?= $page-1 ?>" class="btn btn-secondary" style="font-size:12px;padding:6px 14px">← Mais recentes</a><?php endif; ?>
    <?php if ($offset + $limit < $total): ?><a href="?page=<?= $page+1 ?>" class="btn btn-secondary" style="font-size:12px;padding:6px 14px">Mais antigos →</a><?php endif; ?>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/layout-footer.php'; ?>
