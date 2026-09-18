<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/bible.php';
require_once __DIR__ . '/../../includes/service_program.php';
auth_require_service_editor();

$db       = db();
$churchId = current_church_id();
$memberId = auth_member_id();
$errors   = [];

// Buscar a semana do supervisor logado (ou a semana solicitada por admin)
$serviceId = (int)($_GET['id'] ?? 0);

if ($serviceId) {
    $stmt = $db->prepare("SELECT s.*, m.name AS preacher_name FROM services s LEFT JOIN members m ON m.id=s.preacher_id WHERE s.id=? AND s.church_id=?");
    $stmt->execute([$serviceId, $churchId]);
    $service = $stmt->fetch();
} else {
    // Buscar próximo culto onde o usuário é supervisor
    $stmt = $db->prepare("
        SELECT s.*, m.name AS preacher_name
        FROM services s
        JOIN supervisors sup ON sup.id = s.supervisor_id
        LEFT JOIN members m ON m.id = s.preacher_id
        WHERE s.church_id = ? AND sup.member_id = ? AND s.service_date >= CURDATE()
        ORDER BY s.service_date ASC
        LIMIT 1
    ");
    $stmt->execute([$churchId, $memberId]);
    $service = $stmt->fetch();
}

if (!$service) {
    // Sem culto encontrado (id inválido, ou nenhum culto futuro pro supervisor) — não há o que exibir
    header('Location: /pages/services/index.php');
    exit;
}

// Itens do culto
$items = $db->prepare("
    SELECT si.*, m.name AS responsible_name
    FROM service_items si
    LEFT JOIN members m ON m.id = si.member_id
    WHERE si.service_id = ?
    ORDER BY si.position ASC
");
$items->execute([$service['id']]);
$items = $items->fetchAll();

// Membros para responsável
$members = $db->prepare("SELECT id, name FROM members WHERE church_id=? AND status='active' ORDER BY name");
$members->execute([$churchId]);
$members = $members->fetchAll();

// Processar POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Salvar ordem do culto
    if ($action === 'save_order') {
        $db->prepare("DELETE FROM service_items WHERE service_id=?")->execute([$service['id']]);
        $itemNames   = $_POST['item_name']   ?? [];
        $itemTypes   = $_POST['item_type']   ?? [];
        $itemResps   = $_POST['item_resp']   ?? [];
        $itemDescs   = $_POST['item_desc']   ?? [];
        $itemWc      = $_POST['item_wc']     ?? [];
        $itemDurs    = $_POST['item_dur']    ?? [];

        $si = $db->prepare("INSERT INTO service_items (service_id, position, title, type, member_id, description, duration, worship_count) VALUES (?,?,?,?,?,?,?,?)");
        foreach ($itemNames as $i => $name) {
            if (trim($name) === '') continue;
            $si->execute([
                $service['id'], $i+1,
                trim($name),
                $itemTypes[$i] ?? 'other',
                (int)($itemResps[$i] ?? 0) ?: null,
                trim($itemDescs[$i] ?? '') ?: null,
                (int)($itemDurs[$i] ?? 0) ?: null,
                (int)($itemWc[$i] ?? 0),
            ]);
        }

        // Pregador, título da pregação e referências bíblicas
        $refsRaw = array_values(array_filter(array_map('trim', $_POST['scripture_refs'] ?? []), fn($r) => $r !== ''));
        $parsedRefs = [];
        foreach ($refsRaw as $rawRef) $parsedRefs[] = ['raw' => $rawRef, 'parsed' => bible_parse_reference($db, $rawRef)];
        $sermonText = implode('; ', array_map(
            fn($r) => $r['parsed'] ? bible_format_reference($r['parsed']) : $r['raw'],
            $parsedRefs
        ));

        $db->prepare("UPDATE services SET preacher_id=?, sermon_title=?, sermon_text=? WHERE id=?")
           ->execute([
               (int)($_POST['preacher_id'] ?? 0) ?: null,
               trim($_POST['sermon_title'] ?? '') ?: null,
               $sermonText ?: null,
               $service['id']
           ]);

        // Ressincroniza as referências e a meditação por WhatsApp
        $db->prepare("DELETE FROM service_scriptures WHERE service_id = ?")->execute([$service['id']]);
        if (!empty($parsedRefs)) {
            $ss = $db->prepare("
                INSERT INTO service_scriptures (service_id, raw_reference, book_abbrev, book_name, chapter, verse_start, verse_end, position)
                VALUES (?,?,?,?,?,?,?,?)
            ");
            foreach ($parsedRefs as $i => $r) {
                $p = $r['parsed'];
                $ss->execute([
                    $service['id'], $r['raw'],
                    $p['book_abbrev'] ?? null, $p['book_name'] ?? null,
                    $p['chapter'] ?? null, $p['verse_start'] ?? null, $p['verse_end'] ?? null,
                    $i,
                ]);
            }
        }
        queue_scripture_meditation($db, (int)$service['id'], $service['title'], $service['service_date'], $churchId);

        // "Salvar e confirmar culto": confirma e, na primeira vez, envia a programação
        if (isset($_POST['confirm_service'])) {
            $sent = confirm_service($db, (int)$service['id'], $churchId);
            header('Location: /pages/services/view.php?id=' . $service['id'] . ($sent !== null ? '&sent=' . $sent : ''));
            exit;
        }

        header('Location: /pages/services/supervisor_view.php?id='.$service['id'].'&saved=1');
        exit;
    }

    // Confirmar/recusar supervisão
    if ($action === 'confirm_supervision') {
        $db->prepare("UPDATE services SET supervisor_confirmed=1, supervisor_notes=? WHERE id=?")->execute([trim($_POST['notes']??''), $service['id']]);
        header('Location: /pages/services/supervisor_view.php?id='.$service['id'].'&confirmed=1');
        exit;
    }
}

$pageTitle  = 'Culto · ' . date('d/m/Y', strtotime($service['service_date']));
$activePage = 'services';
require_once __DIR__ . '/../../includes/layout.php';

// Inclui todos os tipos que o modelo do culto cria (welcome, reading, closing);
// sem eles, salvar trocava esses itens pra "Louvor" (primeira opção do select).
$itemTypes = [
    'welcome'      => '👋 Boas-vindas',
    'worship'      => '🎵 Louvor',
    'prayer'       => '🙏 Oração',
    'reading'      => '📖 Leitura Bíblica',
    'offering'     => '💰 Oferta',
    'announcement' => '📢 Anúncio',
    'sermon'       => '✝️ Pregação',
    'communion'    => '🍷 Santa Ceia',
    'closing'      => '🔚 Encerramento',
    'other'        => '📋 Outro',
];

// Quantas pessoas receberiam a programação (só faz sentido antes do primeiro envio)
$programPending = empty($service['program_sent_at']);
$recipientCount = 0;
if ($programPending) {
    foreach (service_program_recipients($db, $service) as $p) if ($p['phone']) $recipientCount++;
}

$existingRefs = $db->prepare("SELECT raw_reference FROM service_scriptures WHERE service_id = ? ORDER BY position");
$existingRefs->execute([$service['id']]);
$existingRefs = $existingRefs->fetchAll(PDO::FETCH_COLUMN);
?>

<?php if (isset($_GET['saved'])): ?>
  <div style="background:#E1F5EE;border:1px solid var(--accent);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#0F6E56">✓ Programação salva com sucesso!</div>
<?php endif; ?>
<?php if (isset($_GET['confirmed'])): ?>
  <div style="background:#E1F5EE;border:1px solid var(--accent);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#0F6E56">✓ Supervisão confirmada!</div>
<?php endif; ?>

<!-- Cabeçalho do culto -->
<div class="card" style="margin-bottom:16px">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <h1 style="font-size:18px;font-weight:500;margin-bottom:6px"><?= htmlspecialchars($service['title']) ?></h1>
      <div style="display:flex;flex-wrap:wrap;gap:12px;font-size:13px;color:var(--text-muted)">
        <span>📅 <?= date_pt($service['service_date']) ?></span>
        <?php if ($service['time_start']): ?>
          <span>🕐 <?= substr($service['time_start'],0,5) ?> — <?= substr($service['time_end']??'',0,5) ?></span>
        <?php endif; ?>
        <?php if ($service['preacher_name']): ?>
          <span>🎤 <?= htmlspecialchars($service['preacher_name']) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <?php if (!$service['supervisor_confirmed']): ?>
      <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="confirm_supervision">
        <button type="submit" class="btn btn-primary" style="font-size:13px">
          ✅ Assumir supervisão deste culto
        </button>
      </form>
    <?php else: ?>
      <span class="badge badge-green">✓ Supervisão confirmada</span>
    <?php endif; ?>
  </div>
</div>

<!-- Ordem do culto -->
<form method="POST">
  <input type="hidden" name="action" value="save_order">

  <!-- Pregação -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Pregação</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Pregador</label>
        <select name="preacher_id" class="form-control">
          <option value="">Selecione</option>
          <?php foreach ($members as $m): ?>
            <option value="<?= $m['id'] ?>" <?= $service['preacher_id']==$m['id']?'selected':''?>>
              <?= htmlspecialchars($m['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Título da mensagem</label>
        <input type="text" name="sermon_title" class="form-control"
               placeholder="Título da pregação…"
               value="<?= htmlspecialchars($service['sermon_title'] ?? '') ?>">
      </div>
    </div>

    <div style="margin-top:8px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
        <label class="form-label" style="margin-bottom:0">Referências bíblicas</label>
        <button type="button" onclick="addScriptureRef()" class="btn btn-secondary" style="font-size:12px">+ Adicionar referência</button>
      </div>
      <p style="font-size:12px;color:var(--text-secondary,#666);margin:0 0 8px">
        Os versículos são enviados aos membros ativos para meditação, 2 dias antes do culto.
      </p>
      <div id="scripture-refs-list" style="display:flex;flex-direction:column;gap:8px"></div>
    </div>
  </div>

  <!-- Itens da programação -->
  <div class="card" style="margin-bottom:16px;padding:0">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
      <p style="font-weight:500;font-size:14px">Programação</p>
      <button type="button" onclick="addItem()" class="btn btn-secondary" style="font-size:12px">+ Adicionar item</button>
    </div>

    <div id="items-list">
      <?php foreach ($items as $i => $item): ?>
        <div class="item-row" style="padding:12px 18px;border-bottom:1px solid var(--border);display:grid;grid-template-columns:auto 1fr 160px 160px 80px auto;gap:10px;align-items:center">
          <div style="cursor:grab;color:var(--text-muted);font-size:18px;padding:0 4px">⠿</div>
          <input type="text" name="item_name[]" class="form-control" style="font-size:13px"
                 placeholder="Nome do item…" value="<?= htmlspecialchars($item['title'] ?? '') ?>">
          <input type="hidden" name="item_desc[]" value="<?= htmlspecialchars($item['description'] ?? '') ?>">
          <input type="hidden" name="item_wc[]" value="<?= (int)($item['worship_count'] ?? 0) ?>">
          <select name="item_type[]" class="form-control" style="font-size:13px">
            <?php foreach ($itemTypes as $k => $v): ?>
              <option value="<?= $k ?>" <?= $item['type']===$k?'selected':''?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
          <select name="item_resp[]" class="form-control" style="font-size:13px">
            <option value="">Responsável…</option>
            <?php foreach ($members as $m): ?>
              <option value="<?= $m['id'] ?>" <?= $item['member_id']==$m['id']?'selected':''?>>
                <?= htmlspecialchars($m['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <input type="number" name="item_dur[]" class="form-control" style="font-size:13px"
                 placeholder="min" min="1" max="120" value="<?= $item['duration'] ?? '' ?>">
          <button type="button" onclick="this.closest('.item-row').remove()"
                  style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:18px;padding:0 4px">×</button>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Total de tempo -->
    <div style="padding:12px 18px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;font-size:13px;color:var(--text-muted)">
      Duração total: <strong style="margin-left:6px;color:var(--text)" id="total-duration">0 min</strong>
    </div>
  </div>

  <div style="display:flex;gap:10px">
    <button type="submit" class="btn btn-primary">Salvar programação</button>
    <?php if ($programPending): ?>
      <button type="submit" name="confirm_service" value="1" class="btn btn-secondary"
              onclick="return confirm('Isso salva a programação, confirma o culto e envia por WhatsApp pra <?= $recipientCount ?> pessoa(s) envolvida(s). Confirmar?')">
        ✓ Salvar e confirmar culto
      </button>
    <?php endif; ?>
    <a href="/pages/services/index.php" class="btn btn-secondary">Voltar</a>
  </div>
</form>

<?php
$membersJson = json_encode(array_map(fn($m) => ['id'=>$m['id'],'name'=>$m['name']], $members));
$typesJson   = json_encode($itemTypes);
$scriptureRefsJson = json_encode($existingRefs);
$extraJs = <<<JS
const members   = {$membersJson};
const itemTypes = {$typesJson};

// ── Referências bíblicas ────────────────────────────────────
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

  const input   = row.querySelector('input');
  const preview = row.querySelector('.scripture-preview');
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
          if (d.ok) {
            preview.style.color = 'var(--accent)';
            preview.textContent = '✓ ' + d.reference + ' - ' + d.preview;
          } else {
            preview.style.color = 'var(--red)';
            preview.textContent = d.error || 'Não reconheci essa referência.';
          }
        })
        .catch(() => { preview.textContent = ''; });
    }, 400);
  });
  if (value) input.dispatchEvent(new Event('input'));
}

const initialScriptureRefs = {$scriptureRefsJson};
if (initialScriptureRefs.length > 0) {
  initialScriptureRefs.forEach(v => addScriptureRef(v));
} else {
  addScriptureRef();
}

function addItem() {
  const list = document.getElementById('items-list');
  const memberOpts = members.map(m => `<option value="\${m.id}">\${m.name}</option>`).join('');
  const typeOpts   = Object.entries(itemTypes).map(([k,v]) => `<option value="\${k}">\${v}</option>`).join('');
  const row = document.createElement('div');
  row.className = 'item-row';
  row.style = 'padding:12px 18px;border-bottom:1px solid var(--border);display:grid;grid-template-columns:auto 1fr 160px 160px 80px auto;gap:10px;align-items:center';
  row.innerHTML = `
    <div style="cursor:grab;color:var(--text-muted);font-size:18px;padding:0 4px">⠿</div>
    <input type="text" name="item_name[]" class="form-control" style="font-size:13px" placeholder="Nome do item…">
    <input type="hidden" name="item_desc[]" value="">
    <input type="hidden" name="item_wc[]" value="0">
    <select name="item_type[]" class="form-control" style="font-size:13px">\${typeOpts}</select>
    <select name="item_resp[]" class="form-control" style="font-size:13px"><option value="">Responsável…</option>\${memberOpts}</select>
    <input type="number" name="item_dur[]" class="form-control" style="font-size:13px" placeholder="min" min="1" max="120">
    <button type="button" onclick="this.closest('.item-row').remove();calcTotal()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:18px;padding:0 4px">×</button>
  `;
  list.appendChild(row);
  row.querySelector('input[name="item_name[]"]').focus();
}

function calcTotal() {
  const durs = [...document.querySelectorAll('input[name="item_dur[]"]')]
    .map(i => parseInt(i.value)||0).reduce((a,b) => a+b, 0);
  document.getElementById('total-duration').textContent = durs + ' min';
}

document.addEventListener('input', e => {
  if (e.target.name === 'item_dur[]') calcTotal();
});
calcTotal();
JS;
require_once __DIR__ . '/../../includes/layout-footer.php';
?>
