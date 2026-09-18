<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
auth_check();

$db         = db();
$ministryId = (int)($_GET['ministry_id'] ?? 0);

// Busca o ministério em qualquer filial da sede (antes travava em CHURCH_ID,
// sempre a sede, então ministério de filial nunca era encontrado)
$stmt = $db->prepare("
    SELECT mn.*
    FROM ministries mn
    JOIN churches ch ON ch.id = mn.church_id
    WHERE mn.id = ? AND ch.id = ?
");
$stmt->execute([$ministryId, current_church_id()]);
$mn = $stmt->fetch();
if (!$mn) { header('Location: /pages/ministries/index.php'); exit; }

$pageTitle  = 'Pertences · ' . $mn['name'];
$activePage = 'ministries';
require_once __DIR__ . '/../../includes/layout.php';

// Itens do ministério com contagem de empréstimos ativos
$items = $db->prepare("
    SELECT mi.*,
           COUNT(CASE WHEN ml.status IN ('approved') THEN 1 END) AS in_use,
           COUNT(CASE WHEN ml.status = 'pending' THEN 1 END) AS pending_loans
    FROM ministry_items mi
    LEFT JOIN ministry_loans ml ON ml.item_id = mi.id
    WHERE mi.ministry_id = ? AND mi.active = 1
    GROUP BY mi.id
    ORDER BY mi.category, mi.name
");
$items->execute([$ministryId]);
$items = $items->fetchAll();

// Empréstimos pendentes de aprovação
$pending = $db->prepare("
    SELECT ml.*, mi.name AS item_name, m.name AS member_name
    FROM ministry_loans ml
    JOIN ministry_items mi ON mi.id = ml.item_id
    JOIN members m         ON m.id  = ml.member_id
    WHERE ml.ministry_id = ? AND ml.status = 'pending'
    ORDER BY ml.created_at ASC
");
$pending->execute([$ministryId]);
$pending = $pending->fetchAll();

// Empréstimos ativos (aprovados, não devolvidos)
$active = $db->prepare("
    SELECT ml.*, mi.name AS item_name, m.name AS member_name, m2.name AS approved_name
    FROM ministry_loans ml
    JOIN ministry_items mi ON mi.id  = ml.item_id
    JOIN members m         ON m.id   = ml.member_id
    LEFT JOIN members m2   ON m2.id  = ml.approved_by
    WHERE ml.ministry_id = ? AND ml.status = 'approved'
    ORDER BY ml.approved_at DESC
");
$active->execute([$ministryId]);
$active = $active->fetchAll();

$categories = ['roupa'=>'👗 Roupa','instrumento'=>'🎸 Instrumento',
               'equipamento'=>'🔧 Equipamento','acessório'=>'💍 Acessório','outro'=>'📦 Outro'];
$conditions = ['good'=>['label'=>'Bom','badge'=>'badge-green'],
               'fair'=>['label'=>'Regular','badge'=>'badge-amber'],
               'poor'=>['label'=>'Ruim','badge'=>'badge-red']];

$canManage = auth_can_manage_ministry($ministryId);
?>

<div style="margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
  <a href="/pages/ministries/view.php?id=<?= $ministryId ?>" style="font-size:13px;color:var(--text-muted);text-decoration:none">
    ← <?= htmlspecialchars($mn['name']) ?>
  </a>
  <div style="display:flex;gap:8px">
    <?php if ($canManage): ?>
      <a href="/pages/ministries/item_create.php?ministry_id=<?= $ministryId ?>" class="btn btn-secondary">+ Cadastrar item</a>
    <?php endif; ?>
    <a href="/pages/ministries/loan_create.php?ministry_id=<?= $ministryId ?>" class="btn btn-primary">+ Solicitar empréstimo</a>
  </div>
</div>

<!-- Pendentes de aprovação -->
<?php if (!empty($pending)): ?>
<div style="background:#FEF3C7;border:1px solid #FCD34D;border-radius:10px;padding:14px 18px;margin-bottom:20px">
  <p style="font-size:13px;font-weight:500;color:#854F0B;margin-bottom:10px">
    ⏳ <?= count($pending) ?> solicitação(ões) aguardando aprovação
  </p>
  <div style="display:flex;flex-direction:column;gap:8px">
    <?php foreach ($pending as $p): ?>
      <div style="background:white;border-radius:8px;padding:12px 16px;display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px">
        <div>
          <div style="font-size:13px;font-weight:500"><?= htmlspecialchars($p['member_name']) ?> →
            <span style="color:var(--accent)"><?= htmlspecialchars($p['item_name']) ?></span>
          </div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:3px">
            Motivo: <?= htmlspecialchars($p['reason']) ?>
          </div>
          <div style="font-size:11px;color:var(--text-muted)">
            Solicitado em <?= date('d/m/Y \à\s H:i', strtotime($p['created_at'])) ?>
          </div>
        </div>
        <?php if ($canManage): ?>
          <div style="display:flex;gap:6px">
            <a href="/pages/ministries/loan_approve.php?id=<?= $p['id'] ?>&action=approve"
               class="btn btn-primary" style="font-size:12px;padding:5px 12px"
               data-confirm="Aprovar este empréstimo?">✓ Aprovar</a>
            <a href="/pages/ministries/loan_refuse.php?id=<?= $p['id'] ?>"
               class="btn btn-secondary" style="font-size:12px;padding:5px 12px;color:var(--red)">✗ Recusar</a>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

  <!-- Itens cadastrados -->
  <div class="card" style="padding:0">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
      <p style="font-weight:500;font-size:14px">Itens <span style="color:var(--text-muted);font-weight:400">(<?= count($items) ?>)</span></p>
    </div>
    <?php if (empty($items)): ?>
      <div class="empty-state" style="padding:24px">
        <p>Nenhum item cadastrado.</p>
        <?php if ($canManage): ?>
          <a href="/pages/ministries/item_create.php?ministry_id=<?= $ministryId ?>" class="btn btn-primary" style="margin-top:12px">+ Cadastrar item</a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <?php foreach ($items as $item):
        $cond = $conditions[$item['condition_status']] ?? ['label'=>'—','badge'=>'badge-gray'];
        $cat  = $categories[$item['category']] ?? '📦 ' . ($item['category'] ?? 'Outro');
      ?>
        <div style="padding:12px 18px;border-bottom:1px solid var(--border)">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px">
            <div style="flex:1">
              <div style="font-size:13px;font-weight:500"><?= htmlspecialchars($item['name']) ?></div>
              <div style="font-size:11px;color:var(--text-muted);margin-top:2px"><?= $cat ?></div>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px">
              <span class="badge <?= $cond['badge'] ?>"><?= $cond['label'] ?></span>
              <?php if ($item['in_use'] > 0): ?>
                <span class="badge badge-amber">Em uso</span>
              <?php elseif ($item['pending_loans'] > 0): ?>
                <span class="badge badge-blue">Solicitado</span>
              <?php else: ?>
                <span class="badge badge-green">Disponível</span>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($item['description']): ?>
            <div style="font-size:12px;color:var(--text-muted);margin-top:4px"><?= htmlspecialchars($item['description']) ?></div>
          <?php endif; ?>
          <?php if ($canManage): ?>
            <div style="margin-top:8px;display:flex;gap:6px">
              <a href="/pages/ministries/item_edit.php?id=<?= $item['id'] ?>" style="font-size:11px;color:var(--accent);text-decoration:none">Editar</a>
              <a href="/pages/ministries/loan_history.php?item_id=<?= $item['id'] ?>&ministry_id=<?= $ministryId ?>" style="font-size:11px;color:var(--text-muted);text-decoration:none">Histórico</a>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Empréstimos ativos -->
  <div class="card" style="padding:0">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
      <p style="font-weight:500;font-size:14px">Em uso agora <span style="color:var(--text-muted);font-weight:400">(<?= count($active) ?>)</span></p>
    </div>
    <?php if (empty($active)): ?>
      <div class="empty-state" style="padding:24px">Nenhum item em uso no momento.</div>
    <?php else: ?>
      <?php foreach ($active as $loan): ?>
        <div style="padding:12px 18px;border-bottom:1px solid var(--border)">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px">
            <div style="flex:1">
              <div style="font-size:13px;font-weight:500"><?= htmlspecialchars($loan['item_name']) ?></div>
              <div style="font-size:12px;color:var(--text-muted);margin-top:2px">
                👤 <?= htmlspecialchars($loan['member_name']) ?>
              </div>
              <div style="font-size:11px;color:var(--text-muted)">
                Aprovado por <?= htmlspecialchars($loan['approved_name'] ?? '—') ?>
                em <?= date('d/m/Y', strtotime($loan['approved_at'])) ?>
              </div>
              <div style="font-size:12px;color:var(--text-muted);margin-top:2px;font-style:italic">
                "<?= htmlspecialchars($loan['reason']) ?>"
              </div>
            </div>
            <?php if ($canManage): ?>
              <a href="/pages/ministries/loan_return.php?id=<?= $loan['id'] ?>"
                 class="btn btn-secondary" style="font-size:12px;padding:5px 12px;flex-shrink:0"
                 data-confirm="Confirmar devolução deste item?">
                ↩ Devolvido
              </a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
