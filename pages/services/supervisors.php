<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db       = db();
$churchId = current_church_id();
$memberId = auth_member_id();
$errors   = [];

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Salvar supervisor
    if ($action === 'save_supervisor') {
        $editId   = (int)($_POST['edit_id']   ?? 0);
        $mId      = (int)($_POST['member_id'] ?? 0);
        $alias    = trim($_POST['alias']       ?? '');
        $cellIds  = $_POST['cell_ids'] ?? [];

        if (!$mId) $errors[] = 'Selecione um membro.';

        if (empty($errors)) {
            if ($editId) {
                $db->prepare("UPDATE supervisors SET member_id=?, name_alias=? WHERE id=? AND church_id=?")
                   ->execute([$mId, $alias?:null, $editId, $churchId]);
                $db->prepare("DELETE FROM supervisor_cells WHERE supervisor_id=?")->execute([$editId]);
                $supId = $editId;
            } else {
                $db->prepare("INSERT INTO supervisors (church_id, member_id, name_alias) VALUES (?,?,?)")
                   ->execute([$churchId, $mId, $alias?:null]);
                $supId = $db->lastInsertId();
            }
            $sc = $db->prepare("INSERT IGNORE INTO supervisor_cells (supervisor_id, cell_id) VALUES (?,?)");
            foreach ($cellIds as $cid) $sc->execute([$supId, (int)$cid]);

            header('Location: /pages/services/supervisors.php?saved=1');
            exit;
        }
    }

    // Substituição manual na rotação
    if ($action === 'substitute') {
        $rotId   = (int)($_POST['rotation_id']    ?? 0);
        $newSupId= (int)($_POST['new_supervisor_id'] ?? 0);
        $notes   = trim($_POST['notes'] ?? '');

        $db->prepare("
            UPDATE supervisor_rotation
            SET supervisor_id=?, is_substitute=1, substituted_by=?, notes=?
            WHERE id=? AND church_id=?
        ")->execute([$newSupId, $memberId, $notes?:null, $rotId, $churchId]);

        header('Location: /pages/services/supervisors.php?saved=1');
        exit;
    }

    // Toggle ativo/inativo
    if ($action === 'toggle') {
        $id = (int)($_POST['sup_id'] ?? 0);
        $db->prepare("UPDATE supervisors SET active = NOT active WHERE id=? AND church_id=?")
           ->execute([$id, $churchId]);
        header('Location: /pages/services/supervisors.php');
        exit;
    }

    // Excluir supervisor
    if ($action === 'delete') {
        $id = (int)($_POST['sup_id'] ?? 0);
        // Remove da rotação futura e da tabela
        $db->prepare("DELETE FROM supervisor_rotation WHERE supervisor_id=? AND service_date >= CURDATE() AND is_substitute=0")->execute([$id]);
        $db->prepare("DELETE FROM supervisors WHERE id=? AND church_id=?")->execute([$id, $churchId]);
        header('Location: /pages/services/supervisors.php');
        exit;
    }
}

// Buscar supervisores
$supervisors = $db->query("
    SELECT s.*, m.name AS member_name, m.phone AS member_phone,
           GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ') AS cells
    FROM supervisors s
    JOIN members m ON m.id = s.member_id
    LEFT JOIN supervisor_cells sc ON sc.supervisor_id = s.id
    LEFT JOIN cells c ON c.id = sc.cell_id
    WHERE s.church_id = $churchId
    GROUP BY s.id
    ORDER BY s.active DESC, m.name ASC
")->fetchAll();

// Próximas 8 semanas de rotação
$nextSundays = [];
$d = strtotime('next Sunday');
if (date('N') == 7) $d = strtotime('today'); // já é domingo
for ($i = 0; $i < 8; $i++) {
    $nextSundays[] = date('Y-m-d', $d);
    $d = strtotime('+7 days', $d);
}

// Rotação existente no banco
$existingRotation = $db->prepare("
    SELECT sr.*, s.member_id, m.name AS supervisor_name
    FROM supervisor_rotation sr
    JOIN supervisors s ON s.id = sr.supervisor_id
    JOIN members m     ON m.id = s.member_id
    WHERE sr.church_id = ? AND sr.service_date >= CURDATE()
    ORDER BY sr.service_date ASC
");
$existingRotation->execute([$churchId]);
$rotationMap = [];
foreach ($existingRotation->fetchAll() as $r) {
    $rotationMap[$r['service_date']] = $r;
}

// Gerar/redistribuir rotação automática
$activeSups = array_values(array_filter($supervisors, fn($s) => $s['active']));
if (!empty($activeSups)) {
    $autoSlots = [];
    foreach ($nextSundays as $sunday) {
        $slot = $rotationMap[$sunday] ?? null;
        // Só regera se não for substituição manual
        if (!$slot || !$slot['is_substitute']) {
            $autoSlots[] = $sunday;
        }
    }

    // Descobrir a última posição usada em semana COM substituição manual ou já passada
    $lastUsedSup = $db->query("
        SELECT supervisor_id FROM supervisor_rotation
        WHERE church_id = $churchId
          AND (is_substitute = 1 OR service_date < CURDATE())
        ORDER BY service_date DESC LIMIT 1
    ")->fetchColumn();

    $lastIdx = 0;
    if ($lastUsedSup) {
        foreach ($activeSups as $i => $s) {
            if ($s['id'] == $lastUsedSup) { $lastIdx = $i; break; }
        }
    }

    // Redistribuir as semanas automáticas em round-robin
    $si = $db->prepare("
        INSERT INTO supervisor_rotation (church_id, supervisor_id, service_date)
        VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE
          supervisor_id = IF(is_substitute=0, VALUES(supervisor_id), supervisor_id)
    ");

    foreach ($autoSlots as $sunday) {
        $lastIdx = ($lastIdx + 1) % count($activeSups);
        $supId   = $activeSups[$lastIdx]['id'];
        $si->execute([$churchId, $supId, $sunday]);

        // Notificar o supervisor apenas se a semana mudou ou é nova
        $prevSup = $rotationMap[$sunday]['supervisor_id'] ?? null;
        if ($prevSup !== $supId) {
            $supMemberId   = $activeSups[$lastIdx]['member_id'];
            $supName       = $activeSups[$lastIdx]['name_alias'] ?: $activeSups[$lastIdx]['member_name'];
            $dateFormatted = date('d/m/Y', strtotime($sunday));

            $tpl = notification_template('supervisor_assigned', [
                'nome' => $supName,
                'data' => $dateFormatted,
            ], $churchId);

            // Aviso no mural
            $db->prepare("
                INSERT INTO announcements
                  (church_id, title, content, type, target_type, target_id, channels, status, created_by, sent_at)
                VALUES (?,?,?,'general','member',?,'internal,push','sent',?,NOW())
            ")->execute([
                $churchId,
                $tpl['title'],
                $tpl['content'] . "\n\n🔗 " . APP_URL . '/pages/services/index.php',
                $supMemberId,
                $memberId,
            ]);

            // Push notification direto para o supervisor
            $autoload = __DIR__ . '/../../vendor/autoload.php';
            if (file_exists($autoload)) {
                require_once $autoload;
                $vapidPublic  = setting('vapid_public_key');
                $vapidPrivate = setting('vapid_private_key');
                $vapidSubject = setting('vapid_subject', 'mailto:admin@igrejanovadimensao.com.br');

                if ($vapidPublic && $vapidPrivate) {
                    $subs = $db->prepare("SELECT * FROM push_subscriptions WHERE member_id=?");
                    $subs->execute([$supMemberId]);
                    $subscriptions = $subs->fetchAll();

                    if (!empty($subscriptions)) {
                        $webPush = new \Minishlink\WebPush\WebPush([
                            'VAPID' => [
                                'subject'    => $vapidSubject,
                                'publicKey'  => $vapidPublic,
                                'privateKey' => $vapidPrivate,
                            ],
                        ]);
                        $payload = json_encode([
                            'title' => "📋 Culto de $dateFormatted",
                            'body'  => "Você é o supervisor responsável pela ordem do culto.",
                            'url'   => APP_URL . '/pages/services/index.php',
                            'tag'   => 'supervisor-' . $sunday,
                        ]);
                        foreach ($subscriptions as $sub) {
                            $subscription = \Minishlink\WebPush\Subscription::create([
                                'endpoint'        => $sub['endpoint'],
                                'keys'            => ['p256dh' => $sub['p256dh'], 'auth' => $sub['auth_key']],
                                'contentEncoding' => 'aesgcm',
                            ]);
                            $webPush->queueNotification($subscription, $payload);
                        }
                        foreach ($webPush->flush() as $report) {
                            if ($report->isSubscriptionExpired()) {
                                $db->prepare("DELETE FROM push_subscriptions WHERE endpoint=?")
                                   ->execute([$report->getRequest()->getUri()->__toString()]);
                            }
                        }
                    }
                }
            }

            // WhatsApp (Z-API)
            $supPhone = $activeSups[$lastIdx]['member_phone'] ?? '';
            if ($supPhone) {
                queue_whatsapp(
                    $supPhone,
                    $tpl['title'] . "\n\n" . $tpl['content'] . "\n\n🔗 " . APP_URL . '/pages/services/index.php',
                    $churchId
                );
            }
        }
    }
}

// Recarregar rotação atualizada
$rotFull = $db->prepare("
    SELECT sr.*, m.name AS supervisor_name, m2.name AS substituted_name,
           sv.name_alias
    FROM supervisor_rotation sr
    JOIN supervisors sv ON sv.id = sr.supervisor_id
    JOIN members m      ON m.id  = sv.member_id
    LEFT JOIN members m2 ON m2.id = sr.substituted_by
    WHERE sr.church_id = ? AND sr.service_date >= CURDATE()
    ORDER BY sr.service_date ASC
    LIMIT 8
");
$rotFull->execute([$churchId]);
$rotation = $rotFull->fetchAll();

$allMembers = $db->query("SELECT id, name FROM members WHERE church_id=$churchId AND status='active' ORDER BY name")->fetchAll();
$allCells   = $db->query("SELECT id, name FROM cells WHERE church_id=$churchId AND active=1 ORDER BY name")->fetchAll();

$editSup = null;
if (isset($_GET['edit'])) {
    foreach ($supervisors as $s) {
        if ($s['id'] == $_GET['edit']) { $editSup = $s; break; }
    }
}

$pageTitle  = 'Supervisores';
$activePage = 'services';
require_once __DIR__ . '/../../includes/layout.php';
?>

<?php if (isset($_GET['saved'])): ?>
  <div style="background:#E1F5EE;border:1px solid var(--accent-border);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#0F6E56">✓ Salvo com sucesso.</div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:340px 1fr;gap:16px;align-items:start">

  <!-- Form -->
  <div class="card">
    <p class="card-title"><?= $editSup ? 'Editar supervisor' : 'Novo supervisor' ?></p>
    <form method="POST">
      <input type="hidden" name="action" value="save_supervisor">
      <?php if ($editSup): ?>
        <input type="hidden" name="edit_id" value="<?= $editSup['id'] ?>">
      <?php endif; ?>

      <div class="form-group">
        <label class="form-label">Membro *</label>
        <select name="member_id" class="form-control">
          <option value="">Selecione</option>
          <?php foreach ($allMembers as $m): ?>
            <option value="<?= $m['id'] ?>" <?= ($editSup['member_id']??'')==$m['id']?'selected':''?>>
              <?= htmlspecialchars($m['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Apelido <span style="font-weight:400;color:var(--text-muted)">(opcional)</span></label>
        <input type="text" name="alias" class="form-control"
               placeholder="Ex: Supervisor Zona Norte"
               value="<?= htmlspecialchars($editSup['name_alias'] ?? '') ?>">
      </div>

      <div class="form-group" style="margin-bottom:20px">
        <label class="form-label">Células supervisionadas</label>
        <div style="border:1px solid var(--border);border-radius:7px;overflow:hidden;max-height:180px;overflow-y:auto">
          <?php
          $editCells = [];
          if ($editSup) {
              $ec = $db->prepare("SELECT cell_id FROM supervisor_cells WHERE supervisor_id=?");
              $ec->execute([$editSup['id']]);
              $editCells = array_column($ec->fetchAll(), 'cell_id');
          }
          foreach ($allCells as $c):
          ?>
            <label style="display:flex;align-items:center;gap:8px;padding:8px 12px;cursor:pointer;border-bottom:1px solid var(--border);font-size:13px"
                   onmouseover="this.style.background='#f9f9f9'" onmouseout="this.style.background=''">
              <input type="checkbox" name="cell_ids[]" value="<?= $c['id'] ?>"
                     <?= in_array($c['id'], $editCells)?'checked':'' ?>>
              <?= htmlspecialchars($c['name']) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary">Salvar</button>
        <?php if ($editSup): ?>
          <a href="/pages/services/supervisors.php" class="btn btn-secondary">Cancelar</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div style="display:flex;flex-direction:column;gap:16px">

    <!-- Rotação das próximas semanas -->
    <div class="card" style="padding:0">
      <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
        <p style="font-weight:500;font-size:14px">📅 Rotação — próximas semanas</p>
      </div>
      <?php if (empty($rotation)): ?>
        <div class="empty-state" style="padding:24px">Cadastre supervisores para gerar a rotação.</div>
      <?php else: ?>
        <?php foreach ($rotation as $r):
          $isThisWeek = $r['service_date'] === date('Y-m-d', strtotime('next Sunday')) ||
                        $r['service_date'] === date('Y-m-d');
        ?>
          <div style="padding:12px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;<?= $isThisWeek?'background:var(--accent-lt)':'' ?>">
            <div>
              <div style="display:flex;align-items:center;gap:8px">
                <span style="font-size:13px;font-weight:500">
                  <?= date('d/m/Y', strtotime($r['service_date'])) ?>
                </span>
                <?php if ($isThisWeek): ?>
                  <span class="badge badge-green">Esta semana</span>
                <?php endif; ?>
                <?php if ($r['is_substitute']): ?>
                  <span class="badge badge-amber">Substituição</span>
                <?php endif; ?>
              </div>
              <div style="font-size:13px;color:var(--text);margin-top:2px;font-weight:500">
                <?= htmlspecialchars($r['name_alias'] ?: $r['supervisor_name']) ?>
              </div>
              <?php if ($r['notes']): ?>
                <div style="font-size:11px;color:var(--text-muted);font-style:italic"><?= htmlspecialchars($r['notes']) ?></div>
              <?php endif; ?>
            </div>
            <!-- Trocar supervisor -->
            <form method="POST" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
              <input type="hidden" name="action" value="substitute">
              <input type="hidden" name="rotation_id" value="<?= $r['id'] ?>">
              <select name="new_supervisor_id" class="form-control" style="width:160px;font-size:12px;padding:4px 8px">
                <?php foreach ($supervisors as $s): if (!$s['active']) continue; ?>
                  <option value="<?= $s['id'] ?>" <?= $r['supervisor_id']==$s['id']?'selected':''?>>
                    <?= htmlspecialchars($s['name_alias'] ?: $s['member_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <input type="text" name="notes" class="form-control" style="width:120px;font-size:12px;padding:4px 8px" placeholder="Motivo…">
              <button type="submit" class="btn btn-secondary" style="font-size:12px;padding:5px 10px">Trocar</button>
            </form>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Lista de supervisores -->
    <div class="card" style="padding:0">
      <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
        <p style="font-weight:500;font-size:14px">Supervisores cadastrados <span style="color:var(--text-muted);font-weight:400">(<?= count($supervisors) ?>)</span></p>
      </div>
      <?php if (empty($supervisors)): ?>
        <div class="empty-state" style="padding:24px">Nenhum supervisor cadastrado.</div>
      <?php else: ?>
        <?php foreach ($supervisors as $s): ?>
          <div style="padding:12px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
            <div>
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:2px">
                <div class="avatar" style="width:28px;height:28px;font-size:10px">
                  <?= strtoupper(substr($s['member_name'],0,2)) ?>
                </div>
                <span style="font-size:13px;font-weight:500"><?= htmlspecialchars($s['member_name']) ?></span>
                <?php if ($s['name_alias']): ?>
                  <span style="font-size:12px;color:var(--text-muted)">(<?= htmlspecialchars($s['name_alias']) ?>)</span>
                <?php endif; ?>
                <?php if (!$s['active']): ?>
                  <span class="badge badge-gray">Inativo</span>
                <?php endif; ?>
              </div>
              <?php if ($s['cells']): ?>
                <div style="font-size:12px;color:var(--text-muted);margin-left:36px">🔗 <?= htmlspecialchars($s['cells']) ?></div>
              <?php endif; ?>
            </div>
            <div style="display:flex;gap:6px">
              <a href="?edit=<?= $s['id'] ?>" class="btn btn-secondary" style="font-size:12px;padding:5px 10px">Editar</a>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="sup_id" value="<?= $s['id'] ?>">
                <button type="submit" class="btn btn-secondary" style="font-size:12px;padding:5px 10px">
                  <?= $s['active'] ? 'Desativar' : 'Ativar' ?>
                </button>
              </form>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="sup_id" value="<?= $s['id'] ?>">
                <button type="submit" class="btn btn-secondary"
                        style="font-size:12px;padding:5px 10px;color:var(--red)"
                        data-confirm="Excluir <?= htmlspecialchars($s['member_name']) ?>? Ele será removido da rotação futura.">
                  Excluir
                </button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
