<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db       = db();
$memberId = auth_member_id();
$errors   = [];
$conflicts = [];
$added    = false;

if ($memberId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        $db->prepare("DELETE FROM member_unavailability WHERE id = ? AND member_id = ?")
           ->execute([(int)$_POST['delete_id'], $memberId]);
        header('Location: /pages/availability/index.php?removed=1');
        exit;
    }

    $from = $_POST['date_from'] ?? '';
    $to   = ($_POST['date_to'] ?? '') ?: $from;
    $note = mb_substr(trim($_POST['note'] ?? ''), 0, 255);
    $validDate = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;

    if (!$validDate($from) || !$validDate($to)) {
        $errors[] = 'Informe a data (ou o período) em que você não pode.';
    } elseif ($to < $from) {
        $errors[] = 'A data final não pode ser antes da inicial.';
    } elseif ($to < date('Y-m-d')) {
        $errors[] = 'Essa data já passou.';
    } elseif ((strtotime($to) - strtotime($from)) > 366 * 86400) {
        $errors[] = 'O período pode ter no máximo 1 ano.';
    } else {
        $db->prepare("INSERT INTO member_unavailability (member_id, date_from, date_to, note) VALUES (?,?,?,?)")
           ->execute([$memberId, $from, $to, $note !== '' ? $note : null]);
        $added = true;

        // Avisa se já está escalado(a) em algo dentro do período
        $c = $db->prepare("
            SELECT ma.id, ma.title, ma.activity_date, mn.name AS ministry_name
            FROM ministry_activity_members mam
            JOIN ministry_activities ma ON ma.id = mam.activity_id
            JOIN ministries mn ON mn.id = ma.ministry_id
            WHERE mam.member_id = ? AND ma.status = 'scheduled' AND ma.activity_date BETWEEN ? AND ?
            ORDER BY ma.activity_date
        ");
        $c->execute([$memberId, $from, $to]);
        $conflicts = $c->fetchAll();
    }
}

$entries = [];
if ($memberId) {
    $q = $db->prepare("SELECT * FROM member_unavailability WHERE member_id = ? AND date_to >= CURDATE() ORDER BY date_from");
    $q->execute([$memberId]);
    $entries = $q->fetchAll();
}

$pageTitle  = 'Minha disponibilidade';
$activePage = 'availability';
require_once __DIR__ . '/../../includes/layout.php';

$fmt = fn($d) => date('d/m/Y', strtotime($d));
?>

<?php if (!$memberId): ?>
  <div class="card"><p style="font-size:13px;color:var(--text-muted)">Seu usuário não está vinculado a um membro, então não há disponibilidade para registrar.</p></div>
<?php else: ?>

<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($added): ?>
  <div style="background:#E1F5EE;border:1px solid var(--accent);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#0F6E56">
    Indisponibilidade registrada. Você não será sorteado(a) nas escalas automáticas dessas datas.
  </div>
  <?php if ($conflicts): ?>
    <div style="background:#FEF3C7;border:1px solid #FCD34D;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#854F0B">
      <strong>Atenção:</strong> você já está escalado(a) nestas atividades. Avise o líder do ministério ou recuse pelo link da mensagem de escala:
      <ul style="margin:8px 0 0 18px">
        <?php foreach ($conflicts as $c): ?>
          <li><?= $fmt($c['activity_date']) ?> · <?= htmlspecialchars($c['ministry_name']) ?> · <?= htmlspecialchars($c['title']) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
<?php elseif (isset($_GET['removed'])): ?>
  <div style="background:#E1F5EE;border:1px solid var(--accent);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#0F6E56">Indisponibilidade removida.</div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px">
  <p class="card-title">Marcar datas em que não posso</p>
  <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px">
    Nas datas marcadas você não entra no sorteio automático das escalas dos seus ministérios.
    Para um dia só, preencha apenas "De".
  </p>
  <form method="POST">
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">De *</label>
        <input type="date" name="date_from" class="form-control" min="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Até (opcional)</label>
        <input type="date" name="date_to" class="form-control" min="<?= date('Y-m-d') ?>">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Motivo (opcional)</label>
      <input type="text" name="note" class="form-control" maxlength="255" placeholder="Ex: viagem, trabalho, saúde">
    </div>
    <button type="submit" class="btn btn-primary">Salvar</button>
  </form>
</div>

<div class="card" style="padding:0">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
    <p style="font-weight:500;font-size:14px">Minhas indisponibilidades <span style="color:var(--text-muted);font-weight:400">(<?= count($entries) ?>)</span></p>
  </div>
  <?php if (empty($entries)): ?>
    <div class="empty-state" style="padding:24px">Nenhuma data marcada.</div>
  <?php else: ?>
    <?php foreach ($entries as $en): ?>
      <div style="display:flex;align-items:center;gap:10px;padding:12px 18px;border-bottom:1px solid var(--border)">
        <div style="flex:1;min-width:0">
          <div style="font-size:13px;font-weight:500">
            <?= $fmt($en['date_from']) ?><?= $en['date_to'] !== $en['date_from'] ? ' a ' . $fmt($en['date_to']) : '' ?>
          </div>
          <?php if ($en['note']): ?>
            <div style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($en['note']) ?></div>
          <?php endif; ?>
        </div>
        <form method="POST" onsubmit="return confirm('Remover esta indisponibilidade?')">
          <input type="hidden" name="delete_id" value="<?= (int)$en['id'] ?>">
          <button type="submit" class="btn btn-secondary" style="font-size:12px;padding:5px 12px;color:var(--red)">Remover</button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
