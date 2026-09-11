<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
auth_check();

$db = db();
$id = (int)($_GET['ministry_id'] ?? 0);

$stmt = $db->prepare("
    SELECT mn.*, ch.name AS branch_name
    FROM ministries mn
    LEFT JOIN churches ch ON ch.id = mn.church_id
    WHERE mn.id = ? AND (ch.id = ? OR ch.parent_id = ?)
");
$stmt->execute([$id, SEDE_ID, SEDE_ID]);
$mn = $stmt->fetch();
if (!$mn) { header('Location: /pages/ministries/index.php'); exit; }

$memberId  = auth_member_id();
$canManage = auth_can_manage_ministry($id);

// Só pode ver quem gerencia o ministério ou é membro dele
$isMember = false;
if ($memberId) {
    $chk = $db->prepare("SELECT 1 FROM member_ministries WHERE ministry_id = ? AND member_id = ?");
    $chk->execute([$id, $memberId]);
    $isMember = (bool)$chk->fetchColumn();
}
if (!$canManage && !$isMember) {
    http_response_code(403);
    include __DIR__ . '/../../includes/403.php';
    exit;
}

$pageTitle  = 'Materiais · ' . $mn['name'];
$activePage = 'ministries';
require_once __DIR__ . '/../../includes/layout.php';

$resources = $db->prepare("
    SELECT r.*, m.name AS created_by_name
    FROM ministry_resources r
    LEFT JOIN members m ON m.id = r.created_by
    WHERE r.ministry_id = ?
    ORDER BY r.category ASC, r.created_at DESC
");
$resources->execute([$id]);
$resources = $resources->fetchAll();

$grouped = [];
foreach ($resources as $r) {
    $grouped[$r['category']][] = $r;
}
ksort($grouped);

$categorySuggestions = ['Repertório', 'Exercícios', 'Partituras', 'Outros'];

function resource_icon(?string $fileName, ?string $externalUrl): string {
    if ($externalUrl && !$fileName) return '🔗';
    $ext = strtolower(pathinfo($fileName ?? '', PATHINFO_EXTENSION));
    return match (true) {
        in_array($ext, ['mp3','wav','m4a','ogg']) => '🎵',
        in_array($ext, ['mp4','mov','avi','webm']) => '🎬',
        in_array($ext, ['jpg','jpeg','png','gif','webp']) => '🖼️',
        in_array($ext, ['pdf']) => '📄',
        in_array($ext, ['doc','docx']) => '📝',
        in_array($ext, ['xls','xlsx']) => '📊',
        in_array($ext, ['ppt','pptx']) => '📽️',
        in_array($ext, ['zip','rar','7z']) => '🗜️',
        default => '📎',
    };
}

function resource_size(?int $bytes): string {
    if (!$bytes) return '';
    if ($bytes < 1024*1024) return round($bytes/1024) . ' KB';
    return round($bytes/1024/1024, 1) . ' MB';
}
?>

<div style="margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
  <a href="/pages/ministries/view.php?id=<?= $id ?>" style="font-size:13px;color:var(--text-muted);text-decoration:none">
    ← <?= htmlspecialchars($mn['name']) ?>
  </a>
  <?php if ($canManage): ?>
    <button onclick="var f=document.getElementById('add-resource-form');f.style.display=f.style.display==='none'?'block':'none'"
            class="btn btn-primary">+ Adicionar material</button>
  <?php endif; ?>
</div>

<?php if (isset($_GET['error'])): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#A32D2D">
    <?php if ($_GET['error'] === 'titulo'): ?>
      Dê um título pro material antes de enviar.
    <?php elseif ($_GET['error'] === 'arquivo'): ?>
      Anexe um arquivo ou informe um link antes de enviar.
    <?php elseif ($_GET['error'] === 'tamanho'): ?>
      Arquivo muito grande (máx. 50 MB). Pra áudio bruto, vídeo ou qualquer coisa maior,
      suba num Google Drive (ou YouTube não-listado) e cole o link aqui em vez do arquivo.
    <?php elseif ($_GET['error'] === 'tipo'): ?>
      Esse tipo de arquivo não é aceito.
    <?php else: ?>
      Não foi possível salvar o material.
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($canManage): ?>
<div id="add-resource-form" class="card" style="display:none;margin-bottom:16px">
  <p class="card-title">Novo material</p>
  <form method="POST" action="/pages/ministries/resource_upload.php" enctype="multipart/form-data">
    <input type="hidden" name="ministry_id" value="<?= $id ?>">
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Tipo</label>
        <select name="type" class="form-control" id="resource-type">
          <option value="material">📄 Material</option>
          <option value="song">🎵 Música (entra na lista pra escalar em cultos/ensaios)</option>
        </select>
      </div>
      <div class="form-group" id="key-tone-group" style="display:none">
        <label class="form-label">Tom</label>
        <input type="text" name="key_tone" class="form-control" placeholder="Ex: G, D, A#m…">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Título *</label>
        <input type="text" name="title" class="form-control" placeholder="Ex: Repertório de domingo, Exercício de afinação…" required>
      </div>
      <div class="form-group">
        <label class="form-label">Categoria</label>
        <input type="text" name="category" class="form-control" list="category-suggestions" placeholder="Ex: Repertório" value="Geral" id="resource-category">
        <datalist id="category-suggestions">
          <?php foreach ($categorySuggestions as $cs): ?>
            <option value="<?= htmlspecialchars($cs) ?>">
          <?php endforeach; ?>
        </datalist>
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Descrição</label>
      <textarea name="description" class="form-control" rows="2" placeholder="Observações sobre o material (opcional)"></textarea>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Arquivo</label>
        <input type="file" name="file" class="form-control">
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px">PDF, cifra, foto, doc, planilha… até 50 MB.</div>
      </div>
      <div class="form-group">
        <label class="form-label">ou link externo</label>
        <input type="url" name="external_url" class="form-control" placeholder="https://drive.google.com/… ou YouTube">
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px">Áudio bruto, vídeo ou arquivo grande? Prefira o link em vez de anexar.</div>
      </div>
    </div>
    <button type="submit" class="btn btn-primary">Salvar material</button>
  </form>
  <script>
    document.getElementById('resource-type').addEventListener('change', function() {
      const isSong = this.value === 'song';
      document.getElementById('key-tone-group').style.display = isSong ? 'block' : 'none';
      const catField = document.getElementById('resource-category');
      if (isSong && catField.value === 'Geral') catField.value = 'Repertório';
      if (!isSong && catField.value === 'Repertório') catField.value = 'Geral';
    });
  </script>
</div>
<?php endif; ?>

<?php if (empty($resources)): ?>
  <div class="card">
    <div class="empty-state">
      <p style="font-size:32px;margin-bottom:8px">📁</p>
      <p>Nenhum material cadastrado ainda.</p>
      <?php if ($canManage): ?>
        <p style="font-size:12px;color:var(--text-muted);margin-top:6px">Use "+ Adicionar material" pra subir repertório, exercícios, partituras e mais.</p>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>
  <?php foreach ($grouped as $category => $items): ?>
    <div class="card" style="padding:0;margin-bottom:16px">
      <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
        <p style="font-weight:500;font-size:14px"><?= htmlspecialchars($category) ?>
          <span style="color:var(--text-muted);font-weight:400">(<?= count($items) ?>)</span>
        </p>
      </div>
      <?php foreach ($items as $r): ?>
        <div style="display:flex;align-items:flex-start;gap:12px;padding:12px 18px;border-bottom:1px solid var(--border)">
          <div style="font-size:22px;flex-shrink:0"><?= resource_icon($r['file_name'], $r['external_url']) ?></div>
          <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:500">
              <?= htmlspecialchars($r['title']) ?>
              <?php if ($r['key_tone']): ?>
                <span class="badge badge-gray" style="font-size:10px;margin-left:6px">Tom: <?= htmlspecialchars($r['key_tone']) ?></span>
              <?php endif; ?>
            </div>
            <?php if ($r['description']): ?>
              <div style="font-size:12px;color:var(--text-muted);margin-top:2px"><?= nl2br(htmlspecialchars($r['description'])) ?></div>
            <?php endif; ?>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px">
              <?= htmlspecialchars($r['created_by_name'] ?? 'Alguém') ?> ·
              <?= date('d/m/Y', strtotime($r['created_at'])) ?>
              <?= $r['file_size'] ? ' · ' . resource_size($r['file_size']) : '' ?>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:8px;flex-shrink:0">
            <?php if ($r['file_path']): ?>
              <a href="<?= htmlspecialchars($r['file_path']) ?>" target="_blank" rel="noopener"
                 class="btn btn-secondary" style="font-size:12px;padding:5px 12px">⬇ Baixar</a>
            <?php elseif ($r['external_url']): ?>
              <a href="<?= htmlspecialchars($r['external_url']) ?>" target="_blank" rel="noopener"
                 class="btn btn-secondary" style="font-size:12px;padding:5px 12px">🔗 Abrir</a>
            <?php endif; ?>
            <?php if ($canManage): ?>
              <a href="/pages/ministries/resource_delete.php?id=<?= $r['id'] ?>"
                 style="font-size:16px;color:var(--text-muted);text-decoration:none"
                 data-confirm="Remover <?= htmlspecialchars($r['title']) ?>?">×</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
