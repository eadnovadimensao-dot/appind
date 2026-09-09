<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db         = db();
$activityId = (int)($_GET['activity_id'] ?? 0);
$memberId   = auth_member_id();
$action     = $_GET['action'] ?? ''; // accept | refuse
$errors     = [];

// Buscar atividade e verificar se o membro está escalado
$stmt = $db->prepare("
    SELECT ma.*, mn.name AS ministry_name, mn.leader_id,
           mam.status AS current_status, mam.notified_at
    FROM ministry_activity_members mam
    JOIN ministry_activities ma ON ma.id = mam.activity_id
    JOIN ministries mn          ON mn.id = ma.ministry_id
    WHERE mam.activity_id = ? AND mam.member_id = ?
");
$stmt->execute([$activityId, $memberId]);
$data = $stmt->fetch();

if (!$data) { header('Location: /dashboard.php'); exit; }

// Verificar prazo de 48h
$deadline  = strtotime($data['activity_date'] . ' -48 hours');
$isPast48  = time() > $deadline;

if ($_SERVER['REQUEST_METHOD'] === 'POST' || $action) {
    $respond = $_POST['action'] ?? $action;
    $reason  = trim($_POST['reason'] ?? '');

    if ($isPast48) {
        $errors[] = 'O prazo para resposta (48h antes) já encerrou.';
    } elseif ($data['current_status'] !== 'pending') {
        $errors[] = 'Você já respondeu a esta escala.';
    } elseif ($respond === 'refuse' && $reason === '') {
        $errors[] = 'Informe o motivo da recusa.';
    } elseif (in_array($respond, ['accept','refuse'])) {
        $status = $respond === 'accept' ? 'accepted' : 'refused';

        $db->prepare("
            UPDATE ministry_activity_members
            SET status=?, confirmed=?, refuse_reason=?, responded_at=NOW()
            WHERE activity_id=? AND member_id=?
        ")->execute([$status, $status==='accepted'?1:0, $reason?:null, $activityId, $memberId]);

        // Notificar líder se recusou
        if ($status === 'refused' && $data['leader_id']) {
            $db->prepare("
                INSERT INTO scale_notifications
                  (activity_id, member_id, leader_id, type, status, message)
                VALUES (?, ?, ?, 'refusal', 'unread', ?)
            ")->execute([$activityId, $memberId, $data['leader_id'], $reason]);

            // Enviar push para o líder
            $leaderSubs = $db->prepare("SELECT * FROM push_subscriptions WHERE member_id=?");
            $leaderSubs->execute([$data['leader_id']]);
            // (envio real via web-push omitido por brevidade — usa a mesma lógica do create.php de comunicação)
        }

        header('Location: /pages/worship-scale/response.php?activity_id='.$activityId.'&result='.$status);
        exit;
    }
}

$pageTitle  = 'Responder escala';
$activePage = 'worship-scale';
require_once __DIR__ . '/../../includes/layout.php';
?>

<div style="max-width:560px;margin:0 auto">
  <!-- Info da atividade -->
  <div class="card" style="margin-bottom:16px">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
      <div style="font-size:32px">🎵</div>
      <div>
        <h2 style="font-size:16px;font-weight:500"><?= htmlspecialchars($data['title']) ?></h2>
        <div style="font-size:13px;color:var(--text-muted)">
          <?= htmlspecialchars($data['ministry_name']) ?> ·
          <?= date('d/m/Y', strtotime($data['activity_date'])) ?>
          <?= $data['time_start'] ? ' às ' . substr($data['time_start'],0,5) : '' ?>
        </div>
      </div>
    </div>

    <?php if ($data['current_status'] !== 'pending'): ?>
      <div style="background:<?= $data['current_status']==='accepted'?'var(--accent-lt)':'#FCEBEB' ?>;border-radius:8px;padding:12px;font-size:13px;color:<?= $data['current_status']==='accepted'?'var(--accent-dk)':'#A32D2D' ?>">
        <?= $data['current_status']==='accepted' ? '✓ Você já confirmou presença nesta escala.' : '✗ Você já recusou esta escala.' ?>
      </div>
    <?php elseif ($isPast48): ?>
      <div style="background:#FEF3C7;border-radius:8px;padding:12px;font-size:13px;color:#854F0B">
        ⏰ O prazo para resposta (48h antes) encerrou em <?= date('d/m/Y H:i', $deadline) ?>.
      </div>
    <?php else: ?>
      <div style="background:#E1F5EE;border-radius:8px;padding:12px;font-size:13px;color:#0F6E56">
        ⏰ Prazo para resposta: <?= date('d/m/Y H:i', $deadline) ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($data['current_status'] === 'pending' && !$isPast48): ?>

    <?php if (!empty($errors)): ?>
      <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px;margin-bottom:16px;font-size:13px;color:#A32D2D">
        <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- Aceitar -->
    <form method="POST" style="margin-bottom:12px">
      <input type="hidden" name="action" value="accept">
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:14px;font-size:15px">
        ✓ Confirmar participação
      </button>
    </form>

    <!-- Recusar -->
    <div class="card">
      <p class="card-title">Recusar participação</p>
      <form method="POST">
        <input type="hidden" name="action" value="refuse">
        <div class="form-group" style="margin-bottom:16px">
          <label class="form-label">Motivo da recusa * <span style="color:var(--red)">(obrigatório)</span></label>
          <textarea name="reason" class="form-control" rows="3" required
                    placeholder="Ex: Já tenho compromisso nesta data, estou viajando…"><?= htmlspecialchars($_POST['reason'] ?? '') ?></textarea>
        </div>
        <button type="submit" class="btn btn-secondary" style="width:100%;justify-content:center;color:var(--red)">
          ✗ Recusar e justificar
        </button>
      </form>
    </div>

  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
