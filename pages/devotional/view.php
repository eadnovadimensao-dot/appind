<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/devotional.php';
auth_check();

$db  = db();
$id  = (int)($_GET['id'] ?? 0);
$q   = $db->prepare("SELECT * FROM devotionals WHERE id = ?");
$q->execute([$id]);
$dev = $q->fetch();
// Agendado (data futura) só quem escreve enxerga
if (!$dev || ($dev['publish_date'] > date('Y-m-d') && !auth_can_write_devotional())) {
    header('Location: /pages/devotional/index.php');
    exit;
}

$blocks  = devotional_verse_blocks($db, $id);
$canEdit = auth_can_edit_devotional($dev);
$future  = $dev['publish_date'] > date('Y-m-d');

$pageTitle  = 'Devocional';
$activePage = 'devotional';
require_once __DIR__ . '/../../includes/layout.php';
?>

<?php if (isset($_GET['test'])): ?>
  <div style="background:#E1F5EE;border:1px solid var(--accent);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#0F6E56">
    📤 <?= $_GET['test'] === 'ok' ? 'Teste enfileirado pro seu WhatsApp, chega em instantes.' : 'Não consegui enviar o teste: seu cadastro de membro não tem telefone.' ?>
  </div>
<?php endif; ?>

<div style="margin-bottom:16px">
  <a href="/pages/devotional/index.php" style="font-size:13px;color:var(--text-muted);text-decoration:none">← Devocional</a>
</div>

<div class="card" style="max-width:720px">
  <p style="font-size:12px;color:var(--text-muted);margin-bottom:6px">
    <?= date_pt($dev['publish_date']) ?>
    <?php if ($future): ?><span class="badge badge-blue" style="font-size:10px;margin-left:4px">agendado</span><?php endif; ?>
  </p>
  <h1 style="font-size:22px;font-weight:600;margin-bottom:16px"><?= htmlspecialchars($dev['title']) ?></h1>

  <?php foreach ($blocks as $b): ?>
    <div style="background:var(--content-bg);border-radius:8px;padding:12px 14px;margin-bottom:14px">
      <div style="font-size:13px;font-weight:600;margin-bottom:6px">📖 <?= htmlspecialchars($b['reference']) ?></div>
      <div style="font-size:14px;line-height:1.8">
        <?php foreach ($b['verses'] as $v): ?><sup style="font-weight:600"><?= (int)$v['verse'] ?></sup> <?= htmlspecialchars($v['text']) ?> <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <div style="font-size:15px;line-height:1.9"><?= nl2br(htmlspecialchars($dev['body'])) ?></div>

  <p style="margin-top:20px;font-size:14px;font-weight:500">✍️ <?= htmlspecialchars($dev['author_name']) ?></p>

  <?php if ($canEdit): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:20px;padding-top:16px;border-top:1px solid var(--border)">
      <a href="/pages/devotional/edit.php?id=<?= $id ?>" class="btn btn-secondary">Editar</a>
      <a href="/pages/devotional/delete.php?id=<?= $id ?>" class="btn btn-secondary" style="color:var(--red)" data-confirm="Excluir este devocional?">Excluir</a>
      <?php if (auth_member_id()): ?>
        <a href="/pages/devotional/send_test.php?id=<?= $id ?>" class="btn btn-secondary">📤 Enviar teste pra mim</a>
      <?php endif; ?>
      <span style="font-size:12px;color:var(--text-muted);align-self:center">
        <?php if ($dev['sent_at']): ?>Enviado por WhatsApp em <?= date('d/m H:i', strtotime($dev['sent_at'])) ?>.
        <?php elseif (!$dev['send_whatsapp']): ?>Sem envio por WhatsApp (só no portal).
        <?php elseif ($dev['publish_date'] < date('Y-m-d')): ?>Data passada: não é enviado por WhatsApp.
        <?php else: ?>Será enviado às <?= sprintf('%02d', DEVOTIONAL_SEND_HOUR) ?>h de <?= date('d/m', strtotime($dev['publish_date'])) ?>.<?php endif; ?>
      </span>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
