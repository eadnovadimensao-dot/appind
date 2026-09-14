<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
require_once __DIR__ . "/../../includes/music_roles.php";
auth_check();

$db = db();
$id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("
    SELECT mn.*, m.name AS leader_name, ch.name AS branch_name, ch.type AS branch_type
    FROM ministries mn
    LEFT JOIN members m   ON m.id  = mn.leader_id
    LEFT JOIN churches ch ON ch.id = mn.church_id
    WHERE mn.id = ? AND (ch.id = ? OR ch.parent_id = ?)
");
$stmt->execute([$id, SEDE_ID, SEDE_ID]);
$mn = $stmt->fetch();
if (!$mn) { header('Location: /pages/ministries/index.php'); exit; }

$pageTitle  = $mn['name'];
$activePage = 'ministries';
$churchId   = $mn['church_id'];
$isMusic    = (bool)($mn['auto_scale_enabled'] ?? false);
$canManage  = auth_can_manage_ministry($id);
// Buscar todos os líderes do ministério
$leadersStmt = $db->prepare("
    SELECT m.id, m.name, m.photo_url,
           (m.id = ?) AS is_main
    FROM ministry_leaders ml
    JOIN members m ON m.id = ml.member_id
    WHERE ml.ministry_id = ?
    ORDER BY is_main DESC, m.name
");
$leadersStmt->execute([$mn['leader_id'], $id]);
$ministryLeaders = $leadersStmt->fetchAll(); // filial do ministério
require_once __DIR__ . '/../../includes/layout.php';

// Membros
$members = $db->prepare("
    SELECT m.id, m.name, m.phone, m.status, mm.joined_at, mm.role
    FROM member_ministries mm
    JOIN members m ON m.id = mm.member_id
    WHERE mm.ministry_id = ?
    ORDER BY m.name
");
$members->execute([$id]);
$members = $members->fetchAll();

// Próximas atividades
$upcoming = $db->prepare("
    SELECT ma.*, COUNT(mam.member_id) AS scaled_count
    FROM ministry_activities ma
    LEFT JOIN ministry_activity_members mam ON mam.activity_id = ma.id
    WHERE ma.ministry_id = ? AND ma.activity_date >= CURDATE() AND ma.status = 'scheduled'
    GROUP BY ma.id
    ORDER BY ma.activity_date ASC
    LIMIT 5
");
$upcoming->execute([$id]);
$upcoming = $upcoming->fetchAll();

// Atividades passadas
$past = $db->prepare("
    SELECT ma.*, COUNT(mam.member_id) AS scaled_count
    FROM ministry_activities ma
    LEFT JOIN ministry_activity_members mam ON mam.activity_id = ma.id
    WHERE ma.ministry_id = ? AND (ma.activity_date < CURDATE() OR ma.status != 'scheduled')
    GROUP BY ma.id
    ORDER BY ma.activity_date DESC
    LIMIT 5
");
$past->execute([$id]);
$past = $past->fetchAll();

$days = ['monday'=>'Segunda-feira','tuesday'=>'Terça-feira','wednesday'=>'Quarta-feira',
         'thursday'=>'Quinta-feira','friday'=>'Sexta-feira','saturday'=>'Sábado','sunday'=>'Domingo'];

$statusLabels = [
    'active'=>['label'=>'Ativo','badge'=>'badge-green'],
    'visitor'=>['label'=>'Visitante','badge'=>'badge-blue'],
    'inactive'=>['label'=>'Afastado','badge'=>'badge-gray'],
];

$actStatusLabels = [
    'scheduled' => ['label'=>'Agendada',  'badge'=>'badge-blue'],
    'done'      => ['label'=>'Realizada', 'badge'=>'badge-green'],
    'cancelled' => ['label'=>'Cancelada', 'badge'=>'badge-gray'],
];

$actTypeLabels = [
    'ensaio' => ['label'=>'Ensaio', 'badge'=>'badge-gray'],
    'culto'  => ['label'=>'Culto',  'badge'=>'badge-blue'],
];
?>

<!-- Header -->
<div class="card" style="margin-bottom:16px">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;flex-wrap:wrap">
        <h1 style="font-size:18px;font-weight:500"><?= htmlspecialchars($mn['name']) ?></h1>
        <span class="badge <?= $mn['active'] ? 'badge-green' : 'badge-gray' ?>">
          <?= $mn['active'] ? 'Ativo' : 'Inativo' ?>
        </span>
        <span class="badge <?= ($mn['branch_type']??'')==='sede'?'badge-blue':'badge-green' ?>" style="font-size:11px">
          <?= htmlspecialchars($mn['branch_name'] ?? '') ?>
        </span>
      </div>
      <div style="font-size:13px;color:var(--text-muted);display:flex;flex-direction:column;gap:3px">
        <?php if (!empty($ministryLeaders)): ?>
          <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
            <?php foreach ($ministryLeaders as $l): ?>
              <span style="display:flex;align-items:center;gap:6px;font-size:13px">
                👤 <strong style="color:var(--text)"><?= htmlspecialchars($l['name']) ?></strong>
                <?php if ($l['is_main']): ?>
                  <span class="badge badge-green" style="font-size:10px">Principal</span>
                <?php else: ?>
                  <span class="badge badge-gray" style="font-size:10px">Líder</span>
                <?php endif; ?>
              </span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if ($mn['meeting_day']): ?>
          <span>📅 <?= $days[$mn['meeting_day']] ?? $mn['meeting_day'] ?>
            <?= $mn['meeting_time'] ? ' às ' . substr($mn['meeting_time'],0,5) : '' ?></span>
        <?php endif; ?>
        <?php if ($mn['description']): ?>
          <span style="margin-top:4px;color:var(--text)"><?= htmlspecialchars($mn['description']) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a href="/pages/ministries/resources.php?ministry_id=<?= $id ?>" class="btn btn-secondary">📁 Materiais</a>
      <a href="/pages/ministries/items.php?ministry_id=<?= $id ?>" class="btn btn-secondary">🎒 Pertences</a>
      <?php if ($canManage): ?>
        <a href="/pages/ministries/activity_create.php?ministry_id=<?= $id ?>" class="btn btn-primary">+ Atividade</a>
        <a href="/pages/ministries/edit.php?id=<?= $id ?>" class="btn btn-secondary">Editar</a>
      <?php endif; ?>
      <a href="/pages/ministries/index.php" class="btn btn-secondary">Voltar</a>
    </div>
  </div>
</div>

<!-- KPIs -->
<div class="kpi-grid" style="margin-bottom:16px">
  <div class="kpi">
    <div class="kpi-icon">👥</div>
    <div class="kpi-label">Membros</div>
    <div class="kpi-value"><?= count($members) ?></div>
  </div>
  <div class="kpi">
    <div class="kpi-icon">📅</div>
    <div class="kpi-label">Próximas atividades</div>
    <div class="kpi-value"><?= count($upcoming) ?></div>
  </div>
  <div class="kpi">
    <div class="kpi-icon">✅</div>
    <div class="kpi-label">Atividades realizadas</div>
    <div class="kpi-value"><?= count($past) ?></div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

  <!-- Membros -->
  <div class="card" style="padding:0">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
      <p style="font-weight:500;font-size:14px">Membros <span style="color:var(--text-muted);font-weight:400">(<?= count($members) ?>)</span></p>
      <?php if ($canManage): ?>
        <button onclick="var f=document.getElementById('add-member-form');f.style.display=f.style.display==='none'?'block':'none'"
                class="btn btn-secondary" style="font-size:12px;padding:5px 12px">+ Vincular</button>
      <?php endif; ?>
    </div>
    <?php if ($canManage): ?>
    <!-- Vincular membro -->
    <div id="add-member-form" style="display:none;padding:12px 18px;border-bottom:1px solid var(--border);background:#fafafa">
      <?php
        $avail = $db->prepare("
            SELECT m.id, m.name FROM members m
            WHERE m.church_id = ? AND m.status = 'active'
              AND m.id NOT IN (SELECT member_id FROM member_ministries WHERE ministry_id = ?)
            ORDER BY m.name
        ");
        $avail->execute([$churchId, $id]);
        $avail = $avail->fetchAll();
      ?>
      <form method="POST" action="/pages/ministries/add_member.php" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="ministry_id" value="<?= $id ?>">
        <div style="flex:1;min-width:180px">
          <label class="form-label">Selecione o membro</label>
          <select name="member_id" class="form-control">
            <option value="">Escolha…</option>
            <?php foreach ($avail as $av): ?>
              <option value="<?= $av['id'] ?>"><?= htmlspecialchars($av['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="flex:1;min-width:160px">
          <label class="form-label">Função (fixa)</label>
          <?php if ($isMusic): ?>
            <select name="role" class="form-control">
              <option value="">Selecione…</option>
              <?php foreach (MUSIC_ROLE_OPTIONS as $opt): ?>
                <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <input type="text" name="role" class="form-control" placeholder="Ex: Guitarrista, Ministro de Louvor…">
          <?php endif; ?>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-bottom:16px">Vincular</button>
      </form>
    </div>
    <?php endif; ?>
    <?php if (empty($members)): ?>
      <div class="empty-state" style="padding:24px">Nenhum membro vinculado.</div>
    <?php else: ?>
      <?php foreach ($members as $m):
        $initials = strtoupper(implode('', array_map(fn($p) => $p[0], array_slice(explode(' ',$m['name']),0,2))));
        $st = $statusLabels[$m['status']] ?? ['label'=>$m['status'],'badge'=>'badge-gray'];
      ?>
        <div style="display:flex;align-items:center;gap:10px;padding:10px 18px;border-bottom:1px solid var(--border)">
          <div class="avatar"><?= $initials ?></div>
          <div style="flex:1;min-width:0">
            <a href="/pages/members/view.php?id=<?= $m['id'] ?>" style="font-size:13px;font-weight:500;color:var(--text);text-decoration:none">
              <?= htmlspecialchars($m['name']) ?>
            </a>
            <?php if ($m['joined_at']): ?>
              <div style="font-size:11px;color:var(--text-muted)">desde <?= date('d/m/Y', strtotime($m['joined_at'])) ?></div>
            <?php endif; ?>
            <?php if ($canManage): ?>
            <div class="role-view" id="role-view-<?= $m['id'] ?>" style="font-size:11px;color:var(--text-muted);margin-top:2px;cursor:pointer"
                 onclick="document.getElementById('role-view-<?= $m['id'] ?>').style.display='none';document.getElementById('role-edit-<?= $m['id'] ?>').style.display='flex'">
              🎵 <?= $m['role'] ? htmlspecialchars($m['role']) : 'Definir função…' ?> ✎
            </div>
            <form method="POST" action="/pages/ministries/update_member_role.php" id="role-edit-<?= $m['id'] ?>" style="display:none;gap:4px;margin-top:4px">
              <input type="hidden" name="ministry_id" value="<?= $id ?>">
              <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
              <?php if ($isMusic): ?>
                <select name="role" class="form-control" style="font-size:12px;padding:4px 8px">
                  <option value="">Selecione…</option>
                  <?php foreach (MUSIC_ROLE_OPTIONS as $opt): ?>
                    <option value="<?= htmlspecialchars($opt) ?>" <?= ($m['role'] ?? '')===$opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <input type="text" name="role" class="form-control" value="<?= htmlspecialchars($m['role'] ?? '') ?>"
                       placeholder="Ex: Guitarrista…" style="font-size:12px;padding:4px 8px">
              <?php endif; ?>
              <button type="submit" class="btn btn-secondary" style="font-size:11px;padding:4px 8px">Salvar</button>
            </form>
            <?php else: ?>
              <div style="font-size:11px;color:var(--text-muted);margin-top:2px">
                🎵 <?= $m['role'] ? htmlspecialchars($m['role']) : 'Sem função definida' ?>
              </div>
            <?php endif; ?>
          </div>
          <span class="badge <?= $st['badge'] ?>"><?= $st['label'] ?></span>
          <?php if ($canManage): ?>
          <a href="/pages/ministries/remove_member.php?ministry_id=<?= $id ?>&member_id=<?= $m['id'] ?>"
             style="font-size:18px;color:var(--text-muted);text-decoration:none;line-height:1"
             data-confirm="Remover <?= htmlspecialchars($m['name']) ?> do ministério?">×</a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Próximas atividades -->
  <div class="card" style="padding:0">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
      <p style="font-weight:500;font-size:14px">Próximas atividades</p>
      <?php if ($canManage): ?>
        <a href="/pages/ministries/activity_create.php?ministry_id=<?= $id ?>" style="font-size:12px;color:var(--accent);text-decoration:none">+ Nova</a>
      <?php endif; ?>
    </div>
    <?php if (empty($upcoming)): ?>
      <div class="empty-state" style="padding:24px">Nenhuma atividade agendada.</div>
    <?php else: ?>
      <?php foreach ($upcoming as $act): ?>
        <a href="/pages/ministries/activity_view.php?id=<?= $act['id'] ?>"
           style="display:block;padding:12px 18px;border-bottom:1px solid var(--border);text-decoration:none;color:var(--text)">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
            <div>
              <div style="display:flex;align-items:center;gap:6px">
                <span style="font-size:13px;font-weight:500"><?= htmlspecialchars($act['title']) ?></span>
                <?php $at = $actTypeLabels[$act['activity_type']] ?? null; if ($at): ?>
                  <span class="badge <?= $at['badge'] ?>" style="font-size:10px"><?= $at['label'] ?></span>
                <?php endif; ?>
              </div>
              <div style="font-size:12px;color:var(--text-muted);margin-top:2px">
                📅 <?= date('d/m/Y', strtotime($act['activity_date'])) ?>
                <?= $act['time_start'] ? ' às ' . substr($act['time_start'],0,5) : '' ?>
              </div>
              <?php if ($act['location']): ?>
                <div style="font-size:12px;color:var(--text-muted)">📍 <?= htmlspecialchars($act['location']) ?></div>
              <?php endif; ?>
            </div>
            <div style="text-align:right;flex-shrink:0">
              <span class="badge badge-blue"><?= $act['scaled_count'] ?> escalado(s)</span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($past)): ?>
      <div style="padding:10px 18px;border-top:1px solid var(--border)">
        <p style="font-size:12px;color:var(--text-muted);font-weight:500;margin-bottom:8px">Atividades anteriores</p>
        <?php foreach ($past as $act):
          $as = $actStatusLabels[$act['status']] ?? ['label'=>$act['status'],'badge'=>'badge-gray'];
        ?>
          <a href="/pages/ministries/activity_view.php?id=<?= $act['id'] ?>"
             style="display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid var(--border);text-decoration:none;color:var(--text);font-size:13px">
            <span>
              <?= htmlspecialchars($act['title']) ?> · <?= date('d/m/Y', strtotime($act['activity_date'])) ?>
              <?php $at = $actTypeLabels[$act['activity_type']] ?? null; if ($at): ?>
                <span class="badge <?= $at['badge'] ?>" style="font-size:10px"><?= $at['label'] ?></span>
              <?php endif; ?>
            </span>
            <span class="badge <?= $as['badge'] ?>"><?= $as['label'] ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
