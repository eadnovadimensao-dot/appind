<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
$pageTitle    = 'Lançamentos';
$activePage   = 'finance';
$topbarAction = ['href' => '/pages/finance/create.php', 'label' => 'Novo lançamento'];
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db       = db();
$churchId = current_church_id();

$month      = (int)($_GET['month']       ?? date('n'));
$year       = (int)($_GET['year']        ?? date('Y'));
$type       = $_GET['type']              ?? '';
$categoryId = (int)($_GET['category_id'] ?? 0);

$where  = ['fe.church_id = :church_id', 'MONTH(fe.entry_date)=:month', 'YEAR(fe.entry_date)=:year'];
$params = [':church_id'=>$churchId, ':month'=>$month, ':year'=>$year];

if ($type) { $where[] = 'fe.type=:type'; $params[':type'] = $type; }
if ($categoryId) { $where[] = 'fe.category_id=:cat'; $params[':cat'] = $categoryId; }

$entries = $db->prepare("
    SELECT fe.*, fc.name AS cat_name, fc.color, m.name AS member_name
    FROM finance_entries fe
    LEFT JOIN finance_categories fc ON fc.id = fe.category_id
    LEFT JOIN members m ON m.id = fe.member_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY fe.entry_date DESC, fe.id DESC
");
$entries->execute($params);
$entries = $entries->fetchAll();

$categories = $db->query("SELECT * FROM finance_categories WHERE church_id=$churchId AND active=1 ORDER BY type,name")->fetchAll();
$monthNames = ['','Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
?>

<!-- Filtros -->
<div style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;align-items:center">
  <select onchange="location='?month='+this.value.split('-')[0]+'&year='+this.value.split('-')[1]+'&type=<?= $type ?>&category_id=<?= $categoryId ?>'" class="form-control" style="width:auto">
    <?php for ($m=1;$m<=12;$m++): ?>
      <option value="<?= $m ?>-<?= $year ?>" <?= $m==$month?'selected':''?>><?= $monthNames[$m] ?> <?= $year ?></option>
    <?php endfor; ?>
  </select>
  <a href="?month=<?= $month ?>&year=<?= $year ?>" class="btn <?= !$type?'btn-primary':'btn-secondary' ?>" style="font-size:12px">Todos</a>
  <a href="?month=<?= $month ?>&year=<?= $year ?>&type=income" class="btn <?= $type==='income'?'btn-primary':'btn-secondary' ?>" style="font-size:12px;color:<?= $type==='income'?'white':'var(--green)' ?>">⬆ Entradas</a>
  <a href="?month=<?= $month ?>&year=<?= $year ?>&type=expense" class="btn <?= $type==='expense'?'btn-primary':'btn-secondary' ?>" style="font-size:12px;color:<?= $type==='expense'?'white':'var(--red)' ?>">⬇ Saídas</a>
  <select onchange="location='?month=<?= $month ?>&year=<?= $year ?>&type=<?= $type ?>&category_id='+this.value" class="form-control" style="width:auto">
    <option value="0">Todas as categorias</option>
    <?php foreach ($categories as $c): ?>
      <option value="<?= $c['id'] ?>" <?= $categoryId==$c['id']?'selected':''?>><?= htmlspecialchars($c['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <span style="margin-left:auto;font-size:13px;color:var(--text-muted)"><?= count($entries) ?> lançamentos</span>
</div>

<div class="card" style="padding:0">
  <?php if (empty($entries)): ?>
    <div class="empty-state" style="padding:40px">
      <p style="font-size:28px;margin-bottom:8px">💰</p>
      <p>Nenhum lançamento encontrado.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Data</th><th>Descrição</th><th>Categoria</th><th>Membro</th><th>Método</th><th style="text-align:right">Valor</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($entries as $e): ?>
            <tr>
              <td style="white-space:nowrap;color:var(--text-muted)"><?= date('d/m/Y', strtotime($e['entry_date'])) ?></td>
              <td>
                <div style="font-weight:500"><?= htmlspecialchars($e['description'] ?? $e['cat_name'] ?? '—') ?></div>
                <?php if ($e['campaign_name']): ?>
                  <div style="font-size:11px;color:var(--accent)">🎯 <?= htmlspecialchars($e['campaign_name']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <span style="display:inline-flex;align-items:center;gap:4px;font-size:12px">
                  <span style="width:8px;height:8px;border-radius:50%;background:<?= $e['color'] ?? '#ccc' ?>;display:inline-block"></span>
                  <?= htmlspecialchars($e['cat_name'] ?? '—') ?>
                </span>
              </td>
              <td style="color:var(--text-muted);font-size:13px"><?= htmlspecialchars($e['member_name'] ?? '—') ?></td>
              <td style="color:var(--text-muted);font-size:12px"><?= htmlspecialchars($e['payment_method'] ?? '—') ?></td>
              <td style="text-align:right;font-weight:500;white-space:nowrap;color:<?= $e['type']==='income'?'var(--green)':'var(--red)' ?>">
                <?= $e['type']==='income'?'+':'-' ?> R$ <?= number_format($e['amount'],2,',','.') ?>
              </td>
              <td style="text-align:right">
                <a href="/pages/finance/delete.php?id=<?= $e['id'] ?>"
                   class="btn btn-secondary" style="font-size:12px;padding:5px 10px;color:var(--red)"
                   data-confirm="Excluir este lançamento?">✕</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
