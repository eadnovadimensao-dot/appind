<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/export_helpers.php';
auth_require('manage_finance');

$db       = db();
$churchId = current_church_id();
$month    = (int)($_GET['month'] ?? date('n'));
$year     = (int)($_GET['year']  ?? date('Y'));
$monthNames = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];

$titheId = $db->query("SELECT id FROM finance_categories WHERE church_id=$churchId AND name='Dízimos' LIMIT 1")->fetchColumn();

$membersStmt = $db->prepare("
    SELECT m.id, m.name, ch.name AS branch_name
    FROM members m
    JOIN churches ch ON ch.id = m.church_id
    WHERE (ch.id = ? OR ch.parent_id = ?) AND m.status = 'active'
    ORDER BY ch.name, m.name
");
$membersStmt->execute([SEDE_ID, SEDE_ID]);
$members = $membersStmt->fetchAll();

$tithersThisMonth = $db->prepare("SELECT member_id, SUM(amount) AS total FROM finance_entries WHERE church_id=? AND category_id=? AND MONTH(entry_date)=? AND YEAR(entry_date)=? GROUP BY member_id");
$tithersThisMonth->execute([$churchId, $titheId, $month, $year]);
$tithersMap = [];
foreach ($tithersThisMonth->fetchAll() as $t) { $tithersMap[$t['member_id']] = $t['total']; }

$tithers = count($tithersMap);
$missing = count($members) - $tithers;

print_page_start('Lista de Dizimistas', $monthNames[$month] . ' ' . $year . ' · ' . $tithers . ' dizimaram, ' . $missing . ' pendentes', $churchId);
?>
<table>
  <thead><tr><th>Membro</th><th>Filial</th><th>Status</th><th style="text-align:right">Valor</th></tr></thead>
  <tbody>
    <?php foreach ($members as $m):
      $dizimou = isset($tithersMap[$m['id']]);
      $valor   = $tithersMap[$m['id']] ?? 0;
    ?>
      <tr>
        <td style="font-weight:500"><?= htmlspecialchars($m['name']) ?></td>
        <td><?= htmlspecialchars($m['branch_name'] ?? '—') ?></td>
        <td><?= $dizimou ? '✓ Dizimou' : 'Pendente' ?></td>
        <td style="text-align:right;color:<?= $dizimou ? '#0F6E56' : '#9ca3af' ?>"><?= $dizimou ? 'R$ ' . number_format($valor,2,',','.') : '—' ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php print_page_end(); ?>
