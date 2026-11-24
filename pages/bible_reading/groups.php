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
    } elseif ($action === 'add_member') {
        $groupId       = (int)($_POST['group_id'] ?? 0);
        $newMemberId   = (int)($_POST['new_member_id'] ?? 0);
        $isCreator = $db->prepare("SELECT 1 FROM bible_reading_groups WHERE id = ? AND church_id = ? AND created_by = ?");
        $isCreator->execute([$groupId, $churchId, $memberId]);
        if ($newMemberId && $isCreator->fetchColumn()) {
            $db->prepare("UPDATE members SET bible_reading_group_id = ? WHERE id = ? AND church_id = ?")
               ->execute([$groupId, $newMemberId, $churchId]);
        }
    } elseif ($action === 'delete_group') {
        $groupId   = (int)($_POST['group_id'] ?? 0);
        $isCreator = $db->prepare("SELECT 1 FROM bible_reading_groups WHERE id = ? AND church_id = ? AND created_by = ?");
        $isCreator->execute([$groupId, $churchId, $memberId]);
        if ($isCreator->fetchColumn()) {
            $db->prepare("UPDATE members SET bible_reading_group_id = NULL WHERE bible_reading_group_id = ?")->execute([$groupId]);
            $db->prepare("DELETE FROM bible_reading_groups WHERE id = ?")->execute([$groupId]);
        }
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
    SELECT g.id, g.name, g.created_at, g.created_by,
           (SELECT COUNT(*) FROM members m WHERE m.bible_reading_group_id = g.id AND m.status = 'active') AS member_count
    FROM bible_reading_groups g
    WHERE g.church_id = ?
    ORDER BY member_count DESC, g.created_at DESC
");
$groups->execute([$churchId]);
$groups = $groups->fetchAll();

$groupMembers = [];
if ($myGroupId) {
    $gm = $db->prepare("SELECT id, name FROM members WHERE bible_reading_group_id = ? AND status = 'active' ORDER BY name");
    $gm->execute([$myGroupId]);
    $groupMembers = $gm->fetchAll();
}

$availableToAdd = [];
if ($myGroupId) {
    $avail = $db->prepare("SELECT id, name FROM members WHERE church_id = ? AND status = 'active' AND (bible_reading_group_id IS NULL OR bible_reading_group_id != ?) ORDER BY name");
    $avail->execute([$churchId, $myGroupId]);
    $availableToAdd = $avail->fetchAll();
}

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
  <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px">Qualquer pessoa pode criar um grupo, sem precisar ser da mesma célula. Quem cria pode adicionar outras pessoas direto (não precisa esperar cada uma entrar por conta própria).</p>
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
      $isMine      = $myGroupId && (int)$g['id'] === (int)$myGroupId;
      $isCreator   = (int)$g['created_by'] === $memberId;
  ?>
    <div style="padding:12px;border:1px solid var(--border);border-radius:8px;margin-bottom:8px;<?= $isMine ? 'background:var(--accent-lt)' : '' ?>">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px">
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

      <?php if ($isMine): ?>
        <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border)">
          <?php if ($groupMembers): ?>
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px">
              <?= implode(', ', array_map(fn($m) => htmlspecialchars($m['name']), $groupMembers)) ?>
            </div>
          <?php endif; ?>

          <?php if ($isCreator): ?>
            <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
              <input type="hidden" name="action" value="add_member">
              <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
              <div style="flex:1;min-width:180px">
                <select name="new_member_id" class="form-control" style="font-size:12px">
                  <option value="">+ Adicionar pessoa…</option>
                  <?php foreach ($availableToAdd as $av): ?>
                    <option value="<?= $av['id'] ?>"><?= htmlspecialchars($av['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button type="submit" class="btn btn-secondary" style="font-size:12px">Adicionar</button>
            </form>
            <form method="POST" onsubmit="return confirm('Excluir este grupo? Todo mundo vai sair dele.')" style="margin-top:8px">
              <input type="hidden" name="action" value="delete_group">
              <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
              <button type="submit" class="btn btn-secondary" style="font-size:11px;color:#A32D2D">🗑 Excluir grupo</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
