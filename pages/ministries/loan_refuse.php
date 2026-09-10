<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db       = db();
$churchId = current_church_id();
$id       = (int)($_GET['id'] ?? 0);
$errors   = [];

$stmt = $db->prepare("
    SELECT ml.*, mi.name AS item_name, mn.name AS ministry_name,
           mn.id AS ministry_id, m.name AS member_name
    FROM ministry_loans ml
    JOIN ministry_items mi ON mi.id = ml.item_id
    JOIN ministries mn     ON mn.id = ml.ministry_id
    JOIN members m         ON m.id  = ml.member_id
    WHERE ml.id = ? AND ml.church_id = ? AND ml.status = 'pending'
");
$stmt->execute([$id, $churchId]);
$loan = $stmt->fetch();
if (!$loan) { header('Location: /pages/ministries/index.php'); exit; }
auth_require_ministry((int)$loan['ministry_id']);

$pageTitle = 'Recusar solicitação';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = trim($_POST['refused_reason'] ?? '');
    if ($reason === '') $errors[] = 'A justificativa é obrigatória para recusar.';

    if (empty($errors)) {
        $memberId = auth_member_id();
        $db->prepare("
            UPDATE ministry_loans
            SET status='refused', refused_reason=?, approved_by=?, approved_at=NOW()
            WHERE id=? AND church_id=?
        ")->execute([$reason, $memberId, $id, $churchId]);
        header('Location: /pages/ministries/items.php?ministry_id='.$loan['ministry_id'].'&refused=1');
        exit;
    }
}

$activePage = 'ministries';
require_once __DIR__ . '/../../includes/layout.php';
?>

<div style="margin-bottom:16px">
  <a href="/pages/ministries/items.php?ministry_id=<?= $loan['ministry_id'] ?>" style="font-size:13px;color:var(--text-muted);text-decoration:none">
    ← Pertences · <?= htmlspecialchars($loan['ministry_name']) ?>
  </a>
</div>

<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="card" style="max-width:560px">
  <p class="card-title">Recusar solicitação</p>

  <!-- Resumo da solicitação -->
  <div style="background:var(--content-bg);border-radius:8px;padding:14px;margin-bottom:20px">
    <div style="font-size:13px;display:flex;flex-direction:column;gap:6px">
      <div style="display:flex;gap:8px">
        <span style="color:var(--text-muted);width:80px;flex-shrink:0">Item</span>
        <strong><?= htmlspecialchars($loan['item_name']) ?></strong>
      </div>
      <div style="display:flex;gap:8px">
        <span style="color:var(--text-muted);width:80px;flex-shrink:0">Membro</span>
        <strong><?= htmlspecialchars($loan['member_name']) ?></strong>
      </div>
      <div style="display:flex;gap:8px">
        <span style="color:var(--text-muted);width:80px;flex-shrink:0">Motivo</span>
        <span><?= htmlspecialchars($loan['reason']) ?></span>
      </div>
    </div>
  </div>

  <form method="POST">
    <div class="form-group" style="margin-bottom:20px">
      <label class="form-label">
        Justificativa da recusa *
        <span style="font-weight:400;color:var(--red)"> (obrigatório)</span>
      </label>
      <textarea name="refused_reason" class="form-control" rows="4" required
                placeholder="Explique o motivo da recusa para o membro…"><?= htmlspecialchars($_POST['refused_reason'] ?? '') ?></textarea>
      <span style="font-size:11px;color:var(--text-muted)">Esta justificativa ficará registrada e será visível para o membro.</span>
    </div>
    <div style="display:flex;gap:10px">
      <button type="submit" class="btn btn-primary" style="background:var(--red)">Confirmar recusa</button>
      <a href="/pages/ministries/items.php?ministry_id=<?= $loan['ministry_id'] ?>" class="btn btn-secondary">Cancelar</a>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
