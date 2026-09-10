<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
auth_check();
auth_require('manage_members');

$db       = db();
$churchId = current_church_id();

// Supermaster na sede vê pendentes de todas as filiais; os demais só da própria igreja
if ($churchId === SEDE_ID && auth_role() === 'supermaster') {
    $signups = $db->query("
        SELECT s.*, c.name AS cell_name, ch.name AS branch_name
        FROM member_signups s
        LEFT JOIN cells c     ON c.id = s.cell_interest_id
        LEFT JOIN churches ch ON ch.id = s.church_id
        WHERE s.status = 'pending' AND (ch.id = " . SEDE_ID . " OR ch.parent_id = " . SEDE_ID . ")
        ORDER BY s.created_at ASC
    ")->fetchAll();
} else {
    $stmt = $db->prepare("
        SELECT s.*, c.name AS cell_name
        FROM member_signups s
        LEFT JOIN cells c ON c.id = s.cell_interest_id
        WHERE s.status = 'pending' AND s.church_id = ?
        ORDER BY s.created_at ASC
    ");
    $stmt->execute([$churchId]);
    $signups = $stmt->fetchAll();
}

$pageTitle  = 'Cadastros pendentes';
$activePage = 'members';
require_once __DIR__ . '/../../includes/layout.php';
?>

<div style="margin-bottom:16px">
  <a href="/pages/members/index.php" style="font-size:13px;color:var(--text-muted);text-decoration:none">
    ← Membros
  </a>
</div>

<?php if (isset($_GET['rejected'])): ?>
  <div style="background:#FEF3C7;border:1px solid #FCD34D;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#854F0B">
    Cadastro recusado.
  </div>
<?php endif; ?>

<div class="card" style="padding:0">
  <div style="padding:16px 20px;border-bottom:1px solid var(--border)">
    <span style="font-size:13px;color:var(--text-muted)"><?= count($signups) ?> cadastro(s) aguardando revisão</span>
  </div>

  <?php if (empty($signups)): ?>
    <div class="empty-state">
      <p style="font-size:32px;margin-bottom:8px">📭</p>
      <p>Nenhum cadastro pendente no momento.</p>
    </div>
  <?php else: ?>
    <?php foreach ($signups as $s):
      $initials = strtoupper(implode('', array_map(fn($p) => $p[0], array_slice(explode(' ', $s['name']), 0, 2))));
    ?>
      <div style="display:flex;align-items:flex-start;gap:14px;padding:14px 20px;border-bottom:1px solid var(--border)">
        <div class="avatar" style="flex-shrink:0"><?= $initials ?></div>
        <div style="flex:1;min-width:0">
          <div style="font-weight:500;font-size:14px"><?= htmlspecialchars($s['name']) ?>
            <?php if (isset($s['branch_name'])): ?>
              <span class="badge badge-gray" style="font-size:10px;margin-left:6px"><?= htmlspecialchars($s['branch_name']) ?></span>
            <?php endif; ?>
          </div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:3px;display:flex;flex-direction:column;gap:2px">
            <span>📱 <?= htmlspecialchars($s['phone']) ?><?= $s['email'] ? ' · ✉️ ' . htmlspecialchars($s['email']) : '' ?></span>
            <?php if ($s['cell_name']): ?>
              <span>🔗 Interesse: <?= htmlspecialchars($s['cell_name']) ?></span>
            <?php endif; ?>
            <?php if ($s['notes']): ?>
              <span style="font-style:italic">"<?= htmlspecialchars($s['notes']) ?>"</span>
            <?php endif; ?>
            <span>Enviado em <?= date('d/m/Y \à\s H:i', strtotime($s['created_at'])) ?></span>
          </div>
        </div>
        <div style="display:flex;gap:6px;flex-shrink:0">
          <a href="/pages/members/create.php?from_signup=<?= $s['id'] ?>" class="btn btn-primary" style="font-size:12px;padding:5px 12px">✓ Revisar e aprovar</a>
          <a href="/pages/members/signup_reject.php?id=<?= $s['id'] ?>" class="btn btn-secondary" style="font-size:12px;padding:5px 12px;color:var(--red)"
             data-confirm="Recusar o cadastro de <?= htmlspecialchars($s['name']) ?>?">✗ Recusar</a>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
