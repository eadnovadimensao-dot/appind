<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/devotional.php';
auth_check();

if (!auth_can_write_devotional()) {
    http_response_code(403);
    include __DIR__ . '/../../includes/403.php';
    exit;
}

$db  = db();
$id  = (int)($_GET['id'] ?? 0);
$dev = null;
if ($id) {
    $q = $db->prepare("SELECT * FROM devotionals WHERE id = ?");
    $q->execute([$id]);
    $dev = $q->fetch();
    if (!$dev || !auth_can_edit_devotional($dev)) { header('Location: /pages/devotional/index.php'); exit; }
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $body  = trim($_POST['body'] ?? '');
    $date  = trim($_POST['publish_date'] ?? '');
    $send  = isset($_POST['send_whatsapp']) ? 1 : 0;
    $refs  = $_POST['scripture_refs'] ?? [];

    if ($title === '') $errors[] = 'Título é obrigatório.';
    if ($body === '')  $errors[] = 'Escreva o texto do devocional.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $errors[] = 'Data inválida.';

    if (empty($errors)) {
        if ($dev) {
            $db->prepare("UPDATE devotionals SET title=?, body=?, publish_date=?, send_whatsapp=? WHERE id=?")
               ->execute([$title, $body, $date, $send, $id]);
        } else {
            $db->prepare("INSERT INTO devotionals (title, body, publish_date, author_member_id, author_name, send_whatsapp) VALUES (?,?,?,?,?,?)")
               ->execute([$title, $body, $date, auth_member_id(), auth_user()['name'] ?? 'Pastor', $send]);
            $id = (int)$db->lastInsertId();
        }
        devotional_save_scriptures($db, $id, $refs);
        header('Location: /pages/devotional/view.php?id=' . $id . '&saved=1');
        exit;
    }
    // Erro: reexibe o que foi digitado
    $dev = array_merge($dev ?? ['send_whatsapp' => 1], ['title' => $title, 'body' => $body, 'publish_date' => $date, 'send_whatsapp' => $send]);
    $existingRefs = array_values(array_filter(array_map('trim', $refs), fn($r) => $r !== ''));
} else {
    $existingRefs = [];
    if ($id) {
        $r = $db->prepare("SELECT raw_reference FROM devotional_scriptures WHERE devotional_id = ? ORDER BY position");
        $r->execute([$id]);
        $existingRefs = $r->fetchAll(PDO::FETCH_COLUMN);
    }
}

$pageTitle  = $id ? 'Editar devocional' : 'Novo devocional';
$activePage = 'devotional';
require_once __DIR__ . '/../../includes/layout.php';
$v = fn($k, $d = '') => htmlspecialchars((string)($dev[$k] ?? $d));
?>

<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="POST" style="max-width:720px">
  <div class="card" style="margin-bottom:16px">
    <div class="form-row">
      <div class="form-group" style="flex:2">
        <label class="form-label">Título *</label>
        <input type="text" name="title" class="form-control" value="<?= $v('title') ?>" required maxlength="150">
      </div>
      <div class="form-group">
        <label class="form-label">Data *</label>
        <input type="date" name="publish_date" class="form-control" value="<?= $v('publish_date', date('Y-m-d')) ?>" required>
      </div>
    </div>

    <div style="margin-bottom:12px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
        <label class="form-label" style="margin-bottom:0">Referências bíblicas (opcional)</label>
        <button type="button" onclick="addScriptureRef()" class="btn btn-secondary" style="font-size:12px">+ Adicionar referência</button>
      </div>
      <div id="scripture-refs-list" style="display:flex;flex-direction:column;gap:8px"></div>
    </div>

    <div class="form-group">
      <label class="form-label">Texto do devocional *</label>
      <textarea name="body" class="form-control" rows="12" required><?= $v('body') ?></textarea>
    </div>

    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
      <input type="checkbox" name="send_whatsapp" value="1" <?= !empty($dev['send_whatsapp']) || !$id && !$_POST ? 'checked' : '' ?>>
      Enviar por WhatsApp aos membros às <?= sprintf('%02d', DEVOTIONAL_SEND_HOUR) ?>h do dia
    </label>
    <p style="font-size:12px;color:var(--text-muted);margin:6px 0 0 24px">
      Se a data for hoje e já passou das <?= sprintf('%02d', DEVOTIONAL_SEND_HOUR) ?>h, sai em poucos minutos. Datas passadas só ficam no portal.
      Vai assinado por <strong><?= htmlspecialchars($dev['author_name'] ?? (auth_user()['name'] ?? '')) ?></strong>.
    </p>
  </div>

  <div style="display:flex;gap:10px">
    <button type="submit" class="btn btn-primary">Salvar</button>
    <a href="<?= $id ? '/pages/devotional/view.php?id=' . $id : '/pages/devotional/index.php' ?>" class="btn btn-secondary">Cancelar</a>
  </div>
</form>

<?php
$refsJson = json_encode($existingRefs);
$extraJs = <<<JS
function addScriptureRef(value) {
  const list = document.getElementById('scripture-refs-list');
  const row = document.createElement('div');
  row.className = 'scripture-ref-row';
  row.style = 'background:var(--content-bg);border-radius:7px;padding:8px 10px';
  row.innerHTML =
    '<div style="display:flex;gap:8px;align-items:center">' +
      '<input type="text" name="scripture_refs[]" class="form-control" style="font-size:13px" placeholder="Ex: João 3:16, Salmos 23" value="' + (value ? value.replace(/"/g, '&quot;') : '') + '">' +
      '<button type="button" onclick="this.closest(\'.scripture-ref-row\').remove()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:18px">&times;</button>' +
    '</div>' +
    '<div class="scripture-preview" style="font-size:12px;color:var(--text-muted);margin-top:4px"></div>';
  list.appendChild(row);
  const input = row.querySelector('input'), preview = row.querySelector('.scripture-preview');
  let timer = null;
  input.addEventListener('input', () => {
    clearTimeout(timer);
    const ref = input.value.trim();
    if (!ref) { preview.textContent = ''; return; }
    timer = setTimeout(() => {
      preview.textContent = 'Buscando…';
      fetch('/pages/services/bible_preview.php?ref=' + encodeURIComponent(ref))
        .then(r => r.json())
        .then(d => {
          if (d.ok) { preview.style.color = 'var(--accent)'; preview.textContent = '✓ ' + d.reference + ' - ' + d.preview; }
          else { preview.style.color = 'var(--red)'; preview.textContent = d.error || 'Não reconheci essa referência.'; }
        })
        .catch(() => { preview.textContent = ''; });
    }, 400);
  });
  if (value) input.dispatchEvent(new Event('input'));
}
const initialRefs = {$refsJson};
if (initialRefs.length) initialRefs.forEach(v => addScriptureRef(v)); else addScriptureRef();
JS;
require_once __DIR__ . '/../../includes/layout-footer.php';
?>
