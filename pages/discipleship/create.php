<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/discipleship.php';
auth_check();

$db       = db();
$memberId = auth_member_id();
$error    = null;

if (!$memberId) { header('Location: /pages/discipleship/index.php'); exit; }

$me = $db->prepare("SELECT cell_id, church_id FROM members WHERE id = ?");
$me->execute([$memberId]);
$me = $me->fetch();

if (!$me || !$me['cell_id']) { header('Location: /pages/discipleship/index.php'); exit; }
if (member_current_discipleship_as_disciple($db, $memberId)) { header('Location: /pages/discipleship/index.php'); exit; }

$cell = $db->prepare("SELECT name FROM cells WHERE id = ?");
$cell->execute([$me['cell_id']]);
$cellName = $cell->fetchColumn();

$candidates = $db->prepare("
    SELECT id, name, discipleship_step FROM members
    WHERE cell_id = ? AND id != ? AND status = 'active' AND discipleship_step >= 1
    ORDER BY name
");
$candidates->execute([$me['cell_id'], $memberId]);
$candidates = $candidates->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $disciplerId = (int)($_POST['discipler_id'] ?? 0);
    if (!$disciplerId) {
        $error = 'Escolha alguém da lista.';
    } else {
        $error = discipleship_request($db, $memberId, $disciplerId);
        if (!$error) { header('Location: /pages/discipleship/index.php?ok=1'); exit; }
    }
}

$pageTitle  = 'Pedir discipulado';
$activePage = 'discipleship';
require_once __DIR__ . '/../../includes/layout.php';
?>

<div style="margin-bottom:16px">
  <a href="/pages/discipleship/index.php" style="font-size:13px;color:var(--text-muted);text-decoration:none">← Discipulado</a>
</div>

<?php if ($error): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#A32D2D">
    <?= $error ?>
  </div>
<?php endif; ?>

<div class="card">
  <p class="card-title">Quem você gostaria que fosse seu discipulador(a)?</p>
  <p style="font-size:12px;color:var(--text-muted);margin-bottom:16px">
    Célula <?= htmlspecialchars($cellName) ?> · precisa ter concluído pelo menos o 1º Passo
  </p>

  <?php if (empty($candidates)): ?>
    <div class="empty-state" style="padding:24px">
      Ainda não há ninguém na sua célula que já concluiu o 1º Passo. Fale com o líder da sua célula.
    </div>
  <?php else: ?>
    <form method="POST">
      <div style="border:1px solid var(--border);border-radius:7px;overflow:hidden;margin-bottom:16px">
        <?php foreach ($candidates as $c): ?>
          <label style="display:flex;align-items:center;gap:10px;padding:12px;cursor:pointer;border-bottom:1px solid var(--border);font-size:13px">
            <input type="radio" name="discipler_id" value="<?= $c['id'] ?>" required>
            <div class="avatar" style="width:28px;height:28px;font-size:11px;flex-shrink:0"><?= strtoupper(substr($c['name'],0,2)) ?></div>
            <div style="flex:1">
              <?= htmlspecialchars($c['name']) ?>
              <div style="font-size:11px;color:var(--text-muted)"><?= DISCIPLESHIP_STEP_LABELS[$c['discipleship_step']] ?? '' ?></div>
            </div>
          </label>
        <?php endforeach; ?>
      </div>
      <button type="submit" class="btn btn-primary">Enviar pedido</button>
      <a href="/pages/discipleship/index.php" class="btn btn-secondary">Cancelar</a>
    </form>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
