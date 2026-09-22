<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
if (auth_role() !== 'supermaster') {
    http_response_code(403);
    include __DIR__ . '/../../includes/403.php';
    exit;
}

$db       = db();
$churchId = current_church_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $mid    = (int)($_POST['member_id'] ?? 0);
    // Confere que o membro é da igreja atual antes de mexer
    $chk = $db->prepare("SELECT 1 FROM members WHERE id = ? AND church_id = ?");
    $chk->execute([$mid, $churchId]);
    if ($mid && $chk->fetchColumn()) {
        if ($action === 'add') {
            $db->prepare("INSERT IGNORE INTO discipleship_coordinators (member_id) VALUES (?)")->execute([$mid]);
        } elseif ($action === 'remove') {
            $db->prepare("DELETE FROM discipleship_coordinators WHERE member_id = ?")->execute([$mid]);
        }
    }
    header('Location: /pages/discipleship/coordinators.php');
    exit;
}

$coordinators = $db->prepare("
    SELECT m.id, m.name
    FROM discipleship_coordinators dc JOIN members m ON m.id = dc.member_id
    WHERE m.church_id = ? ORDER BY m.name
");
$coordinators->execute([$churchId]);
$coordinators = $coordinators->fetchAll();

$candidates = $db->prepare("
    SELECT id, name FROM members
    WHERE church_id = ? AND status = 'active' AND id NOT IN (SELECT member_id FROM discipleship_coordinators)
    ORDER BY name
");
$candidates->execute([$churchId]);
$candidates = $candidates->fetchAll();

$pageTitle  = 'Coordenação do discipulado';
$activePage = 'discipleship';
require_once __DIR__ . '/../../includes/layout.php';
?>

<div style="margin-bottom:16px">
  <a href="/pages/discipleship/index.php" style="font-size:13px;color:var(--text-muted);text-decoration:none">← Discipulado</a>
</div>

<div class="card" style="margin-bottom:16px;background:#F5F5F5;border:none">
  <p style="font-size:13px;line-height:1.7">
    ✅ Quem estiver aqui dá o segundo aval do discipulado, depois do líder da célula. O supermaster sempre pode confirmar, mesmo sem estar na lista.
  </p>
</div>

<div class="card" style="padding:0;margin-bottom:16px">
  <?php foreach ($coordinators as $c): ?>
    <form method="POST" style="display:flex;align-items:center;gap:12px;padding:12px 18px;border-bottom:1px solid var(--border)">
      <input type="hidden" name="action" value="remove">
      <input type="hidden" name="member_id" value="<?= $c['id'] ?>">
      <div style="flex:1;font-size:14px;font-weight:500"><?= htmlspecialchars($c['name']) ?></div>
      <button type="submit" class="btn btn-secondary" style="font-size:12px;color:var(--red)">Remover</button>
    </form>
  <?php endforeach; ?>
  <?php if (empty($coordinators)): ?><div class="empty-state" style="padding:24px">Ninguém cadastrado ainda.</div><?php endif; ?>
</div>

<div class="card">
  <p class="card-title">+ Adicionar à coordenação</p>
  <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap">
    <input type="hidden" name="action" value="add">
    <select name="member_id" class="form-control" style="flex:1;min-width:220px" required>
      <option value="">Selecione o membro…</option>
      <?php foreach ($candidates as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary">Adicionar</button>
  </form>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
