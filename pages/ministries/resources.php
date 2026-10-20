<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
require_once __DIR__ . "/../../includes/chords.php";
auth_check();

$db = db();
$id = (int)($_GET['ministry_id'] ?? 0);

$stmt = $db->prepare("
    SELECT mn.*, ch.name AS branch_name
    FROM ministries mn
    LEFT JOIN churches ch ON ch.id = mn.church_id
    WHERE mn.id = ? AND ch.id = ?
");
$stmt->execute([$id, current_church_id()]);
$mn = $stmt->fetch();
if (!$mn) { header('Location: /pages/ministries/index.php'); exit; }
if (!auth_member_in_ministry($id)) { header('Location: /dashboard.php?no_access=1'); exit; }

$memberId  = auth_member_id();
$canManage = auth_can_manage_ministry($id);
$isMusic   = (bool)($mn['is_music_ministry'] ?? false);

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
    SELECT r.*, m.name AS created_by_name,
           (SELECT COUNT(DISTINCT mas.activity_id) FROM ministry_activity_songs mas
            JOIN ministry_activities ma ON ma.id = mas.activity_id
            WHERE mas.resource_id = r.id AND ma.activity_type = 'culto') AS culto_use_count
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

// Marca títulos de música repetidos (mesmo nome, caixa-baixa) — pra pegar
// duplicata tipo "Tudo é Perda" cadastrada duas vezes sem ninguém notar.
$titleCounts = [];
foreach ($resources as $r) {
    if ($r['type'] === 'song') {
        $key = mb_strtolower(trim($r['title']));
        $titleCounts[$key] = ($titleCounts[$key] ?? 0) + 1;
    }
}

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

<?php if (!empty($_GET['dup_id'])): ?>
  <div style="background:#FEF3C7;border:1px solid #FCD34D;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#854F0B">
    ⚠️ Já existia uma música com esse mesmo título aqui. Dá uma olhada se não ficou duplicada —
    <a href="/pages/ministries/resource_edit.php?id=<?= (int)$_GET['dup_id'] ?>" style="color:#854F0B;font-weight:500">ver a outra entrada</a>.
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
          <?php if ($isMusic): ?>
          <option value="song">🎵 Música (entra na lista pra escalar em cultos/ensaios)</option>
          <?php endif; ?>
        </select>
      </div>
      <div class="form-group" id="key-tone-group" style="display:none">
        <label class="form-label">Tom</label>
        <input type="text" name="key_tone" class="form-control" placeholder="Ex: G, D, A#m…">
      </div>
    </div>
    <div class="form-row" id="capo-bpm-group" style="display:none">
      <div class="form-group">
        <label class="form-label">Sugestão de capotraste <span style="font-weight:400;color:var(--text-muted)">(casa, opcional)</span></label>
        <input type="number" name="capo" class="form-control" min="0" max="11" placeholder="Ex: 2">
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px">Mostra uma referência pro violonista; cada um decide se usa ou não.</div>
      </div>
      <div class="form-group">
        <label class="form-label">BPM <span style="font-weight:400;color:var(--text-muted)">(andamento, opcional)</span></label>
        <input type="number" name="bpm" class="form-control" id="bpm-input" min="0" placeholder="Ex: 120">
      </div>
      <div class="form-group">
        <label class="form-label">Divisão rítmica <span style="font-weight:400;color:var(--text-muted)">(compasso, opcional)</span></label>
        <select name="time_signature" class="form-control">
          <option value="">—</option>
          <?php foreach (TIME_SIGNATURE_OPTIONS as $ts): ?>
            <option value="<?= $ts ?>"><?= $ts ?></option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px">O metrônomo já abre configurado com esse compasso.</div>
      </div>
      <div class="form-group">
        <label class="form-label">Energia <span style="font-weight:400;color:var(--text-muted)">(opcional)</span></label>
        <select name="energy" class="form-control" id="energy-select">
          <option value="">—</option>
          <?php foreach (ENERGY_OPTIONS as $k => $label): ?>
            <option value="<?= $k ?>"><?= $label ?></option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px">Sugerido pelo BPM; ajuste se não bater com a sensação da música.</div>
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
        <label class="form-label">Arquivo <span style="font-weight:400;color:var(--text-muted)">(cifra/partitura avulsa)</span></label>
        <input type="file" name="file" class="form-control">
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px">PDF, cifra, foto, doc, planilha… até 50 MB.</div>
      </div>
      <div class="form-group">
        <label class="form-label" id="external-url-label">ou link externo</label>
        <input type="url" name="external_url" class="form-control" id="external-url-input" placeholder="https://…">
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px" id="external-url-hint">Áudio bruto, vídeo ou arquivo grande? Prefira o link em vez de anexar.</div>
      </div>
    </div>
    <div class="form-group" id="materials-url-group" style="display:none">
      <label class="form-label">Link de materiais (Drive) <span style="font-weight:400;color:var(--text-muted)">— multitracks, cifra, pasta completa</span></label>
      <input type="url" name="materials_url" class="form-control" placeholder="https://drive.google.com/…">
    </div>
    <div class="form-group" id="chord-sheet-group" style="display:none">
      <label class="form-label">Cifra <span style="font-weight:400;color:var(--text-muted)">— opcional, mas habilita o Modo Ensaio com transposição</span></label>
      <textarea name="chord_sheet_text" class="form-control chord-sheet" rows="8" placeholder="[G]Tudo é [D]perda comparado a [Em]Ti&#10;[C]Nada mais importa [D]além de [G]Ti"></textarea>
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
    <div id="theme-group" style="display:none">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Tema principal <span style="font-weight:400;color:var(--text-muted)">(opcional)</span></label>
          <input type="text" name="theme_main" class="form-control" placeholder="Ex: Igreja e unidade">
        </div>
        <div class="form-group">
          <label class="form-label">Temas secundários <span style="font-weight:400;color:var(--text-muted)">(opcional, separados por vírgula)</span></label>
          <input type="text" name="theme_secondary" class="form-control" placeholder="Ex: Comunhão, serviço, reino de Deus">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Mensagem central <span style="font-weight:400;color:var(--text-muted)">(opcional)</span></label>
        <textarea name="central_message" class="form-control" rows="2" placeholder="Ex: A igreja como um só corpo, chamado a expressar o amor de Deus e cumprir sua vontade."></textarea>
      </div>
      <div class="form-group">
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">
          <input type="checkbox" name="known_repertoire" value="1">
          Já faz parte do repertório há tempo <span style="font-weight:400;color:var(--text-muted)">(não marcar como "✨ Nova" mesmo sem histórico de uso no sistema)</span>
        </label>
      </div>
    </div>
    <button type="submit" class="btn btn-primary">Salvar material</button>
  </form>
  <script>
    document.getElementById('resource-type').addEventListener('change', function() {
      const isSong = this.value === 'song';
      document.getElementById('key-tone-group').style.display = isSong ? 'block' : 'none';
      document.getElementById('materials-url-group').style.display = isSong ? 'block' : 'none';
      document.getElementById('capo-bpm-group').style.display = isSong ? 'flex' : 'none';
      document.getElementById('chord-sheet-group').style.display = isSong ? 'block' : 'none';
      document.getElementById('theme-group').style.display = isSong ? 'block' : 'none';
      document.getElementById('external-url-label').textContent = isSong ? 'Link de referência (YouTube)' : 'ou link externo';
      document.getElementById('external-url-input').placeholder = isSong ? 'https://youtube.com/…' : 'https://…';
      document.getElementById('external-url-hint').textContent = isSong
        ? 'A versão/interpretação que vocês estão seguindo.'
        : 'Áudio bruto, vídeo ou arquivo grande? Prefira o link em vez de anexar.';
      const catField = document.getElementById('resource-category');
      if (isSong && catField.value === 'Geral') catField.value = 'Repertório';
      if (!isSong && catField.value === 'Repertório') catField.value = 'Geral';
    });

    // Sugere Energia pelo BPM, sem sobrescrever se a pessoa já escolheu na mão
    (function () {
      const bpmInput = document.getElementById('bpm-input');
      const energySelect = document.getElementById('energy-select');
      if (!bpmInput || !energySelect) return;
      let touched = false;
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
  <div style="margin-bottom:18px">
    <input type="text" id="resource-search" class="form-control" placeholder="🔎 Buscar por título ou tema (ex: comunhão, entrega, gratidão…)">
  </div>
  <p id="resource-search-empty" style="display:none;color:var(--text-muted);font-size:13px;margin-bottom:16px">Nenhum material encontrado pra essa busca.</p>
  <?php foreach ($grouped as $category => $items): ?>
    <div class="resource-category" style="margin-bottom:24px">
      <p style="font-weight:500;font-size:14px;margin-bottom:10px"><?= htmlspecialchars($category) ?>
        <span style="color:var(--text-muted);font-weight:400">(<?= count($items) ?>)</span>
      </p>
      <div class="resource-grid">
        <?php foreach ($items as $r): ?>
          <?php
            $searchBlob = trim(implode(' ', array_filter([
                $r['title'], $r['theme_main'], $r['theme_secondary'], $r['central_message'], $r['description'],
            ])));
          ?>
          <div class="resource-item" data-search="<?= htmlspecialchars($searchBlob) ?>">
            <div style="display:flex;align-items:flex-start;gap:12px">
              <div style="font-size:22px;flex-shrink:0"><?= resource_icon($r['file_name'], $r['external_url']) ?></div>
              <div style="flex:1;min-width:0">
                <div style="font-size:13px;font-weight:500">
                  <?= htmlspecialchars($r['title']) ?>
                  <?php if ($r['type'] === 'song' && !$r['known_repertoire'] && (int)$r['culto_use_count'] < SONG_NEW_USE_THRESHOLD): ?>
                    <span class="badge badge-green" style="font-size:10px" title="Ainda não foi usada em <?= SONG_NEW_USE_THRESHOLD ?> cultos">✨ Nova</span>
                  <?php endif; ?>
                </div>
                <?php if ($r['key_tone'] || $r['capo'] || $r['bpm'] || $r['time_signature'] || ($r['type'] === 'song' && ($titleCounts[mb_strtolower(trim($r['title']))] ?? 0) > 1)): ?>
                  <div style="margin-top:4px;display:flex;gap:6px;flex-wrap:wrap">
                    <?php if ($r['key_tone']): ?>
                      <span class="badge badge-gray" style="font-size:10px">Tom: <?= htmlspecialchars($r['key_tone']) ?></span>
                    <?php endif; ?>
                    <?php if ($r['capo']): ?>
                      <span class="badge badge-gray" style="font-size:10px">Capo <?= (int)$r['capo'] ?></span>
                    <?php endif; ?>
                    <?php if ($r['bpm']): ?>
                      <span class="badge badge-gray" style="font-size:10px"><?= (int)$r['bpm'] ?> BPM</span>
                    <?php endif; ?>
                    <?php if ($r['time_signature']): ?>
                      <span class="badge badge-gray" style="font-size:10px"><?= htmlspecialchars($r['time_signature']) ?></span>
                    <?php endif; ?>
                    <?= energy_badge($r['energy'] ?? null) ?>
                    <?php if ($r['type'] === 'song' && ($titleCounts[mb_strtolower(trim($r['title']))] ?? 0) > 1): ?>
                      <span class="badge badge-amber" style="font-size:10px" title="Existe mais de uma música com esse título nesse ministério">⚠️ duplicada</span>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
                <?php if ($r['description']): ?>
                  <div style="font-size:12px;color:var(--text-muted);margin-top:2px"><?= nl2br(htmlspecialchars($r['description'])) ?></div>
                <?php endif; ?>
                <?php if ($r['theme_main'] || $r['theme_secondary']): ?>
                  <div style="margin-top:4px;display:flex;align-items:center;gap:4px;flex-wrap:wrap">
                    🏷️
                    <?php if ($r['theme_main']): ?>
                      <button type="button" class="theme-chip theme-chip-main" data-theme-chip="<?= htmlspecialchars($r['theme_main']) ?>"><?= htmlspecialchars($r['theme_main']) ?></button>
                    <?php endif; ?>
                    <?php foreach (array_filter(array_map('trim', explode(',', $r['theme_secondary'] ?? ''))) as $t): ?>
                      <button type="button" class="theme-chip" data-theme-chip="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></button>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
                <?php if ($r['central_message']): ?>
                  <div style="font-size:11px;color:var(--text-muted);margin-top:2px;font-style:italic">"<?= htmlspecialchars($r['central_message']) ?>"</div>
                <?php endif; ?>
                <div style="font-size:11px;color:var(--text-muted);margin-top:4px">
                  <?= htmlspecialchars($r['created_by_name'] ?? 'Alguém') ?> ·
                  <?= date('d/m/Y', strtotime($r['created_at'])) ?>
                  <?= $r['file_size'] ? ' · ' . resource_size($r['file_size']) : '' ?>
                </div>
              </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:10px;margin-left:34px">
              <?php if ($r['chord_sheet_text']): ?>
                <a href="/pages/ministries/resource_chords.php?id=<?= $r['id'] ?>"
                   class="btn btn-primary" style="font-size:12px;padding:5px 12px">📝 Ver cifra</a>
              <?php endif; ?>
              <?php if ($r['external_url']): ?>
                <a href="<?= htmlspecialchars($r['external_url']) ?>" target="_blank" rel="noopener"
                   class="btn btn-secondary" style="font-size:12px;padding:5px 12px"><?= $r['type'] === 'song' ? '▶ Referência' : '🔗 Abrir' ?></a>
              <?php endif; ?>
              <?php if ($r['materials_url']): ?>
                <a href="<?= htmlspecialchars($r['materials_url']) ?>" target="_blank" rel="noopener"
                   class="btn btn-secondary" style="font-size:12px;padding:5px 12px">📁 Materiais</a>
              <?php endif; ?>
              <?php if ($r['file_path']): ?>
                <a href="<?= htmlspecialchars($r['file_path']) ?>" target="_blank" rel="noopener"
                   class="btn btn-secondary" style="font-size:12px;padding:5px 12px">⬇ Baixar</a>
              <?php endif; ?>
              <?php if ($canManage): ?>
                <a href="/pages/ministries/resource_edit.php?id=<?= $r['id'] ?>"
                   title="Editar" style="font-size:14px;color:var(--text-muted);text-decoration:none">✎</a>
                <a href="/pages/ministries/resource_delete.php?id=<?= $r['id'] ?>"
                   style="font-size:16px;color:var(--text-muted);text-decoration:none"
                   data-confirm="Remover <?= htmlspecialchars($r['title']) ?>?">×</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php
$extraScriptSrc = '/public/js/resource-search.js';
require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
