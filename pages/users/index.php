<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require('manage_users');

$db       = db();
$churchId = current_church_id();
$errors   = [];

// Membros sem usuário (de todas as filiais)
$members_without_user = $db->prepare("
    SELECT m.id, m.name, m.email, ch.name AS branch_name
    FROM members m
    JOIN churches ch ON ch.id = m.church_id
    WHERE ch.id = ?
      AND m.status = 'active'
      AND m.id NOT IN (SELECT member_id FROM users WHERE member_id IS NOT NULL)
    ORDER BY m.name
");
$members_without_user->execute([current_church_id()]);
$members_without_user = $members_without_user->fetchAll();

// Processar convite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'create') {
        $memberId = (int)($_POST['member_id'] ?? 0) ?: null;
        $name     = trim($_POST['name']  ?? '');
        $email    = trim($_POST['email'] ?? '');
        $role     = trim($_POST['role']  ?? 'member');

        if ($name  === '') $errors[] = 'Nome é obrigatório.';
        if ($email === '') $errors[] = 'E-mail é obrigatório.';

        if (empty($errors)) {
            $exists = $db->prepare("SELECT id FROM users WHERE email = ?");
            $exists->execute([$email]);
            if ($exists->fetch()) $errors[] = 'Este e-mail já está cadastrado.';
        }

        if (empty($errors)) {
            $token    = bin2hex(random_bytes(32));
            $expires  = date('Y-m-d H:i:s', time() + 86400 * 3);
            $tempHash = password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT);

            $db->prepare("
                INSERT INTO users (church_id, member_id, name, email, password_hash, role, invite_token, invite_expires)
                VALUES (?,?,?,?,?,?,?,?)
            ")->execute([$churchId, $memberId, $name, $email, $tempHash, $role, $token, $expires]);

            $inviteLink = APP_URL . '/activate.php?token=' . $token;
            $_SESSION['invite_link'] = $inviteLink;
            $_SESSION['invite_name'] = $name;

            header('Location: /pages/users/index.php?created=1');
            exit;
        }
    }

    if ($_POST['action'] === 'toggle') {
        $uid    = (int)($_POST['user_id'] ?? 0);
        $active = (int)($_POST['active']  ?? 0);
        $db->prepare("UPDATE users SET active = ? WHERE id = ? AND church_id = ? AND role != 'supermaster'")
           ->execute([$active ? 0 : 1, $uid, $churchId]);
        header('Location: /pages/users/index.php');
        exit;
    }

    if ($_POST['action'] === 'change_role') {
        $uid  = (int)($_POST['user_id'] ?? 0);
        $role = trim($_POST['role'] ?? '');
        if (in_array($role, ['supermaster','admin','leader','member'])) {
            $db->prepare("UPDATE users SET role = ? WHERE id = ? AND church_id = ?")
               ->execute([$role, $uid, $churchId]);
        }
        header('Location: /pages/users/index.php');
        exit;
    }

    if ($_POST['action'] === 'delete') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $me  = $_SESSION['user_id'] ?? 0;
        // Nunca deixa excluir a si mesmo
        if ($uid && $uid != $me) {
            $db->prepare("DELETE FROM users WHERE id = ? AND church_id = ? AND role != 'supermaster'")
               ->execute([$uid, $churchId]);
        }
        header('Location: /pages/users/index.php');
        exit;
    }
}

$users = $db->query("
    SELECT u.*, m.name AS member_name
    FROM users u
    LEFT JOIN members m ON m.id = u.member_id
    WHERE u.church_id = $churchId
    ORDER BY u.role ASC, u.name ASC
")->fetchAll();

$roleLabels = [
    'supermaster' => ['label'=>'Supermaster', 'badge'=>'badge-red'],
    'admin'       => ['label'=>'Admin/Pastor', 'badge'=>'badge-blue'],
    'leader'      => ['label'=>'Líder',        'badge'=>'badge-amber'],
    'member'      => ['label'=>'Membro',       'badge'=>'badge-gray'],
];

$pageTitle    = 'Usuários';
$activePage   = 'users';
require_once __DIR__ . '/../../includes/layout.php';
?>

<?php if (isset($_GET['created']) && isset($_SESSION['invite_link'])): ?>
  <div style="background:#E1F5EE;border:1px solid var(--accent-border);border-radius:10px;padding:16px 20px;margin-bottom:20px">
    <p style="font-weight:500;color:#0F6E56;margin-bottom:8px">✓ Usuário criado! Envie o link de ativação para <?= htmlspecialchars($_SESSION['invite_name']) ?>:</p>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <code style="background:white;border:1px solid var(--accent-border);border-radius:6px;padding:8px 12px;font-size:12px;flex:1;word-break:break-all">
        <?= htmlspecialchars($_SESSION['invite_link']) ?>
      </code>
      <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($_SESSION['invite_link']) ?>');this.textContent='Copiado!'"
              class="btn btn-primary" style="font-size:12px;padding:8px 14px">Copiar</button>
    </div>
    <p style="font-size:12px;color:#0F6E56;margin-top:8px">Link válido por 3 dias. Envie pelo WhatsApp para o membro.</p>
  </div>
  <?php unset($_SESSION['invite_link'], $_SESSION['invite_name']); ?>
<?php endif; ?>

<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- Formulário de novo usuário -->
<div class="card" style="margin-bottom:20px">
  <p class="card-title">Convidar novo usuário</p>
  <form method="POST" style="width:100%">
    <input type="hidden" name="action" value="create">
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Vincular a membro existente <span style="font-weight:400;color:var(--text-muted)">(opcional)</span></label>
        <select name="member_id" class="form-control" id="member-select" onchange="fillFromMember(this)">
          <option value="">Selecione um membro…</option>
          <?php foreach ($members_without_user as $m): ?>
            <option value="<?= $m['id'] ?>"
                    data-name="<?= htmlspecialchars($m['name']) ?>"
                    data-email="<?= htmlspecialchars($m['email'] ?? '') ?>">
              <?= htmlspecialchars($m['name']) ?>
              <?= $m['branch_name'] ? ' — ' . htmlspecialchars($m['branch_name']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Perfil de acesso</label>
        <select name="role" class="form-control">
          <option value="member">Membro</option>
          <option value="leader">Líder</option>
          <option value="admin">Admin / Pastor</option>
          <?php if (auth_role() === 'supermaster'): ?>
            <option value="supermaster">Supermaster</option>
          <?php endif; ?>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Nome *</label>
        <input type="text" name="name" id="invite-name" class="form-control"
               value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">E-mail *</label>
        <input type="email" name="email" id="invite-email" class="form-control"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
      </div>
    </div>
    <button type="submit" class="btn btn-primary">Gerar link de convite</button>
  </form>
</div>

<!-- Lista de usuários -->
<div class="card" style="padding:0">
  <div style="padding:16px 20px;border-bottom:1px solid var(--border)">
    <span style="font-size:13px;color:var(--text-muted)"><?= count($users) ?> usuário(s)</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Nome</th><th>E-mail</th><th>Membro vinculado</th><th>Perfil</th><th>Último acesso</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u):
          $rl = $roleLabels[$u['role']] ?? ['label'=>$u['role'],'badge'=>'badge-gray'];
          $isMe = $u['id'] == ($_SESSION['user_id'] ?? 0);
        ?>
          <tr>
            <td>
              <div style="font-weight:500"><?= htmlspecialchars($u['name']) ?></div>
              <?php if ($isMe): ?>
                <span style="font-size:11px;color:var(--accent)">← você</span>
              <?php endif; ?>
            </td>
            <td style="color:var(--text-muted)"><?= htmlspecialchars($u['email']) ?></td>
            <td style="color:var(--text-muted)"><?= htmlspecialchars($u['member_name'] ?? '—') ?></td>
            <td>
              <?php if (!$isMe && $u['role'] !== 'supermaster'): ?>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="action" value="change_role">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <select name="role" class="form-control" style="padding:4px 8px;font-size:12px;width:auto"
                          onchange="this.form.submit()">
                    <?php foreach ($roleLabels as $rk => $rv): ?>
                      <?php if ($rk === 'supermaster' && auth_role() !== 'supermaster') continue; ?>
                      <option value="<?= $rk ?>" <?= $u['role']===$rk?'selected':''?>><?= $rv['label'] ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
              <?php else: ?>
                <span class="badge <?= $rl['badge'] ?>"><?= $rl['label'] ?></span>
              <?php endif; ?>
            </td>
            <td style="color:var(--text-muted);font-size:12px">
              <?= $u['last_login'] ? date('d/m/Y H:i', strtotime($u['last_login'])) : 'Nunca' ?>
            </td>
            <td>
              <span class="badge <?= $u['active'] ? 'badge-green' : 'badge-gray' ?>">
                <?= $u['active'] ? 'Ativo' : 'Inativo' ?>
              </span>
            </td>
            <td style="text-align:right">
              <?php if (!$isMe && $u['role'] !== 'supermaster'): ?>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <input type="hidden" name="active" value="<?= $u['active'] ?>">
                  <button type="submit" class="btn btn-secondary" style="font-size:12px;padding:5px 12px"
                          onclick="return confirm('<?= $u['active'] ? 'Desativar' : 'Ativar' ?> este usuário?')">
                    <?= $u['active'] ? 'Desativar' : 'Ativar' ?>
                  </button>
                </form>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <button type="submit" class="btn btn-secondary"
                          style="font-size:12px;padding:5px 12px;color:var(--red)"
                          onclick="return confirm('Excluir o usuário <?= htmlspecialchars($u['name']) ?>? O e-mail ficará disponível para novo cadastro.')">
                    Excluir
                  </button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
$extraJs = <<<JS
function fillFromMember(sel) {
  const opt = sel.options[sel.selectedIndex];
  if (opt.value) {
    document.getElementById('invite-name').value  = opt.dataset.name  || '';
    document.getElementById('invite-email').value = opt.dataset.email || '';
  }
}
JS;
require_once __DIR__ . '/../../includes/layout-footer.php';
?>
