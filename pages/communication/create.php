<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db       = db();
$churchId = current_church_id();
$memberId = auth_member_id();
$role     = auth_role();
$errors   = [];

// Carregar destinos disponíveis
$cells      = $db->query("SELECT id, name FROM cells WHERE church_id=$churchId AND active=1 ORDER BY name")->fetchAll();
$ministries = $db->query("SELECT id, name FROM ministries WHERE church_id=$churchId AND active=1 ORDER BY name")->fetchAll();

// Líderes só podem enviar para sua célula/ministério
$isLeader  = $role === 'leader';
$isAdmin   = in_array($role, ['supermaster','admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title      = trim($_POST['title']       ?? '');
    $content    = trim($_POST['content']     ?? '');
    $type       = trim($_POST['type']        ?? 'general');
    $targetType = trim($_POST['target_type'] ?? 'all');
    $targetId   = (int)($_POST['target_id']  ?? 0) ?: null;
    $channels   = $_POST['channels'] ?? ['internal'];
    $sendNow    = isset($_POST['send_now']);

    if ($title === '')   $errors[] = 'Título é obrigatório.';
    if ($content === '') $errors[] = 'Conteúdo é obrigatório.';
    if (empty($channels)) $errors[] = 'Selecione pelo menos um canal.';

    if (empty($errors)) {
        $channelsStr = implode(',', $channels);
        $status      = $sendNow ? 'sent' : 'draft';
        $sentAt      = $sendNow ? date('Y-m-d H:i:s') : null;

        $db->prepare("
            INSERT INTO announcements
              (church_id, title, content, type, target_type, target_id, channels, status, created_by, sent_at)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ")->execute([$churchId,$title,$content,$type,$targetType,$targetId,$channelsStr,$status,$memberId,$sentAt]);

        $announcementId = $db->lastInsertId();

        if ($sendNow) {
            // Buscar destinatários
            $recipients = getRecipients($db, $churchId, $targetType, $targetId);

            // Enviar e-mail se selecionado
            if (in_array('email', $channels)) {
                sendEmails($db, $announcementId, $recipients, $title, $content);
            }

            // Push notification se selecionado
            if (in_array('push', $channels)) {
                sendPushNotifications($db, $announcementId, $recipients, $title, $content);
            }

            // WhatsApp se selecionado — entra na fila (mesmo mecanismo das escalas)
            if (in_array('whatsapp', $channels)) {
                sendWhatsappAnnouncement($db, $announcementId, $recipients, $title, $content, $churchId);
            }
        }

        header('Location: /pages/communication/index.php?sent=1');
        exit;
    }
}

// Funções auxiliares
function getRecipients(PDO $db, int $churchId, string $targetType, ?int $targetId): array {
    // Sempre só a igreja selecionada: "todos", célula e ministério nunca alcançam outra igreja
    $sql    = "SELECT m.id, m.name, m.email, m.phone FROM members m WHERE m.status = 'active' AND m.church_id = ?";
    $params = [$churchId];

    if ($targetType === 'cell') {
        $sql .= " AND m.cell_id = ?";
        $params[] = $targetId;
    } elseif ($targetType === 'ministry') {
        $sql .= " AND m.id IN (SELECT member_id FROM member_ministries WHERE ministry_id = ?)";
        $params[] = $targetId;
    } elseif ($targetType !== 'all') {
        return [];
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function sendEmails(PDO $db, int $announcementId, array $recipients, string $title, string $content): void {
    $autoload = __DIR__ . '/../../vendor/autoload.php';
    if (!file_exists($autoload)) return;
    require_once $autoload;

    $churchName = setting('church_name', 'Igreja Manager');
    $smtpHost   = setting('smtp_host',   'localhost');
    $smtpPort   = (int)setting('smtp_port', '587');
    $smtpUser   = setting('smtp_user',   '');
    $smtpPass   = setting('smtp_pass',   '');
    $smtpFrom   = setting('church_email') ?: $smtpUser;

    $sl = $db->prepare("INSERT IGNORE INTO announcement_sends (announcement_id, member_id, channel) VALUES (?,?,'email')");

    foreach ($recipients as $r) {
        if (empty($r['email'])) continue;
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $smtpHost;
            $mail->Port       = $smtpPort;
            $mail->SMTPAuth   = !empty($smtpUser);
            $mail->Username   = $smtpUser;
            $mail->Password   = $smtpPass;
            $mail->SMTPSecure = $smtpPort == 465
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->CharSet    = 'UTF-8';
            $mail->setFrom($smtpFrom, $churchName);
            $mail->addAddress($r['email'], $r['name']);
            $mail->Subject    = $title;
            $mail->Body       = "Olá, {$r['name']}!\n\n{$content}\n\n— {$churchName}";
            $mail->send();
            $sl->execute([$announcementId, $r['id']]);
        } catch (\Exception $e) {
            // Log silencioso — não interrompe o fluxo
        }
    }
}

function sendPushNotifications(PDO $db, int $announcementId, array $recipients, string $title, string $content): void {
    $vapidPublic  = setting('vapid_public_key');
    $vapidPrivate = setting('vapid_private_key');
    $vapidSubject = setting('vapid_subject', 'mailto:admin@igrejanovadimensao.com.br');

    if (!$vapidPublic || !$vapidPrivate) return;

    // Verificar se web-push está instalado
    $autoload = __DIR__ . '/../../vendor/autoload.php';
    if (!file_exists($autoload)) return;
    require_once $autoload;

    $webPush = new \Minishlink\WebPush\WebPush([
        'VAPID' => [
            'subject'    => $vapidSubject,
            'publicKey'  => $vapidPublic,
            'privateKey' => $vapidPrivate,
        ],
    ]);

    $recipientIds = array_column($recipients, 'id');
    if (empty($recipientIds)) return;

    $placeholders = implode(',', array_fill(0, count($recipientIds), '?'));
    $subs = $db->prepare("SELECT * FROM push_subscriptions WHERE member_id IN ($placeholders)");
    $subs->execute($recipientIds);
    $subscriptions = $subs->fetchAll();

    $sl = $db->prepare("INSERT IGNORE INTO announcement_sends (announcement_id, member_id, channel) VALUES (?,?,'push')");

    $payload = json_encode([
        'title' => $title,
        'body'  => mb_substr(strip_tags($content), 0, 120),
        'url'   => '/pages/communication/index.php',
        'tag'   => 'announcement-' . $announcementId,
    ]);

    foreach ($subscriptions as $sub) {
        $subscription = \Minishlink\WebPush\Subscription::create([
            'endpoint'        => $sub['endpoint'],
            'keys'            => ['p256dh' => $sub['p256dh'], 'auth' => $sub['auth_key']],
            'contentEncoding' => 'aesgcm',
        ]);
        $webPush->queueNotification($subscription, $payload);
        $sl->execute([$announcementId, $sub['member_id']]);
    }

    // Enviar todas as notificações
    foreach ($webPush->flush() as $report) {
        if ($report->isSubscriptionExpired()) {
            // Remover subscription expirada
            $db->prepare("DELETE FROM push_subscriptions WHERE endpoint=?")
               ->execute([$report->getRequest()->getUri()->__toString()]);
        }
    }
}

function sendWhatsappAnnouncement(PDO $db, int $announcementId, array $recipients, string $title, string $content, int $churchId): void {
    $churchName = setting('church_name', 'Igreja', $churchId);
    $message    = "*{$churchName}*\n\n*{$title}*\n\n{$content}";

    $sl = $db->prepare("INSERT IGNORE INTO announcement_sends (announcement_id, member_id, channel) VALUES (?,?,'whatsapp')");

    foreach ($recipients as $r) {
        if (empty($r['phone'])) continue;
        queue_whatsapp($r['phone'], $message, $churchId);
        $sl->execute([$announcementId, $r['id']]);
    }
}

$pageTitle  = 'Novo aviso';
$activePage = 'communication';
require_once __DIR__ . '/../../includes/layout.php';
?>

<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="POST" style="width:100%">

  <!-- Dados do aviso -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Aviso</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Título *</label>
        <input type="text" name="title" class="form-control"
               placeholder="Ex: Culto especial no domingo, Reunião de líderes…"
               value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Tipo</label>
        <select name="type" class="form-control">
          <option value="general" <?= ($_POST['type']??'general')==='general'?'selected':''?>>📢 Geral</option>
          <option value="event"   <?= ($_POST['type']??'')==='event'  ?'selected':''?>>📅 Evento</option>
          <option value="urgent"  <?= ($_POST['type']??'')==='urgent' ?'selected':''?>>🚨 Urgente</option>
          <option value="prayer"  <?= ($_POST['type']??'')==='prayer' ?'selected':''?>>🙏 Oração</option>
        </select>
      </div>
    </div>
    <div class="form-group" style="margin-bottom:0">
      <label class="form-label">Conteúdo *</label>
      <textarea name="content" class="form-control" rows="5" required
                placeholder="Escreva o aviso aqui…"><?= htmlspecialchars($_POST['content'] ?? '') ?></textarea>
    </div>
  </div>

  <!-- Destinatários -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Destinatários</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Enviar para</label>
        <select name="target_type" class="form-control" id="target-type" onchange="updateTargetId()">
          <?php if ($isAdmin): ?>
            <option value="all"      <?= ($_POST['target_type']??'all')==='all'     ?'selected':''?>>👥 Todos os membros</option>
          <?php endif; ?>
          <option value="cell"       <?= ($_POST['target_type']??'')==='cell'       ?'selected':''?>>🔗 Por célula</option>
          <option value="ministry"   <?= ($_POST['target_type']??'')==='ministry'   ?'selected':''?>>✝️ Por ministério</option>
        </select>
      </div>
      <div class="form-group" id="target-id-group" style="display:none">
        <label class="form-label" id="target-id-label">Selecione</label>
        <select name="target_id" class="form-control" id="target-id-select">
          <option value="">Selecione…</option>
        </select>
      </div>
    </div>
  </div>

  <!-- Canais -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Canais de envio</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px">

      <label style="display:flex;align-items:flex-start;gap:10px;padding:14px;border:1.5px solid var(--border);border-radius:8px;cursor:pointer">
        <input type="checkbox" name="channels[]" value="internal" checked style="margin-top:2px">
        <div>
          <div style="font-weight:500;font-size:13px">📋 Mural interno</div>
          <div style="font-size:12px;color:var(--text-muted)">Visível ao fazer login</div>
        </div>
      </label>

      <label style="display:flex;align-items:flex-start;gap:10px;padding:14px;border:1.5px solid var(--border);border-radius:8px;cursor:pointer">
        <input type="checkbox" name="channels[]" value="push" style="margin-top:2px">
        <div>
          <div style="font-weight:500;font-size:13px">🔔 Push notification</div>
          <div style="font-size:12px;color:var(--text-muted)">Notificação no celular (PWA)</div>
        </div>
      </label>

      <label style="display:flex;align-items:flex-start;gap:10px;padding:14px;border:1.5px solid var(--border);border-radius:8px;cursor:pointer">
        <input type="checkbox" name="channels[]" value="email" style="margin-top:2px">
        <div>
          <div style="font-weight:500;font-size:13px">✉️ E-mail</div>
          <div style="font-size:12px;color:var(--text-muted)">Envia para o e-mail dos membros</div>
        </div>
      </label>

      <label style="display:flex;align-items:flex-start;gap:10px;padding:14px;border:1.5px solid var(--border);border-radius:8px;cursor:pointer"
             id="whatsapp-channel">
        <input type="checkbox" name="channels[]" value="whatsapp" style="margin-top:2px" id="whatsapp-cb" onchange="toggleWhatsapp()">
        <div>
          <div style="font-weight:500;font-size:13px">💬 WhatsApp</div>
          <div style="font-size:12px;color:var(--text-muted)">Envia automaticamente pra quem tem telefone cadastrado</div>
        </div>
      </label>

    </div>
  </div>

  <!-- Preview WhatsApp -->
  <div id="whatsapp-preview" style="display:none;margin-bottom:16px">
    <div class="card">
      <p class="card-title">💬 Preview da mensagem WhatsApp</p>
      <div style="background:#FEF3C7;border:1px solid #FCD34D;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:12px;color:#854F0B">
        ⚠️ Ao clicar em "Enviar agora", essa mensagem entra numa fila e é enviada de verdade,
        aos poucos, pra todo mundo do destinatário selecionado que tem telefone cadastrado.
      </div>
      <div style="background:#ECF8F1;border-radius:8px;padding:14px;font-size:13px;line-height:1.8;font-family:monospace;white-space:pre-wrap" id="wa-preview-text"></div>
      <button type="button" onclick="copyWhatsapp()"
              class="btn btn-secondary" style="margin-top:12px;font-size:12px">
        📋 Copiar mensagem
      </button>
    </div>
  </div>

  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <button type="submit" name="send_now" value="1" class="btn btn-primary">📤 Enviar agora</button>
    <button type="submit" class="btn btn-secondary">💾 Salvar rascunho</button>
    <a href="/pages/communication/index.php" class="btn btn-secondary">Cancelar</a>
  </div>
</form>

<?php
// Dados para JS
$cellsJson      = json_encode(array_map(fn($c) => ['id'=>$c['id'],'name'=>$c['name']], $cells));
$ministriesJson = json_encode(array_map(fn($m) => ['id'=>$m['id'],'name'=>$m['name']], $ministries));
$churchName     = htmlspecialchars(setting('church_name', 'Igreja'), ENT_QUOTES);

$extraJs = <<<JS
const cells      = $cellsJson;
const ministries = $ministriesJson;

function updateTargetId() {
  const type    = document.getElementById('target-type').value;
  const group   = document.getElementById('target-id-group');
  const label   = document.getElementById('target-id-label');
  const select  = document.getElementById('target-id-select');

  const maps = { cell: [cells,'Selecione a célula'], ministry: [ministries,'Selecione o ministério'] };

  if (maps[type]) {
    const [items, placeholder] = maps[type];
    label.textContent = placeholder;
    select.innerHTML  = '<option value="">'+placeholder+'</option>';
    items.forEach(i => select.innerHTML += '<option value="'+i.id+'">'+i.name+'</option>');
    group.style.display = 'block';
  } else {
    group.style.display = 'none';
  }
  updateWaPreview();
}

function toggleWhatsapp() {
  const show = document.getElementById('whatsapp-cb').checked;
  document.getElementById('whatsapp-preview').style.display = show ? 'block' : 'none';
  if (show) updateWaPreview();
}

function updateWaPreview() {
  const title   = document.querySelector('[name=title]').value   || 'Aviso';
  const content = document.querySelector('[name=content]').value || '';
  const text    = '*$churchName*\\n\\n*' + title + '*\\n\\n' + content;
  document.getElementById('wa-preview-text').textContent = text;
}

function copyWhatsapp() {
  const text = document.getElementById('wa-preview-text').textContent;
  navigator.clipboard.writeText(text).then(() => {
    const btn = event.target;
    btn.textContent = '✓ Copiado!';
    setTimeout(() => btn.textContent = '📋 Copiar mensagem', 2000);
  });
}

// Listeners para atualizar preview
document.querySelector('[name=title]').addEventListener('input', updateWaPreview);
document.querySelector('[name=content]').addEventListener('input', updateWaPreview);

// Inicializar
updateTargetId();
JS;
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/layout-footer.php';
?>
