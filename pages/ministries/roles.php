<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/music_roles.php';
auth_check();

$db         = db();
$ministryId = (int)($_GET['ministry_id'] ?? 0);
auth_require_ministry($ministryId);

$stmt = $db->prepare("
    SELECT mn.*, ch.id AS branch_church_id
    FROM ministries mn
    JOIN churches ch ON ch.id = mn.church_id
    WHERE mn.id = ? AND ch.id = ?
");
$stmt->execute([$ministryId, current_church_id()]);
$mn = $stmt->fetch();
if (!$mn) { header('Location: /pages/ministries/index.php'); exit; }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name   = trim($_POST['name'] ?? '');
        $qty    = max(0, (int)($_POST['quantity_needed'] ?? 1));
        $always = isset($_POST['always_include']) ? 1 : 0;
        $naipe  = isset($_POST['use_naipe']) ? 1 : 0;
        if ($name === '') {
            $errors[] = 'Nome da função é obrigatório.';
        } else {
            $maxPos = $db->prepare("SELECT COALESCE(MAX(position),-1) FROM ministry_roles WHERE ministry_id = ?");
            $maxPos->execute([$ministryId]);
            $db->prepare("INSERT INTO ministry_roles (ministry_id, name, quantity_needed, always_include, use_naipe, position) VALUES (?,?,?,?,?,?)")
               ->execute([$ministryId, $name, $qty, $always, $naipe, $maxPos->fetchColumn() + 1]);
        }
    } elseif ($action === 'update') {
        $id     = (int)($_POST['id'] ?? 0);
        $name   = trim($_POST['name'] ?? '');
        $qty    = max(0, (int)($_POST['quantity_needed'] ?? 1));
        $always = isset($_POST['always_include']) ? 1 : 0;
        $naipe  = isset($_POST['use_naipe']) ? 1 : 0;
        if ($name !== '') {
            $db->prepare("UPDATE ministry_roles SET name=?, quantity_needed=?, always_include=?, use_naipe=? WHERE id=? AND ministry_id=?")
               ->execute([$name, $qty, $always, $naipe, $id, $ministryId]);
        }
    }
    header('Location: /pages/ministries/roles.php?ministry_id=' . $ministryId);
    exit;
}

if (isset($_GET['delete'])) {
    $db->prepare("DELETE FROM ministry_roles WHERE id = ? AND ministry_id = ?")->execute([(int)$_GET['delete'], $ministryId]);
    header('Location: /pages/ministries/roles.php?ministry_id=' . $ministryId);
    exit;
}

if (isset($_GET['move']) && in_array($_GET['dir'] ?? '', ['up','down'])) {
    $roles = $db->prepare("SELECT id, position FROM ministry_roles WHERE ministry_id = ? ORDER BY position");
    $roles->execute([$ministryId]);
    $roles = $roles->fetchAll();
    $idx = array_search((int)$_GET['move'], array_column($roles, 'id'));
    if ($idx !== false) {
        $swapWith = $_GET['dir'] === 'up' ? $idx - 1 : $idx + 1;
        if (isset($roles[$swapWith])) {
            $db->prepare("UPDATE ministry_roles SET position = ? WHERE id = ?")->execute([$roles[$swapWith]['position'], $roles[$idx]['id']]);
            $db->prepare("UPDATE ministry_roles SET position = ? WHERE id = ?")->execute([$roles[$idx]['position'], $roles[$swapWith]['id']]);
        }
    }
    header('Location: /pages/ministries/roles.php?ministry_id=' . $ministryId);
    exit;
}

$roles = get_ministry_roles($db, $ministryId);

$pageTitle  = 'Funções da escala · ' . $mn['name'];
$activePage = 'ministries';
require_once __DIR__ . '/../../includes/layout.php';
?>

<div style="margin-bottom:16px">
  <a href="/pages/ministries/view.php?id=<?= $ministryId ?>" style="font-size:13px;color:var(--text-muted);text-decoration:none">← <?= htmlspecialchars($mn['name']) ?></a>
</div>

<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px;background:#F5F5F5;border:none">
  <p style="font-size:13px;color:var(--text);line-height:1.7">
    🎲 Defina as funções que a escala automática desse ministério precisa preencher a cada culto/ensaio
    (ex: 1 Ministro de Louvor, 3 Backing Vocal, 1 Guitarrista…). O rodízio prioriza quem não serviu na
    última escala do mesmo tipo. <strong>Sempre inclui</strong> escala todo mundo com essa função, sem
    rodízio nem limite de vaga (ex: pastor da base). <strong>Usa naipe</strong> tenta mesclar vocais de
    naipes diferentes (Soprano/Contralto/Tenor) em vez de sortear solto.
  </p>
</div>

<div class="card" style="padding:0;margin-bottom:16px">
  <?php foreach ($roles as $i => $r): ?>
    <div style="display:flex;align-items:center;gap:12px;padding:12px 18px;border-bottom:1px solid var(--border)">
      <div style="display:flex;flex-direction:column;gap:2px">
        <a href="?ministry_id=<?= $ministryId ?>&move=<?= $r['id'] ?>&dir=up" style="color:<?= $i===0?'var(--border)':'var(--text-muted)' ?>;text-decoration:none;font-size:12px">▲</a>
        <a href="?ministry_id=<?= $ministryId ?>&move=<?= $r['id'] ?>&dir=down" style="color:<?= $i===count($roles)-1?'var(--border)':'var(--text-muted)' ?>;text-decoration:none;font-size:12px">▼</a>
      </div>
      <div style="flex:1;min-width:0">
        <div style="font-size:14px;font-weight:500"><?= htmlspecialchars($r['name']) ?></div>
        <div style="font-size:12px;color:var(--text-muted)">
          <?= $r['always_include'] ? 'Sempre inclui todo mundo' : $r['quantity_needed'] . ' vaga(s) por rodízio' ?>
          <?= $r['use_naipe'] ? ' · usa naipe' : '' ?>
        </div>
      </div>
      <button type="button" onclick="toggleEdit(<?= $r['id'] ?>)" class="btn btn-secondary" style="font-size:12px;flex-shrink:0">Editar</button>
      <a href="?ministry_id=<?= $ministryId ?>&delete=<?= $r['id'] ?>" class="btn btn-secondary" style="font-size:12px;flex-shrink:0;color:var(--red)"
         data-confirm="Excluir a função '<?= htmlspecialchars($r['name']) ?>'?">Excluir</a>
    </div>
    <form method="POST" id="edit-<?= $r['id'] ?>" style="display:none;padding:12px 18px;border-bottom:1px solid var(--border);background:var(--content-bg)">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" value="<?= $r['id'] ?>">
      <div style="display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap;align-items:center">
        <input type="text" name="name" value="<?= htmlspecialchars($r['name']) ?>" class="form-control" style="flex:1;min-width:180px" placeholder="Nome da função" required>
        <input type="number" name="quantity_needed" value="<?= $r['quantity_needed'] ?>" min="0" class="form-control" style="width:80px" title="Vagas por rodízio">
      </div>
      <div style="display:flex;gap:16px;margin-bottom:8px">
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
          <input type="checkbox" name="always_include" value="1" <?= $r['always_include']?'checked':'' ?>> Sempre inclui
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
          <input type="checkbox" name="use_naipe" value="1" <?= $r['use_naipe']?'checked':'' ?>> Usa naipe
        </label>
      </div>
      <button type="submit" class="btn btn-primary" style="font-size:12px">Salvar</button>
    </form>
  <?php endforeach; ?>
  <?php if (empty($roles)): ?>
    <div class="empty-state" style="padding:24px">Nenhuma função configurada ainda — a escala automática não vai funcionar até adicionar pelo menos uma.</div>
  <?php endif; ?>
</div>

<!-- Nova função -->
<div class="card">
  <p class="card-title">+ Adicionar função</p>
  <form method="POST" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <input type="hidden" name="action" value="create">
    <input type="text" name="name" class="form-control" style="flex:1;min-width:200px" placeholder="Nome da função" required>
    <input type="number" name="quantity_needed" value="1" min="0" class="form-control" style="width:80px" title="Vagas por rodízio">
    <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
      <input type="checkbox" name="always_include" value="1"> Sempre inclui
    </label>
    <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
      <input type="checkbox" name="use_naipe" value="1"> Usa naipe
    </label>
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
