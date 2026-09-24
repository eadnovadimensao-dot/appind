<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
auth_check();

$db    = db();
$resId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM ministry_resources WHERE id = ?");
$stmt->execute([$resId]);
$res = $stmt->fetch();
if (!$res) { header('Location: /pages/ministries/index.php'); exit; }

auth_require_ministry((int)$res['ministry_id']);

$stmt = $db->prepare("SELECT * FROM ministries WHERE id = ?");
$stmt->execute([$res['ministry_id']]);
$mn = $stmt->fetch();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type         = ($_POST['type'] ?? 'material') === 'song' ? 'song' : 'material';
    $title        = trim($_POST['title'] ?? '');
    $keyTone      = trim($_POST['key_tone'] ?? '') ?: null;
    $category     = trim($_POST['category'] ?? '') ?: 'Geral';
    $description  = trim($_POST['description'] ?? '');
    $externalUrl  = trim($_POST['external_url'] ?? '');
    $materialsUrl = trim($_POST['materials_url'] ?? '');
    $removeFile   = isset($_POST['remove_file']);

    if ($title === '') $errors[] = 'Dê um título pro material.';

    // Só decide o que fazer com o arquivo (sem tocar em disco ainda) — precisa
    // validar tudo primeiro, senão um pedido inválido já teria apagado o arquivo
    // antigo do disco mesmo sem chegar a salvar a alteração.
    $filePath = $res['file_path'];
    $fileName = $res['file_name'];
    $fileSize = $res['file_size'];
    $newFile  = null;

    if (!empty($_FILES['file']['tmp_name']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $file       = $_FILES['file'];
        $allowedExt = ['pdf','doc','docx','xls','xlsx','ppt','pptx','mp3','wav','m4a','ogg',
                       'mp4','mov','webm','jpg','jpeg','png','gif','webp','zip','txt'];
        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $maxSize = 50 * 1024 * 1024;

        if ($file['size'] > $maxSize) {
            $errors[] = 'Arquivo muito grande (máx. 50 MB). Pra algo maior, use o link em vez do arquivo.';
        } elseif (!in_array($ext, $allowedExt, true)) {
            $errors[] = 'Esse tipo de arquivo não é aceito.';
        } elseif ($file['size'] > 0) {
            $newFile  = ['tmp' => $file['tmp_name'], 'ext' => $ext, 'name' => $file['name'], 'size' => $file['size']];
            $filePath = 'novo'; // só um marcador pra passar na validação abaixo — o caminho real sai depois de salvar
            $fileName = $file['name'];
            $fileSize = $file['size'];
        }
    } elseif ($removeFile) {
        $filePath = null; $fileName = null; $fileSize = null;
    }

    if (empty($errors) && !$filePath && $externalUrl === '' && $materialsUrl === '') {
        $errors[] = 'Anexe um arquivo ou informe pelo menos um link.';
    }

    if (empty($errors)) {
        // Só agora mexe em disco, com tudo já validado
        if ($newFile) {
            $dir = __DIR__ . '/../../public/ministry_files/' . $res['ministry_id'] . '/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $safeName = 'mr_' . uniqid() . '.' . $newFile['ext'];
            if (move_uploaded_file($newFile['tmp'], $dir . $safeName)) {
                if ($res['file_path']) {
                    $oldFull = __DIR__ . '/../../' . ltrim($res['file_path'], '/');
                    if (is_file($oldFull)) @unlink($oldFull);
                }
                $filePath = '/public/ministry_files/' . $res['ministry_id'] . '/' . $safeName;
            } else {
                $filePath = $res['file_path']; $fileName = $res['file_name']; $fileSize = $res['file_size'];
            }
        } elseif ($removeFile && $res['file_path']) {
            $full = __DIR__ . '/../../' . ltrim($res['file_path'], '/');
            if (is_file($full)) @unlink($full);
        }

        $db->prepare("
            UPDATE ministry_resources SET
              type=?, title=?, key_tone=?, category=?, description=?,
              file_path=?, file_name=?, file_size=?, external_url=?, materials_url=?
            WHERE id=?
        ")->execute([
            $type, $title, $keyTone, $category, $description ?: null,
            $filePath, $fileName, $fileSize, $externalUrl ?: null, $materialsUrl ?: null,
            $resId,
        ]);
        header('Location: /pages/ministries/resources.php?ministry_id=' . $res['ministry_id'] . '&updated=1');
        exit;
    }
    // Reexibe o formulário com o que a pessoa já tinha digitado
    $res = array_merge($res, ['type' => $type, 'title' => $title, 'key_tone' => $keyTone,
        'category' => $category, 'description' => $description, 'external_url' => $externalUrl,
        'materials_url' => $materialsUrl]);
}

$categorySuggestions = ['Repertório', 'Exercícios', 'Partituras', 'Outros'];

$pageTitle  = 'Editar material';
$activePage = 'ministries';
require_once __DIR__ . '/../../includes/layout.php';
?>

<div style="margin-bottom:16px">
  <a href="/pages/ministries/resources.php?ministry_id=<?= $res['ministry_id'] ?>" style="font-size:13px;color:var(--text-muted);text-decoration:none">
    ← Materiais · <?= htmlspecialchars($mn['name'] ?? '') ?>
  </a>
</div>

<?php if ($errors): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="card">
  <p class="card-title">Editar material</p>
  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="id" value="<?= $resId ?>">
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Tipo</label>
        <select name="type" class="form-control" id="resource-type">
          <option value="material" <?= $res['type'] === 'material' ? 'selected' : '' ?>>📄 Material</option>
          <option value="song" <?= $res['type'] === 'song' ? 'selected' : '' ?>>🎵 Música (entra na lista pra escalar em cultos/ensaios)</option>
        </select>
      </div>
      <div class="form-group" id="key-tone-group" style="<?= $res['type'] === 'song' ? '' : 'display:none' ?>">
        <label class="form-label">Tom</label>
        <input type="text" name="key_tone" class="form-control" placeholder="Ex: G, D, A#m…" value="<?= htmlspecialchars($res['key_tone'] ?? '') ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Título *</label>
        <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($res['title']) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Categoria</label>
        <input type="text" name="category" class="form-control" list="category-suggestions" id="resource-category" value="<?= htmlspecialchars($res['category']) ?>">
        <datalist id="category-suggestions">
          <?php foreach ($categorySuggestions as $cs): ?>
            <option value="<?= htmlspecialchars($cs) ?>">
          <?php endforeach; ?>
        </datalist>
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Descrição</label>
      <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($res['description'] ?? '') ?></textarea>
    </div>

    <div class="form-group">
      <label class="form-label">Arquivo</label>
      <?php if ($res['file_path']): ?>
        <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;border:1px solid var(--border);border-radius:7px;margin-bottom:8px">
          <span style="font-size:13px;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">📎 <?= htmlspecialchars($res['file_name'] ?? '') ?></span>
          <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--red);cursor:pointer;white-space:nowrap">
            <input type="checkbox" name="remove_file" value="1"> Remover
          </label>
        </div>
      <?php endif; ?>
      <input type="file" name="file" class="form-control">
      <div style="font-size:11px;color:var(--text-muted);margin-top:4px">
        <?= $res['file_path'] ? 'Envie um novo arquivo pra substituir o atual.' : 'PDF, cifra, foto, doc, planilha… até 50 MB.' ?>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label" id="external-url-label"><?= $res['type'] === 'song' ? 'Link de referência (YouTube)' : 'Link externo' ?></label>
        <input type="url" name="external_url" class="form-control" id="external-url-input" placeholder="https://…" value="<?= htmlspecialchars($res['external_url'] ?? '') ?>">
      </div>
      <div class="form-group" id="materials-url-group" style="<?= $res['type'] === 'song' ? '' : 'display:none' ?>">
        <label class="form-label">Link de materiais (Drive)</label>
        <input type="url" name="materials_url" class="form-control" placeholder="https://drive.google.com/…" value="<?= htmlspecialchars($res['materials_url'] ?? '') ?>">
      </div>
    </div>

    <div style="display:flex;gap:10px;margin-top:8px">
      <button type="submit" class="btn btn-primary">Salvar alterações</button>
      <a href="/pages/ministries/resources.php?ministry_id=<?= $res['ministry_id'] ?>" class="btn btn-secondary">Cancelar</a>
    </div>
  </form>
</div>

<?php
$extraJs = <<<JS
document.getElementById('resource-type').addEventListener('change', function() {
  const isSong = this.value === 'song';
  document.getElementById('key-tone-group').style.display = isSong ? 'block' : 'none';
  document.getElementById('materials-url-group').style.display = isSong ? 'block' : 'none';
  document.getElementById('external-url-label').textContent = isSong ? 'Link de referência (YouTube)' : 'Link externo';
});
JS;
require_once __DIR__ . '/../../includes/layout-footer.php';
?>
