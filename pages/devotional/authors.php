<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
if (auth_role() !== 'supermaster') {
    http_response_code(403);
    include __DIR__ . '/../../includes/403.php';
    exit;
}

$db = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $mid    = (int)($_POST['member_id'] ?? 0);
    if ($action === 'add' && $mid) {
        $db->prepare("INSERT IGNORE INTO devotional_authors (member_id) VALUES (?)")->execute([$mid]);
    } elseif ($action === 'remove' && $mid) {
        $db->prepare("DELETE FROM devotional_authors WHERE member_id = ?")->execute([$mid]);
    }
    header('Location: /pages/devotional/authors.php');
    exit;
}

$authors = $db->query("
    SELECT m.id, m.name, ch.name AS church_name
    FROM devotional_authors da JOIN members m ON m.id = da.member_id LEFT JOIN churches ch ON ch.id = m.church_id
    ORDER BY m.name
")->fetchAll();

// Candidatos: membros ativos de TODAS as igrejas (o devocional é único pra todas), agrupados por igreja
$candidates = [];
foreach ($db->query("
    SELECT m.id, m.name, COALESCE(ch.name, 'Sem igreja') AS church_name, ch.type
    FROM members m LEFT JOIN churches ch ON ch.id = m.church_id
    WHERE m.status = 'active' AND m.id NOT IN (SELECT member_id FROM devotional_authors)
    ORDER BY (ch.type = 'sede') DESC, ch.name, m.name
")->fetchAll() as $c) {
    $candidates[$c['church_name']][] = $c;
}

$pageTitle  = 'Quem pode escrever devocional';
$activePage = 'devotional';
require_once __DIR__ . '/../../includes/layout.php';
?>

<div style="margin-bottom:16px">
  <a href="/pages/devotional/index.php" style="font-size:13px;color:var(--text-muted);text-decoration:none">← Devocional</a>
</div>

<div class="card" style="margin-bottom:16px;background:#F5F5F5;border:none">
  <p style="font-size:13px;line-height:1.7">
    ✍️ O supermaster sempre pode escrever. Aqui você libera pastores (da Sede ou de qualquer filial) pra escrever e enviar devocionais.
    Cada um só edita os próprios devocionais, e a assinatura na mensagem é o nome de quem escreveu.
  </p>
</div>

<div class="card" style="padding:0;margin-bottom:16px">
  <?php foreach ($authors as $a): ?>
    <form method="POST" style="display:flex;align-items:center;gap:12px;padding:12px 18px;border-bottom:1px solid var(--border)">
      <input type="hidden" name="action" value="remove">
      <input type="hidden" name="member_id" value="<?= $a['id'] ?>">
      <div style="flex:1">
        <div style="font-size:14px;font-weight:500"><?= htmlspecialchars($a['name']) ?></div>
        <div style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($a['church_name'] ?? '') ?></div>
      </div>
      <button type="submit" class="btn btn-secondary" style="font-size:12px;color:var(--red)">Remover</button>
    </form>
  <?php endforeach; ?>
  <?php if (empty($authors)): ?><div class="empty-state" style="padding:24px">Nenhum autor cadastrado ainda.</div><?php endif; ?>
</div>

<div class="card">
  <p class="card-title">+ Liberar um pastor</p>
  <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap">
    <input type="hidden" name="action" value="add">
    <select name="member_id" class="form-control" style="flex:1;min-width:220px" required>
      <option value="">Selecione o membro…</option>
      <?php foreach ($candidates as $churchName => $list): ?>
        <optgroup label="<?= htmlspecialchars($churchName) ?>">
          <?php foreach ($list as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
        </optgroup>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary">Liberar</button>
  </form>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
