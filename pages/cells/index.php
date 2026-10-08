<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

// A listagem completa (todas as células da igreja) é só pra quem administra
// células de verdade. Os demais papéis (inclusive líder/líder de célula sem
// relação com nenhuma célula) vão pra "Minhas células".
if (!auth_can('manage_cells')) {
    header('Location: /pages/cells/my_cells.php');
    exit;
}

$pageTitle    = 'Células';
$activePage   = 'cells';
$canManageCells = true;
$topbarAction = ['href' => '/pages/cells/create.php', 'label' => 'Nova célula'];
require_once __DIR__ . '/../../includes/layout.php';

$db       = db();
$churchId = current_church_id();

$search = trim($_GET['q'] ?? '');
$where  = ['c.church_id = :church_id'];
$params = [':church_id' => $churchId];

if ($search !== '') {
    $where[]      = '(c.name LIKE :q OR m.name LIKE :q)';
    $params[':q'] = "%$search%";
}

$cells = $db->prepare("
    SELECT c.*,
           m.name AS leader_name,
           COUNT(mb.id) AS member_count
    FROM cells c
    LEFT JOIN members m  ON m.id = c.leader_id
    LEFT JOIN members mb ON mb.cell_id = c.id AND mb.status = 'active'
    WHERE " . implode(' AND ', $where) . "
    GROUP BY c.id
    ORDER BY c.name ASC
");
$cells->execute($params);
$cells = $cells->fetchAll();

$days = ['monday'=>'Segunda','tuesday'=>'Terça','wednesday'=>'Quarta',
         'thursday'=>'Quinta','friday'=>'Sexta','saturday'=>'Sábado','sunday'=>'Domingo'];
?>

<form method="GET" style="display:flex;gap:10px;margin-bottom:20px">
  <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
         placeholder="Buscar por nome ou líder…"
         class="form-control" style="max-width:360px">
  <button type="submit" class="btn btn-secondary">Buscar</button>
  <?php if ($search): ?>
    <a href="/pages/cells/index.php" class="btn btn-secondary">Limpar</a>
  <?php endif; ?>
</form>

<div class="card" style="padding:0">
  <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
    <span style="font-size:13px;color:var(--text-muted)"><?= count($cells) ?> célula(s)</span>
  </div>

  <?php if (empty($cells)): ?>
    <div class="empty-state">
      <p style="font-size:32px;margin-bottom:8px">🔗</p>
      <p>Nenhuma célula cadastrada ainda.</p>
      <?php if ($canManageCells): ?>
        <a href="/pages/cells/create.php" class="btn btn-primary" style="margin-top:16px">+ Criar primeira célula</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <?php foreach ($cells as $c): ?>
      <div style="padding:12px 20px;border-bottom:1px solid var(--border)">
        <div style="font-size:14px;font-weight:500">
          <?= htmlspecialchars($c['name']) ?>
          <span class="badge <?= $c['active'] ? 'badge-green' : 'badge-gray' ?>" style="font-size:10px">
            <?= $c['active'] ? 'Ativa' : 'Inativa' ?>
          </span>
        </div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:6px;display:flex;align-items:center;gap:6px;flex-wrap:wrap">
          <?php if ($c['leader_name']): ?>
            <span style="display:flex;align-items:center;gap:5px">
              <span class="avatar" style="width:20px;height:20px;font-size:9px">
                <?= strtoupper(substr($c['leader_name'], 0, 2)) ?>
              </span>
              <?= htmlspecialchars($c['leader_name']) ?>
            </span>
          <?php else: ?>
            <span>Sem líder definido</span>
          <?php endif; ?>
          <span>·</span>
          <span>
            <?= $c['day_of_week'] ? ($days[$c['day_of_week']] ?? $c['day_of_week']) : 'Sem dia definido' ?>
            <?= $c['time_start'] ? ' · ' . substr($c['time_start'], 0, 5) : '' ?>
          </span>
          <span>·</span>
          <span><?= $c['member_count'] ?> membro(s)</span>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
          <a href="/pages/cells/view.php?id=<?= $c['id'] ?>" class="btn btn-secondary" style="font-size:12px;padding:5px 12px">Ver</a>
          <?php if ($canManageCells || auth_can_manage_cell((int)$c['id'])): ?>
            <a href="/pages/cells/edit.php?id=<?= $c['id'] ?>" class="btn btn-secondary" style="font-size:12px;padding:5px 12px">Editar</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
