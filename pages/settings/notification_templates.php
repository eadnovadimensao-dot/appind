<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require('manage_members');

$db       = db();
$churchId = current_church_id();

$typeLabels = [
    'scale_invited'       => '🎵 Convite de escala',
    'scale_confirmed'     => '✅ Confirmação de escala',
    'scale_refused'       => '❌ Recusa de escala',
    'loan_requested'      => '📦 Solicitação de empréstimo',
    'loan_approved'       => '✅ Empréstimo aprovado',
    'loan_refused'        => '❌ Empréstimo recusado',
    'supervisor_assigned' => '⛪ Supervisor designado',
    'event_approved'      => '✅ Evento aprovado',
    'event_refused'       => '❌ Evento recusado',
];

$typeVars = [
    'scale_invited'       => '{nome}, {ministerio}, {data}, {prazo}',
    'scale_confirmed'     => '{nome}, {ministerio}, {data}',
    'scale_refused'       => '{nome}, {ministerio}, {data}, {motivo}',
    'loan_requested'      => '{nome}, {ministerio}, {item}, {motivo}',
    'loan_approved'       => '{nome}, {ministerio}, {item}',
    'loan_refused'        => '{nome}, {ministerio}, {item}, {motivo}',
    'supervisor_assigned' => '{nome}, {data}',
    'event_approved'      => '{nome}, {evento}, {data}',
    'event_refused'       => '{nome}, {evento}, {data}, {motivo}',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type    = $_POST['type']    ?? '';
    $title   = trim($_POST['title']   ?? '');
    $content = trim($_POST['content'] ?? '');
    if ($title && $content && isset($typeLabels[$type])) {
        $db->prepare("
            INSERT INTO notification_templates (church_id, type, title, content)
            VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE title=VALUES(title), content=VALUES(content), updated_at=NOW()
        ")->execute([$churchId, $type, $title, $content]);
        header('Location: /pages/settings/notification_templates.php?saved=1');
        exit;
    }
}

$templates = [];
foreach ($typeLabels as $type => $_) {
    $s = $db->prepare("SELECT * FROM notification_templates WHERE church_id=? AND type=?");
    $s->execute([$churchId, $type]);
    $tpl = $s->fetch();
    if (!$tpl && $churchId !== SEDE_ID) {
        $s->execute([SEDE_ID, $type]);
        $tpl = $s->fetch();
    }
    $templates[$type] = $tpl;
}

$editing   = $_GET['edit'] ?? null;
$pageTitle  = 'Templates de Notificação';
$activePage = 'settings';
require_once __DIR__ . '/../../includes/layout.php';
?>

<?php if (isset($_GET['saved'])): ?>
  <div style="background:#E1F5EE;border:1px solid var(--accent);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#0F6E56">✓ Template salvo!</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 380px;gap:16px;align-items:start">
  <div>
    <?php foreach ($typeLabels as $type => $label):
      $tpl = $templates[$type]; $isEditing = $editing === $type; ?>
      <div class="card" style="margin-bottom:10px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:<?= $isEditing?'14':'0' ?>px">
          <div>
            <div style="font-weight:500;font-size:14px"><?= $label ?></div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px">
              Variáveis: <code style="background:var(--content-bg);padding:1px 5px;border-radius:4px;font-size:11px"><?= $typeVars[$type] ?></code>
            </div>
          </div>
          <a href="?edit=<?= $type ?>" class="btn btn-secondary" style="font-size:12px">Editar</a>
        </div>
        <?php if ($isEditing): ?>
          <form method="POST">
            <input type="hidden" name="type" value="<?= $type ?>">
            <div class="form-group">
              <label class="form-label">Título</label>
              <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($tpl['title'] ?? '') ?>" required>
            </div>
            <div class="form-group" style="margin-bottom:12px">
              <label class="form-label">Mensagem</label>
              <textarea name="content" class="form-control" rows="6" style="font-size:12px;line-height:1.6"><?= htmlspecialchars($tpl['content'] ?? '') ?></textarea>
            </div>
            <div style="display:flex;gap:8px">
              <button type="submit" class="btn btn-primary" style="font-size:12px">Salvar</button>
              <a href="/pages/settings/notification_templates.php" class="btn btn-secondary" style="font-size:12px">Cancelar</a>
            </div>
          </form>
        <?php elseif ($tpl): ?>
          <div style="margin-top:10px;border-top:1px solid var(--border);padding-top:10px">
            <div style="padding:10px;background:var(--content-bg);border-radius:6px;font-size:12px;line-height:1.6">
              <div style="font-weight:500;color:var(--text);margin-bottom:4px"><?= htmlspecialchars($tpl['title']) ?></div>
              <div style="color:var(--text-muted)"><?= nl2br(htmlspecialchars($tpl['content'])) ?></div>
            </div>
          </div>
        <?php else: ?>
          <div style="margin-top:10px;font-size:12px;color:var(--text-muted)">⚠️ Usando template padrão da sede.</div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card" style="position:sticky;top:80px">
    <p class="card-title">💡 Variáveis disponíveis</p>
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px;line-height:1.6">Use entre chaves — o sistema substitui pelo valor real ao enviar.</p>
    <?php
    $allVars = [
      '{nome}'       => 'Nome do destinatário',
      '{ministerio}' => 'Nome do ministério',
      '{data}'       => 'Data da atividade/culto',
      '{prazo}'      => 'Prazo para resposta',
      '{motivo}'     => 'Motivo informado',
      '{item}'       => 'Nome do item',
      '{evento}'     => 'Nome do evento',
    ];
    foreach ($allVars as $var => $desc): ?>
      <div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:8px;font-size:12px">
        <code style="background:var(--accent-lt);color:var(--accent-dk);padding:2px 7px;border-radius:4px;flex-shrink:0"><?= $var ?></code>
        <span style="color:var(--text-muted)"><?= $desc ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
