<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
auth_check();
$db       = db();
$churchId = current_church_id();
$cellId   = (int)($_GET['cell_id'] ?? 0);
$errors   = [];

$stmt = $db->prepare("SELECT * FROM cells WHERE id = ? AND church_id = ?");
$stmt->execute([$cellId, $churchId]);
$cell = $stmt->fetch();
if (!$cell) { header('Location: /pages/cells/index.php'); exit; }
auth_require_cell($cellId);

$pageTitle = 'Relatório · ' . $cell['name'];

// Membros da célula para marcar presença
$members = $db->prepare("
    SELECT id, name FROM members
    WHERE cell_id = ? AND church_id = ? AND status = 'active'
    ORDER BY name
");
$members->execute([$cellId, $churchId]);
$members = $members->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date       = trim($_POST['report_date']     ?? '');
    $present    = (int)($_POST['total_present']  ?? 0);
    $visitors   = (int)($_POST['visitors']       ?? 0);
    $offering   = str_replace(['.',',' ], ['','.'], trim($_POST['offering'] ?? '0'));
    $subject    = trim($_POST['subject']         ?? '');
    $description= trim($_POST['description']     ?? '');
    $prayers    = trim($_POST['prayer_requests'] ?? '');
    $presentIds = $_POST['present_ids'] ?? [];

    if ($date === '') $errors[] = 'Data do relatório é obrigatória.';

    if (empty($errors)) {
        $stmt = $db->prepare("
            INSERT INTO cell_reports
              (cell_id, church_id, report_date, total_present, visitors, offering, subject, description, prayer_requests)
            VALUES (:cell_id,:church_id,:date,:present,:visitors,:offering,:subject,:description,:prayers)
        ");
        $stmt->execute([
            ':cell_id'     => $cellId,
            ':church_id'   => $churchId,
            ':date'        => $date,
            ':present'     => $present,
            ':visitors'    => $visitors,
            ':offering'    => (float)$offering,
            ':subject'     => $subject ?: null,
            ':description' => $description ?: null,
            ':prayers'     => $prayers ?: null,
        ]);
        $reportId = $db->lastInsertId();

        // Registrar presença
        if (!empty($presentIds)) {
            $sp = $db->prepare("INSERT IGNORE INTO cell_report_members (report_id, member_id) VALUES (?, ?)");
            foreach ($presentIds as $mid) {
                $sp->execute([$reportId, (int)$mid]);
            }
        }

        header('Location: /pages/cells/view.php?id=' . $cellId . '&reported=1');
        exit;
    }
}

$activePage = 'cells';
require_once __DIR__ . '/../../includes/layout.php';
?>

<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<div style="margin-bottom:16px">
  <a href="/pages/cells/view.php?id=<?= $cellId ?>" style="font-size:13px;color:var(--text-muted);text-decoration:none">
    ← <?= htmlspecialchars($cell['name']) ?>
  </a>
</div>

<form method="POST" style="width:100%">

  <!-- Cabeçalho do relatório -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Informações da reunião</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Data da reunião *</label>
        <input type="date" name="report_date" class="form-control"
               value="<?= htmlspecialchars($_POST['report_date'] ?? date('Y-m-d')) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Tema / Assunto</label>
        <input type="text" name="subject" class="form-control"
               placeholder="Tema estudado ou pregado…"
               value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Total de presentes</label>
        <input type="number" name="total_present" class="form-control" min="0"
               value="<?= htmlspecialchars($_POST['total_present'] ?? count($members)) ?>" id="total_present">
      </div>
      <div class="form-group">
        <label class="form-label">Visitantes</label>
        <input type="number" name="visitors" class="form-control" min="0"
               value="<?= htmlspecialchars($_POST['visitors'] ?? '0') ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Oferta arrecadada</label>
        <input type="text" name="offering" class="form-control"
               placeholder="0,00"
               value="<?= htmlspecialchars($_POST['offering'] ?? '') ?>">
      </div>
    </div>
  </div>

  <!-- Presença dos membros -->
  <?php if (!empty($members)): ?>
  <div class="card" style="margin-bottom:16px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
      <p class="card-title" style="margin-bottom:0">Lista de presença</p>
      <button type="button" onclick="toggleAll()" class="btn btn-secondary" style="font-size:12px;padding:5px 12px" id="toggle-btn">
        Marcar todos
      </button>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px">
      <?php foreach ($members as $m): ?>
        <label style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:6px;cursor:pointer;font-size:13px;border:1px solid var(--border)"
               onmouseover="this.style.background='#f9f9f9'" onmouseout="this.style.background=''">
          <input type="checkbox" name="present_ids[]" value="<?= $m['id'] ?>"
                 class="presence-cb"
                 <?= in_array($m['id'], $_POST['present_ids']??[]) ? 'checked' : '' ?>>
          <div class="avatar" style="width:26px;height:26px;font-size:10px;flex-shrink:0">
            <?= strtoupper(substr($m['name'],0,2)) ?>
          </div>
          <?= htmlspecialchars($m['name']) ?>
        </label>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Relatório narrativo -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Relatório da reunião</p>
    <div class="form-group">
      <label class="form-label">O que aconteceu na reunião?</label>
      <textarea name="description" class="form-control" rows="4"
                placeholder="Descreva como foi a reunião, destaques, decisões tomadas…"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
    </div>
    <div class="form-group" style="margin-bottom:0">
      <label class="form-label">Pedidos de oração</label>
      <textarea name="prayer_requests" class="form-control" rows="3"
                placeholder="Liste os pedidos de oração dos membros…"><?= htmlspecialchars($_POST['prayer_requests'] ?? '') ?></textarea>
    </div>
  </div>

  <div style="display:flex;gap:10px">
    <button type="submit" class="btn btn-primary">Enviar relatório</button>
    <a href="/pages/cells/view.php?id=<?= $cellId ?>" class="btn btn-secondary">Cancelar</a>
  </div>
</form>

<?php
$extraJs = <<<JS
// Marcar/desmarcar todos
let allMarked = false;
function toggleAll() {
  allMarked = !allMarked;
  document.querySelectorAll('.presence-cb').forEach(cb => cb.checked = allMarked);
  document.getElementById('toggle-btn').textContent = allMarked ? 'Desmarcar todos' : 'Marcar todos';
  updateCount();
}

// Atualizar contador de presentes ao marcar
document.querySelectorAll('.presence-cb').forEach(cb => {
  cb.addEventListener('change', updateCount);
});
function updateCount() {
  const count = document.querySelectorAll('.presence-cb:checked').length;
  const extra = parseInt(document.querySelector('[name=visitors]').value) || 0;
  document.getElementById('total_present').value = count + extra;
}
document.querySelector('[name=visitors]').addEventListener('input', updateCount);
JS;
require_once __DIR__ . '/../../includes/layout-footer.php';
?>
