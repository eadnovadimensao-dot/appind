<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/discipleship.php';
auth_check();

$db = db();
$id = (int)($_GET['id'] ?? 0);

$member = $db->prepare("
    SELECT m.*, c.name AS cell_name, ch.name AS branch_name, ch.type AS branch_type
    FROM members m
    LEFT JOIN cells c    ON c.id  = m.cell_id
    LEFT JOIN churches ch ON ch.id = m.church_id
    WHERE m.id = ? AND ch.id = ?
");
$member->execute([$id, current_church_id()]);
$m = $member->fetch();

if (!$m) {
    header('Location: /pages/members/index.php');
    exit;
}
if (!auth_member_can_view_member((int)$m['id'])) {
    header('Location: /dashboard.php?no_access=1');
    exit;
}

$pageTitle  = $m['name'];
$activePage = 'members';
require_once __DIR__ . '/../../includes/layout.php';

$statusLabels = [
    'active'      => ['label' => 'Ativo',        'badge' => 'badge-green'],
    'visitor'     => ['label' => 'Visitante',     'badge' => 'badge-blue'],
    'inactive'    => ['label' => 'Afastado',      'badge' => 'badge-gray'],
    'discipline'  => ['label' => 'Em disciplina', 'badge' => 'badge-amber'],
    'transferred' => ['label' => 'Transferido',   'badge' => 'badge-gray'],
    'deceased'    => ['label' => 'Falecido',      'badge' => 'badge-red'],
];
$st = $statusLabels[$m['status']] ?? ['label' => $m['status'], 'badge' => 'badge-gray'];

$initials = strtoupper(implode('', array_map(fn($p) => $p[0], array_slice(explode(' ', $m['name']), 0, 2))));

$genderLabel  = ['M' => 'Masculino', 'F' => 'Feminino'][$m['gender'] ?? ''] ?? '—';
$maritalLabel = ['single'=>'Solteiro(a)','married'=>'Casado(a)','divorced'=>'Divorciado(a)','widowed'=>'Viúvo(a)'][$m['marital_status']??''] ?? '—';

function fdate($d) { return $d ? date('d/m/Y', strtotime($d)) : '—'; }
?>

<!-- Header do perfil -->
<div class="card" style="margin-bottom:16px">
  <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap">
    <div style="width:64px;height:64px;border-radius:50%;overflow:hidden;background:var(--accent-lt);color:var(--accent-dk);font-size:22px;font-weight:600;display:flex;align-items:center;justify-content:center;flex-shrink:0">
      <?php if (!empty($m['photo_url'])): ?>
        <img src="<?= htmlspecialchars($m['photo_url']) ?>" style="width:100%;height:100%;object-fit:cover">
      <?php else: ?>
        <?= $initials ?>
      <?php endif; ?>
    </div>
    <div style="flex:1">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <h1 style="font-size:18px;font-weight:500"><?= htmlspecialchars($m['name']) ?></h1>
        <span class="badge <?= $st['badge'] ?>"><?= $st['label'] ?></span>
      </div>
      <div style="font-size:13px;color:var(--text-muted);margin-top:2px">
        <?= $m['cell_name'] ? '🔗 ' . htmlspecialchars($m['cell_name']) : 'Sem célula' ?>
        · <span class="badge <?= $m['branch_type']==='sede'?'badge-blue':'badge-green' ?>" style="font-size:11px">
            <?= htmlspecialchars($m['branch_name'] ?? '—') ?>
          </span>
        <?php if ($m['join_date']): ?>
          · Membro desde <?= fdate($m['join_date']) ?>
        <?php endif; ?>
      </div>
    </div>
    <div style="display:flex;gap:8px">
      <?php if (auth_can('manage_members')): ?>
        <a href="/pages/members/edit.php?id=<?= $m['id'] ?>" class="btn btn-primary">Editar</a>
      <?php endif; ?>
      <a href="/pages/members/index.php" class="btn btn-secondary">Voltar</a>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

  <!-- Dados pessoais -->
  <div class="card">
    <p class="card-title">Dados pessoais</p>
    <?php
    $rows = [
      ['CPF',          $m['cpf']        ?? '—'],
      ['Nascimento',   fdate($m['birth_date'])],
      ['Gênero',       $genderLabel],
      ['Estado civil', $maritalLabel],
    ];
    foreach ($rows as [$label, $value]):
    ?>
      <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px">
        <span style="color:var(--text-muted)"><?= $label ?></span>
        <span style="font-weight:500"><?= htmlspecialchars($value) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Contato -->
  <div class="card">
    <p class="card-title">Contato</p>
    <?php
    $rows = [
      ['Telefone', $m['phone']  ?? '—'],
      ['E-mail',   $m['email']  ?? '—'],
      ['CEP',      $m['zip_code'] ?? '—'],
      ['Cidade',   $m['city']   ?? '—'],
      ['Endereço', trim(($m['address'] ?? '') . ($m['number'] ? ', nº ' . $m['number'] : '')) ?: '—'],
      ['Bairro',   $m['neighborhood'] ?? '—'],
    ];
    foreach ($rows as [$label, $value]):
    ?>
      <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px">
        <span style="color:var(--text-muted)"><?= $label ?></span>
        <span style="font-weight:500;text-align:right;max-width:200px"><?= htmlspecialchars($value) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Vida espiritual -->
  <div class="card">
    <p class="card-title">Vida espiritual</p>
    <?php
    $rows = [
      ['Ingresso',    fdate($m['join_date'])],
      ['Conversão',   fdate($m['conversion_date'])],
      ['Batismo',     fdate($m['baptism_date'])],
      ['Igreja origem', $m['origin_church'] ?? '—'],
    ];
    foreach ($rows as [$label, $value]):
    ?>
      <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px">
        <span style="color:var(--text-muted)"><?= $label ?></span>
        <span style="font-weight:500"><?= htmlspecialchars($value) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Observações -->
  <div class="card">
    <p class="card-title">Observações</p>
    <p style="font-size:13px;color:<?= $m['notes'] ? 'var(--text)' : 'var(--text-muted)' ?>;line-height:1.7">
      <?= $m['notes'] ? nl2br(htmlspecialchars($m['notes'])) : 'Nenhuma observação.' ?>
    </p>
  </div>

</div>

<?php
// Trilha de crescimento espiritual
$steps = $db->prepare("
    SELECT gs.*, mgp.completed_at
    FROM growth_steps gs
    LEFT JOIN member_growth_progress mgp ON mgp.step_id = gs.id AND mgp.member_id = ?
    WHERE gs.church_id = ? AND gs.active = 1
    ORDER BY gs.position
");
$steps->execute([$m['id'], $m['church_id']]);
$steps = $steps->fetchAll();
$doneCount = count(array_filter($steps, fn($s) => $s['completed_at'] !== null));
?>
<?php if (!empty($steps)): ?>
<div class="card" style="margin-top:16px;padding:0">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
    <p style="font-weight:500;font-size:14px">🌱 Trilha de crescimento <span style="color:var(--text-muted);font-weight:400">(<?= $doneCount ?>/<?= count($steps) ?>)</span></p>
    <?php if (auth_can('manage_members')): ?>
      <a href="/pages/members/growth_steps.php" style="font-size:12px;color:var(--text-muted);text-decoration:none">Configurar etapas</a>
    <?php endif; ?>
  </div>
  <div style="padding:16px 18px;display:flex;flex-direction:column;gap:4px">
    <?php $canToggleTrail = auth_can('manage_members'); ?>
    <?php foreach ($steps as $s): $isDone = $s['completed_at'] !== null; ?>
      <form method="POST" action="/pages/members/growth_toggle.php" style="display:flex;align-items:center;gap:12px;padding:8px 0">
        <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
        <input type="hidden" name="step_id" value="<?= $s['id'] ?>">
        <input type="hidden" name="done" value="<?= $isDone ? '0' : '1' ?>">
        <?php if ($canToggleTrail): ?>
          <button type="submit" style="border:none;background:none;cursor:pointer;padding:0;font-size:20px;line-height:1;flex-shrink:0"
                  title="<?= $isDone ? 'Desmarcar' : 'Marcar como concluído' ?>">
            <?= $isDone ? '✅' : '⬜' ?>
          </button>
        <?php else: ?>
          <span style="font-size:20px;line-height:1;flex-shrink:0"><?= $isDone ? '✅' : '⬜' ?></span>
        <?php endif; ?>
        <div style="font-size:16px;flex-shrink:0"><?= htmlspecialchars($s['icon']) ?></div>
        <div style="flex:1">
          <div style="font-size:13px;font-weight:500;<?= $isDone ? '' : 'color:var(--text-muted)' ?>"><?= htmlspecialchars($s['name']) ?></div>
          <?php if ($s['description']): ?>
            <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($s['description']) ?></div>
          <?php endif; ?>
        </div>
        <?php if ($isDone && $s['completed_at']): ?>
          <span style="font-size:11px;color:var(--accent);flex-shrink:0"><?= fdate($s['completed_at']) ?></span>
        <?php endif; ?>
      </form>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php
// Discipulado: etapa do currículo (1º/2º Passo) e o discipulado em andamento (se houver)
$canSetDiscStep = auth_is_discipleship_coordinator();
$discActive     = member_current_discipleship_as_disciple($db, (int)$m['id']);
$discDiscipling = member_current_disciples($db, (int)$m['id']);
?>
<div class="card" style="margin-top:16px;padding:0">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
    <p style="font-weight:500;font-size:14px">🤝 Discipulado</p>
  </div>
  <div style="padding:16px 18px">
    <?php if ($canSetDiscStep): ?>
      <form method="POST" action="/pages/members/discipleship_step_set.php" style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
        <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
        <label class="form-label" style="margin-bottom:0">Etapa do currículo</label>
        <select name="step" class="form-control" style="width:auto" onchange="this.form.submit()">
          <?php foreach (DISCIPLESHIP_STEP_LABELS as $val => $label): ?>
            <option value="<?= $val ?>" <?= (int)$m['discipleship_step'] === $val ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php else: ?>
      <p style="font-size:13px;margin-bottom:12px">Etapa do currículo: <strong><?= DISCIPLESHIP_STEP_LABELS[(int)$m['discipleship_step']] ?? 'Nenhum' ?></strong></p>
    <?php endif; ?>

    <?php if ($discActive): ?>
      <p style="font-size:13px">Sendo discipulado(a) por <strong><?= htmlspecialchars($discActive['discipler_name']) ?></strong></p>
    <?php endif; ?>
    <?php if ($discDiscipling): ?>
      <p style="font-size:13px;<?= $discActive ? 'margin-top:6px' : '' ?>">Discipulando: <strong><?= htmlspecialchars(implode(', ', array_column($discDiscipling, 'disciple_name'))) ?></strong></p>
    <?php endif; ?>
    <?php if (!$discActive && !$discDiscipling): ?>
      <p style="font-size:12px;color:var(--text-muted)">Sem discipulado em andamento.</p>
    <?php endif; ?>
  </div>
</div>

<?php
// Histórico de transferências entre filiais
$visits = $db->prepare("
    SELECT mv.*, cf.name AS from_name, ct.name AS to_name, mb.name AS created_name
    FROM member_visits mv
    JOIN churches cf ON cf.id = mv.from_church
    JOIN churches ct ON ct.id = mv.to_church
    LEFT JOIN members mb ON mb.id = mv.created_by
    WHERE mv.member_id = ?
    ORDER BY mv.visit_date DESC
");
$visits->execute([$m['id']]);
$visits = $visits->fetchAll();

if (!empty($visits)):
?>
<div class="card" style="margin-top:16px;padding:0">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
    <p style="font-weight:500;font-size:14px">🔄 Histórico de transferências</p>
  </div>
  <?php foreach ($visits as $v): ?>
    <div style="padding:12px 18px;border-bottom:1px solid var(--border);font-size:13px">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <span class="badge badge-gray"><?= htmlspecialchars($v['from_name']) ?></span>
        <span style="color:var(--text-muted)">→</span>
        <span class="badge badge-green"><?= htmlspecialchars($v['to_name']) ?></span>
        <span style="color:var(--text-muted);font-size:12px;margin-left:4px">
          <?= date('d/m/Y', strtotime($v['visit_date'])) ?>
          <?= $v['created_name'] ? '· por ' . htmlspecialchars($v['created_name']) : '' ?>
        </span>
      </div>
      <?php if ($v['notes']): ?>
        <div style="margin-top:4px;color:var(--text-muted);font-style:italic;font-size:12px">
          "<?= htmlspecialchars($v['notes']) ?>"
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
