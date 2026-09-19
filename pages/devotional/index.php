<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/devotional.php';
auth_check();

$db       = db();
$memberId = auth_member_id();
$canWrite = auth_can_write_devotional();

// Descadastrar / voltar a receber por WhatsApp (só pra quem tem cadastro de membro)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $memberId && isset($_POST['receive'])) {
    devotional_set_optout($db, (int)$memberId, $_POST['receive'] !== '1');
    header('Location: /pages/devotional/index.php?pref=1');
    exit;
}

$optout = false;
if ($memberId) {
    $q = $db->prepare("SELECT devotional_optout FROM members WHERE id = ?");
    $q->execute([$memberId]);
    $optout = (bool)$q->fetchColumn();
}

// Quem escreve também vê os agendados (data futura)
$where = $canWrite ? '1=1' : 'publish_date <= CURDATE()';
$list  = $db->query("SELECT * FROM devotionals WHERE $where ORDER BY publish_date DESC, id DESC LIMIT 90")->fetchAll();
$today = null;
foreach ($list as $d) if ($d['publish_date'] === date('Y-m-d')) { $today = $d; break; }

$pageTitle    = 'Devocional';
$activePage   = 'devotional';
if ($canWrite) $topbarAction = ['href' => '/pages/devotional/edit.php', 'label' => 'Novo devocional'];
require_once __DIR__ . '/../../includes/layout.php';
?>

<?php if (isset($_GET['saved'])): ?>
  <div style="background:#E1F5EE;border:1px solid var(--accent);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#0F6E56">✓ Devocional salvo.</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
  <div style="background:#E1F5EE;border:1px solid var(--accent);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#0F6E56">✓ Devocional excluído.</div>
<?php endif; ?>
<?php if (isset($_GET['pref'])): ?>
  <div style="background:#E1F5EE;border:1px solid var(--accent);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#0F6E56">✓ Preferência atualizada.</div>
<?php endif; ?>

<?php if ($memberId): ?>
<div class="card" style="margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
  <div style="font-size:13px">
    <?= $optout ? '🔕 Você <strong>não</strong> recebe o devocional no WhatsApp.' : '🔔 Você recebe o devocional no WhatsApp todo dia de manhã.' ?>
  </div>
  <form method="POST">
    <input type="hidden" name="receive" value="<?= $optout ? '1' : '0' ?>">
    <button type="submit" class="btn btn-secondary" style="font-size:12px"><?= $optout ? 'Voltar a receber' : 'Parar de receber' ?></button>
  </form>
</div>
<?php endif; ?>

<?php if ($canWrite): ?>
  <div style="display:flex;justify-content:flex-end;margin-bottom:12px">
    <?php if (auth_role() === 'supermaster'): ?>
      <a href="/pages/devotional/authors.php" class="btn btn-secondary" style="font-size:12px">✍️ Quem pode escrever</a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($today): ?>
  <div class="card" style="margin-bottom:16px;border-left:4px solid var(--accent)">
    <p style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--accent);margin-bottom:6px">🌅 Devocional de hoje</p>
    <a href="/pages/devotional/view.php?id=<?= $today['id'] ?>" style="font-size:18px;font-weight:600;color:var(--text);text-decoration:none"><?= htmlspecialchars($today['title']) ?></a>
    <p style="font-size:13px;color:var(--text);line-height:1.7;margin-top:8px"><?= nl2br(htmlspecialchars(mb_strimwidth($today['body'], 0, 320, '…'))) ?></p>
    <p style="font-size:12px;color:var(--text-muted);margin-top:8px">✍️ <?= htmlspecialchars($today['author_name']) ?> · <a href="/pages/devotional/view.php?id=<?= $today['id'] ?>">Ler completo</a></p>
  </div>
<?php endif; ?>

<div class="card" style="padding:0">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
    <p style="font-weight:500;font-size:14px">Devocionais anteriores</p>
  </div>
  <?php if (empty($list)): ?>
    <div class="empty-state" style="padding:24px">Nenhum devocional publicado ainda.</div>
  <?php endif; ?>
  <?php foreach ($list as $d):
    $future = $d['publish_date'] > date('Y-m-d');
  ?>
    <a href="/pages/devotional/view.php?id=<?= $d['id'] ?>" style="display:block;padding:14px 18px;border-bottom:1px solid var(--border);text-decoration:none;color:var(--text)">
      <div style="display:flex;justify-content:space-between;gap:8px;align-items:center;flex-wrap:wrap">
        <span style="font-size:14px;font-weight:500"><?= htmlspecialchars($d['title']) ?></span>
        <span style="font-size:12px;color:var(--text-muted)">
          <?= date('d/m/Y', strtotime($d['publish_date'])) ?>
          <?php if ($future): ?><span class="badge badge-blue" style="font-size:10px;margin-left:4px">agendado</span><?php endif; ?>
        </span>
      </div>
      <div style="font-size:12px;color:var(--text-muted);margin-top:2px">✍️ <?= htmlspecialchars($d['author_name']) ?></div>
    </a>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
