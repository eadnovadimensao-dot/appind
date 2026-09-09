<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
auth_check();
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db         = db();
$churchId   = CHURCH_ID;
$ministryId = (int)($_GET['ministry_id'] ?? 0);
$itemId     = (int)($_GET['item_id']     ?? 0);
$errors     = [];

$mn = $db->prepare("SELECT * FROM ministries WHERE id = ? AND church_id = ?");
$mn->execute([$ministryId, $churchId]);
$mn = $mn->fetch();
if (!$mn) { header('Location: /pages/ministries/index.php'); exit; }

$pageTitle = 'Solicitar empréstimo · ' . $mn['name'];

// Itens disponíveis
$items = $db->prepare("
    SELECT mi.*,
           COUNT(CASE WHEN ml.status = 'approved' THEN 1 END) AS in_use
    FROM ministry_items mi
    LEFT JOIN ministry_loans ml ON ml.item_id = mi.id
    WHERE mi.ministry_id = ? AND mi.active = 1
    GROUP BY mi.id
    HAVING in_use < mi.quantity
    ORDER BY mi.category, mi.name
");
$items->execute([$ministryId]);
$items = $items->fetchAll();

// Membro logado
$memberId = auth_member_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedItemId = (int)($_POST['item_id'] ?? 0);
    $reason         = trim($_POST['reason']   ?? '');

    if (!$selectedItemId)  $errors[] = 'Selecione um item.';
    if ($reason === '')    $errors[] = 'Informe o motivo do empréstimo.';

    // Verificar se já tem empréstimo ativo deste item
    if (empty($errors) && $memberId) {
        $existing = $db->prepare("SELECT id FROM ministry_loans WHERE item_id=? AND member_id=? AND status IN ('pending','approved')");
        $existing->execute([$selectedItemId, $memberId]);
        if ($existing->fetch()) $errors[] = 'Você já possui uma solicitação ativa para este item.';
    }

    if (empty($errors)) {
        $db->prepare("
            INSERT INTO ministry_loans (item_id, ministry_id, church_id, member_id, reason, status)
            VALUES (?, ?, ?, ?, ?, 'pending')
        ")->execute([$selectedItemId, $ministryId, $churchId, $memberId, $reason]);

        // Notificar líderes do ministério
        $itemName   = $db->query("SELECT name FROM ministry_items WHERE id=$selectedItemId")->fetchColumn();
        $memberName = $db->query("SELECT name FROM members WHERE id=$memberId")->fetchColumn();

        $leadersStmt = $db->prepare("SELECT member_id FROM ministry_leaders WHERE ministry_id=?");
        $leadersStmt->execute([$ministryId]);
        $leaderIds = array_column($leadersStmt->fetchAll(), 'member_id');
        if (empty($leaderIds) && $mn['leader_id']) $leaderIds = [$mn['leader_id']];

        foreach ($leaderIds as $lid) {
            $tpl = notification_template('loan_requested', [
                'nome'       => $memberName,
                'ministerio' => $mn['name'],
                'item'       => $itemName,
                'motivo'     => $reason,
            ], $churchId);

            $db->prepare("
                INSERT INTO announcements (church_id, title, content, type, target_type, target_id, channels, status, created_by, sent_at)
                VALUES (?,?,?,'general','member',?,'internal,push','sent',?,NOW())
            ")->execute([$churchId, $tpl['title'], $tpl['content'], (int)$lid, $memberId]);
        }

            // Push para todos os líderes
            $autoload = __DIR__ . '/../../vendor/autoload.php';
            if (file_exists($autoload)) {
                require_once $autoload;
                $vapidPublic  = setting('vapid_public_key');
                $vapidPrivate = setting('vapid_private_key');
                if ($vapidPublic && $vapidPrivate && !empty($leaderIds)) {
                    $webPush = new \Minishlink\WebPush\WebPush(['VAPID' => [
                        'subject'    => setting('vapid_subject', 'mailto:admin@igrejanovadimensao.com.br'),
                        'publicKey'  => $vapidPublic,
                        'privateKey' => $vapidPrivate,
                    ]]);
                    $payload = json_encode([
                        'title' => "📦 Solicitação de empréstimo",
                        'body'  => "$memberName quer emprestar: $itemName",
                        'url'   => APP_URL . "/pages/ministries/items.php?ministry_id=$ministryId",
                        'tag'   => 'loan-' . $selectedItemId,
                    ]);
                    foreach ($leaderIds as $lid) {
                        $subs = $db->prepare("SELECT * FROM push_subscriptions WHERE member_id=?");
                        $subs->execute([$lid]);
                        foreach ($subs->fetchAll() as $sub) {
                            $webPush->queueNotification(
                                \Minishlink\WebPush\Subscription::create([
                                    'endpoint' => $sub['endpoint'],
                                    'keys'     => ['p256dh' => $sub['p256dh'], 'auth' => $sub['auth_key']],
                                    'contentEncoding' => 'aesgcm',
                                ]),
                                $payload
                            );
                        }
                    }
                    foreach ($webPush->flush() as $report) {
                        if ($report->isSubscriptionExpired()) {
                            $db->prepare("DELETE FROM push_subscriptions WHERE endpoint=?")
                               ->execute([$report->getRequest()->getUri()->__toString()]);
                        }
                    }
                }
            }

        header('Location: /pages/ministries/items.php?ministry_id='.$ministryId.'&loan_requested=1');
        exit;
    }
}

$pageTitle  = 'Solicitar empréstimo · ' . $mn['name'];
$activePage = 'ministries';
$categories = ['roupa'=>'👗','instrumento'=>'🎸','equipamento'=>'🔧','acessório'=>'💍','outro'=>'📦'];
$conditions = ['good'=>['label'=>'Bom','badge'=>'badge-green'],
               'fair'=>['label'=>'Regular','badge'=>'badge-amber'],
               'poor'=>['label'=>'Ruim','badge'=>'badge-red']];
require_once __DIR__ . '/../../includes/layout.php';
?>

<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<div style="margin-bottom:16px">
  <a href="/pages/ministries/items.php?ministry_id=<?= $ministryId ?>" style="font-size:13px;color:var(--text-muted);text-decoration:none">
    ← Pertences · <?= htmlspecialchars($mn['name']) ?>
  </a>
</div>

<form method="POST" style="width:100%">
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Selecione o item</p>
    <?php if (empty($items)): ?>
      <p style="font-size:13px;color:var(--text-muted)">Nenhum item disponível no momento.</p>
    <?php else: ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px">
        <?php foreach ($items as $item):
          $cond = $conditions[$item['condition_status']] ?? ['label'=>'—','badge'=>'badge-gray'];
          $icon = $categories[$item['category']] ?? '📦';
          $selected = ($_POST['item_id'] ?? $itemId) == $item['id'];
        ?>
          <label style="display:flex;align-items:flex-start;gap:10px;padding:12px;border:1.5px solid <?= $selected ? 'var(--accent)' : 'var(--border)' ?>;border-radius:8px;cursor:pointer;background:<?= $selected ? 'var(--accent-lt)' : 'white' ?>"
                 id="item-label-<?= $item['id'] ?>">
            <input type="radio" name="item_id" value="<?= $item['id'] ?>"
                   <?= $selected ? 'checked' : '' ?>
                   onchange="selectItem(<?= $item['id'] ?>)">
            <div style="flex:1">
              <div style="font-size:14px"><?= $icon ?></div>
              <div style="font-size:13px;font-weight:500;margin-top:2px"><?= htmlspecialchars($item['name']) ?></div>
              <?php if ($item['description']): ?>
                <div style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($item['description']) ?></div>
              <?php endif; ?>
              <span class="badge <?= $cond['badge'] ?>" style="margin-top:6px"><?= $cond['label'] ?></span>
            </div>
          </label>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card" style="margin-bottom:24px">
    <p class="card-title">Motivo do empréstimo</p>
    <div class="form-group" style="margin-bottom:0">
      <label class="form-label">
        Por que você precisa deste item? *
        <span style="font-weight:400;color:var(--text-muted)">(será enviado para o líder aprovar)</span>
      </label>
      <textarea name="reason" class="form-control" rows="3" required
                placeholder="Ex: Vou usar a roupa de dança no evento da Igreja Renovação no dia 15/06…"><?= htmlspecialchars($_POST['reason'] ?? '') ?></textarea>
    </div>
  </div>

  <div style="display:flex;gap:10px">
    <button type="submit" class="btn btn-primary" <?= empty($items) ? 'disabled' : '' ?>>
      Enviar solicitação
    </button>
    <a href="/pages/ministries/items.php?ministry_id=<?= $ministryId ?>" class="btn btn-secondary">Cancelar</a>
  </div>
</form>

<?php
$extraJs = <<<JS
function selectItem(id) {
  document.querySelectorAll('[id^="item-label-"]').forEach(el => {
    el.style.borderColor = 'var(--border)';
    el.style.background  = 'white';
  });
  const label = document.getElementById('item-label-' + id);
  if (label) {
    label.style.borderColor = 'var(--accent)';
    label.style.background  = 'var(--accent-lt)';
  }
}
// Inicializar item pré-selecionado
const checked = document.querySelector('[name=item_id]:checked');
if (checked) selectItem(checked.value);
JS;
require_once __DIR__ . '/../../includes/layout-footer.php';
?>
