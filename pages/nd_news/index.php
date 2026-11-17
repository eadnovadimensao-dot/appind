<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/nd_news.php';
auth_check();

$db       = db();
$memberId = auth_member_id();
$canCreate = auth_can_create_nd_news();

$items = nd_news_active_items($db);

$pageTitle  = 'ND News';
$activePage = 'nd_news';
if ($canCreate) $topbarAction = ['href' => '/pages/nd_news/create.php', 'label' => 'Novo story'];
require_once __DIR__ . '/../../includes/layout.php';
?>

<?php if (isset($_GET['saved'])): ?>
  <div style="background:#E1F5EE;border:1px solid var(--accent);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#0F6E56">✓ Story publicado.</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
  <div style="background:#E1F5EE;border:1px solid var(--accent);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#0F6E56">✓ Story removido.</div>
<?php endif; ?>

<?php if (auth_role() === 'supermaster'): ?>
  <div style="display:flex;justify-content:flex-end;margin-bottom:12px">
    <a href="/pages/nd_news/authors.php" style="font-size:12px;color:var(--text-muted);text-decoration:none">⚙️ Quem pode criar</a>
  </div>
<?php endif; ?>

<?php if (empty($items)): ?>
  <div class="empty-state" style="padding:40px 20px">Nenhum story ativo agora. <?= $canCreate ? 'Crie o primeiro!' : '' ?></div>
<?php else: ?>
  <?php foreach ($items as $it): ?>
    <div class="card" style="margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
      <div style="display:flex;align-items:center;gap:12px;min-width:0">
        <div style="width:48px;height:48px;border-radius:50%;background:linear-gradient(45deg,#F58529,#DD2A7B,#8134AF,#515BD4);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:20px">🎥</div>
        <div style="min-width:0">
          <div style="font-size:14px;font-weight:600"><?= htmlspecialchars($it['title']) ?></div>
          <div style="font-size:12px;color:var(--text-muted)">
            <?= $it['event_date'] ? date_pt($it['event_date']) . ' · ' : '' ?>por <?= htmlspecialchars($it['author_name']) ?> · expira em <?= date('d/m/Y H:i', strtotime($it['expires_at'])) ?>
          </div>
        </div>
      </div>
      <?php if ($memberId == $it['created_by'] || auth_role() === 'supermaster'): ?>
        <form method="POST" action="/pages/nd_news/delete.php" onsubmit="return confirm('Remover esse story? O vídeo será apagado do servidor.')">
          <input type="hidden" name="id" value="<?= $it['id'] ?>">
          <button type="submit" class="btn btn-secondary" style="font-size:12px;color:var(--red)">Remover</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
