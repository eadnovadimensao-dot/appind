<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
require_once __DIR__ . "/../../includes/music_roles.php";
auth_check();
$db       = db();
$churchId = current_church_id();
$id       = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("
    SELECT ma.*, mn.name AS ministry_name, mn.id AS ministry_id
    FROM ministry_activities ma
    JOIN ministries mn ON mn.id = ma.ministry_id
    WHERE ma.id = ? AND ma.church_id = ?
");
$stmt->execute([$id, $churchId]);
$act = $stmt->fetch();
if (!$act) { header('Location: /pages/ministries/index.php'); exit; }

$activePage = 'ministries';
require_once __DIR__ . '/../../includes/layout.php';



$pageTitle = $act['title'];
$isMusic   = is_music_ministry($act['ministry_name']);
$canManage = auth_can_manage_ministry((int)$act['ministry_id']);

// Escala
$scaled = $db->prepare("
    SELECT m.id, m.name, m.phone, mam.role, mam.confirmed, mam.status, mam.refuse_reason
    FROM ministry_activity_members mam
    JOIN members m ON m.id = mam.member_id
    WHERE mam.activity_id = ?
    ORDER BY m.name
");
$scaled->execute([$id]);
$scaled = $scaled->fetchAll();

// Membros do ministério que ainda não estão nessa escala (pra substituição/adição)
$available = $db->prepare("
    SELECT m.id, m.name, mm.role AS default_role
    FROM member_ministries mm
    JOIN members m ON m.id = mm.member_id
    WHERE mm.ministry_id = ? AND m.church_id = ?
      AND m.id NOT IN (SELECT member_id FROM ministry_activity_members WHERE activity_id = ?)
    ORDER BY m.name
");
$available->execute([$act['ministry_id'], $act['church_id'], $id]);
$available = $available->fetchAll();

// Repertório — músicas do catálogo (resource_id) têm prioridade sobre os campos
// locais legados (entradas avulsas criadas antes do catálogo existir)
$songs = $db->prepare("
    SELECT mas.id, mas.resource_id,
           COALESCE(r.title, mas.title) AS title,
           COALESCE(r.key_tone, mas.key_tone) AS key_tone,
           COALESCE(r.external_url, mas.reference_link) AS reference_link,
           COALESCE(r.file_path, mas.file_path) AS file_path
    FROM ministry_activity_songs mas
    LEFT JOIN ministry_resources r ON r.id = mas.resource_id
    WHERE mas.activity_id = ?
    ORDER BY mas.position, mas.id
");
$songs->execute([$id]);
$songs = $songs->fetchAll();

// Catálogo de músicas do ministério que ainda não estão nessa atividade
$songCatalog = $db->prepare("
    SELECT id, title, key_tone, external_url, file_path
    FROM ministry_resources
    WHERE ministry_id = ? AND type = 'song'
      AND id NOT IN (SELECT resource_id FROM ministry_activity_songs WHERE activity_id = ? AND resource_id IS NOT NULL)
    ORDER BY title
");
$songCatalog->execute([$act['ministry_id'], $id]);
$songCatalog = $songCatalog->fetchAll();

$statusLabels = [
    'scheduled' => ['label'=>'Agendada',  'badge'=>'badge-blue'],
    'done'      => ['label'=>'Realizada', 'badge'=>'badge-green'],
    'cancelled' => ['label'=>'Cancelada', 'badge'=>'badge-gray'],
];
$st = $statusLabels[$act['status']] ?? ['label'=>$act['status'],'badge'=>'badge-gray'];

$actTypeLabels = [
    'ensaio' => ['label'=>'Ensaio', 'badge'=>'badge-gray'],
    'culto'  => ['label'=>'Culto',  'badge'=>'badge-blue'],
];
$at = $actTypeLabels[$act['activity_type']] ?? null;
?>

<div style="margin-bottom:16px">
  <a href="/pages/ministries/view.php?id=<?= $act['ministry_id'] ?>" style="font-size:13px;color:var(--text-muted);text-decoration:none">
    ← <?= htmlspecialchars($act['ministry_name']) ?>
  </a>
</div>

<!-- Header -->
<div class="card" style="margin-bottom:16px">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
        <h1 style="font-size:18px;font-weight:500"><?= htmlspecialchars($act['title']) ?></h1>
        <span class="badge <?= $st['badge'] ?>"><?= $st['label'] ?></span>
        <?php if ($at): ?>
          <span class="badge <?= $at['badge'] ?>"><?= $at['label'] ?></span>
        <?php endif; ?>
      </div>
      <div style="font-size:13px;color:var(--text-muted);display:flex;flex-wrap:wrap;gap:12px">
        <span>📅 <?= date('d/m/Y', strtotime($act['activity_date'])) ?>
          <?= $act['time_start'] ? ' às ' . substr($act['time_start'],0,5) : '' ?>
          <?= $act['time_end']   ? ' — '  . substr($act['time_end'],0,5)   : '' ?>
        </span>
        <?php if ($act['location']): ?>
          <span>📍 <?= htmlspecialchars($act['location']) ?></span>
        <?php endif; ?>
      </div>
      <?php if ($act['description']): ?>
        <p style="margin-top:8px;font-size:13px;color:var(--text);line-height:1.6"><?= nl2br(htmlspecialchars($act['description'])) ?></p>
      <?php endif; ?>
    </div>
    <!-- Ações de status -->
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php if ($act['status'] === 'scheduled'): ?>
        <a href="/pages/ministries/activity_edit.php?id=<?= $id ?>" class="btn btn-secondary">✎ Editar</a>
        <a href="/pages/ministries/activity_status.php?id=<?= $id ?>&status=done"
           class="btn btn-primary"
           data-confirm="Marcar esta atividade como realizada?">✓ Realizada</a>
        <a href="/pages/ministries/activity_status.php?id=<?= $id ?>&status=cancelled"
           class="btn btn-secondary" style="color:var(--red)"
           data-confirm="Cancelar esta atividade?">Cancelar</a>
      <?php endif; ?>
      <a href="/pages/ministries/activity_delete.php?id=<?= $id ?>"
         class="btn btn-secondary" style="color:var(--red)"
         data-confirm="Excluir esta atividade permanentemente? Remove a escala, o repertório e o evento na Agenda também. Não pode ser desfeito.">
        🗑 Excluir
      </a>
      <a href="/pages/ministries/view.php?id=<?= $act['ministry_id'] ?>" class="btn btn-secondary">Voltar</a>
    </div>
  </div>
</div>

<!-- Repertório -->
<div class="card" style="padding:0;margin-bottom:16px">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
    <p style="font-weight:500;font-size:14px">Repertório <span style="color:var(--text-muted);font-weight:400">(<?= count($songs) ?>)</span></p>
  </div>
  <?php if (empty($songs)): ?>
    <div class="empty-state" style="padding:24px">Nenhuma música adicionada.</div>
  <?php else: ?>
    <?php foreach ($songs as $sg): ?>
      <div style="display:flex;align-items:center;gap:10px;padding:10px 18px;border-bottom:1px solid var(--border)">
        <div style="flex:1;min-width:0">
          <span style="font-size:13px;font-weight:500"><?= htmlspecialchars($sg['title']) ?></span>
          <?php if ($sg['key_tone']): ?>
            <span class="badge badge-gray" style="font-size:10px;margin-left:6px">Tom: <?= htmlspecialchars($sg['key_tone']) ?></span>
          <?php endif; ?>
          <div style="font-size:12px;margin-top:2px;display:flex;gap:12px">
            <?php if ($sg['reference_link']): ?>
              <a href="<?= htmlspecialchars($sg['reference_link']) ?>" target="_blank" rel="noopener" style="color:var(--accent);text-decoration:none">🔗 Referência</a>
            <?php endif; ?>
            <?php if ($sg['file_path']): ?>
              <a href="<?= htmlspecialchars($sg['file_path']) ?>" target="_blank" rel="noopener" style="color:var(--accent);text-decoration:none">📄 Cifra/partitura</a>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($canManage): ?>
          <a href="/pages/ministries/song_delete.php?song_id=<?= $sg['id'] ?>&activity_id=<?= $id ?>"
             style="font-size:18px;color:var(--text-muted);text-decoration:none;line-height:1"
             data-confirm="Remover <?= htmlspecialchars($sg['title']) ?> do repertório?">×</a>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
  <?php if ($canManage): ?>
  <div style="padding:12px 18px;background:#fafafa">
    <?php if (empty($songCatalog)): ?>
      <p style="font-size:12px;color:var(--text-muted)">
        Todo o catálogo já está nessa atividade, ou ainda não há músicas cadastradas.
        <a href="/pages/ministries/resources.php?ministry_id=<?= $act['ministry_id'] ?>" target="_blank">Adicionar em Materiais</a>
      </p>
    <?php else: ?>
      <form method="POST" action="/pages/ministries/song_add.php">
        <input type="hidden" name="activity_id" value="<?= $id ?>">
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:8px">+ Adicionar do catálogo:</p>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px">
          <?php foreach ($songCatalog as $sg): ?>
            <label style="display:flex;align-items:center;gap:5px;border:1px solid var(--border);border-radius:20px;padding:5px 12px;font-size:12px;cursor:pointer;background:white">
              <input type="checkbox" name="resource_ids[]" value="<?= $sg['id'] ?>">
              <?= htmlspecialchars($sg['title']) ?>
              <?= $sg['key_tone'] ? ' · ' . htmlspecialchars($sg['key_tone']) : '' ?>
            </label>
          <?php endforeach; ?>
        </div>
        <button type="submit" class="btn btn-primary" style="font-size:12px">+ Adicionar selecionadas</button>
      </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Escala -->
<div class="card" style="padding:0">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
    <p style="font-weight:500;font-size:14px">Escala <span style="color:var(--text-muted);font-weight:400">(<?= count($scaled) ?> pessoas)</span></p>
    <?php if ($act['status'] === 'scheduled' && !empty($available)): ?>
      <button onclick="var f=document.getElementById('add-scale-form');f.style.display=f.style.display==='none'?'flex':'none'"
              class="btn btn-secondary" style="font-size:12px;padding:5px 12px">+ Adicionar / Substituir</button>
    <?php endif; ?>
  </div>
  <?php if ($act['status'] === 'scheduled' && !empty($available)): ?>
    <form method="POST" action="/pages/ministries/activity_add_member.php" id="add-scale-form"
          style="display:none;gap:8px;align-items:flex-end;flex-wrap:wrap;padding:12px 18px;border-bottom:1px solid var(--border);background:#fafafa">
      <input type="hidden" name="activity_id" value="<?= $id ?>">
      <div style="flex:1;min-width:180px">
        <label class="form-label">Membro</label>
        <select name="member_id" class="form-control" id="add-scale-member" onchange="document.getElementById('add-scale-role').value=this.selectedOptions[0].dataset.role||''">
          <option value="">Selecione…</option>
          <?php foreach ($available as $av): ?>
            <option value="<?= $av['id'] ?>" data-role="<?= htmlspecialchars($av['default_role'] ?? '') ?>">
              <?= htmlspecialchars($av['name']) ?><?= $av['default_role'] ? ' · ' . htmlspecialchars($av['default_role']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="flex:1;min-width:160px">
        <label class="form-label">Função</label>
        <?php if ($isMusic): ?>
          <select name="role" class="form-control" id="add-scale-role">
            <option value="">Selecione…</option>
            <?php foreach (MUSIC_ROLE_OPTIONS as $opt): ?>
              <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <input type="text" name="role" class="form-control" id="add-scale-role" placeholder="Função…">
        <?php endif; ?>
      </div>
      <button type="submit" class="btn btn-primary" style="margin-bottom:16px">Escalar</button>
    </form>
  <?php endif; ?>
  <?php if (empty($scaled)): ?>
    <div class="empty-state" style="padding:24px">Nenhuma pessoa escalada.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Nome</th>
            <th>Função</th>
            <th>Telefone</th>
            <th>Resposta do membro</th>
          </tr>
        </thead>
        <tbody>
          <?php
            $respStatusLabels = [
                'pending'   => ['label'=>'Pendente',      'badge'=>'badge-gray'],
                'confirmed' => ['label'=>'✓ Confirmado',  'badge'=>'badge-green'],
                'refused'   => ['label'=>'✗ Recusou',     'badge'=>'badge-red'],
            ];
          ?>
          <?php foreach ($scaled as $s):
            $initials = strtoupper(implode('', array_map(fn($p) => $p[0], array_slice(explode(' ',$s['name']),0,2))));
            $rs = $respStatusLabels[$s['status'] ?? 'pending'] ?? $respStatusLabels['pending'];
          ?>
            <tr>
              <td>
                <div style="display:flex;align-items:center;gap:10px">
                  <div class="avatar"><?= $initials ?></div>
                  <a href="/pages/members/view.php?id=<?= $s['id'] ?>" style="color:var(--text);text-decoration:none;font-weight:500">
                    <?= htmlspecialchars($s['name']) ?>
                  </a>
                </div>
              </td>
              <td style="color:var(--text-muted)"><?= htmlspecialchars($s['role'] ?? '—') ?></td>
              <td style="color:var(--text-muted)"><?= htmlspecialchars($s['phone'] ?? '—') ?></td>
              <td>
                <a href="/pages/ministries/activity_confirm.php?activity_id=<?= $id ?>&member_id=<?= $s['id'] ?>&confirmed=<?= $s['status']==='confirmed' ? 0 : 1 ?>"
                   class="badge <?= $rs['badge'] ?>"
                   style="cursor:pointer;text-decoration:none"
                   title="Clique pra marcar/desmarcar manualmente (sobrescreve a resposta do membro)">
                  <?= $rs['label'] ?>
                </a>
                <?php if ($s['status'] === 'refused' && $s['refuse_reason']): ?>
                  <div style="font-size:11px;color:var(--text-muted);margin-top:4px;max-width:220px">
                    💬 <?= htmlspecialchars($s['refuse_reason']) ?>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
