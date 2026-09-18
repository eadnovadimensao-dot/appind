<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
auth_check();

$db         = db();
$itemId     = (int)($_GET['item_id']     ?? 0);
$ministryId = (int)($_GET['ministry_id'] ?? 0);

// Busca o item em qualquer filial da sede (igual ao resto do app) — antes
// travava em CHURCH_ID (sempre 1, a sede), então item de filial nunca era
// encontrado e $item ficava sem as colunas esperadas.
$item = $db->prepare("
    SELECT mi.*, mn.name AS ministry_name
    FROM ministry_items mi
    JOIN ministries mn ON mn.id = mi.ministry_id
    JOIN churches ch    ON ch.id = mi.church_id
    WHERE mi.id = ? AND ch.id = ?
");
$item->execute([$itemId, current_church_id()]);
$item = $item->fetch();
if (!$item) { header('Location: /pages/ministries/index.php'); exit; }

$pageTitle  = 'Histórico · ' . $item['name'];
$activePage = 'ministries';
require_once __DIR__ . '/../../includes/layout.php';

$loans = $db->prepare("
    SELECT ml.*, m.name AS member_name, m2.name AS approved_name, m3.name AS return_confirmed_name
    FROM ministry_loans ml
    JOIN members m         ON m.id  = ml.member_id
    LEFT JOIN members m2   ON m2.id = ml.approved_by
    LEFT JOIN members m3   ON m3.id = ml.return_confirmed_by
    WHERE ml.item_id = ?
    ORDER BY ml.created_at DESC
");
$loans->execute([$itemId]);
$loans = $loans->fetchAll();

$statusLabels = [
    'pending'   => ['label'=>'Pendente',   'badge'=>'badge-amber'],
    'approved'  => ['label'=>'Em uso',     'badge'=>'badge-blue'],
    'refused'   => ['label'=>'Recusado',   'badge'=>'badge-red'],
    'returned'  => ['label'=>'Devolvido',  'badge'=>'badge-green'],
    'cancelled' => ['label'=>'Cancelado',  'badge'=>'badge-gray'],
];
?>
<div style="margin-bottom:16px">
  <a href="/pages/ministries/items.php?ministry_id=<?= $ministryId ?>" style="font-size:13px;color:var(--text-muted);text-decoration:none">
    ← Pertences · <?= htmlspecialchars($item['ministry_name']) ?>
  </a>
</div>
<div class="card" style="padding:0">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
    <p style="font-weight:500;font-size:14px">Histórico de empréstimos · <?= htmlspecialchars($item['name']) ?></p>
  </div>
  <?php if (empty($loans)): ?>
    <div class="empty-state" style="padding:24px">Nenhum empréstimo registrado.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Membro</th><th>Motivo</th><th>Solicitado</th><th>Aprovado por</th><th>Devolvido</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php foreach ($loans as $l):
            $st = $statusLabels[$l['status']] ?? ['label'=>$l['status'],'badge'=>'badge-gray'];
          ?>
            <tr>
              <td style="font-weight:500"><?= htmlspecialchars($l['member_name']) ?></td>
              <td style="color:var(--text-muted);font-size:12px;max-width:200px">
                <?= htmlspecialchars($l['reason']) ?>
                <?php if ($l['refused_reason']): ?>
                  <div style="color:var(--red);margin-top:2px">Recusa: <?= htmlspecialchars($l['refused_reason']) ?></div>
                <?php endif; ?>
              </td>
              <td style="color:var(--text-muted);font-size:12px"><?= date('d/m/Y H:i', strtotime($l['created_at'])) ?></td>
              <td style="color:var(--text-muted);font-size:12px"><?= htmlspecialchars($l['approved_name'] ?? '—') ?></td>
              <td style="color:var(--text-muted);font-size:12px">
                <?= $l['returned_at'] ? date('d/m/Y', strtotime($l['returned_at'])) : '—' ?>
                <?= $l['return_confirmed_name'] ? '<br><span style="font-size:11px">por ' . htmlspecialchars($l['return_confirmed_name']) . '</span>' : '' ?>
              </td>
              <td><span class="badge <?= $st['badge'] ?>"><?= $st['label'] ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
