<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db       = db();
$churchId = current_church_id();
$memberId = auth_member_id();
$errors   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $memberId) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $errors[] = 'Dê um nome pro grupo.';
        } else {
            $name = mb_substr($name, 0, 100);
            $db->prepare("INSERT INTO bible_reading_groups (church_id, name, created_by, created_at) VALUES (?,?,?,NOW())")
               ->execute([$churchId, $name, $memberId]);
            $newGroupId = $db->lastInsertId();
            $db->prepare("UPDATE members SET bible_reading_group_id = ? WHERE id = ?")->execute([$newGroupId, $memberId]);
        }
    } elseif ($action === 'join') {
        $groupId = (int)($_POST['group_id'] ?? 0);
        $check = $db->prepare("SELECT id FROM bible_reading_groups WHERE id = ? AND church_id = ?");
        $check->execute([$groupId, $churchId]);
        if ($check->fetchColumn()) {
            $db->prepare("UPDATE members SET bible_reading_group_id = ? WHERE id = ?")->execute([$groupId, $memberId]);
        }
    } elseif ($action === 'leave') {
        $db->prepare("UPDATE members SET bible_reading_group_id = NULL WHERE id = ?")->execute([$memberId]);
    }

    if (empty($errors)) {
        header('Location: /pages/bible_reading/groups.php');
        exit;
    }
}

$me = $db->prepare("SELECT bible_reading_group_id FROM members WHERE id = ?");
$me->execute([$memberId]);
$myGroupId = $me->fetchColumn() ?: null;

$groups = $db->prepare("
    SELECT g.id, g.name, g.created_at,
           (SELECT COUNT(*) FROM members m WHERE m.bible_reading_group_id = g.id AND m.status = 'active') AS member_count
    FROM bible_reading_groups g
    WHERE g.church_id = ?
    ORDER BY member_count DESC, g.created_at DESC
");
$groups->execute([$churchId]);
$groups = $groups->fetchAll();

$pageTitle  = 'Grupos de Leitura';
$activePage = 'bible_reading';
require_once __DIR__ . '/../../includes/layout.php';
?>

<a href="/pages/bible_reading/index.php" style="display:inline-block;margin-bottom:14px;font-size:13px;color:var(--accent)">← Voltar pra leitura</a>

<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px">
  <p class="card-title">➕ Criar um grupo novo</p>
  <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px">Qualquer pessoa pode criar um grupo e convidar quem quiser pra entrar, sem precisar ser da mesma célula.</p>
  <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap">
    <input type="hidden" name="action" value="create">
    <input type="text" name="name" class="form-control" placeholder="Nome do grupo (ex: Leitura da Família, Amigos do trabalho…)" style="flex:1;min-width:200px" required>
    <button type="submit" class="btn btn-primary">Criar grupo</button>
  </form>
</div>

<div class="card">
  <p class="card-title">👥 Grupos desta igreja</p>
  <?php if (!$groups): ?>
    <p style="font-size:13px;color:var(--text-muted)">Nenhum grupo criado ainda. Seja o primeiro.</p>
  <?php endif; ?>
  <?php foreach ($groups as $g):
      $isMine = $myGroupId && (int)$g['id'] === (int)$myGroupId;
  ?>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px;border:1px solid var(--border);border-radius:8px;margin-bottom:8px;<?= $isMine ? 'background:var(--accent-lt)' : '' ?>">
      <div>
        <div style="font-size:13px;font-weight:600"><?= htmlspecialchars($g['name']) ?><?= $isMine ? ' <span style="color:var(--accent);font-weight:700">(seu grupo)</span>' : '' ?></div>
        <div style="font-size:12px;color:var(--text-muted)"><?= (int)$g['member_count'] ?> pessoa<?= $g['member_count'] == 1 ? '' : 's' ?></div>
      </div>
      <?php if ($isMine): ?>
        <form method="POST" onsubmit="return confirm('Sair deste grupo?')">
          <input type="hidden" name="action" value="leave">
          <button type="submit" class="btn btn-secondary" style="font-size:12px">Sair</button>
        </form>
      <?php else: ?>
        <form method="POST">
          <input type="hidden" name="action" value="join">
          <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
          <button type="submit" class="btn btn-primary" style="font-size:12px">Entrar</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
