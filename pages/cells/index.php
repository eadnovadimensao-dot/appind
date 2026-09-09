<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
$pageTitle    = 'Células';
$activePage   = 'cells';
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
      <a href="/pages/cells/create.php" class="btn btn-primary" style="margin-top:16px">+ Criar primeira célula</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Nome</th>
            <th>Líder</th>
            <th>Dia / Horário</th>
            <th>Membros</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cells as $c): ?>
            <tr>
              <td style="font-weight:500"><?= htmlspecialchars($c['name']) ?></td>
              <td>
                <?php if ($c['leader_name']): ?>
                  <div style="display:flex;align-items:center;gap:8px">
                    <div class="avatar" style="width:28px;height:28px;font-size:10px">
                      <?= strtoupper(substr($c['leader_name'], 0, 2)) ?>
                    </div>
                    <?= htmlspecialchars($c['leader_name']) ?>
                  </div>
                <?php else: ?>
                  <span style="color:var(--text-muted)">—</span>
                <?php endif; ?>
              </td>
              <td style="color:var(--text-muted);font-size:13px">
                <?= $c['day_of_week'] ? ($days[$c['day_of_week']] ?? $c['day_of_week']) : '—' ?>
                <?= $c['time_start'] ? ' · ' . substr($c['time_start'], 0, 5) : '' ?>
              </td>
              <td>
                <span style="font-weight:500"><?= $c['member_count'] ?></span>
                <span style="color:var(--text-muted);font-size:12px"> membros</span>
              </td>
              <td>
                <span class="badge <?= $c['active'] ? 'badge-green' : 'badge-gray' ?>">
                  <?= $c['active'] ? 'Ativa' : 'Inativa' ?>
                </span>
              </td>
              <td style="text-align:right">
                <a href="/pages/cells/view.php?id=<?= $c['id'] ?>" class="btn btn-secondary" style="font-size:12px;padding:5px 12px">Ver</a>
                <a href="/pages/cells/edit.php?id=<?= $c['id'] ?>" class="btn btn-secondary" style="font-size:12px;padding:5px 12px">Editar</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
