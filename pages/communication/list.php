<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db       = db();
$churchId = current_church_id();
$memberId = auth_member_id();

$pageTitle  = 'Avisos enviados';
$activePage = 'communication';
require_once __DIR__ . '/../../includes/layout.php';

$announcements = $db->prepare("
    SELECT a.*,
           m.name AS author_name,
           (SELECT COUNT(*) FROM announcement_reads ar WHERE ar.announcement_id = a.id) AS read_count,
           (SELECT COUNT(*) FROM announcement_sends as2 WHERE as2.announcement_id = a.id AND as2.channel='email') AS email_count
    FROM announcements a
    LEFT JOIN members m ON m.id = a.created_by
    WHERE a.church_id = ? AND a.target_type <> 'member'
    ORDER BY a.created_at DESC
");
$announcements->execute([$churchId]);
$announcements = $announcements->fetchAll();

$typeLabels = [
    'general' => ['label'=>'Geral',   'badge'=>'badge-blue',  'icon'=>'📢'],
    'event'   => ['label'=>'Evento',  'badge'=>'badge-green', 'icon'=>'📅'],
    'urgent'  => ['label'=>'Urgente', 'badge'=>'badge-red',   'icon'=>'🚨'],
    'prayer'  => ['label'=>'Oração',  'badge'=>'badge-amber', 'icon'=>'🙏'],
];
?>

<!-- Abas -->
<div style="display:flex;gap:8px;margin-bottom:20px;border-bottom:1px solid var(--border)">
  <a href="/pages/communication/index.php"
     style="padding:8px 16px;font-size:13px;color:var(--text-muted);text-decoration:none;border-bottom:2px solid transparent">
    Mural
  </a>
  <a href="/pages/communication/list.php"
     style="padding:8px 16px;font-size:13px;font-weight:500;color:var(--accent);border-bottom:2px solid var(--accent);text-decoration:none">
    Enviados
  </a>
</div>

<div class="card" style="padding:0">
  <?php if (empty($announcements)): ?>
    <div class="empty-state" style="padding:40px">
      <p style="font-size:28px;margin-bottom:8px">📢</p>
      <p>Nenhum aviso enviado ainda.</p>
      <a href="/pages/communication/create.php" class="btn btn-primary" style="margin-top:16px">+ Criar aviso</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Aviso</th><th>Tipo</th><th>Destinatários</th><th>Canais</th><th>Visualizações</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($announcements as $a):
            $tl = $typeLabels[$a['type']] ?? ['label'=>'Geral','badge'=>'badge-blue','icon'=>'📢'];
            $channelIcons = [];
            foreach (explode(',', $a['channels']) as $ch) {
              $channelIcons[] = match($ch) {
                'internal'  => '📋',
                'email'     => '✉️',
                'push'      => '🔔',
                'whatsapp'  => '💬',
                default     => $ch,
              };
            }
          ?>
            <tr>
              <td>
                <div style="font-weight:500"><?= htmlspecialchars($a['title']) ?></div>
                <div style="font-size:11px;color:var(--text-muted)">
                  <?= $a['author_name'] ? 'por ' . htmlspecialchars($a['author_name']) : '' ?>
                  · <?= date('d/m/Y H:i', strtotime($a['created_at'])) ?>
                </div>
              </td>
              <td><span class="badge <?= $tl['badge'] ?>"><?= $tl['icon'] ?> <?= $tl['label'] ?></span></td>
              <td style="font-size:12px;color:var(--text-muted)">
                <?= match($a['target_type']) {
                  'all'      => '👥 Todos',
                  'branch'   => '🏛️ Filial',
                  'cell'     => '🔗 Célula',
                  'ministry' => '✝️ Ministério',
                  default    => $a['target_type'],
                } ?>
              </td>
              <td style="font-size:16px;letter-spacing:2px"><?= implode(' ', $channelIcons) ?></td>
              <td>
                <span style="font-weight:500"><?= $a['read_count'] ?></span>
                <span style="font-size:12px;color:var(--text-muted)"> leituras</span>
              </td>
              <td>
                <span class="badge <?= $a['status']==='sent'?'badge-green':'badge-gray' ?>">
                  <?= $a['status']==='sent' ? 'Enviado' : 'Rascunho' ?>
                </span>
              </td>
              <td style="text-align:right">
                <?php if ($a['status'] === 'draft'): ?>
                  <a href="/pages/communication/send.php?id=<?= $a['id'] ?>"
                     class="btn btn-primary" style="font-size:12px;padding:5px 12px"
                     data-confirm="Enviar este aviso agora?">Enviar</a>
                <?php endif; ?>
                <a href="/pages/communication/delete.php?id=<?= $a['id'] ?>"
                   class="btn btn-secondary" style="font-size:12px;padding:5px 12px;color:var(--red)"
                   data-confirm="Excluir este aviso?">Excluir</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
