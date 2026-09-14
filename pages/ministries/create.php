<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
auth_require('manage_ministries');
$db       = db();
$churchId = current_church_id();
$errors   = [];

$allMembers = $db->query("SELECT id, name FROM members WHERE church_id = $churchId AND status = 'active' ORDER BY name")->fetchAll();
$days       = ['monday'=>'Segunda-feira','tuesday'=>'Terça-feira','wednesday'=>'Quarta-feira',
               'thursday'=>'Quinta-feira','friday'=>'Sexta-feira','saturday'=>'Sábado','sunday'=>'Domingo'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['name']        ?? '');
    $description = trim($_POST['description'] ?? '');
    $leaderId    = (int)($_POST['leader_id']  ?? 0) ?: null;
    $leaderIds   = array_map('intval', $_POST['leader_ids'] ?? []);
    $day         = trim($_POST['meeting_day'] ?? '') ?: null;
    $time        = trim($_POST['meeting_time']?? '') ?: null;
    $active      = isset($_POST['active']) ? 1 : 0;
    $autoScale   = isset($_POST['auto_scale_enabled']) ? 1 : 0;
    $memberIds   = array_map('intval', $_POST['member_ids'] ?? []);

    if ($name === '') $errors[] = 'Nome do ministério é obrigatório.';
    if (empty($leaderIds)) $errors[] = 'Selecione pelo menos um líder.';

    if (empty($errors)) {
        // Líder principal = primeiro da lista ou o selecionado
        $mainLeader = $leaderId ?: ($leaderIds[0] ?? null);

        $db->prepare("
            INSERT INTO ministries (church_id, name, description, leader_id, meeting_day, meeting_time, active, auto_scale_enabled)
            VALUES (:church_id,:name,:desc,:leader_id,:day,:time,:active,:auto_scale)
        ")->execute([
            ':church_id' => $churchId, ':name' => $name, ':desc' => $description ?: null,
            ':leader_id' => $mainLeader, ':day' => $day, ':time' => $time, ':active' => $active,
            ':auto_scale' => $autoScale,
        ]);
        $ministryId = $db->lastInsertId();

        // Inserir líderes na tabela ministry_leaders
        $sl = $db->prepare("INSERT IGNORE INTO ministry_leaders (ministry_id, member_id) VALUES (?,?)");
        foreach ($leaderIds as $lid) $sl->execute([$ministryId, $lid]);

        // Inserir membros
        $sm = $db->prepare("INSERT IGNORE INTO member_ministries (member_id, ministry_id, joined_at) VALUES (?,?,CURDATE())");
        foreach ($memberIds as $mid) $sm->execute([$mid, $ministryId]);
        // Líderes também são membros
        foreach ($leaderIds as $lid) $sm->execute([$lid, $ministryId]);

        header('Location: /pages/ministries/index.php?saved=1');
        exit;
    }
}

$pageTitle    = 'Novo ministério';
$activePage   = 'ministries';
$topbarAction = null;
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
      <input type="text" name="name" class="form-control"
             placeholder="Ex: Ministério de Louvor, Infantil, Intercessão…"
             value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
    </div>
    <div class="form-group">
      <label class="form-label">Descrição</label>
      <textarea name="description" class="form-control" rows="2"
                placeholder="Descreva o propósito e visão do ministério…"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Dia de reunião / ensaio</label>
        <select name="meeting_day" class="form-control">
          <option value="">Selecione</option>
          <?php foreach ($days as $k => $v): ?>
            <option value="<?= $k ?>" <?= ($_POST['meeting_day']??'')===$k?'selected':''?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Horário</label>
        <input type="time" name="meeting_time" class="form-control"
               value="<?= htmlspecialchars($_POST['meeting_time'] ?? '') ?>">
      </div>
    </div>
    <p style="font-size:12px;color:var(--text-muted);margin-top:-8px;margin-bottom:16px">
      Se definido, toda escala de Culto criada gera automaticamente um Ensaio neste dia, com a mesma equipe.
    </p>
    <div class="form-group" style="margin-bottom:10px">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
        <input type="checkbox" name="active" value="1" <?= !isset($_POST['name']) || isset($_POST['active']) ? 'checked' : '' ?>>
        Ministério ativo
      </label>
    </div>
    <div class="form-group" style="margin-bottom:0">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
        <input type="checkbox" name="auto_scale_enabled" value="1" <?= isset($_POST['auto_scale_enabled']) ? 'checked' : '' ?>>
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
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">Selecione um ou mais líderes. O primeiro marcado será o líder principal.</p>
    <div style="border:1px solid var(--border);border-radius:7px;overflow:hidden;max-height:260px;overflow-y:auto">
      <?php foreach ($allMembers as $m): ?>
        <label style="display:flex;align-items:center;gap:10px;padding:9px 12px;cursor:pointer;border-bottom:1px solid var(--border);font-size:13px"
               onmouseover="this.style.background='#f9f9f9'" onmouseout="this.style.background=''">
          <input type="checkbox" name="leader_ids[]" value="<?= $m['id'] ?>"
                 <?= in_array($m['id'], $_POST['leader_ids']??[]) ? 'checked' : '' ?>>
          <div class="avatar" style="width:26px;height:26px;font-size:10px;flex-shrink:0">
            <?= strtoupper(substr($m['name'],0,2)) ?>
          </div>
          <?= htmlspecialchars($m['name']) ?>
        </label>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Membros -->
  <div class="card" style="margin-bottom:24px">
    <p class="card-title">Membros do ministério</p>
    <div style="border:1px solid var(--border);border-radius:7px;overflow:hidden;max-height:260px;overflow-y:auto">
      <?php foreach ($allMembers as $m): ?>
        <label style="display:flex;align-items:center;gap:10px;padding:9px 12px;cursor:pointer;border-bottom:1px solid var(--border);font-size:13px"
               onmouseover="this.style.background='#f9f9f9'" onmouseout="this.style.background=''">
          <input type="checkbox" name="member_ids[]" value="<?= $m['id'] ?>"
                 <?= in_array($m['id'], $_POST['member_ids']??[]) ? 'checked' : '' ?>>
          <div class="avatar" style="width:26px;height:26px;font-size:10px;flex-shrink:0">
            <?= strtoupper(substr($m['name'],0,2)) ?>
          </div>
          <?= htmlspecialchars($m['name']) ?>
        </label>
      <?php endforeach; ?>
    </div>
  </div>

  <div style="display:flex;gap:10px">
    <button type="submit" class="btn btn-primary">Salvar ministério</button>
    <a href="/pages/ministries/index.php" class="btn btn-secondary">Cancelar</a>
  </div>
</form>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
