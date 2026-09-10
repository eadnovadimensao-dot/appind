<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require('manage_finance');

$db       = db();
$churchId = current_church_id();
$errors   = [];

$categories = $db->query("SELECT * FROM finance_categories WHERE church_id=$churchId AND active=1 ORDER BY type, name")->fetchAll();
$membersStmt = $db->prepare("
    SELECT m.id, m.name, ch.name AS branch_name
    FROM members m
    JOIN churches ch ON ch.id = m.church_id
    WHERE (ch.id = ? OR ch.parent_id = ?) AND m.status = 'active'
    ORDER BY m.name
");
$membersStmt->execute([SEDE_ID, SEDE_ID]);
$members = $membersStmt->fetchAll();

$paymentMethods = ['dinheiro'=>'Dinheiro','pix'=>'PIX','cartão'=>'Cartão','transferência'=>'Transferência','cheque'=>'Cheque'];

$titheCategory = null;
foreach ($categories as $c) {
    if (strtolower($c['name']) === 'dízimos') { $titheCategory = $c['id']; break; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type          = trim($_POST['type']           ?? '');
    $categoryId    = (int)($_POST['category_id']   ?? 0) ?: null;
    $description   = trim($_POST['description']    ?? '');
    $campaignName  = trim($_POST['campaign_name']  ?? '');
    $amount        = str_replace(['.',',' ], ['','.'], trim($_POST['amount'] ?? '0'));
    $date          = trim($_POST['entry_date']      ?? date('Y-m-d'));
    $memberId      = (int)($_POST['member_id']      ?? 0) ?: null;
    $paymentMethod = trim($_POST['payment_method']  ?? '');
    $referenceDoc  = trim($_POST['reference_doc']   ?? '');
    $createdBy     = auth_member_id();

    if (!in_array($type, ['income','expense'])) $errors[] = 'Tipo inválido.';
    if (!$categoryId) $errors[] = 'Selecione uma categoria.';
    if ((float)$amount <= 0) $errors[] = 'Valor deve ser maior que zero.';
    if ($date === '') $errors[] = 'Data é obrigatória.';

    if (empty($errors)) {
        $db->prepare("
            INSERT INTO finance_entries
              (church_id, type, category_id, category, description, campaign_name,
               amount, entry_date, member_id, payment_method, reference_doc, created_by)
            VALUES (?,?,?,(SELECT name FROM finance_categories WHERE id=?),?,?,?,?,?,?,?,?)
        ")->execute([$churchId,$type,$categoryId,$categoryId,$description?:null,
                     $campaignName?:null,(float)$amount,$date,$memberId,
                     $paymentMethod?:null,$referenceDoc?:null,$createdBy]);

        header('Location: /pages/finance/index.php?saved=1');
        exit;
    }
}

$incomeCategories  = array_filter($categories, fn($c) => $c['type']==='income');
$expenseCategories = array_filter($categories, fn($c) => $c['type']==='expense');
$selectedType = $_POST['type'] ?? 'income';
$selectedCat  = (int)($_POST['category_id'] ?? 0);

$pageTitle  = 'Novo lançamento';
$activePage = 'finance';
require_once __DIR__ . '/../../includes/layout.php';
?>

<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="POST" style="width:100%">

  <!-- Tipo -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Tipo de lançamento</p>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <label style="display:flex;align-items:center;gap:10px;padding:14px;border:2px solid <?= $selectedType==='income'?'var(--accent)':'var(--border)' ?>;border-radius:8px;cursor:pointer;background:<?= $selectedType==='income'?'var(--accent-lt)':'white' ?>"
             id="type-income-label">
        <input type="radio" name="type" value="income" <?= $selectedType==='income'?'checked':'' ?> onchange="switchType('income')">
        <div>
          <div style="font-size:15px">⬆️</div>
          <div style="font-weight:500;font-size:14px;color:var(--green)">Entrada</div>
          <div style="font-size:12px;color:var(--text-muted)">Dízimos, ofertas, doações</div>
        </div>
      </label>
      <label style="display:flex;align-items:center;gap:10px;padding:14px;border:2px solid <?= $selectedType==='expense'?'var(--red)':'var(--border)' ?>;border-radius:8px;cursor:pointer;background:<?= $selectedType==='expense'?'#FEF2F2':'white' ?>"
             id="type-expense-label">
        <input type="radio" name="type" value="expense" <?= $selectedType==='expense'?'checked':'' ?> onchange="switchType('expense')">
        <div>
          <div style="font-size:15px">⬇️</div>
          <div style="font-weight:500;font-size:14px;color:var(--red)">Saída</div>
          <div style="font-size:12px;color:var(--text-muted)">Despesas, pagamentos</div>
        </div>
      </label>
    </div>
  </div>

  <!-- Categoria -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Categoria *</p>
    <div id="income-cats" style="display:<?= $selectedType==='income'?'grid':'none' ?>;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px">
      <?php foreach ($incomeCategories as $cat): ?>
        <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1.5px solid <?= $selectedCat==$cat['id']?$cat['color']:'var(--border)' ?>;border-radius:8px;cursor:pointer;font-size:13px"
               id="cat-label-<?= $cat['id'] ?>">
          <input type="radio" name="category_id" value="<?= $cat['id'] ?>"
                 <?= $selectedCat==$cat['id']?'checked':'' ?>
                 onchange="selectCat(<?= $cat['id'] ?>,'<?= $cat['color'] ?>')">
          <span style="width:10px;height:10px;border-radius:50%;background:<?= $cat['color'] ?>;flex-shrink:0"></span>
          <?= htmlspecialchars($cat['name']) ?>
        </label>
      <?php endforeach; ?>
    </div>
    <div id="expense-cats" style="display:<?= $selectedType==='expense'?'grid':'none' ?>;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px">
      <?php foreach ($expenseCategories as $cat): ?>
        <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1.5px solid <?= $selectedCat==$cat['id']?$cat['color']:'var(--border)' ?>;border-radius:8px;cursor:pointer;font-size:13px"
               id="cat-label-<?= $cat['id'] ?>">
          <input type="radio" name="category_id" value="<?= $cat['id'] ?>"
                 <?= $selectedCat==$cat['id']?'checked':'' ?>
                 onchange="selectCat(<?= $cat['id'] ?>,'<?= $cat['color'] ?>')">
          <span style="width:10px;height:10px;border-radius:50%;background:<?= $cat['color'] ?>;flex-shrink:0"></span>
          <?= htmlspecialchars($cat['name']) ?>
        </label>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Dados -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Dados do lançamento</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Valor (R$) *</label>
        <input type="text" name="amount" class="form-control"
               placeholder="0,00"
               value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>"
               style="font-size:18px;font-weight:500" required>
      </div>
      <div class="form-group">
        <label class="form-label">Data *</label>
        <input type="date" name="entry_date" class="form-control"
               value="<?= htmlspecialchars($_POST['entry_date'] ?? date('Y-m-d')) ?>" required>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Descrição</label>
        <input type="text" name="description" class="form-control"
               placeholder="Detalhes do lançamento…"
               value="<?= htmlspecialchars($_POST['description'] ?? '') ?>">
      </div>
      <div class="form-group" id="campaign-group" style="display:<?= ($_POST['category_id']??0)==(array_filter($categories,fn($c)=>strtolower($c['name'])==='oferta específica')[0]['id']??-1)?'block':'none' ?>">
        <label class="form-label">Nome da campanha / finalidade</label>
        <input type="text" name="campaign_name" class="form-control"
               placeholder="Ex: Oferta para família Silva, Reforma do salão…"
               value="<?= htmlspecialchars($_POST['campaign_name'] ?? '') ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Forma de pagamento</label>
        <select name="payment_method" class="form-control">
          <option value="">Selecione</option>
          <?php foreach ($paymentMethods as $k => $v): ?>
            <option value="<?= $k ?>" <?= ($_POST['payment_method']??'')===$k?'selected':''?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Documento / referência</label>
        <input type="text" name="reference_doc" class="form-control"
               placeholder="Nº nota fiscal, comprovante…"
               value="<?= htmlspecialchars($_POST['reference_doc'] ?? '') ?>">
      </div>
    </div>

    <!-- Membro (para dízimos) -->
    <div class="form-group" style="margin-bottom:0" id="member-group">
      <label class="form-label">Membro <span style="font-weight:400;color:var(--text-muted)" id="member-hint">(opcional)</span></label>
      <select name="member_id" class="form-control">
        <option value="">Selecione um membro</option>
        <?php foreach ($members as $m): ?>
          <option value="<?= $m['id'] ?>" <?= ($_POST['member_id']??'')==$m['id']?'selected':''?>>
            <?= htmlspecialchars($m['name']) ?>
            <?= $m['branch_name'] ? ' — ' . htmlspecialchars($m['branch_name']) : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div style="display:flex;gap:10px">
    <button type="submit" class="btn btn-primary">Salvar lançamento</button>
    <a href="/pages/finance/index.php" class="btn btn-secondary">Cancelar</a>
  </div>
</form>

<?php
$titheId = $titheCategory ?? 0;
$extraJs = <<<JS
function switchType(type) {
  const incEl = document.getElementById('income-cats');
  const expEl = document.getElementById('expense-cats');
  const inLbl = document.getElementById('type-income-label');
  const exLbl = document.getElementById('type-expense-label');
  incEl.style.display = type==='income' ? 'grid' : 'none';
  expEl.style.display = type==='expense'? 'grid' : 'none';
  inLbl.style.borderColor = type==='income' ? 'var(--accent)' : 'var(--border)';
  inLbl.style.background  = type==='income' ? 'var(--accent-lt)' : 'white';
  exLbl.style.borderColor = type==='expense'? 'var(--red)' : 'var(--border)';
  exLbl.style.background  = type==='expense'? '#FEF2F2' : 'white';
  // Desmarcar categoria do outro tipo
  document.querySelectorAll('[name=category_id]').forEach(r => r.checked = false);
}

function selectCat(id, color) {
  document.querySelectorAll('[id^="cat-label-"]').forEach(el => {
    el.style.borderColor = 'var(--border)';
  });
  const label = document.getElementById('cat-label-' + id);
  if (label) label.style.borderColor = color;
  // Mostrar campo campanha se for Oferta Específica
  const campGroup = document.getElementById('campaign-group');
  // Mostrar hint de membro obrigatório se for dízimo
  const memberHint = document.getElementById('member-hint');
  if (id == $titheId) {
    memberHint.textContent = '(obrigatório para dízimos)';
    memberHint.style.color = 'var(--red)';
  } else {
    memberHint.textContent = '(opcional)';
    memberHint.style.color = 'var(--text-muted)';
  }
}

// Inicializar cat selecionada
const checkedCat = document.querySelector('[name=category_id]:checked');
if (checkedCat) {
  const lbl = document.getElementById('cat-label-' + checkedCat.value);
  if (lbl) {
    const color = lbl.querySelector('span').style.background;
    selectCat(checkedCat.value, color);
  }
}
JS;
require_once __DIR__ . '/../../includes/layout-footer.php';
?>
