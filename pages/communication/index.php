<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db       = db();
$churchId = current_church_id();
$memberId = auth_member_id();

$pageTitle    = 'Comunicação';
$activePage   = 'communication';
$topbarAction = ['href' => '/pages/communication/create.php', 'label' => 'Novo aviso'];
require_once __DIR__ . '/../../includes/layout.php';

// Buscar avisos visíveis para o usuário
$announcements = $db->prepare("
    SELECT a.*,
           m.name AS author_name,
           c.name AS cell_name,
           mn.name AS ministry_name,
           ch.name AS branch_name,
           (SELECT COUNT(*) FROM announcement_reads ar WHERE ar.announcement_id = a.id) AS read_count,
           (SELECT COUNT(*) FROM announcement_reads ar WHERE ar.announcement_id = a.id AND ar.member_id = ?) AS is_read
    FROM announcements a
    LEFT JOIN members m    ON m.id  = a.created_by
    LEFT JOIN cells c      ON c.id  = a.target_id AND a.target_type = 'cell'
    LEFT JOIN ministries mn ON mn.id = a.target_id AND a.target_type = 'ministry'
    LEFT JOIN churches ch  ON ch.id = a.target_id AND a.target_type = 'branch'
    WHERE a.church_id = ? AND a.status = 'sent'
      AND (a.target_type <> 'member' OR a.target_id = ?)
    ORDER BY a.sent_at DESC
    LIMIT 30
");
$announcements->execute([$memberId, $churchId, $memberId]);
$announcements = $announcements->fetchAll();

// Marcar como lido ao visualizar
if ($memberId && !empty($announcements)) {
    $unread = array_filter($announcements, fn($a) => !$a['is_read']);
    if (!empty($unread)) {
        $sr = $db->prepare("INSERT IGNORE INTO announcement_reads (announcement_id, member_id) VALUES (?,?)");
        foreach ($unread as $a) {
            $sr->execute([$a['id'], $memberId]);
        }
    }
}

$typeLabels = [
    'general' => ['label'=>'Geral',    'badge'=>'badge-blue',  'icon'=>'📢'],
    'event'   => ['label'=>'Evento',   'badge'=>'badge-green', 'icon'=>'📅'],
    'urgent'  => ['label'=>'Urgente',  'badge'=>'badge-red',   'icon'=>'🚨'],
    'prayer'  => ['label'=>'Oração',   'badge'=>'badge-amber', 'icon'=>'🙏'],
];

$targetLabel = function($a) {
    if ($a['target_type'] === 'all')      return '👥 Todos os membros';
    if ($a['target_type'] === 'branch')   return '🏛️ ' . ($a['branch_name'] ?? 'Filial');
    if ($a['target_type'] === 'cell')     return '🔗 ' . ($a['cell_name'] ?? 'Célula');
    if ($a['target_type'] === 'ministry') return '✝️ ' . ($a['ministry_name'] ?? 'Ministério');
    return '';
};
?>

<!-- Abas -->
<div style="display:flex;gap:8px;margin-bottom:20px;border-bottom:1px solid var(--border);padding-bottom:0">
  <a href="/pages/communication/index.php"
     style="padding:8px 16px;font-size:13px;font-weight:500;color:var(--accent);border-bottom:2px solid var(--accent);text-decoration:none">
    Mural
  </a>
  <?php if (auth_can('all') || auth_can('manage_own_ministry')): ?>
  <a href="/pages/communication/list.php"
     style="padding:8px 16px;font-size:13px;color:var(--text-muted);text-decoration:none;border-bottom:2px solid transparent">
    Enviados
  </a>
  <?php endif; ?>
</div>

<?php if (empty($announcements)): ?>
  <div class="empty-state" style="padding:60px">
    <p style="font-size:32px;margin-bottom:8px">📢</p>
    <p>Nenhum aviso no momento.</p>
  </div>
<?php else: ?>
  <div style="display:flex;flex-direction:column;gap:12px">
    <?php foreach ($announcements as $a):
      $tl = $typeLabels[$a['type']] ?? ['label'=>'Geral','badge'=>'badge-blue','icon'=>'📢'];
    ?>
      <div class="card" style="border-left:3px solid <?= $a['type']==='urgent'?'var(--red)':($a['type']==='event'?'var(--green)':'var(--accent)') ?>">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:10px">
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <span style="font-size:18px"><?= $tl['icon'] ?></span>
            <h3 style="font-size:15px;font-weight:500;color:var(--text)"><?= htmlspecialchars($a['title']) ?></h3>
            <span class="badge <?= $tl['badge'] ?>"><?= $tl['label'] ?></span>
            <?php if (!$a['is_read']): ?>
              <span class="badge badge-blue" style="font-size:10px">Novo</span>
            <?php endif; ?>
          </div>
          <div style="font-size:11px;color:var(--text-muted);text-align:right">
            <?= $targetLabel($a) ?><br>
            <?= $a['author_name'] ? 'por ' . htmlspecialchars($a['author_name']) : '' ?>
            · <?= date('d/m/Y H:i', strtotime($a['sent_at'])) ?>
          </div>
        </div>
        <div style="font-size:13px;color:var(--text);line-height:1.7;white-space:pre-line"><?= nl2br(htmlspecialchars($a['content'])) ?></div>
        <?php if (auth_can('all') || auth_can('manage_own_ministry')): ?>
          <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border);font-size:12px;color:var(--text-muted)">
            👁 <?= $a['read_count'] ?> visualização(ões)
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
