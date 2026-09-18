<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db       = db();
$role     = auth_role();
$churchId = current_church_id();
$isAdmin  = in_array($role, ['supermaster','admin']);
$memberId = auth_member_id();

// Membro comum só vê os ministérios em que participa
if ($role === 'member') {
    $stmt = $db->prepare("
        SELECT mn.*, m.name AS leader_name, ch.name AS branch_name, ch.type AS branch_type,
               COUNT(DISTINCT mm.member_id) AS member_count,
               COUNT(DISTINCT ma.id) AS activity_count
        FROM ministries mn
        JOIN member_ministries mine ON mine.ministry_id = mn.id AND mine.member_id = ?
        LEFT JOIN members m   ON m.id  = mn.leader_id
        LEFT JOIN churches ch ON ch.id = mn.church_id
        LEFT JOIN member_ministries mm  ON mm.ministry_id = mn.id
        LEFT JOIN ministry_activities ma ON ma.ministry_id = mn.id AND ma.status='scheduled' AND ma.activity_date >= CURDATE()
        WHERE mn.church_id = ?
        GROUP BY mn.id
        ORDER BY mn.name
    ");
    $stmt->execute([$memberId, $churchId]);
    $ministries = $stmt->fetchAll();
} else { // Demais papéis: só a igreja selecionada
    $stmt = $db->prepare("
        SELECT mn.*, m.name AS leader_name, ch.name AS branch_name, ch.type AS branch_type,
               COUNT(DISTINCT mm.member_id) AS member_count,
               COUNT(DISTINCT ma.id) AS activity_count
        FROM ministries mn
        LEFT JOIN members m   ON m.id  = mn.leader_id
        LEFT JOIN churches ch ON ch.id = mn.church_id
        LEFT JOIN member_ministries mm  ON mm.ministry_id = mn.id
        LEFT JOIN ministry_activities ma ON ma.ministry_id = mn.id AND ma.status='scheduled' AND ma.activity_date >= CURDATE()
        WHERE mn.church_id = ?
        GROUP BY mn.id
        ORDER BY mn.name
    ");
    $stmt->execute([$churchId]);
    $ministries = $stmt->fetchAll();
}

$pageTitle       = 'Ministérios';
$activePage      = 'ministries';
$canCreateMinistry = auth_can('manage_ministries');
if ($canCreateMinistry) {
    $topbarAction = ['href' => '/pages/ministries/create.php', 'label' => 'Novo ministério'];
}
require_once __DIR__ . '/../../includes/layout.php';
?>

<div class="card" style="padding:0">
  <div style="padding:16px 20px;border-bottom:1px solid var(--border)">
    <span style="font-size:13px;color:var(--text-muted)"><?= count($ministries) ?> ministério(s)</span>
  </div>

  <?php if (empty($ministries)): ?>
    <div class="empty-state">
      <p style="font-size:32px;margin-bottom:8px">✝️</p>
      <p>Nenhum ministério cadastrado ainda.</p>
      <?php if ($canCreateMinistry): ?>
        <a href="/pages/ministries/create.php" class="btn btn-primary" style="margin-top:16px">+ Criar primeiro ministério</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Nome</th>
            <?php if ($isAdmin && $churchId === SEDE_ID): ?>
            <th>Filial</th>
            <?php endif; ?>
            <th>Líder</th>
            <th>Próx. atividades</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ministries as $mn): ?>
            <tr>
              <td>
                <div style="font-weight:500"><?= htmlspecialchars($mn['name']) ?></div>
                <?php if ($mn['description']): ?>
                  <div style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars(mb_substr($mn['description'],0,60)) ?>…</div>
                <?php endif; ?>
              </td>
              <?php if ($isAdmin && $churchId === SEDE_ID): ?>
              <td>
                <span class="badge <?= ($mn['branch_type']??'')==='sede'?'badge-blue':'badge-green' ?>" style="font-size:11px">
                  <?= htmlspecialchars($mn['branch_name'] ?? '—') ?>
                </span>
              </td>
              <?php endif; ?>
              <td>
                <?php if ($mn['leader_name']): ?>
                  <div style="display:flex;align-items:center;gap:8px">
                    <div class="avatar" style="width:28px;height:28px;font-size:10px">
                      <?= strtoupper(substr($mn['leader_name'],0,2)) ?>
                    </div>
                    <?= htmlspecialchars($mn['leader_name']) ?>
                  </div>
                <?php else: ?>
                  <span style="color:var(--text-muted)">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($mn['activity_count'] > 0): ?>
                  <span class="badge badge-blue"><?= $mn['activity_count'] ?> agendada(s)</span>
                <?php else: ?>
                  <span style="color:var(--text-muted);font-size:12px">Nenhuma</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge <?= $mn['active'] ? 'badge-green' : 'badge-gray' ?>">
                  <?= $mn['active'] ? 'Ativo' : 'Inativo' ?>
                </span>
              </td>
              <td style="text-align:right">
                <a href="/pages/ministries/view.php?id=<?= $mn['id'] ?>" class="btn btn-secondary" style="font-size:12px;padding:5px 12px">Ver</a>
                <?php if ($canCreateMinistry || auth_can_manage_ministry((int)$mn['id'])): ?>
                  <a href="/pages/ministries/edit.php?id=<?= $mn['id'] ?>" class="btn btn-secondary" style="font-size:12px;padding:5px 12px">Editar</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
