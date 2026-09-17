<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
auth_require('approve_events');
$db       = db();
$churchId = current_church_id();
$errors   = [];
$success  = false;

// Salvar novo local
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['name']        ?? '');
    $description = trim($_POST['description'] ?? '');
    $capacity    = (int)($_POST['capacity']   ?? 0) ?: null;
    $active      = isset($_POST['active']) ? 1 : 0;
    $editId      = (int)($_POST['edit_id']    ?? 0);

    if ($name === '') $errors[] = 'Nome é obrigatório.';

    if (empty($errors)) {
        if ($editId) {
            $db->prepare("UPDATE locations SET name=?,description=?,capacity=?,active=? WHERE id=? AND church_id=?")
               ->execute([$name, $description?:null, $capacity, $active, $editId, $churchId]);
        } else {
            $db->prepare("INSERT INTO locations (church_id,name,description,capacity,active) VALUES (?,?,?,?,?)")
               ->execute([$churchId, $name, $description?:null, $capacity, $active]);
        }
        header('Location: /pages/events/locations.php?saved=1');
        exit;


    }
}


$pageTitle    = 'Locais';
$activePage   = 'events';
$topbarAction = ['href' => '/pages/events/locations.php?new=1', 'label' => 'Novo local'];
require_once __DIR__ . '/../../includes/layout.php';
$locations = $db->query("SELECT * FROM locations WHERE church_id = $churchId ORDER BY name")->fetchAll();
$showForm  = isset($_GET['new']) || isset($_GET['edit']);
$editLocation = null;
if (isset($_GET['edit'])) {
    foreach ($locations as $l) {
        if ($l['id'] == $_GET['edit']) { $editLocation = $l; break; }
    }
}
?>

<?php if (isset($_GET['saved'])): ?>
  <div class="flash" style="background:#E1F5EE;border:1px solid var(--accent-border);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#0F6E56">
    ✓ Local salvo com sucesso.
  </div>
<?php endif; ?>
<?php if (($_GET['error'] ?? '') === 'inuse'): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#A32D2D">
    Não é possível excluir: esse local ainda tem evento(s) vinculado(s) na agenda. Marque como "Inativo" em vez de excluir, ou cancele os eventos primeiro.
  </div>
<?php endif; ?>

<?php if ($showForm): ?>
<div class="card" style="margin-bottom:20px">
  <p class="card-title"><?= $editLocation ? 'Editar local' : 'Novo local' ?></p>
  <?php if (!empty($errors)): ?>
    <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#A32D2D">
      <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>
  <form method="POST" style="width:100%">
    <?php if ($editLocation): ?>
      <input type="hidden" name="edit_id" value="<?= $editLocation['id'] ?>">
    <?php endif; ?>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Nome *</label>
        <input type="text" name="name" class="form-control"
               placeholder="Ex: Nave da Igreja, Sala 1, Cozinha…"
               value="<?= htmlspecialchars($editLocation['name'] ?? $_POST['name'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Capacidade (pessoas)</label>
        <input type="number" name="capacity" class="form-control" min="0"
               value="<?= htmlspecialchars($editLocation['capacity'] ?? $_POST['capacity'] ?? '') ?>">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Descrição</label>
      <input type="text" name="description" class="form-control"
             placeholder="Breve descrição do espaço…"
             value="<?= htmlspecialchars($editLocation['description'] ?? $_POST['description'] ?? '') ?>">
    </div>
    <div class="form-group" style="margin-bottom:16px">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
        <input type="checkbox" name="active" value="1"
               <?= ($editLocation['active'] ?? 1) ? 'checked' : '' ?>>
        Local ativo
      </label>
    </div>
    <div style="display:flex;gap:10px">
      <button type="submit" class="btn btn-primary">Salvar</button>
      <a href="/pages/events/locations.php" class="btn btn-secondary">Cancelar</a>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- Lista de locais -->
<div class="card" style="padding:0">
  <div style="padding:16px 20px;border-bottom:1px solid var(--border)">
    <span style="font-size:13px;color:var(--text-muted)"><?= count($locations) ?> local(is) cadastrado(s)</span>
  </div>
  <?php if (empty($locations)): ?>
    <div class="empty-state">Nenhum local cadastrado.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Nome</th><th>Descrição</th><th>Capacidade</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($locations as $loc): ?>
            <tr>
              <td style="font-weight:500"><?= htmlspecialchars($loc['name']) ?></td>
              <td style="color:var(--text-muted)"><?= htmlspecialchars($loc['description'] ?? '—') ?></td>
              <td><?= $loc['capacity'] ? $loc['capacity'] . ' pessoas' : '—' ?></td>
              <td><span class="badge <?= $loc['active'] ? 'badge-green' : 'badge-gray' ?>"><?= $loc['active'] ? 'Ativo' : 'Inativo' ?></span></td>
              <td style="text-align:right">
                <a href="?edit=<?= $loc['id'] ?>" class="btn btn-secondary" style="font-size:12px;padding:5px 12px">Editar</a>
                <a href="/pages/events/location_delete.php?id=<?= $loc['id'] ?>"
                   class="btn btn-secondary" style="font-size:12px;padding:5px 12px;color:var(--red)"
                   data-confirm="Excluir este local?">Excluir</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
