<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
auth_check();
if (auth_role() !== 'supermaster') {
    http_response_code(403);
    include __DIR__ . '/../includes/403.php';
    exit;
}

$db = db();

$connected = zapi_is_connected(SEDE_ID);
$sentToday = (int)$db->query("SELECT COUNT(*) FROM whatsapp_queue WHERE status = 'sent' AND sent_at >= CURDATE()")->fetchColumn();

$kindLabels = ['checkin' => 'Check-in', 'program' => 'Programação', 'meditation' => 'Meditação', 'devotional' => 'Devocional', '' => 'Outras'];
$kindOf = fn($k) => $kindLabels[$k ?? ''] ?? $k;

// Fila agora, por tipo
$pending = $db->query("
    SELECT COALESCE(kind,'') AS kind, COUNT(*) n, MIN(created_at) oldest,
           SUM(not_before IS NOT NULL AND not_before > NOW()) scheduled
    FROM whatsapp_queue WHERE status = 'pending' GROUP BY kind
")->fetchAll();
$pendingTotal = array_sum(array_column($pending, 'n'));

// Últimas 48h por tipo e situação
$recent = [];
foreach ($db->query("
    SELECT COALESCE(kind,'') AS kind, status, COUNT(*) n
    FROM whatsapp_queue
    WHERE status IN ('sent','failed','expired') AND COALESCE(sent_at, created_at) >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
    GROUP BY kind, status
")->fetchAll() as $r) {
    $recent[$r['kind']][$r['status']] = (int)$r['n'];
}

// Envios por dia (7 dias)
$perDay = $db->query("
    SELECT DATE(sent_at) d, COUNT(*) n FROM whatsapp_queue
    WHERE status = 'sent' AND sent_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY d ORDER BY d DESC
")->fetchAll();

// Problemas: falhas e vencidas das últimas 48h (número mascarado)
$problems = $db->query("
    SELECT q.kind, q.status, q.attempts, q.created_at, q.phone, s.title AS service_title
    FROM whatsapp_queue q LEFT JOIN services s ON s.id = q.service_id
    WHERE q.status IN ('failed','expired') AND q.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
    ORDER BY q.created_at DESC LIMIT 40
")->fetchAll();
$statusLabels = ['failed' => 'Falhou', 'expired' => 'Venceu'];

$pageTitle  = 'WhatsApp';
$activePage = 'whatsapp';
require_once __DIR__ . '/../includes/layout.php';
?>

<div class="card" style="margin-bottom:16px;border-left:4px solid <?= $connected ? 'var(--accent)' : 'var(--red)' ?>">
  <div style="display:flex;flex-wrap:wrap;gap:28px;align-items:center">
    <div>
      <div style="font-size:12px;color:var(--text-muted)">Número da igreja</div>
      <div style="font-size:18px;font-weight:600;color:<?= $connected ? 'var(--accent)' : 'var(--red)' ?>">
        <?= $connected ? '● Conectado' : '● Desconectado' ?>
      </div>
      <?php if (!$connected): ?>
        <div style="font-size:12px;color:var(--text-muted);max-width:360px;margin-top:4px">
          Nada é enviado enquanto estiver assim. As mensagens ficam na fila. Reconecte no painel do Z-API (ler o QR Code).
        </div>
      <?php endif; ?>
    </div>
    <div><div style="font-size:12px;color:var(--text-muted)">Enviadas hoje</div>
         <div style="font-size:22px;font-weight:600"><?= $sentToday ?> <span style="font-size:12px;font-weight:400;color:var(--text-muted)">/ teto de segurança <?= WA_DAILY_CAP ?></span></div></div>
    <div><div style="font-size:12px;color:var(--text-muted)">Na fila agora</div>
         <div style="font-size:22px;font-weight:600"><?= $pendingTotal ?></div></div>
  </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-bottom:16px">

  <div class="card" style="padding:0">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border)"><p style="font-weight:500;font-size:14px">Fila agora</p></div>
    <?php if (!$pending): ?>
      <div class="empty-state" style="padding:20px;font-size:13px">Fila vazia.</div>
    <?php else: ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Tipo</th><th>Aguardando</th><th>Agendadas</th><th>Mais antiga</th></tr></thead>
        <tbody>
        <?php foreach ($pending as $r): ?>
          <tr><td><?= htmlspecialchars($kindOf($r['kind'])) ?></td><td><?= (int)$r['n'] ?></td>
              <td><?= (int)$r['scheduled'] ?></td><td><?= date('d/m H:i', strtotime($r['oldest'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>

  <div class="card" style="padding:0">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border)"><p style="font-weight:500;font-size:14px">Últimas 48 horas</p></div>
    <?php if (!$recent): ?>
      <div class="empty-state" style="padding:20px;font-size:13px">Nada enviado nas últimas 48 horas.</div>
    <?php else: ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Tipo</th><th>Enviadas</th><th>Falharam</th><th>Venceram</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $k => $v): ?>
          <tr><td><?= htmlspecialchars($kindOf($k)) ?></td><td><?= $v['sent'] ?? 0 ?></td>
              <td><?= $v['failed'] ?? 0 ?></td><td><?= $v['expired'] ?? 0 ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>

  <div class="card" style="padding:0">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border)"><p style="font-weight:500;font-size:14px">Envios por dia</p></div>
    <?php if (!$perDay): ?>
      <div class="empty-state" style="padding:20px;font-size:13px">Sem envios nos últimos 7 dias.</div>
    <?php else: ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Dia</th><th>Enviadas</th></tr></thead>
        <tbody>
        <?php foreach ($perDay as $r): ?>
          <tr><td><?= date('d/m (D)', strtotime($r['d'])) ?></td><td><?= (int)$r['n'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>
</div>

<div class="card" style="padding:0">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
    <p style="font-weight:500;font-size:14px">Não entregues (falharam ou venceram) nas últimas 48 horas</p>
    <p style="font-size:12px;color:var(--text-muted);margin-top:2px">Convite de check-in que não saiu em 3 horas vence e não é enviado depois. Pra quem ficou de fora, use o controle de presença do culto.</p>
  </div>
  <?php if (!$problems): ?>
    <div class="empty-state" style="padding:20px;font-size:13px">Nenhuma mensagem perdida.</div>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Quando</th><th>Tipo</th><th>Culto</th><th>Número</th><th>Situação</th></tr></thead>
      <tbody>
      <?php foreach ($problems as $r): ?>
        <tr>
          <td><?= date('d/m H:i', strtotime($r['created_at'])) ?></td>
          <td><?= htmlspecialchars($kindOf($r['kind'])) ?></td>
          <td><?= htmlspecialchars($r['service_title'] ?? '') ?></td>
          <td>…<?= htmlspecialchars(substr($r['phone'], -4)) ?></td>
          <td><span class="badge <?= $r['status'] === 'failed' ? 'badge-red' : 'badge-gray' ?>"><?= $statusLabels[$r['status']] ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/layout-footer.php'; ?>
