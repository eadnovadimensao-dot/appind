<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/music_roles.php';
require_once __DIR__ . '/../../includes/ministry_activity.php';
auth_check();

$db         = db();
$ministryId = (int)($_GET['ministry_id'] ?? 0);
auth_require_ministry($ministryId);

$stmt = $db->prepare("
    SELECT mn.*, ch.id AS branch_church_id
    FROM ministries mn
    JOIN churches ch ON ch.id = mn.church_id
    WHERE mn.id = ? AND (ch.id = ? OR ch.parent_id = ?)
");
$stmt->execute([$ministryId, SEDE_ID, SEDE_ID]);
$mn = $stmt->fetch();
if (!$mn) { header('Location: /pages/ministries/index.php'); exit; }

$churchId = $mn['church_id'];
$errors   = [];
$results  = null; // preenchido depois de gerar

$days = ['monday'=>'Segunda-feira','tuesday'=>'Terça-feira','wednesday'=>'Quarta-feira',
         'thursday'=>'Quinta-feira','friday'=>'Sexta-feira','saturday'=>'Sábado','sunday'=>'Domingo'];

if (empty($mn['auto_scale_enabled'])) {
    $errors[] = 'Escala em lote precisa da escala automática ativada pra esse ministério (configure em Editar ministério).';
} elseif (empty(get_ministry_roles($db, $ministryId))) {
    $errors[] = 'Nenhuma função configurada ainda pra esse ministério (configure em Funções da escala).';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $activityType  = ($_POST['activity_type'] ?? 'culto') === 'ensaio' ? 'ensaio' : 'culto';
    $weekday       = $_POST['weekday'] ?? '';
    $timeStart     = trim($_POST['time_start'] ?? '') ?: null;
    $timeEnd       = trim($_POST['time_end']   ?? '') ?: null;
    $location      = trim($_POST['location']   ?? '');
    $weeks         = max(1, min(12, (int)($_POST['weeks'] ?? 4)));
    $autoRehearsal = isset($_POST['auto_rehearsal']);
    $titlePrefix   = trim($_POST['title_prefix'] ?? '') ?: ($activityType === 'culto' ? 'Culto' : 'Ensaio');

    if (!isset($days[$weekday])) $errors[] = 'Selecione um dia da semana válido.';

    if (empty($errors)) {
        // Calcula as próximas $weeks datas daquele dia da semana
        $d = strtotime("next $weekday");
        $dowMap = ['monday'=>1,'tuesday'=>2,'wednesday'=>3,'thursday'=>4,'friday'=>5,'saturday'=>6,'sunday'=>7];
        if ((int)date('N') === $dowMap[$weekday]) $d = strtotime('today');
        $dates = [];
        for ($i = 0; $i < $weeks; $i++) {
            $dates[] = date('Y-m-d', $d);
            $d = strtotime('+7 days', $d);
        }

        $pool          = build_scale_pool($db, $ministryId, $churchId);
        $prevStmt      = $db->prepare("
            SELECT member_id FROM ministry_activity_members
            WHERE activity_id = (
                SELECT id FROM ministry_activities
                WHERE ministry_id = ? AND activity_type = ? AND status != 'cancelled'
                ORDER BY activity_date DESC, id DESC LIMIT 1
            )
        ");
        $prevStmt->execute([$ministryId, $activityType]);
        $prevMemberIds = array_map('intval', $prevStmt->fetchAll(PDO::FETCH_COLUMN));

        $created = [];
        $skipped = [];

        foreach ($dates as $date) {
            $exists = $db->prepare("SELECT id FROM ministry_activities WHERE ministry_id=? AND activity_type=? AND activity_date=? AND status != 'cancelled'");
            $exists->execute([$ministryId, $activityType, $date]);
            if ($exists->fetchColumn()) {
                $skipped[] = $date;
                continue;
            }

            $draw        = draw_scale($db, $ministryId, $pool, $prevMemberIds);
            $assignments = $draw['assignments'];
            $title       = $titlePrefix . ' — ' . date('d/m/Y', strtotime($date));

            $activityId = create_ministry_activity(
                $db, $mn, $ministryId, $churchId, $activityType, $title, null,
                $date, $timeStart, $timeEnd, $location ?: null,
                array_keys($assignments), $assignments, [], auth_member_id()
            );

            $rehearsalCreated = false;
            if ($activityType === 'culto' && $autoRehearsal && !empty($mn['meeting_day'])) {
                $rehearsalDate = previous_weekday_before($date, $mn['meeting_day']);
                if ($rehearsalDate >= date('Y-m-d')) {
                    $rExists = $db->prepare("SELECT id FROM ministry_activities WHERE ministry_id=? AND activity_type='ensaio' AND activity_date=? AND status != 'cancelled'");
                    $rExists->execute([$ministryId, $rehearsalDate]);
                    if (!$rExists->fetchColumn()) {
                        $rTimeStart = $mn['meeting_time'] ?: null;
                        $rTimeEnd   = $rTimeStart ? date('H:i:s', strtotime($rTimeStart . ' +2 hours')) : null;
                        create_ministry_activity(
                            $db, $mn, $ministryId, $churchId, 'ensaio',
                            'Ensaio · ' . $title, null,
                            $rehearsalDate, $rTimeStart, $rTimeEnd, $location ?: null,
                            array_keys($assignments), $assignments, [], auth_member_id(), 3
                        );
                        $rehearsalCreated = true;
                    }
                }
            }

            $created[] = [
                'id' => $activityId, 'date' => $date, 'title' => $title,
                'count' => count($assignments), 'warnings' => $draw['warnings'],
                'rehearsal' => $rehearsalCreated,
            ];

            // A próxima data da leva evita repetir quem serviu nesta
            $prevMemberIds = array_keys($assignments);
        }

        $results = ['created' => $created, 'skipped' => $skipped];
    }
}

$pageTitle  = 'Escala em lote · ' . $mn['name'];
$activePage = 'ministries';
require_once __DIR__ . '/../../includes/layout.php';
?>

<div style="margin-bottom:16px">
  <a href="/pages/ministries/view.php?id=<?= $ministryId ?>" style="font-size:13px;color:var(--text-muted);text-decoration:none">
    ← <?= htmlspecialchars($mn['name']) ?>
  </a>
</div>

<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($results): ?>
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Resultado</p>
    <?php if (empty($results['created'])): ?>
      <p style="font-size:13px;color:var(--text-muted)">Nenhuma atividade criada — todas as datas já tinham escala.</p>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px">
        <?php foreach ($results['created'] as $c): ?>
          <div style="border:1px solid var(--border);border-radius:7px;padding:10px 14px">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
              <a href="/pages/ministries/activity_view.php?id=<?= $c['id'] ?>" style="font-size:13px;font-weight:500;color:var(--text);text-decoration:none">
                <?= htmlspecialchars($c['title']) ?>
              </a>
              <span style="font-size:12px;color:var(--text-muted)">
                <?= $c['count'] ?> escalado(s)<?= $c['rehearsal'] ? ' · ensaio gerado junto' : '' ?>
              </span>
            </div>
            <?php if (!empty($c['warnings'])): ?>
              <div style="font-size:11px;color:#8A5A00;margin-top:4px">⚠️ <?= implode(' · ', array_map('htmlspecialchars', $c['warnings'])) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if (!empty($results['skipped'])): ?>
      <p style="font-size:12px;color:var(--text-muted)">
        Datas puladas (já tinham escala): <?= implode(', ', array_map(fn($d) => date('d/m', strtotime($d)), $results['skipped'])) ?>
      </p>
    <?php endif; ?>
    <a href="/pages/ministries/view.php?id=<?= $ministryId ?>" class="btn btn-primary" style="margin-top:12px">Voltar ao ministério</a>
  </div>
<?php endif; ?>

<?php if (!$results): ?>
<form method="POST" style="width:100%">
  <div class="card" style="margin-bottom:16px;max-width:520px">
    <p class="card-title">Gerar várias escalas de uma vez</p>
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:16px">
      Sorteia automaticamente a equipe pra cada semana (evitando repetir quem serviu na semana anterior,
      igual ao botão avulso), e já manda o convite por WhatsApp/push/e-mail pra cada escalado.
    </p>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Tipo</label>
        <select name="activity_type" class="form-control" id="activity-type">
          <option value="culto">Culto</option>
          <option value="ensaio">Ensaio</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Dia da semana *</label>
        <select name="weekday" class="form-control" required>
          <option value="">Selecione</option>
          <?php foreach ($days as $k => $v): ?>
            <option value="<?= $k ?>" <?= ($k === ($mn['meeting_day'] ?? '')) ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Início</label>
        <input type="time" name="time_start" class="form-control" value="<?= htmlspecialchars($mn['meeting_time'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Término</label>
        <input type="time" name="time_end" class="form-control">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Local</label>
      <input type="text" name="location" class="form-control" placeholder="Templo, sala…">
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Título (prefixo)</label>
        <input type="text" name="title_prefix" class="form-control" placeholder="Ex: Culto" value="Culto">
      </div>
      <div class="form-group">
        <label class="form-label">Quantas semanas *</label>
        <input type="number" name="weeks" class="form-control" min="1" max="12" value="4" required>
      </div>
    </div>
    <?php if (!empty($mn['meeting_day'])): ?>
      <div class="form-group" style="margin-bottom:0">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px" id="auto-rehearsal-row">
          <input type="checkbox" name="auto_rehearsal" value="1" checked>
          Criar o ensaio automaticamente junto com cada culto (<?= $days[$mn['meeting_day']] ?? $mn['meeting_day'] ?>)
        </label>
      </div>
    <?php endif; ?>
  </div>

  <div style="display:flex;gap:10px">
    <button type="submit" class="btn btn-primary">🎲 Gerar escalas</button>
    <a href="/pages/ministries/view.php?id=<?= $ministryId ?>" class="btn btn-secondary">Cancelar</a>
  </div>
</form>
<?php endif; ?>

<?php
$extraJs = <<<JS
document.getElementById('activity-type')?.addEventListener('change', function() {
  const row = document.getElementById('auto-rehearsal-row');
  if (row) row.style.display = this.value === 'culto' ? 'flex' : 'none';
});
JS;
require_once __DIR__ . '/../../includes/layout-footer.php';
?>
