<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/export_helpers.php';
auth_require('manage_members');

$db       = db();
$churchId = current_church_id();

$search = trim($_GET['q']      ?? '');
$status = trim($_GET['status'] ?? '');

// Só a igreja selecionada
$where  = ['m.church_id = :church_id'];
$params = [':church_id' => $churchId];
if ($search !== '') { $where[] = '(m.name LIKE :q OR m.phone LIKE :q OR m.email LIKE :q)'; $params[':q'] = "%$search%"; }
if ($status !== '') { $where[] = 'm.status = :status'; $params[':status'] = $status; }

$stmt = $db->prepare("
    SELECT m.name, m.phone, m.email, m.status, c.name AS cell_name, ch.name AS branch_name
    FROM members m
    LEFT JOIN cells c     ON c.id = m.cell_id
    LEFT JOIN churches ch ON ch.id = m.church_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY ch.name ASC, m.name ASC
");
$stmt->execute($params);
$members = $stmt->fetchAll();

$statusLabels = [
    'active' => 'Ativo', 'visitor' => 'Visitante', 'inactive' => 'Afastado',
    'discipline' => 'Disciplina', 'transferred' => 'Transferido', 'deceased' => 'Falecido',
];

$subtitle = count($members) . ' membro(s)' . ($status !== '' ? ' · ' . ($statusLabels[$status] ?? $status) : '') . ($search !== '' ? ' · busca: "' . $search . '"' : '');
print_page_start('Lista de Membros', $subtitle, $churchId);
?>
<table>
  <thead>
    <tr><th>Nome</th><th>Telefone</th><th>E-mail</th><th>Célula</th><th>Filial</th><th>Status</th></tr>
  </thead>
  <tbody>
    <?php foreach ($members as $m): ?>
      <tr>
        <td style="font-weight:500"><?= htmlspecialchars($m['name']) ?></td>
        <td><?= htmlspecialchars($m['phone'] ?? '—') ?></td>
        <td><?= htmlspecialchars($m['email'] ?? '—') ?></td>
        <td><?= htmlspecialchars($m['cell_name'] ?? '—') ?></td>
        <td><?= htmlspecialchars($m['branch_name'] ?? '—') ?></td>
        <td><?= htmlspecialchars($statusLabels[$m['status']] ?? $m['status']) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php print_page_end(); ?>
