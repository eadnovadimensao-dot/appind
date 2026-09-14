<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db       = db();
$churchId = current_church_id();
$id       = (int)($_GET['id'] ?? 0);
auth_require_ministry($id);
$errors   = [];

$stmt = $db->prepare("SELECT * FROM ministries WHERE id = ? AND church_id = ?");
$stmt->execute([$id, $churchId]);
$mn = $stmt->fetch();
if (!$mn) { header('Location: /pages/ministries/index.php'); exit; }

$allMembers    = $db->query("SELECT id, name FROM members WHERE church_id = $churchId AND status = 'active' ORDER BY name")->fetchAll();
$currentLeaders = $db->prepare("SELECT member_id FROM ministry_leaders WHERE ministry_id = ?");
$currentLeaders->execute([$id]);
$currentLeaderIds = array_column($currentLeaders->fetchAll(), 'member_id');

$days = ['monday'=>'Segunda-feira','tuesday'=>'Terça-feira','wednesday'=>'Quarta-feira',
         'thursday'=>'Quinta-feira','friday'=>'Sexta-feira','saturday'=>'Sábado','sunday'=>'Domingo'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name      = trim($_POST['name']        ?? '');
    $desc      = trim($_POST['description'] ?? '');
    $leaderIds = array_map('intval', $_POST['leader_ids'] ?? []);
    $day       = trim($_POST['meeting_day'] ?? '') ?: null;
    $time      = trim($_POST['meeting_time']?? '') ?: null;
    $active    = isset($_POST['active']) ? 1 : 0;
    $autoScale = isset($_POST['auto_scale_enabled']) ? 1 : 0;

    if ($name === '') $errors[] = 'Nome é obrigatório.';
    if (empty($leaderIds)) $errors[] = 'Selecione pelo menos um líder.';

    if (empty($errors)) {
        $mainLeader = $leaderIds[0] ?? $mn['leader_id'];

        $db->prepare("
            UPDATE ministries SET name=:name, description=:desc, leader_id=:leader,
              meeting_day=:day, meeting_time=:time, active=:active, auto_scale_enabled=:auto_scale
            WHERE id=:id AND church_id=:church_id
        ")->execute([':name'=>$name,':desc'=>$desc?:null,':leader'=>$mainLeader,
                     ':day'=>$day,':time'=>$time,':active'=>$active,':auto_scale'=>$autoScale,
                     ':id'=>$id,':church_id'=>$churchId]);

        // Atualizar tabela ministry_leaders
        $db->prepare("DELETE FROM ministry_leaders WHERE ministry_id=?")->execute([$id]);
        $sl = $db->prepare("INSERT IGNORE INTO ministry_leaders (ministry_id, member_id) VALUES (?,?)");
        foreach ($leaderIds as $lid) $sl->execute([$id, $lid]);

        header('Location: /pages/ministries/view.php?id='.$id.'&saved=1');
        exit;
    }
    $currentLeaderIds = array_map('intval', $_POST['leader_ids'] ?? []);
    $mn = array_merge($mn, $_POST);
}

$pageTitle  = 'Editar · ' . $mn['name'];
$activePage = 'ministries';
require_once __DIR__ . '/../../includes/layout.php';
?>
<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>
<form method="POST" style="width:100%">
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Dados do ministério</p>
    <div class="form-group">
      <label class="form-label">Nome *</label>
      <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($mn['name']) ?>" required>
    </div>
    <div class="form-group">
      <label class="form-label">Descrição</label>
      <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($mn['description'] ?? '') ?></textarea>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Dia de reunião / ensaio</label>
        <select name="meeting_day" class="form-control">
          <option value="">Selecione</option>
          <?php foreach ($days as $k => $v): ?>
            <option value="<?= $k ?>" <?= ($mn['meeting_day']??'')===$k?'selected':''?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Horário</label>
        <input type="time" name="meeting_time" class="form-control" value="<?= $mn['meeting_time'] ? substr($mn['meeting_time'],0,5) : '' ?>">
      </div>
    </div>
    <p style="font-size:12px;color:var(--text-muted);margin-top:-8px;margin-bottom:16px">
      Se definido, toda escala de Culto criada gera automaticamente um Ensaio neste dia, com a mesma equipe.
    </p>
    <div class="form-group" style="margin-bottom:10px">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
        <input type="checkbox" name="active" value="1" <?= $mn['active']?'checked':''?>>
        Ministério ativo
      </label>
    </div>
    <div class="form-group" style="margin-bottom:0">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
        <input type="checkbox" name="auto_scale_enabled" value="1" <?= !empty($mn['auto_scale_enabled'])?'checked':''?>>
        🎲 Usar escala automática com funções fixas (vocal, instrumentos, etc.)
      </label>
      <p style="font-size:11px;color:var(--text-muted);margin-top:4px;margin-left:24px">
        Ative pra ministérios de louvor/música — libera o sorteio automático de escala e as funções fixas na tela de atividade.
      </p>
    </div>
  </div>

  <!-- Líderes -->
  <div class="card" style="margin-bottom:16px">
    <p class="card-title">Líderes do ministério *</p>
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">O primeiro marcado será o líder principal.</p>
    <div style="border:1px solid var(--border);border-radius:7px;overflow:hidden;max-height:260px;overflow-y:auto">
      <?php foreach ($allMembers as $m): ?>
        <label style="display:flex;align-items:center;gap:10px;padding:9px 12px;cursor:pointer;border-bottom:1px solid var(--border);font-size:13px"
               onmouseover="this.style.background='#f9f9f9'" onmouseout="this.style.background=''">
          <input type="checkbox" name="leader_ids[]" value="<?= $m['id'] ?>"
                 <?= in_array($m['id'], $currentLeaderIds) ? 'checked' : '' ?>>
          <div class="avatar" style="width:26px;height:26px;font-size:10px;flex-shrink:0">
            <?= strtoupper(substr($m['name'],0,2)) ?>
          </div>
          <?= htmlspecialchars($m['name']) ?>
          <?php if ($m['id'] == $mn['leader_id']): ?>
            <span class="badge badge-green" style="font-size:10px;margin-left:auto">Principal</span>
          <?php endif; ?>
        </label>
      <?php endforeach; ?>
    </div>
  </div>

  <div style="display:flex;gap:10px;align-items:center">
    <button type="submit" class="btn btn-primary">Salvar alterações</button>
    <a href="/pages/ministries/view.php?id=<?= $id ?>" class="btn btn-secondary">Cancelar</a>
    <a href="/pages/ministries/delete.php?id=<?= $id ?>"
       class="btn btn-secondary" style="margin-left:auto;color:var(--red)"
       data-confirm="Excluir este ministério?">Excluir</a>
  </div>
</form>
<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
