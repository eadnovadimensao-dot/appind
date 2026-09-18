<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
auth_check();
// Criar/editar/excluir filiais: só supermaster
if (auth_role() !== 'supermaster') {
    http_response_code(403);
    include __DIR__ . '/../includes/403.php';
    exit;
}

$db     = db();
$errors = [];

// Todos os membros ativos (para selecionar pastores)
$allMembersStmt = $db->prepare("
    SELECT m.id, m.name, ch.name AS branch_name
    FROM members m
    JOIN churches ch ON ch.id = m.church_id
    WHERE m.status = 'active' AND (ch.id = ? OR ch.parent_id = ?)
    ORDER BY m.name
");
$allMembersStmt->execute([SEDE_ID, SEDE_ID]);
$allMembers = $allMembersStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? '';
    $pastorIds  = $_POST['pastor_ids']  ?? [];
    $pastorRoles= $_POST['pastor_roles'] ?? [];

    if ($action === 'save') {
        $editId     = (int)($_POST['edit_id'] ?? 0);
        $name       = trim($_POST['name']    ?? '');
        $address    = trim($_POST['address'] ?? '');
        $phone      = trim($_POST['phone']   ?? '');

        if ($name === '') $errors[] = 'Nome é obrigatório.';

        if (empty($errors)) {
            if ($editId) {
                $db->prepare("UPDATE churches SET name=?,address=?,phone=? WHERE id=? AND parent_id=?")
                   ->execute([$name, $address?:null, $phone?:null, $editId, SEDE_ID]);
                $churchId = $editId;
            } else {
                $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
                $db->prepare("INSERT INTO churches (name,slug,type,parent_id,address,phone) VALUES (?,?,'branch',?,?,?)")
                   ->execute([$name,$slug,SEDE_ID,$address?:null,$phone?:null]);
                $churchId = $db->lastInsertId();
                // Copiar a identidade da sede (cores, contato...). Pix nunca é copiado (cada
                // igreja tem a sua chave) e e-mail/WhatsApp/push já vêm sempre da sede.
                $skip = array_merge(SETTINGS_PER_CHURCH_ONLY, SETTINGS_ALWAYS_FROM_SEDE);
                $ph   = implode(',', array_fill(0, count($skip), '?'));
                $db->prepare("
                    INSERT IGNORE INTO church_settings (church_id, `key`, `value`)
                    SELECT ?, `key`, `value` FROM church_settings WHERE church_id = ? AND `key` NOT IN ($ph)
                ")->execute(array_merge([$churchId, SEDE_ID], $skip));
                $db->prepare("UPDATE church_settings SET `value`=? WHERE church_id=? AND `key`='church_name'")
                   ->execute([$name, $churchId]);
            }

            // Salvar pastores e transferir para a filial automaticamente
            $db->prepare("DELETE FROM church_pastors WHERE church_id=?")->execute([$churchId]);
            if (!empty($pastorIds)) {
                $sp = $db->prepare("INSERT IGNORE INTO church_pastors (church_id, member_id, role) VALUES (?,?,?)");
                $sm = $db->prepare("UPDATE members SET church_id=? WHERE id=?");
                foreach ($pastorIds as $mid) {
                    $role = $pastorRoles[$mid] ?? 'pastor';
                    $sp->execute([$churchId, (int)$mid, $role]);
                    // Transfere o membro para a filial automaticamente
                    $sm->execute([$churchId, (int)$mid]);
                }
            }

            header('Location: /pages/branches.php?saved=1');
            exit;
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['branch_id'] ?? 0);

        // Tudo que referencia churches.id e bloqueia a exclusão (FK RESTRICT) —
        // church_settings é só config copiada da sede na criação, não é "dado"
        // de verdade, então é limpa automaticamente em vez de bloquear.
        $blockingChecks = [
            'members'             => ['column' => 'church_id', 'label' => 'membro(s)'],
            'cells'               => ['column' => 'church_id', 'label' => 'célula(s)'],
            'cell_reports'        => ['column' => 'church_id', 'label' => 'relatório(s) de célula'],
            'ministries'          => ['column' => 'church_id', 'label' => 'ministério(s)'],
            'ministry_activities' => ['column' => 'church_id', 'label' => 'atividade(s) de ministério'],
            'ministry_items'      => ['column' => 'church_id', 'label' => 'item(ns)/pertence(s) de ministério'],
            'ministry_loans'      => ['column' => 'church_id', 'label' => 'empréstimo(s) de ministério'],
            'services'            => ['column' => 'church_id', 'label' => 'culto(s)'],
            'service_templates'   => ['column' => 'church_id', 'label' => 'modelo(s) de culto'],
            'supervisors'         => ['column' => 'church_id', 'label' => 'supervisor(es)'],
            'supervisor_rotation' => ['column' => 'church_id', 'label' => 'rotação(ões) de supervisor'],
            'agenda_events'       => ['column' => 'church_id', 'label' => 'evento(s) na agenda'],
            'events'              => ['column' => 'church_id', 'label' => 'evento(s)'],
            'announcements'       => ['column' => 'church_id', 'label' => 'aviso(s) de comunicação'],
            'families'            => ['column' => 'church_id', 'label' => 'família(s)'],
            'finance_categories'  => ['column' => 'church_id', 'label' => 'categoria(s) financeira(s)'],
            'finance_entries'     => ['column' => 'church_id', 'label' => 'lançamento(s) financeiro(s)'],
            'locations'           => ['column' => 'church_id', 'label' => 'local(is) cadastrado(s)'],
            'worship_scales'      => ['column' => 'church_id', 'label' => 'escala(s) de culto (legado)'],
            'churches'            => ['column' => 'parent_id', 'label' => 'sub-filial(is)'],
        ];

        $blockers = [];
        foreach ($blockingChecks as $table => $chk) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM `$table` WHERE `{$chk['column']}` = ?");
            $stmt->execute([$id]);
            $count = (int)$stmt->fetchColumn();
            if ($count > 0) $blockers[] = "$count {$chk['label']}";
        }

        // users e member_visits têm duas colunas cada — checa como um bloco só
        $userStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE church_id = ? OR branch_id = ?");
        $userStmt->execute([$id, $id]);
        $userCount = (int)$userStmt->fetchColumn();
        if ($userCount > 0) $blockers[] = "$userCount usuário(s) vinculado(s)";

        $visitStmt = $db->prepare("SELECT COUNT(*) FROM member_visits WHERE from_church = ? OR to_church = ?");
        $visitStmt->execute([$id, $id]);
        $visitCount = (int)$visitStmt->fetchColumn();
        if ($visitCount > 0) $blockers[] = "$visitCount transferência(s) de membro no histórico";

        if (!empty($blockers)) {
            $errors[] = 'Não é possível excluir: essa filial ainda tem ' . implode(', ', $blockers) . '. Remova ou transfira isso primeiro.';
        } else {
            // Config copiada da sede na criação — limpa antes de excluir de fato
            $db->prepare("DELETE FROM church_settings WHERE church_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM churches WHERE id=? AND parent_id=?")->execute([$id, SEDE_ID]);
            header('Location: /pages/branches.php?deleted=1');
            exit;
        }
    }
}

// Buscar filiais com pastores
$branches = $db->query("
    SELECT c.*,
           COUNT(DISTINCT m.id)  AS member_count,
           COUNT(DISTINCT ce.id) AS cell_count
    FROM churches c
    LEFT JOIN members m  ON m.church_id = c.id AND m.status = 'active'
    LEFT JOIN cells ce   ON ce.church_id = c.id AND ce.active = 1
    WHERE (c.id = " . SEDE_ID . " OR c.parent_id = " . SEDE_ID . ")
    GROUP BY c.id
    ORDER BY c.type DESC, c.name ASC
")->fetchAll();

// Pastores por filial
$pastorsMap = [];
$pastors = $db->query("
    SELECT cp.church_id, cp.role, m.id AS member_id, m.name AS member_name
    FROM church_pastors cp
    JOIN members m ON m.id = cp.member_id
")->fetchAll();
foreach ($pastors as $p) {
    $pastorsMap[$p['church_id']][] = $p;
}

// Filial sendo editada
$editBranch = null;
$editPastors = [];
if (isset($_GET['edit'])) {
    foreach ($branches as $b) {
        if ($b['id'] == $_GET['edit']) { $editBranch = $b; break; }
    }
    $editPastors = $pastorsMap[$_GET['edit']] ?? [];
}

$roleOptions = ['pastor'=>'Pastor','pastora'=>'Pastora','co-pastor'=>'Co-pastor','co-pastora'=>'Co-pastora'];

$pageTitle  = 'Filiais';
$activePage = 'branches';
require_once __DIR__ . '/../includes/layout.php';
?>

<?php if (isset($_GET['saved'])): ?>
  <div style="background:#E1F5EE;border:1px solid var(--accent-border);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#0F6E56">✓ Filial salva com sucesso.</div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:360px 1fr;gap:16px;align-items:start">

  <!-- Formulário -->
  <div class="card">
    <p class="card-title"><?= $editBranch ? 'Editar filial' : 'Nova filial' ?></p>
    <form method="POST">
      <input type="hidden" name="action" value="save">
      <?php if ($editBranch): ?>
        <input type="hidden" name="edit_id" value="<?= $editBranch['id'] ?>">
      <?php endif; ?>

      <div class="form-group">
        <label class="form-label">Nome *</label>
        <input type="text" name="name" class="form-control"
               placeholder="Ex: Filial Zona Norte"
               value="<?= htmlspecialchars($editBranch['name'] ?? '') ?>" required>
      </div>

      <div class="form-group">
        <label class="form-label">Endereço</label>
        <input type="text" name="address" class="form-control"
               placeholder="Rua, número, bairro, cidade"
               value="<?= htmlspecialchars($editBranch['address'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label class="form-label">Telefone</label>
        <input type="text" name="phone" class="form-control"
               placeholder="(11) 99999-9999"
               value="<?= htmlspecialchars($editBranch['phone'] ?? '') ?>">
      </div>

      <!-- Pastores responsáveis -->
      <div class="form-group" style="margin-bottom:20px">
        <label class="form-label">
          Pastor(es) responsável(is)
          <span style="font-weight:400;color:var(--text-muted)">(pode ser um casal)</span>
        </label>
        <div style="border:1px solid var(--border);border-radius:7px;overflow:hidden;max-height:220px;overflow-y:auto">
          <?php foreach ($allMembers as $mb):
            $isChecked = false;
            $checkedRole = 'pastor';
            foreach ($editPastors as $ep) {
              if ($ep['member_id'] == $mb['id']) { $isChecked = true; $checkedRole = $ep['role']; break; }
            }
          ?>
            <div style="padding:8px 12px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px"
                 id="pastor-row-<?= $mb['id'] ?>">
              <input type="checkbox" name="pastor_ids[]" value="<?= $mb['id'] ?>"
                     id="p<?= $mb['id'] ?>"
                     <?= $isChecked ? 'checked' : '' ?>
                     onchange="toggleRole(<?= $mb['id'] ?>)">
              <div class="avatar" style="width:24px;height:24px;font-size:9px;flex-shrink:0">
                <?= strtoupper(substr($mb['name'],0,2)) ?>
              </div>
              <label for="p<?= $mb['id'] ?>" style="flex:1;font-size:13px;cursor:pointer">
                <?= htmlspecialchars($mb['name']) ?>
                <span style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($mb['branch_name']) ?></span>
              </label>
              <select name="pastor_roles[<?= $mb['id'] ?>]"
                      id="role-<?= $mb['id'] ?>"
                      class="form-control"
                      style="width:110px;padding:3px 6px;font-size:11px;display:<?= $isChecked?'block':'none' ?>">
                <?php foreach ($roleOptions as $rk => $rv): ?>
                  <option value="<?= $rk ?>" <?= $checkedRole===$rk?'selected':''?>><?= $rv ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary">Salvar</button>
        <?php if ($editBranch): ?>
          <a href="/pages/branches.php" class="btn btn-secondary">Cancelar</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- Lista -->
  <div class="card" style="padding:0">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
      <p style="font-weight:500;font-size:14px">Sede e filiais <span style="color:var(--text-muted);font-weight:400">(<?= count($branches) ?>)</span></p>
    </div>
    <?php foreach ($branches as $b):
      $bPastors = $pastorsMap[$b['id']] ?? [];
    ?>
      <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px">
          <div>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
              <span style="font-size:14px;font-weight:500"><?= htmlspecialchars($b['name']) ?></span>
              <span class="badge <?= $b['type']==='sede'?'badge-blue':'badge-green' ?>">
                <?= $b['type']==='sede' ? 'Sede' : 'Filial' ?>
              </span>
            </div>
            <div style="font-size:12px;color:var(--text-muted);display:flex;flex-direction:column;gap:3px">
              <!-- Pastores -->
              <?php if (!empty($bPastors)): ?>
                <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center">
                  <span>✝️</span>
                  <?php foreach ($bPastors as $p): ?>
                    <span style="display:flex;align-items:center;gap:4px">
                      <strong style="color:var(--text)"><?= htmlspecialchars($p['member_name']) ?></strong>
                      <span class="badge badge-gray" style="font-size:10px"><?= htmlspecialchars($p['role']) ?></span>
                    </span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <?php if ($b['address']): ?>
                <span>📍 <?= htmlspecialchars($b['address']) ?></span>
              <?php endif; ?>
              <?php if ($b['phone']): ?>
                <span>📞 <?= htmlspecialchars($b['phone']) ?></span>
              <?php endif; ?>
              <span>👥 <?= $b['member_count'] ?? 0 ?> membros · 🔗 <?= $b['cell_count'] ?? 0 ?> células</span>
            </div>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <a href="/pages/members/index.php?branch_id=<?= $b['id'] ?>"
               class="btn btn-secondary" style="font-size:12px;padding:5px 12px">Ver membros</a>
            <?php if ($b['type'] !== 'sede'): ?>
              <a href="?edit=<?= $b['id'] ?>" class="btn btn-secondary" style="font-size:12px;padding:5px 12px">Editar</a>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="branch_id" value="<?= $b['id'] ?>">
                <button type="submit" class="btn btn-secondary"
                        style="font-size:12px;padding:5px 12px;color:var(--red)"
                        data-confirm="Excluir esta filial?">Excluir</button>
              </form>
            <?php else: ?>
              <a href="?edit=<?= $b['id'] ?>" class="btn btn-secondary" style="font-size:12px;padding:5px 12px">Editar pastores</a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php
$extraJs = <<<JS
function toggleRole(id) {
  const cb   = document.getElementById('p' + id);
  const role = document.getElementById('role-' + id);
  role.style.display = cb.checked ? 'block' : 'none';
}
JS;
require_once __DIR__ . '/../includes/layout-footer.php';
?>
