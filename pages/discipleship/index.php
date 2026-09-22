<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/discipleship.php';
auth_check();

$db       = db();
$churchId = current_church_id();
$memberId = auth_member_id();
$isCoordinator = auth_is_discipleship_coordinator();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    $d = $db->prepare("SELECT * FROM discipleships WHERE id = ? AND church_id = ?");
    $d->execute([$id, $churchId]);
    $d = $d->fetch();

    if ($d && $action === 'discipler_decide' && $memberId === (int)$d['discipler_member_id'] && $d['status'] === 'pending_discipler') {
        $approve = ($_POST['approve'] ?? '') === '1';
        discipleship_decide_discipler($db, $id, $approve, $memberId);
        header('Location: /pages/discipleship/index.php?ok=1');
        exit;
    }

    if ($d && $action === 'leader_decide' && auth_can_decide_discipleship_as_leader((int)$d['cell_id']) && $d['status'] === 'pending_leader') {
        $approve = ($_POST['approve'] ?? '') === '1';
        discipleship_decide_leader($db, $id, $approve, $memberId, trim($_POST['notes'] ?? '') ?: null);
        header('Location: /pages/discipleship/index.php?ok=1');
        exit;
    }

    if ($d && $action === 'coordination_decide' && $isCoordinator && $d['status'] === 'pending_coordination') {
        $approve = ($_POST['approve'] ?? '') === '1';
        discipleship_decide_coordination($db, $id, $approve, $memberId, trim($_POST['notes'] ?? '') ?: null);
        header('Location: /pages/discipleship/index.php?ok=1');
        exit;
    }

    if ($d && $action === 'end' && $d['status'] === 'active'
        && ($isCoordinator || auth_can_decide_discipleship_as_leader((int)$d['cell_id']))) {
        $newStatus = ($_POST['result'] ?? '') === 'completed' ? 'completed' : 'cancelled';
        $db->prepare("UPDATE discipleships SET status=?, ended_at=NOW(), ended_reason=? WHERE id=?")
           ->execute([$newStatus, trim($_POST['reason'] ?? '') ?: null, $id]);
        header('Location: /pages/discipleship/index.php?ok=1');
        exit;
    }

    header('Location: /pages/discipleship/index.php');
    exit;
}

$myDiscipleship = $memberId ? member_current_discipleship_as_disciple($db, $memberId) : null;
$myDisciples    = $memberId ? member_current_disciples($db, $memberId) : [];
$myInvites      = $memberId ? member_pending_discipler_invites($db, $memberId) : [];
$pendingLeader  = discipleships_pending_leader($db, $churchId);
$pendingCoord   = $isCoordinator ? discipleships_pending_coordination($db, $churchId) : [];
$activeAll      = $isCoordinator ? discipleships_active($db, $churchId) : [];

$myCell = null;
if ($memberId) {
    $myCell = $db->query("SELECT cell_id FROM members WHERE id=$memberId")->fetchColumn() ?: null;
}

$statusLabels = [
    'pending_discipler'    => ['label' => 'Aguardando aceite',     'badge' => 'badge-amber'],
    'pending_leader'       => ['label' => 'Aguardando líder',      'badge' => 'badge-amber'],
    'pending_coordination' => ['label' => 'Aguardando coordenação','badge' => 'badge-amber'],
    'active'               => ['label' => 'Em andamento',          'badge' => 'badge-green'],
    'completed'            => ['label' => 'Concluído',             'badge' => 'badge-blue'],
    'cancelled'            => ['label' => 'Cancelado',             'badge' => 'badge-gray'],
    'rejected'             => ['label' => 'Não aprovado',          'badge' => 'badge-red'],
];

$pageTitle  = 'Discipulado';
$activePage = 'discipleship';
require_once __DIR__ . '/../../includes/layout.php';
?>

<?php if (isset($_GET['ok'])): ?>
  <div style="background:#E1F5EE;border:1px solid var(--accent);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#0F6E56">
    ✅ Feito.
  </div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px;background:#F5F5F5;border:none">
  <p style="font-size:13px;line-height:1.7">
    🤝 O discipulado acontece dentro da célula: você escolhe alguém da sua própria célula que já concluiu o 1º Passo.
    Essa pessoa precisa aceitar, depois o líder da célula dá o aval e a coordenação do discipulado confirma.
    Só depois de tudo isso o acompanhamento começa.
  </p>
</div>

<!-- Meu discipulado -->
<div class="card" style="padding:0;margin-bottom:16px">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
    <p style="font-weight:500;font-size:14px">🙋 Meu discipulado</p>
  </div>
  <div style="padding:16px 18px">
    <?php if ($myDiscipleship): ?>
      <p style="font-size:13px">
        Discipulador(a): <strong><?= htmlspecialchars($myDiscipleship['discipler_name']) ?></strong>
        · Célula <?= htmlspecialchars($myDiscipleship['cell_name']) ?>
      </p>
      <span class="badge <?= $statusLabels[$myDiscipleship['status']]['badge'] ?>" style="margin-top:6px;display:inline-block">
        <?= $statusLabels[$myDiscipleship['status']]['label'] ?>
      </span>
    <?php elseif ($myCell): ?>
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:10px">Você ainda não tem um discipulador.</p>
      <a href="/pages/discipleship/create.php" class="btn btn-primary">+ Pedir discipulado</a>
    <?php else: ?>
      <p style="font-size:13px;color:var(--text-muted)">Você precisa estar em uma célula pra pedir um discipulado.</p>
    <?php endif; ?>
  </div>
</div>

<!-- Convites de discipulado aguardando minha resposta -->
<?php if ($myInvites): ?>
<div class="card" style="padding:0;margin-bottom:16px">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
    <p style="font-weight:500;font-size:14px">💌 Convites pra discipular <span style="color:var(--text-muted);font-weight:400">(<?= count($myInvites) ?>)</span></p>
  </div>
  <?php foreach ($myInvites as $d): ?>
    <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
      <p style="font-size:13px;margin-bottom:8px">
        <strong><?= htmlspecialchars($d['disciple_name']) ?></strong> gostaria que você fosse discipulador(a) dela(e)
        · Célula <?= htmlspecialchars($d['cell_name']) ?>
      </p>
      <form method="POST" style="display:flex;gap:8px">
        <input type="hidden" name="action" value="discipler_decide">
        <input type="hidden" name="id" value="<?= $d['id'] ?>">
        <button type="submit" name="approve" value="1" class="btn btn-primary" style="font-size:12px;padding:6px 14px">✅ Aceito</button>
        <button type="submit" name="approve" value="0" class="btn btn-secondary" style="font-size:12px;padding:6px 14px;color:var(--red)"
                data-confirm="Recusar esse convite?">❌ Não posso agora</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Quem estou discipulando -->
<?php if ($myDisciples): ?>
<div class="card" style="padding:0;margin-bottom:16px">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
    <p style="font-weight:500;font-size:14px">✝️ Quem estou discipulando <span style="color:var(--text-muted);font-weight:400">(<?= count($myDisciples) ?>)</span></p>
  </div>
  <?php foreach ($myDisciples as $d): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 18px;border-bottom:1px solid var(--border)">
      <div>
        <div style="font-size:13px;font-weight:500"><?= htmlspecialchars($d['disciple_name']) ?></div>
        <div style="font-size:11px;color:var(--text-muted)">Célula <?= htmlspecialchars($d['cell_name']) ?></div>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <span class="badge <?= $statusLabels[$d['status']]['badge'] ?>"><?= $statusLabels[$d['status']]['label'] ?></span>
        <?php if ($d['status'] === 'active'): ?>
          <a href="/pages/discipleship/reports.php?id=<?= $d['id'] ?>" class="btn btn-secondary" style="font-size:11px;padding:5px 10px">Encontros</a>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Aguardando meu aval como líder -->
<?php if ($pendingLeader): ?>
<div class="card" style="padding:0;margin-bottom:16px">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
    <p style="font-weight:500;font-size:14px">📋 Aguardando meu aval <span style="color:var(--text-muted);font-weight:400">(<?= count($pendingLeader) ?>)</span></p>
  </div>
  <?php foreach ($pendingLeader as $d): ?>
    <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
      <p style="font-size:13px;margin-bottom:8px">
        <strong><?= htmlspecialchars($d['disciple_name']) ?></strong> escolheu <strong><?= htmlspecialchars($d['discipler_name']) ?></strong>
        como discipulador(a) · Célula <?= htmlspecialchars($d['cell_name']) ?>
      </p>
      <form method="POST" style="display:flex;gap:8px">
        <input type="hidden" name="action" value="leader_decide">
        <input type="hidden" name="id" value="<?= $d['id'] ?>">
        <button type="submit" name="approve" value="1" class="btn btn-primary" style="font-size:12px;padding:6px 14px">✓ Aprovar</button>
        <button type="submit" name="approve" value="0" class="btn btn-secondary" style="font-size:12px;padding:6px 14px;color:var(--red)"
                data-confirm="Recusar esse pedido de discipulado?">✕ Recusar</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($isCoordinator): ?>
  <!-- Aguardando confirmação da coordenação -->
  <div class="card" style="padding:0;margin-bottom:16px">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
      <p style="font-weight:500;font-size:14px">✅ Aguardando confirmação da coordenação <span style="color:var(--text-muted);font-weight:400">(<?= count($pendingCoord) ?>)</span></p>
      <?php if (auth_role() === 'supermaster'): ?>
        <a href="/pages/discipleship/coordinators.php" style="font-size:12px;color:var(--text-muted);text-decoration:none">Gerenciar coordenação</a>
      <?php endif; ?>
    </div>
    <?php if (empty($pendingCoord)): ?>
      <div class="empty-state" style="padding:24px">Nada aguardando confirmação.</div>
    <?php else: ?>
      <?php foreach ($pendingCoord as $d): ?>
        <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
          <p style="font-size:13px;margin-bottom:8px">
            <strong><?= htmlspecialchars($d['disciple_name']) ?></strong> · discipulador(a) <strong><?= htmlspecialchars($d['discipler_name']) ?></strong>
            · Célula <?= htmlspecialchars($d['cell_name']) ?> · líder já aprovou em <?= date('d/m', strtotime($d['leader_decided_at'])) ?>
          </p>
          <form method="POST" style="display:flex;gap:8px">
            <input type="hidden" name="action" value="coordination_decide">
            <input type="hidden" name="id" value="<?= $d['id'] ?>">
            <button type="submit" name="approve" value="1" class="btn btn-primary" style="font-size:12px;padding:6px 14px">✓ Confirmar</button>
            <button type="submit" name="approve" value="0" class="btn btn-secondary" style="font-size:12px;padding:6px 14px;color:var(--red)"
                    data-confirm="Recusar esse pedido de discipulado?">✕ Recusar</button>
          </form>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Todos os discipulados ativos -->
  <div class="card" style="padding:0">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
      <p style="font-weight:500;font-size:14px">🌿 Discipulados em andamento <span style="color:var(--text-muted);font-weight:400">(<?= count($activeAll) ?>)</span></p>
    </div>
    <?php if (empty($activeAll)): ?>
      <div class="empty-state" style="padding:24px">Nenhum discipulado em andamento ainda.</div>
    <?php else: ?>
      <?php foreach ($activeAll as $d): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 18px;border-bottom:1px solid var(--border)">
          <div>
            <div style="font-size:13px"><strong><?= htmlspecialchars($d['disciple_name']) ?></strong> com <strong><?= htmlspecialchars($d['discipler_name']) ?></strong></div>
            <div style="font-size:11px;color:var(--text-muted)">Célula <?= htmlspecialchars($d['cell_name']) ?> · desde <?= date('d/m/Y', strtotime($d['started_at'])) ?></div>
          </div>
          <div style="display:flex;align-items:center;gap:6px">
            <a href="/pages/discipleship/reports.php?id=<?= $d['id'] ?>" class="btn btn-secondary" style="font-size:11px;padding:5px 10px">Encontros</a>
            <form method="POST" style="display:flex;gap:6px" data-confirm="Encerrar esse discipulado?">
              <input type="hidden" name="action" value="end">
              <input type="hidden" name="id" value="<?= $d['id'] ?>">
              <button type="submit" name="result" value="completed" class="btn btn-secondary" style="font-size:11px;padding:5px 10px">Concluir</button>
              <button type="submit" name="result" value="cancelled" class="btn btn-secondary" style="font-size:11px;padding:5px 10px;color:var(--red)">Cancelar</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
