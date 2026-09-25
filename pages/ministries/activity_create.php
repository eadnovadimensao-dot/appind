<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/music_roles.php';
require_once __DIR__ . '/../../includes/ministry_activity.php';
auth_check();

$db         = db();
$ministryId = (int)($_GET['ministry_id'] ?? 0);
auth_require_ministry($ministryId);
$errors     = [];

// Buscar ministério de qualquer filial da sede
$stmt = $db->prepare("
    SELECT mn.*, ch.id AS branch_church_id
    FROM ministries mn
    JOIN churches ch ON ch.id = mn.church_id
    WHERE mn.id = ? AND ch.id = ?
");
$stmt->execute([$ministryId, current_church_id()]);
$mn = $stmt->fetch();
if (!$mn) { header('Location: /pages/ministries/index.php'); exit; }

$churchId = $mn['church_id']; // usa a church_id DO MINISTÉRIO, não do usuário
$isMusic  = (bool)($mn['auto_scale_enabled'] ?? false);

$days = ['monday'=>'Segunda-feira','tuesday'=>'Terça-feira','wednesday'=>'Quarta-feira',
         'thursday'=>'Quinta-feira','friday'=>'Sexta-feira','saturday'=>'Sábado','sunday'=>'Domingo'];

// Membros do ministério filtrados pela mesma filial
$members = $db->prepare("
    SELECT m.id, m.name, mm.role AS default_role FROM member_ministries mm
    JOIN members m ON m.id = mm.member_id
    WHERE mm.ministry_id = ? AND m.church_id = ?
    ORDER BY m.name
");
$members->execute([$ministryId, $churchId]);
$members = $members->fetchAll();

// Catálogo de músicas do ministério (cadastradas em Materiais)
$songCatalog = $db->prepare("
    SELECT id, title, key_tone, external_url, materials_url, file_path
    FROM ministry_resources
    WHERE ministry_id = ? AND type = 'song'
    ORDER BY title
");
$songCatalog->execute([$ministryId]);
$songCatalog = $songCatalog->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title        = trim($_POST['title']         ?? '');
    $description  = trim($_POST['description']   ?? '');
    $date         = trim($_POST['activity_date'] ?? '');
    $timeStart    = trim($_POST['time_start']    ?? '') ?: null;
    $timeEnd      = trim($_POST['time_end']      ?? '') ?: null;
    $location     = trim($_POST['location']      ?? '');
    $activityType   = ($_POST['activity_type'] ?? 'culto') === 'ensaio' ? 'ensaio' : 'culto';
    $scaledIds      = $_POST['scaled_ids'] ?? [];
    $roles          = $_POST['roles']      ?? [];
    $songs          = array_map('intval', $_POST['song_ids'] ?? []);
    $autoRehearsal  = isset($_POST['auto_rehearsal']);

    if ($title === '') $errors[] = 'Título é obrigatório.';
    if ($date  === '') $errors[] = 'Data é obrigatória.';

    if (empty($errors)) {
        $activityId = create_ministry_activity(
            $db, $mn, $ministryId, $churchId, $activityType, $title, $description,
            $date, $timeStart, $timeEnd, $location, $scaledIds, $roles, $songs, auth_member_id()
        );

        // ── Ensaio automático: mesma equipe e repertório, no dia de reunião do ministério ──
        if ($activityType === 'culto' && $autoRehearsal && !empty($mn['meeting_day']) && !empty($scaledIds)) {
            $rehearsalDate = previous_weekday_before($date, $mn['meeting_day']);

            // Se a última ocorrência do dia de ensaio antes do culto já passou (culto
            // criado em cima da hora), não faz sentido gerar um ensaio no passado
            if ($rehearsalDate >= date('Y-m-d')) {
                $existing = $db->prepare("
                    SELECT id FROM ministry_activities
                    WHERE ministry_id = ? AND activity_type = 'ensaio' AND activity_date = ? AND status != 'cancelled'
                ");
                $existing->execute([$ministryId, $rehearsalDate]);

                if (!$existing->fetchColumn()) {
                    $rTimeStart = $mn['meeting_time'] ?: null;
                    $rTimeEnd   = $rTimeStart ? date('H:i:s', strtotime($rTimeStart . ' +2 hours')) : null;

                    // Atraso de 3 min na notificação do ensaio — evita a mensagem do
                    // ensaio chegar junto (quase idêntica) com a do culto que o gerou
                    create_ministry_activity(
                        $db, $mn, $ministryId, $churchId, 'ensaio',
                        'Ensaio · ' . $title, $description,
                        $rehearsalDate, $rTimeStart, $rTimeEnd, $location,
                        $scaledIds, $roles, $songs, auth_member_id(), 3
                    );
                }
                // Se já existe um ensaio nesse dia, não duplica — a equipe pode ser
                // ajustada manualmente na tela do ensaio existente.
            }
        }

        header('Location: /pages/ministries/view.php?id=' . $ministryId . '&saved=1');
        exit;
    }
}

$pageTitle  = 'Nova atividade · ' . $mn['name'];
$activePage = 'ministries';
require_once __DIR__ . '/../../includes/layout.php';
?>

<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<div style="margin-bottom:16px">
  <a href="/pages/ministries/view.php?id=<?= $ministryId ?>" style="font-size:13px;color:var(--text-muted);text-decoration:none">
    ← <?= htmlspecialchars($mn['name']) ?>
  </a>
</div>

<form method="POST" style="width:100%">

  <!-- Dados da atividade -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Dados da atividade</p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Título *</label>
        <input type="text" name="title" class="form-control"
               placeholder="Ex: Ensaio de quinta, Culto de domingo…"
               value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Tipo</label>
        <select name="activity_type" class="form-control">
          <?php $selType = $_POST['activity_type'] ?? 'culto'; ?>
          <option value="culto"  <?= $selType==='culto'  ? 'selected' : '' ?>>Culto</option>
          <option value="ensaio" <?= $selType==='ensaio' ? 'selected' : '' ?>>Ensaio</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Local</label>
        <input type="text" name="location" class="form-control"
               placeholder="Sala, templo, endereço…"
               value="<?= htmlspecialchars($_POST['location'] ?? '') ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Data *</label>
        <input type="date" name="activity_date" class="form-control"
               value="<?= htmlspecialchars($_POST['activity_date'] ?? date('Y-m-d')) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Início</label>
        <input type="time" name="time_start" class="form-control"
               value="<?= htmlspecialchars($_POST['time_start'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Término</label>
        <input type="time" name="time_end" class="form-control"
               value="<?= htmlspecialchars($_POST['time_end'] ?? '') ?>">
      </div>
    </div>
    <div class="form-group" style="margin-bottom:0">
      <label class="form-label">Descrição / Observações</label>
      <textarea name="description" class="form-control" rows="2"
                placeholder="Detalhes, repertório, materiais necessários…"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
    </div>
  </div>

  <!-- Repertório -->
  <div class="card" style="margin-bottom:16px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:8px">
      <p class="card-title" style="margin:0">Repertório</p>
      <a href="/pages/ministries/resources.php?ministry_id=<?= $ministryId ?>" target="_blank" style="font-size:12px;color:var(--accent);text-decoration:none">+ Adicionar música ao catálogo ↗</a>
    </div>
    <?php if (empty($songCatalog)): ?>
      <p style="font-size:13px;color:var(--text-muted)">
        Nenhuma música cadastrada ainda no catálogo do ministério.
        <a href="/pages/ministries/resources.php?ministry_id=<?= $ministryId ?>" target="_blank">Cadastrar em Materiais</a>
      </p>
    <?php else: ?>
      <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px">Selecione as músicas dessa atividade (cadastradas em Materiais):</p>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px">
        <?php foreach ($songCatalog as $sg): ?>
          <label style="border:1px solid var(--border);border-radius:7px;padding:10px 12px;display:flex;align-items:flex-start;gap:8px;cursor:pointer">
            <input type="checkbox" name="song_ids[]" value="<?= $sg['id'] ?>" style="margin-top:2px"
                   <?= in_array($sg['id'], array_map('intval', $_POST['song_ids'] ?? [])) ? 'checked' : '' ?>>
            <div style="flex:1;min-width:0">
              <div style="font-size:13px;font-weight:500">
                <?= htmlspecialchars($sg['title']) ?>
                <?php if ($sg['key_tone']): ?>
                  <span class="badge badge-gray" style="font-size:10px;margin-left:4px">Tom: <?= htmlspecialchars($sg['key_tone']) ?></span>
                <?php endif; ?>
              </div>
              <div style="font-size:11px;color:var(--text-muted);margin-top:2px">
                <?php
                  $tags = [];
                  if ($sg['external_url'])  $tags[] = '▶ referência';
                  if (!empty($sg['materials_url'])) $tags[] = '📁 materiais';
                  if ($sg['file_path'])     $tags[] = '📄 arquivo';
                  echo implode(' · ', $tags);
                ?>
              </div>
            </div>
          </label>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Escala -->
  <div class="card" style="margin-bottom:24px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:8px">
      <p class="card-title" style="margin:0">Escala — quem vai participar</p>
      <?php if ($isMusic): ?>
        <button type="button" id="auto-scale-btn" class="btn btn-secondary" style="font-size:12px">🎲 Gerar automaticamente</button>
      <?php endif; ?>
    </div>
    <?php if ($isMusic): ?>
      <div id="auto-scale-warnings" style="display:none;background:#FFF7E6;border:1px solid #F0D595;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:12px;color:#8A5A00"></div>
    <?php endif; ?>
    <?php if (!empty($mn['meeting_day'])): ?>
      <label id="auto-rehearsal-row" style="display:<?= $selType==='culto'?'flex':'none' ?>;align-items:center;gap:8px;cursor:pointer;font-size:13px;margin-bottom:12px;background:#F9FAFB;border-radius:8px;padding:10px 12px">
        <input type="checkbox" name="auto_rehearsal" value="1" <?= !isset($_POST['activity_type']) || isset($_POST['auto_rehearsal']) ? 'checked' : '' ?>>
        Criar ensaio automaticamente (<?= $days[$mn['meeting_day']] ?? $mn['meeting_day'] ?><?= $mn['meeting_time'] ? ' às ' . substr($mn['meeting_time'],0,5) : '' ?>) com a mesma equipe e repertório
      </label>
    <?php endif; ?>
    <?php if (empty($members)): ?>
      <p style="font-size:13px;color:var(--text-muted)">
        Nenhum membro vinculado ao ministério ainda.
        <a href="/pages/ministries/view.php?id=<?= $ministryId ?>">Vincular membros</a>
      </p>
    <?php else: ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:8px">
        <?php foreach ($members as $m): ?>
          <div style="border:1px solid var(--border);border-radius:7px;padding:10px 12px;display:flex;align-items:center;gap:10px">
            <input type="checkbox" name="scaled_ids[]" value="<?= $m['id'] ?>"
                   id="m<?= $m['id'] ?>"
                   class="scale-cb" data-id="<?= $m['id'] ?>"
                   <?= in_array($m['id'], $_POST['scaled_ids']??[]) ? 'checked' : '' ?>>
            <div class="avatar" style="width:28px;height:28px;font-size:10px;flex-shrink:0">
              <?= strtoupper(substr($m['name'],0,2)) ?>
            </div>
            <div style="flex:1;min-width:0">
              <label for="m<?= $m['id'] ?>" style="font-size:13px;font-weight:500;cursor:pointer;display:block">
                <?= htmlspecialchars($m['name']) ?>
              </label>
              <input type="text" name="roles[<?= $m['id'] ?>]"
                     class="form-control role-input" id="role<?= $m['id'] ?>"
                     placeholder="Função (ex: vocal, guitarra…)"
                     style="margin-top:4px;font-size:12px;padding:5px 8px;display:<?= in_array($m['id'], $_POST['scaled_ids']??[]) ? 'block' : 'none' ?>"
                     value="<?= htmlspecialchars($_POST['roles'][$m['id']] ?? $m['default_role'] ?? '') ?>">
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div style="display:flex;gap:10px">
    <button type="submit" class="btn btn-primary">Salvar atividade</button>
    <a href="/pages/ministries/view.php?id=<?= $ministryId ?>" class="btn btn-secondary">Cancelar</a>
  </div>
</form>

<?php
$extraJs = <<<JS
// Mostrar/ocultar o checkbox de ensaio automático conforme o Tipo selecionado
document.querySelector('select[name="activity_type"]')?.addEventListener('change', function() {
  const row = document.getElementById('auto-rehearsal-row');
  if (row) row.style.display = this.value === 'culto' ? 'flex' : 'none';
});

// Mostrar/ocultar campo de função ao marcar/desmarcar (mantém o valor pré-preenchido)
document.querySelectorAll('.scale-cb').forEach(cb => {
  cb.addEventListener('change', function() {
    const roleInput = document.getElementById('role' + this.dataset.id);
    roleInput.style.display = this.checked ? 'block' : 'none';
  });
});

// Gerar escala automaticamente (só ministério de Música)
document.getElementById('auto-scale-btn')?.addEventListener('click', function() {
  const btn = this;
  const ministryId = $ministryId;
  const activityType = document.querySelector('select[name="activity_type"]').value;
  const warnBox = document.getElementById('auto-scale-warnings');
  warnBox.style.display = 'none';
  btn.disabled = true;
  btn.textContent = 'Gerando…';

  const actDate = document.querySelector('input[name="activity_date"]').value;

  fetch('/pages/ministries/auto_scale.php?ministry_id=' + ministryId + '&activity_type=' + activityType + '&date=' + encodeURIComponent(actDate) + '&_=' + Date.now())
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      btn.textContent = '🎲 Gerar automaticamente';

      if (data.error) {
        warnBox.textContent = data.error;
        warnBox.style.display = 'block';
        return;
      }

      // Desmarca tudo primeiro
      document.querySelectorAll('.scale-cb').forEach(cb => {
        cb.checked = false;
        const roleInput = document.getElementById('role' + cb.dataset.id);
        if (roleInput) roleInput.style.display = 'none';
      });

      // Marca e preenche os sorteados
      Object.entries(data.assignments).forEach(([memberId, role]) => {
        const cb = document.querySelector('.scale-cb[data-id="' + memberId + '"]');
        const roleInput = document.getElementById('role' + memberId);
        if (cb) cb.checked = true;
        if (roleInput) {
          roleInput.value = role;
          roleInput.style.display = 'block';
        }
      });

      const warnings = (data.warnings || []).slice();
      if (!actDate) warnings.push('Data ainda não preenchida: as indisponibilidades marcadas pelos membros não foram consideradas. Preencha a data e gere de novo.');
      if (warnings.length) {
        const esc = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        warnBox.innerHTML = '⚠️ ' + warnings.map(esc).join('<br>⚠️ ');
        warnBox.style.display = 'block';
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.textContent = '🎲 Gerar automaticamente';
      warnBox.textContent = 'Não foi possível gerar a escala. Tente novamente.';
      warnBox.style.display = 'block';
    });
});
JS;
require_once __DIR__ . '/../../includes/layout-footer.php';
?>
