<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require('manage_members');

$db       = db();
$churchId = current_church_id();

// Lazy-seed: filial nova sem etapas ainda ganha o conjunto padrão
$count = $db->prepare("SELECT COUNT(*) FROM growth_steps WHERE church_id = ?");
$count->execute([$churchId]);
if ($count->fetchColumn() == 0) {
    $defaults = [
        ['🙋','Visitante / Primeira vez','Já frequentou pelo menos um culto ou evento'],
        ['❤️','Decisão de Fé','Decidiu seguir Jesus'],
        ['💧','Batismo nas Águas',null],
        ['📖','Curso de Novos Convertidos',null],
        ['🔗','Integrado em uma Célula',null],
        ['⭐','Serve em um Ministério',null],
    ];
    $ins = $db->prepare("INSERT INTO growth_steps (church_id, icon, name, description, position) VALUES (?,?,?,?,?)");
    foreach ($defaults as $i => [$icon, $name, $desc]) $ins->execute([$churchId, $icon, $name, $desc, $i]);
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? '') ?: '⭐';
        $desc = trim($_POST['description'] ?? '') ?: null;
        if ($name === '') {
            $errors[] = 'Nome da etapa é obrigatório.';
        } else {
            $maxPos = $db->prepare("SELECT COALESCE(MAX(position),-1) FROM growth_steps WHERE church_id = ?");
            $maxPos->execute([$churchId]);
            $db->prepare("INSERT INTO growth_steps (church_id, icon, name, description, position) VALUES (?,?,?,?,?)")
               ->execute([$churchId, $icon, $name, $desc, $maxPos->fetchColumn() + 1]);
        }
    } elseif ($action === 'update') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? '') ?: '⭐';
        $desc = trim($_POST['description'] ?? '') ?: null;
        if ($name !== '') {
            $db->prepare("UPDATE growth_steps SET name=?, icon=?, description=? WHERE id=? AND church_id=?")
               ->execute([$name, $icon, $desc, $id, $churchId]);
        }
    }
    header('Location: /pages/members/growth_steps.php');
    exit;
}

if (isset($_GET['delete'])) {
    $db->prepare("DELETE FROM growth_steps WHERE id = ? AND church_id = ?")->execute([(int)$_GET['delete'], $churchId]);
    header('Location: /pages/members/growth_steps.php');
    exit;
}

if (isset($_GET['move']) && in_array($_GET['dir'] ?? '', ['up','down'])) {
    $steps = $db->prepare("SELECT id, position FROM growth_steps WHERE church_id = ? ORDER BY position");
    $steps->execute([$churchId]);
    $steps = $steps->fetchAll();
    $idx = array_search((int)$_GET['move'], array_column($steps, 'id'));
    if ($idx !== false) {
        $swapWith = $_GET['dir'] === 'up' ? $idx - 1 : $idx + 1;
        if (isset($steps[$swapWith])) {
            $db->prepare("UPDATE growth_steps SET position = ? WHERE id = ?")->execute([$steps[$swapWith]['position'], $steps[$idx]['id']]);
            $db->prepare("UPDATE growth_steps SET position = ? WHERE id = ?")->execute([$steps[$idx]['position'], $steps[$swapWith]['id']]);
        }
    }
    header('Location: /pages/members/growth_steps.php');
    exit;
}

$steps = $db->prepare("
    SELECT gs.*, (SELECT COUNT(*) FROM member_growth_progress mgp WHERE mgp.step_id = gs.id) AS members_done
    FROM growth_steps gs WHERE gs.church_id = ? ORDER BY gs.position
");
$steps->execute([$churchId]);
$steps = $steps->fetchAll();

$pageTitle  = 'Etapas da trilha de crescimento';
$activePage = 'members';
require_once __DIR__ . '/../../includes/layout.php';
?>

<div style="margin-bottom:16px">
  <a href="/pages/members/index.php" style="font-size:13px;color:var(--text-muted);text-decoration:none">← Membros</a>
</div>

<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px;background:#F5F5F5;border:none">
  <p style="font-size:13px;color:var(--text);line-height:1.7">
    🌱 Defina as etapas da jornada espiritual usada no perfil de cada membro.
    A ordem aqui é a ordem mostrada na trilha.
  </p>
</div>

<div class="card" style="padding:0;margin-bottom:16px">
  <?php foreach ($steps as $i => $s): ?>
    <div style="display:flex;align-items:center;gap:12px;padding:12px 18px;border-bottom:1px solid var(--border)">
      <div style="display:flex;flex-direction:column;gap:2px">
        <a href="?move=<?= $s['id'] ?>&dir=up" style="color:<?= $i===0?'var(--border)':'var(--text-muted)' ?>;text-decoration:none;font-size:12px">▲</a>
        <a href="?move=<?= $s['id'] ?>&dir=down" style="color:<?= $i===count($steps)-1?'var(--border)':'var(--text-muted)' ?>;text-decoration:none;font-size:12px">▼</a>
      </div>
      <div style="font-size:20px;flex-shrink:0"><?= htmlspecialchars($s['icon']) ?></div>
      <div style="flex:1;min-width:0">
        <div style="font-size:14px;font-weight:500"><?= htmlspecialchars($s['name']) ?></div>
        <?php if ($s['description']): ?>
          <div style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($s['description']) ?></div>
        <?php endif; ?>
      </div>
      <span style="font-size:12px;color:var(--text-muted);flex-shrink:0"><?= $s['members_done'] ?> concluído(s)</span>
      <button type="button" onclick="toggleEdit(<?= $s['id'] ?>)" class="btn btn-secondary" style="font-size:12px;flex-shrink:0">Editar</button>
      <a href="?delete=<?= $s['id'] ?>" class="btn btn-secondary" style="font-size:12px;flex-shrink:0;color:var(--red)"
         data-confirm="Excluir a etapa '<?= htmlspecialchars($s['name']) ?>'? Isso apaga o progresso de todos os membros nela.">Excluir</a>
    </div>
    <form method="POST" id="edit-<?= $s['id'] ?>" style="display:none;padding:12px 18px;border-bottom:1px solid var(--border);background:var(--content-bg)">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" value="<?= $s['id'] ?>">
      <div style="display:flex;gap:8px;margin-bottom:8px">
        <input type="text" name="icon" value="<?= htmlspecialchars($s['icon']) ?>" class="form-control" style="width:60px;text-align:center" maxlength="4">
        <input type="text" name="name" value="<?= htmlspecialchars($s['name']) ?>" class="form-control" style="flex:1" placeholder="Nome da etapa" required>
      </div>
      <input type="text" name="description" value="<?= htmlspecialchars($s['description'] ?? '') ?>" class="form-control" style="margin-bottom:8px" placeholder="Descrição (opcional)">
      <button type="submit" class="btn btn-primary" style="font-size:12px">Salvar</button>
    </form>
  <?php endforeach; ?>
  <?php if (empty($steps)): ?>
    <div class="empty-state" style="padding:24px">Nenhuma etapa cadastrada ainda.</div>
  <?php endif; ?>
</div>

<!-- Nova etapa -->
<div class="card">
  <p class="card-title">+ Adicionar etapa</p>
  <form method="POST" style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap">
    <input type="hidden" name="action" value="create">
    <input type="text" name="icon" value="⭐" class="form-control" style="width:60px;text-align:center" maxlength="4">
    <input type="text" name="name" class="form-control" style="flex:1;min-width:200px" placeholder="Nome da etapa" required>
    <input type="text" name="description" class="form-control" style="flex:1;min-width:200px" placeholder="Descrição (opcional)">
    <button type="submit" class="btn btn-primary">Adicionar</button>
  </form>
</div>

<?php
$extraJs = <<<JS
function toggleEdit(id) {
  const f = document.getElementById('edit-' + id);
  f.style.display = f.style.display === 'none' ? 'block' : 'none';
}
JS;
require_once __DIR__ . '/../../includes/layout-footer.php';
?>
