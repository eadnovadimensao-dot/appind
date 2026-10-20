<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
require_once __DIR__ . "/../../includes/chords.php";
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
    $chordSheet   = trim($_POST['chord_sheet_text'] ?? '') ?: null;
    $capo         = ($type === 'song' && trim($_POST['capo'] ?? '') !== '') ? max(0, min(11, (int)$_POST['capo'])) : null;
    $bpm          = ($type === 'song' && trim($_POST['bpm'] ?? '') !== '') ? max(0, (int)$_POST['bpm']) : null;
    $timeSig      = ($type === 'song' && trim($_POST['time_signature'] ?? '') !== '') ? trim($_POST['time_signature']) : null;
    $energy       = ($type === 'song' && isset(ENERGY_OPTIONS[$_POST['energy'] ?? ''])) ? $_POST['energy'] : null;
    $themeMain    = ($type === 'song') ? (trim($_POST['theme_main'] ?? '') ?: null) : null;
    $themeSec     = ($type === 'song') ? (trim($_POST['theme_secondary'] ?? '') ?: null) : null;
    $centralMsg   = ($type === 'song') ? (trim($_POST['central_message'] ?? '') ?: null) : null;
    $knownRepertoire = ($type === 'song' && isset($_POST['known_repertoire'])) ? 1 : 0;
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

    if (empty($errors) && !$filePath && $externalUrl === '' && $materialsUrl === '' && !$chordSheet) {
        $errors[] = 'Anexe um arquivo, informe pelo menos um link ou cole a cifra.';
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
              type=?, title=?, key_tone=?, chord_sheet_text=?, capo=?, bpm=?, time_signature=?, energy=?,
              theme_main=?, theme_secondary=?, central_message=?, known_repertoire=?, category=?, description=?,
              file_path=?, file_name=?, file_size=?, external_url=?, materials_url=?
            WHERE id=?
        ")->execute([
            $type, $title, $keyTone, $chordSheet, $capo, $bpm, $timeSig, $energy,
            $themeMain, $themeSec, $centralMsg, $knownRepertoire, $category, $description ?: null,
            $filePath, $fileName, $fileSize, $externalUrl ?: null, $materialsUrl ?: null,
            $resId,
        ]);
        header('Location: /pages/ministries/resources.php?ministry_id=' . $res['ministry_id'] . '&updated=1');
        exit;
    }
    // Reexibe o formulário com o que a pessoa já tinha digitado
    $res = array_merge($res, ['type' => $type, 'title' => $title, 'key_tone' => $keyTone,
        'chord_sheet_text' => $chordSheet, 'capo' => $capo, 'bpm' => $bpm, 'time_signature' => $timeSig, 'energy' => $energy,
        'theme_main' => $themeMain, 'theme_secondary' => $themeSec, 'central_message' => $centralMsg,
        'known_repertoire' => $knownRepertoire,
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
    <div class="form-row" id="capo-bpm-group" style="<?= $res['type'] === 'song' ? 'display:flex' : 'display:none' ?>">
      <div class="form-group">
        <label class="form-label">Sugestão de capotraste <span style="font-weight:400;color:var(--text-muted)">(casa, opcional)</span></label>
        <input type="number" name="capo" class="form-control" min="0" max="11" placeholder="Ex: 2" value="<?= htmlspecialchars($res['capo'] ?? '') ?>">
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px">Mostra uma referência pro violonista; cada um decide se usa ou não.</div>
      </div>
      <div class="form-group">
        <label class="form-label">BPM <span style="font-weight:400;color:var(--text-muted)">(andamento, opcional)</span></label>
        <input type="number" name="bpm" class="form-control" id="bpm-input" min="0" placeholder="Ex: 120" value="<?= htmlspecialchars($res['bpm'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Divisão rítmica <span style="font-weight:400;color:var(--text-muted)">(compasso, opcional)</span></label>
        <select name="time_signature" class="form-control">
          <option value="">—</option>
          <?php foreach (TIME_SIGNATURE_OPTIONS as $ts): ?>
            <option value="<?= $ts ?>" <?= ($res['time_signature'] ?? '') === $ts ? 'selected' : '' ?>><?= $ts ?></option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px">O metrônomo já abre configurado com esse compasso.</div>
      </div>
      <div class="form-group">
        <label class="form-label">Energia <span style="font-weight:400;color:var(--text-muted)">(opcional)</span></label>
        <select name="energy" class="form-control" id="energy-select">
          <option value="">—</option>
          <?php foreach (ENERGY_OPTIONS as $k => $label): ?>
            <option value="<?= $k ?>" <?= ($res['energy'] ?? '') === $k ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px">Sugerido pelo BPM; ajuste se não bater com a sensação da música.</div>
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

    <div class="form-group" id="chord-sheet-group" style="<?= $res['type'] === 'song' ? '' : 'display:none' ?>">
      <label class="form-label">Cifra <span style="font-weight:400;color:var(--text-muted)">— opcional, mas habilita o Modo Ensaio com transposição</span></label>
      <textarea name="chord_sheet_text" class="form-control chord-sheet" rows="10" placeholder="[G]Tudo é [D]perda comparado a [Em]Ti&#10;[C]Nada mais importa [D]além de [G]Ti"><?= htmlspecialchars($res['chord_sheet_text'] ?? '') ?></textarea>
      <div style="font-size:11px;color:var(--text-muted);margin-top:4px">
        Acorde entre colchetes antes da sílaba, ex: <code>[G]Tudo é [D]perda</code>. Dá pra transpor o tom depois sem editar de novo.
        <details style="margin-top:6px">
          <summary style="cursor:pointer;color:var(--accent)">Quer organizar em seções (Intro, Verso, Refrão…)? Veja como</summary>
          <div style="margin-top:6px;background:#fafafa;border-radius:6px;padding:10px;font-family:'Courier New',monospace;white-space:pre-wrap">[Verso]
nota: Toda banda, dinâmica média
G              Bm
És a vida,    és o amor

[Refrão (2x)]
G         Bm        A
Grande     é o Senhor</div>
          <p style="margin-top:6px">Acorde numa linha sozinha, letra embaixo. O "(2x)" no título é opcional (repetição). "nota:" é opcional, pra observação de dinâmica/instrumentação. O mapa no topo é montado sozinho, na ordem das seções.</p>
        </details>
      </div>
    </div>

    <div id="theme-group" style="<?= $res['type'] === 'song' ? '' : 'display:none' ?>">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Tema principal <span style="font-weight:400;color:var(--text-muted)">(opcional)</span></label>
          <input type="text" name="theme_main" class="form-control" placeholder="Ex: Igreja e unidade" value="<?= htmlspecialchars($res['theme_main'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Temas secundários <span style="font-weight:400;color:var(--text-muted)">(opcional, separados por vírgula)</span></label>
          <input type="text" name="theme_secondary" class="form-control" placeholder="Ex: Comunhão, serviço, reino de Deus" value="<?= htmlspecialchars($res['theme_secondary'] ?? '') ?>">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Mensagem central <span style="font-weight:400;color:var(--text-muted)">(opcional)</span></label>
        <textarea name="central_message" class="form-control" rows="2" placeholder="Ex: A igreja como um só corpo, chamado a expressar o amor de Deus e cumprir sua vontade."><?= htmlspecialchars($res['central_message'] ?? '') ?></textarea>
      </div>
      <div class="form-group">
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">
          <input type="checkbox" name="known_repertoire" value="1" <?= !empty($res['known_repertoire']) ? 'checked' : '' ?>>
          Já faz parte do repertório há tempo <span style="font-weight:400;color:var(--text-muted)">(não marcar como "✨ Nova" mesmo sem histórico de uso no sistema)</span>
        </label>
      </div>
    </div>

    <div style="display:flex;gap:10px;margin-top:8px">
      <button type="submit" class="btn btn-primary">Salvar alterações</button>
      <a href="/pages/ministries/resources.php?ministry_id=<?= $res['ministry_id'] ?>" class="btn btn-secondary">Cancelar</a>
    </div>
  </form>
</div>

<?php if ($res['type'] === 'song'): ?>
<?php
  $voices = $db->prepare("SELECT * FROM ministry_resource_voices WHERE resource_id = ? ORDER BY position, id");
  $voices->execute([$resId]);
  $voices = $voices->fetchAll();
  $voiceSuggestions = ['Soprano', 'Contralto', 'Meio-soprano', 'Tenor', 'Barítono', 'Baixo', 'Base/Click'];
?>
<div class="card" style="margin-top:16px">
  <p class="card-title">Vozes <span style="font-weight:400;color:var(--text-muted)">— áudio-guia por naipe, opcional. Aparece só na tela de cifra, não no Modo Ensaio/Culto.</span></p>

  <?php if ($voices): ?>
    <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:16px">
      <?php foreach ($voices as $v): ?>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:8px 12px;border:1px solid var(--border);border-radius:8px">
          <span style="font-size:13px;font-weight:500;min-width:110px"><?= htmlspecialchars($v['voice_name']) ?></span>
          <audio controls preload="none" src="<?= htmlspecialchars($v['file_path']) ?>" style="flex:1;min-width:200px;height:36px"></audio>
          <a href="/pages/ministries/resource_voice_delete.php?id=<?= $v['id'] ?>"
             style="font-size:16px;color:var(--text-muted);text-decoration:none"
             data-confirm="Remover a voz <?= htmlspecialchars($v['voice_name']) ?>?">×</a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="POST" action="/pages/ministries/resource_voice_add.php" enctype="multipart/form-data">
    <input type="hidden" name="resource_id" value="<?= $resId ?>">
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Voz</label>
        <input type="text" name="voice_name" class="form-control" list="voice-suggestions" placeholder="Ex: Soprano">
        <datalist id="voice-suggestions">
          <?php foreach ($voiceSuggestions as $vs): ?>
            <option value="<?= htmlspecialchars($vs) ?>">
          <?php endforeach; ?>
        </datalist>
      </div>
      <div class="form-group">
        <label class="form-label">Arquivo de áudio</label>
        <input type="file" name="voice_file" class="form-control" accept="audio/*">
      </div>
    </div>
    <button type="submit" class="btn btn-secondary">+ Adicionar voz</button>
  </form>
</div>
<?php endif; ?>

<?php
$extraJs = <<<JS
document.getElementById('resource-type').addEventListener('change', function() {
  const isSong = this.value === 'song';
  document.getElementById('key-tone-group').style.display = isSong ? 'block' : 'none';
  document.getElementById('materials-url-group').style.display = isSong ? 'block' : 'none';
  document.getElementById('capo-bpm-group').style.display = isSong ? 'flex' : 'none';
  document.getElementById('chord-sheet-group').style.display = isSong ? 'block' : 'none';
  document.getElementById('theme-group').style.display = isSong ? 'block' : 'none';
  document.getElementById('external-url-label').textContent = isSong ? 'Link de referência (YouTube)' : 'Link externo';
});

(function () {
  const bpmInput = document.getElementById('bpm-input');
  const energySelect = document.getElementById('energy-select');
  if (!bpmInput || !energySelect) return;
  let touched = energySelect.value !== ''; // já tinha energia salva — não sobrescreve ao editar o BPM
  energySelect.addEventListener('change', function () { touched = true; });
  bpmInput.addEventListener('input', function () {
    if (touched) return;
    const bpm = parseInt(bpmInput.value, 10);
    if (!bpm) { energySelect.value = ''; return; }
    if (bpm >= 100) energySelect.value = 'agitada';
    else if (bpm <= 80) energySelect.value = 'lenta';
    else energySelect.value = '';
  });
})();
JS;
require_once __DIR__ . '/../../includes/layout-footer.php';
?>
