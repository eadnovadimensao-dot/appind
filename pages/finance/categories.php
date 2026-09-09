<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require('manage_finance');

$db       = db();
$churchId = current_church_id();
$errors   = [];

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $editId      = (int)($_POST['edit_id'] ?? 0);
        $name        = trim($_POST['name']  ?? '');
        $type        = trim($_POST['type']  ?? '');
        $color       = trim($_POST['color'] ?? '#1D9E75');

        if ($name === '') $errors[] = 'Nome é obrigatório.';
        if (!in_array($type, ['income','expense'])) $errors[] = 'Tipo inválido.';

        if (empty($errors)) {
            if ($editId) {
                $db->prepare("UPDATE finance_categories SET name=?,type=?,color=? WHERE id=? AND church_id=?")
                   ->execute([$name,$type,$color,$editId,$churchId]);
            } else {
                $db->prepare("INSERT INTO finance_categories (church_id,name,type,color) VALUES (?,?,?,?)")
                   ->execute([$churchId,$name,$type,$color]);
            }
            header('Location: /pages/finance/categories.php?saved=1');
            exit;
        }
    }

    if ($action === 'toggle') {
        $id     = (int)($_POST['cat_id'] ?? 0);
        $active = (int)($_POST['active'] ?? 0);
        $db->prepare("UPDATE finance_categories SET active=? WHERE id=? AND church_id=?")
           ->execute([$active ? 0 : 1, $id, $churchId]);
        header('Location: /pages/finance/categories.php');
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['cat_id'] ?? 0);
        // Só exclui se não houver lançamentos vinculados
        $count = $db->prepare("SELECT COUNT(*) FROM finance_entries WHERE category_id=?");
        $count->execute([$id]);
        if ($count->fetchColumn() > 0) {
            $errors[] = 'Não é possível excluir uma categoria com lançamentos. Desative-a em vez de excluir.';
        } else {
            $db->prepare("DELETE FROM finance_categories WHERE id=? AND church_id=?")
               ->execute([$id, $churchId]);
            header('Location: /pages/finance/categories.php?deleted=1');
            exit;
        }
    }
}

$categories = $db->query("
    SELECT fc.*, COUNT(fe.id) AS entry_count
    FROM finance_categories fc
    LEFT JOIN finance_entries fe ON fe.category_id = fc.id
    WHERE fc.church_id = $churchId
    GROUP BY fc.id
    ORDER BY fc.type ASC, fc.name ASC
")->fetchAll();

$editCategory = null;
if (isset($_GET['edit'])) {
    foreach ($categories as $c) {
        if ($c['id'] == $_GET['edit']) { $editCategory = $c; break; }
    }
}

$incomeCategories  = array_filter($categories, fn($c) => $c['type'] === 'income');
$expenseCategories = array_filter($categories, fn($c) => $c['type'] === 'expense');

$pageTitle  = 'Categorias Financeiras';
$activePage = 'finance';
require_once __DIR__ . '/../../includes/layout.php';

$colors = [
    '#1D9E75','#185FA5','#6B21A8','#854F0B','#B45309',
    '#A32D2D','#C2410C','#7C3AED','#5F5E5A','#1D4ED8',
    '#0F766E','#B91C1C','#92400E','#166534','#1E40AF',
];
?>

<?php if (isset($_GET['saved'])): ?>
  <div class="flash" style="background:#E1F5EE;border:1px solid var(--accent-border);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#0F6E56">✓ Categoria salva com sucesso.</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:320px 1fr;gap:16px;align-items:start">

  <!-- Formulário -->
  <div class="card">
    <p class="card-title"><?= $editCategory ? 'Editar categoria' : 'Nova categoria' ?></p>
    <form method="POST">
      <input type="hidden" name="action" value="save">
      <?php if ($editCategory): ?>
        <input type="hidden" name="edit_id" value="<?= $editCategory['id'] ?>">
      <?php endif; ?>

      <div class="form-group">
        <label class="form-label">Nome *</label>
        <input type="text" name="name" class="form-control"
               placeholder="Ex: Fundo Missionário, Aluguel…"
               value="<?= htmlspecialchars($editCategory['name'] ?? $_POST['name'] ?? '') ?>" required>
      </div>

      <div class="form-group">
        <label class="form-label">Tipo *</label>
        <div style="display:flex;gap:8px">
          <label style="display:flex;align-items:center;gap:6px;padding:8px 14px;border:1.5px solid var(--border);border-radius:7px;cursor:pointer;font-size:13px;flex:1;justify-content:center" id="lbl-income">
            <input type="radio" name="type" value="income"
                   <?= ($editCategory['type'] ?? $_POST['type'] ?? 'income')==='income'?'checked':'' ?>
                   onchange="setType('income')">
            <span style="color:var(--green);font-weight:500">⬆ Entrada</span>
          </label>
          <label style="display:flex;align-items:center;gap:6px;padding:8px 14px;border:1.5px solid var(--border);border-radius:7px;cursor:pointer;font-size:13px;flex:1;justify-content:center" id="lbl-expense">
            <input type="radio" name="type" value="expense"
                   <?= ($editCategory['type'] ?? $_POST['type'] ?? '')==='expense'?'checked':'' ?>
                   onchange="setType('expense')">
            <span style="color:var(--red);font-weight:500">⬇ Saída</span>
          </label>
        </div>
      </div>

      <div class="form-group" style="margin-bottom:20px">
        <label class="form-label">Cor</label>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:4px">
          <?php foreach ($colors as $hex): ?>
            <label style="cursor:pointer">
              <input type="radio" name="color" value="<?= $hex ?>"
                     <?= ($editCategory['color'] ?? $_POST['color'] ?? '#1D9E75')===$hex?'checked':'' ?>
                     style="display:none">
              <span style="display:block;width:28px;height:28px;border-radius:50%;background:<?= $hex ?>;border:3px solid <?= ($editCategory['color'] ?? $_POST['color'] ?? '#1D9E75')===$hex?'var(--text)':'transparent' ?>"
                    onclick="selectColor('<?= $hex ?>', this)"></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary">Salvar</button>
        <?php if ($editCategory): ?>
          <a href="/pages/finance/categories.php" class="btn btn-secondary">Cancelar</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- Lista de categorias -->
  <div style="display:flex;flex-direction:column;gap:16px">

    <!-- Entradas -->
    <div class="card" style="padding:0">
      <div style="padding:12px 18px;border-bottom:1px solid var(--border)">
        <p style="font-weight:500;font-size:14px">⬆ Entradas <span style="color:var(--text-muted);font-weight:400">(<?= count($incomeCategories) ?>)</span></p>
      </div>
      <?php foreach ($incomeCategories as $cat): ?>
        <div style="display:flex;align-items:center;gap:10px;padding:11px 18px;border-bottom:1px solid var(--border)">
          <span style="width:12px;height:12px;border-radius:50%;background:<?= $cat['color'] ?>;flex-shrink:0"></span>
          <div style="flex:1">
            <span style="font-size:13px;font-weight:<?= $cat['active']?'500':'400' ?>;color:<?= $cat['active']?'var(--text)':'var(--text-muted)' ?>">
              <?= htmlspecialchars($cat['name']) ?>
            </span>
            <span style="font-size:11px;color:var(--text-muted);margin-left:6px"><?= $cat['entry_count'] ?> lançamento(s)</span>
          </div>
          <?php if (!$cat['active']): ?>
            <span class="badge badge-gray">Inativa</span>
          <?php endif; ?>
          <div style="display:flex;gap:6px">
            <a href="?edit=<?= $cat['id'] ?>" class="btn btn-secondary" style="font-size:11px;padding:4px 10px">Editar</a>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="cat_id" value="<?= $cat['id'] ?>">
              <input type="hidden" name="active" value="<?= $cat['active'] ?>">
              <button type="submit" class="btn btn-secondary" style="font-size:11px;padding:4px 10px">
                <?= $cat['active'] ? 'Desativar' : 'Ativar' ?>
              </button>
            </form>
            <?php if ($cat['entry_count'] == 0): ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="cat_id" value="<?= $cat['id'] ?>">
                <button type="submit" class="btn btn-secondary" style="font-size:11px;padding:4px 10px;color:var(--red)"
                        onclick="return confirm('Excluir categoria?')">Excluir</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Saídas -->
    <div class="card" style="padding:0">
      <div style="padding:12px 18px;border-bottom:1px solid var(--border)">
        <p style="font-weight:500;font-size:14px">⬇ Saídas <span style="color:var(--text-muted);font-weight:400">(<?= count($expenseCategories) ?>)</span></p>
      </div>
      <?php foreach ($expenseCategories as $cat): ?>
        <div style="display:flex;align-items:center;gap:10px;padding:11px 18px;border-bottom:1px solid var(--border)">
          <span style="width:12px;height:12px;border-radius:50%;background:<?= $cat['color'] ?>;flex-shrink:0"></span>
          <div style="flex:1">
            <span style="font-size:13px;font-weight:<?= $cat['active']?'500':'400' ?>;color:<?= $cat['active']?'var(--text)':'var(--text-muted)' ?>">
              <?= htmlspecialchars($cat['name']) ?>
            </span>
            <span style="font-size:11px;color:var(--text-muted);margin-left:6px"><?= $cat['entry_count'] ?> lançamento(s)</span>
          </div>
          <?php if (!$cat['active']): ?>
            <span class="badge badge-gray">Inativa</span>
          <?php endif; ?>
          <div style="display:flex;gap:6px">
            <a href="?edit=<?= $cat['id'] ?>" class="btn btn-secondary" style="font-size:11px;padding:4px 10px">Editar</a>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="cat_id" value="<?= $cat['id'] ?>">
              <input type="hidden" name="active" value="<?= $cat['active'] ?>">
              <button type="submit" class="btn btn-secondary" style="font-size:11px;padding:4px 10px">
                <?= $cat['active'] ? 'Desativar' : 'Ativar' ?>
              </button>
            </form>
            <?php if ($cat['entry_count'] == 0): ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="cat_id" value="<?= $cat['id'] ?>">
                <button type="submit" class="btn btn-secondary" style="font-size:11px;padding:4px 10px;color:var(--red)"
                        onclick="return confirm('Excluir categoria?')">Excluir</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

  </div>
</div>

<?php
$extraJs = <<<JS
function setType(type) {
  const li = document.getElementById('lbl-income');
  const le = document.getElementById('lbl-expense');
  li.style.borderColor = type==='income' ? 'var(--accent)' : 'var(--border)';
  le.style.borderColor = type==='expense'? 'var(--red)'    : 'var(--border)';
}
function selectColor(hex, el) {
  document.querySelectorAll('[name=color]').forEach(r => {
    r.nextElementSibling.style.border = '3px solid transparent';
  });
  el.style.border = '3px solid var(--text)';
  document.querySelector('[name=color][value="'+hex+'"]').checked = true;
}
// Inicializar tipo selecionado
const checkedType = document.querySelector('[name=type]:checked');
if (checkedType) setType(checkedType.value);
JS;
require_once __DIR__ . '/../../includes/layout-footer.php';
?>
