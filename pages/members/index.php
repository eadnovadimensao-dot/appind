<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
$pageTitle    = 'Membros';
$activePage   = 'members';
$topbarAction = ['href' => '/pages/members/create.php', 'label' => 'Novo membro'];
require_once __DIR__ . '/../../includes/layout.php';

$db       = db();
$churchId = current_church_id();

// Filtros
$search   = trim($_GET['q']         ?? '');
$status   = trim($_GET['status']    ?? '');

// Supermaster na sede vê todos; outros veem só a sua filial
if ($churchId === SEDE_ID && auth_role() === 'supermaster') {
    $where  = ['(m.church_id = :church_id OR ch.parent_id = :church_id)'];
    $params = [':church_id' => SEDE_ID];
} else {
    $where  = ['m.church_id = :church_id'];
    $params = [':church_id' => $churchId];
}

if ($search !== '') {
    $where[]          = '(m.name LIKE :q OR m.phone LIKE :q OR m.email LIKE :q)';
    $params[':q']     = "%$search%";
}
if ($status !== '') {
    $where[]           = 'm.status = :status';
    $params[':status'] = $status;
}

$sql = 'SELECT m.*, c.name AS cell_name, ch.name AS branch_name, ch.type AS branch_type
        FROM members m
        LEFT JOIN cells c    ON c.id = m.cell_id
        LEFT JOIN churches ch ON ch.id = m.church_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY ch.name ASC, m.name ASC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll();

$statusLabels = [
    'active'     => ['label' => 'Ativo',        'badge' => 'badge-green'],
    'visitor'    => ['label' => 'Visitante',     'badge' => 'badge-blue'],
    'inactive'   => ['label' => 'Afastado',      'badge' => 'badge-gray'],
    'discipline' => ['label' => 'Em disciplina', 'badge' => 'badge-amber'],
    'transferred'=> ['label' => 'Transferido',   'badge' => 'badge-gray'],
    'deceased'   => ['label' => 'Falecido',      'badge' => 'badge-red'],
];
?>

<!-- Filtros -->
<form method="GET" style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap">
  <input
    type="text"
    name="q"
    value="<?= htmlspecialchars($search) ?>"
    placeholder="Buscar por nome, telefone ou e-mail…"
    class="form-control"
    style="flex:1;min-width:220px;max-width:400px"
  >
  <select name="status" class="form-control" style="width:160px" onchange="this.form.submit()">
    <option value="">Todos os status</option>
    <?php foreach ($statusLabels as $key => $s): ?>
      <option value="<?= $key ?>" <?= $status === $key ? 'selected' : '' ?>>
        <?= $s['label'] ?>
      </option>
    <?php endforeach; ?>
  </select>
  <?php if (auth_role() === 'supermaster' && $churchId === SEDE_ID):
    $branchId = (int)($_GET['branch_id'] ?? 0);
  ?>
  <select name="branch_id" class="form-control" style="width:180px" onchange="this.form.submit()">
    <option value="0">Todas as filiais</option>
    <?php foreach (get_branches() as $b): ?>
      <option value="<?= $b['id'] ?>" <?= $branchId==$b['id']?'selected':''?>>
        <?= htmlspecialchars($b['name']) ?> <?= $b['type']==='sede'?'(Sede)':'' ?>
      </option>
    <?php endforeach; ?>
  </select>
  <?php
    // Aplicar filtro de filial se selecionado
    if ($branchId) {
        $where  = ['m.church_id = :church_id'];
        $params = [':church_id' => $branchId];
        if ($search !== '') { $where[] = '(m.name LIKE :q OR m.phone LIKE :q OR m.email LIKE :q)'; $params[':q'] = "%$search%"; }
        if ($status !== '') { $where[] = 'm.status = :status'; $params[':status'] = $status; }
        $sql = 'SELECT m.*, c.name AS cell_name, ch.name AS branch_name, ch.type AS branch_type
                FROM members m
                LEFT JOIN cells c ON c.id = m.cell_id
                LEFT JOIN churches ch ON ch.id = m.church_id
                WHERE ' . implode(' AND ', $where) . ' ORDER BY m.name ASC';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $members = $stmt->fetchAll();
    }
  ?>
  <?php endif; ?>
  <button type="submit" class="btn btn-secondary">Buscar</button>
  <?php if ($search || $status): ?>
    <a href="/pages/members/index.php" class="btn btn-secondary">Limpar</a>
  <?php endif; ?>
</form>

<!-- Tabela -->
<div class="card" style="padding:0">
  <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
    <span style="font-size:13px;color:var(--text-muted)"><?= count($members) ?> membro(s) encontrado(s)</span>
  </div>

  <?php if (empty($members)): ?>
    <div class="empty-state">
      <p style="font-size:32px;margin-bottom:8px">👥</p>
      <p>Nenhum membro encontrado.</p>
      <a href="/pages/members/create.php" class="btn btn-primary" style="margin-top:16px">+ Cadastrar primeiro membro</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Nome</th>
            <th>Filial</th>
            <th>Telefone</th>
            <th>Célula</th>
            <th>Status</th>
            <th>Ingresso</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($members as $m):
            $initials = strtoupper(implode('', array_map(fn($p) => $p[0], array_slice(explode(' ', $m['name']), 0, 2))));
            $st = $statusLabels[$m['status']] ?? ['label' => $m['status'], 'badge' => 'badge-gray'];
          ?>
            <tr>
              <td>
                <div style="display:flex;align-items:center;gap:10px">
                  <div class="avatar"><?= $initials ?></div>
                  <div>
                    <div style="font-weight:500"><?= htmlspecialchars($m['name']) ?></div>
                    <?php if ($m['email']): ?>
                      <div style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($m['email']) ?></div>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
              <td>
                <span class="badge <?= $m['branch_type']==='sede'?'badge-blue':'badge-green' ?>" style="font-size:10px">
                  <?= htmlspecialchars($m['branch_name'] ?? '—') ?>
                </span>
              </td>
              <td><?= htmlspecialchars($m['phone'] ?? '—') ?></td>
              <td><?= htmlspecialchars($m['cell_name'] ?? '—') ?></td>
              <td><span class="badge <?= $st['badge'] ?>"><?= $st['label'] ?></span></td>
              <td style="color:var(--text-muted);font-size:13px">
                <?= $m['join_date'] ? date('d/m/Y', strtotime($m['join_date'])) : '—' ?>
              </td>
              <td style="text-align:right">
                <a href="/pages/members/view.php?id=<?= $m['id'] ?>" class="btn btn-secondary" style="font-size:12px;padding:5px 12px">Ver</a>
                <a href="/pages/members/edit.php?id=<?= $m['id'] ?>" class="btn btn-secondary" style="font-size:12px;padding:5px 12px">Editar</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
