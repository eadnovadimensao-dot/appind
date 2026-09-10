<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
auth_check();

$db         = db();
$churchId   = CHURCH_ID;
$ministryId = (int)($_GET['ministry_id'] ?? 0);
$editId     = (int)($_GET['id'] ?? 0);
$errors     = [];

$stmt = $db->prepare("SELECT * FROM ministries WHERE id = ? AND church_id = ?");
$stmt->execute([$ministryId ?: $editId, $churchId]);

// Se editando, busca o item
$item = null;
if ($editId) {
    $si = $db->prepare("SELECT * FROM ministry_items WHERE id = ? AND church_id = ?");
    $si->execute([$editId, $churchId]);
    $item = $si->fetch();
    if (!$item) { header('Location: /pages/ministries/index.php'); exit; }
    $ministryId = $item['ministry_id'];
}

auth_require_ministry($ministryId);

$mn = $db->prepare("SELECT * FROM ministries WHERE id = ? AND church_id = ?");
$mn->execute([$ministryId, $churchId]);
$mn = $mn->fetch();
if (!$mn) { header('Location: /pages/ministries/index.php'); exit; }

$pageTitle = ($editId ? 'Editar item' : 'Novo item') . ' · ' . $mn['name'];

$categories = ['roupa'=>'Roupa','instrumento'=>'Instrumento',
               'equipamento'=>'Equipamento','acessório'=>'Acessório','outro'=>'Outro'];
$conditions = ['good'=>'Bom estado','fair'=>'Estado regular','poor'=>'Precisa de reparo'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name      = trim($_POST['name']             ?? '');
    $category  = trim($_POST['category']         ?? '');
    $desc      = trim($_POST['description']      ?? '');
    $quantity  = max(1, (int)($_POST['quantity'] ?? 1));
    $condition = trim($_POST['condition_status'] ?? 'good');

    if ($name === '') $errors[] = 'Nome do item é obrigatório.';

    if (empty($errors)) {
        if ($editId) {
            $db->prepare("UPDATE ministry_items SET name=?,category=?,description=?,quantity=?,condition_status=? WHERE id=? AND church_id=?")
               ->execute([$name,$category?:null,$desc?:null,$quantity,$condition,$editId,$churchId]);
        } else {
            $db->prepare("INSERT INTO ministry_items (ministry_id,church_id,name,category,description,quantity,condition_status) VALUES (?,?,?,?,?,?,?)")
               ->execute([$ministryId,$churchId,$name,$category?:null,$desc?:null,$quantity,$condition]);
        }
        header('Location: /pages/ministries/items.php?ministry_id='.$ministryId.'&saved=1');
        exit;
    }
    if ($item) $item = array_merge($item, $_POST);
}

$pageTitle = ($editId ? 'Editar item' : 'Novo item') . ' · ' . $mn['name'];
$activePage = 'ministries';
require_once __DIR__ . '/../../includes/layout.php';

$d = $item ?? $_POST;
?>

<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<div style="margin-bottom:16px">
  <a href="/pages/ministries/items.php?ministry_id=<?= $ministryId ?>" style="font-size:13px;color:var(--text-muted);text-decoration:none">
    ← Pertences · <?= htmlspecialchars($mn['name']) ?>
  </a>
</div>

<form method="POST" style="width:100%;max-width:680px">
  <div class="card" style="margin-bottom:16px">
    <p class="card-title"><?= $editId ? 'Editar item' : 'Cadastrar novo item' ?></p>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Nome do item *</label>
        <input type="text" name="name" class="form-control"
               placeholder="Ex: Roupa de dança azul tam. M, Violão Giannini…"
               value="<?= htmlspecialchars($d['name'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Categoria</label>
        <select name="category" class="form-control">
          <option value="">Selecione</option>
          <?php foreach ($categories as $k => $v): ?>
            <option value="<?= $k ?>" <?= ($d['category']??'')===$k?'selected':''?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Estado de conservação</label>
        <select name="condition_status" class="form-control">
          <?php foreach ($conditions as $k => $v): ?>
            <option value="<?= $k ?>" <?= ($d['condition_status']??'good')===$k?'selected':''?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Quantidade</label>
        <input type="number" name="quantity" class="form-control" min="1"
               value="<?= (int)($d['quantity'] ?? 1) ?>">
      </div>
    </div>

    <div class="form-group" style="margin-bottom:0">
      <label class="form-label">Descrição <span style="font-weight:400;color:var(--text-muted)">(tamanho, cor, detalhes…)</span></label>
      <textarea name="description" class="form-control" rows="2"
                placeholder="Detalhes que ajudem a identificar o item…"><?= htmlspecialchars($d['description'] ?? '') ?></textarea>
    </div>
  </div>

  <div style="display:flex;gap:10px">
    <button type="submit" class="btn btn-primary">Salvar item</button>
    <a href="/pages/ministries/items.php?ministry_id=<?= $ministryId ?>" class="btn btn-secondary">Cancelar</a>
  </div>
</form>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
