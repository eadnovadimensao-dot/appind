<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db = db();
$id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("
    SELECT f.*,
           mf.name AS father_name, mf.phone AS father_phone,
           mm.name AS mother_name, mm.phone AS mother_phone
    FROM families f
    LEFT JOIN members mf ON mf.id = f.father_id
    LEFT JOIN members mm ON mm.id = f.mother_id
    WHERE f.id = ?
");
$stmt->execute([$id]);
$family = $stmt->fetch();
if (!$family) { header('Location: /pages/families/index.php'); exit; }

$familyMembers = $db->prepare("
    SELECT m.id, m.name, m.birth_date, m.status, m.phone, c.name AS cell_name
    FROM members m
    LEFT JOIN cells c ON c.id = m.cell_id
    WHERE m.family_id = ?
    ORDER BY m.birth_date ASC
");
$familyMembers->execute([$id]);
$familyMembers = $familyMembers->fetchAll();

$availableMembers = $db->prepare("
    SELECT m.id, m.name FROM members m
    JOIN churches ch ON ch.id = m.church_id
    WHERE ch.id = ? AND m.status='active'
      AND (m.family_id IS NULL OR m.family_id = ?)
    ORDER BY m.name
");
$availableMembers->execute([current_church_id(), $id]);
$availableMembers = $availableMembers->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_member'])) {
    $mid = (int)($_POST['member_id'] ?? 0);
    if ($mid) $db->prepare("UPDATE members SET family_id=? WHERE id=?")->execute([$id, $mid]);
    header('Location: /pages/families/view.php?id=' . $id);
    exit;
}

if (isset($_GET['remove_member'])) {
    $mid = (int)$_GET['remove_member'];
    $db->prepare("UPDATE members SET family_id=NULL WHERE id=? AND family_id=?")->execute([$mid, $id]);
    header('Location: /pages/families/view.php?id=' . $id);
    exit;
}

$pageTitle  = $family['name'];
$activePage = 'families';
require_once __DIR__ . '/../../includes/layout.php';
?>

<?php if (isset($_GET['saved'])): ?>
  <div style="background:#E1F5EE;border:1px solid var(--accent-border);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#0F6E56">✓ Família salva com sucesso.</div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div style="display:flex;align-items:center;gap:14px">
      <div style="width:52px;height:52px;border-radius:12px;background:var(--accent-lt);display:flex;align-items:center;justify-content:center;font-size:26px">👨‍👩‍👧‍👦</div>
      <div>
        <h1 style="font-size:18px;font-weight:500;margin-bottom:4px"><?= htmlspecialchars($family['name']) ?></h1>
        <div style="font-size:13px;color:var(--text-muted);display:flex;flex-wrap:wrap;gap:12px">
          <?php if ($family['father_name']): ?><span>👨 <?= htmlspecialchars($family['father_name']) ?></span><?php endif; ?>
          <?php if ($family['mother_name']): ?><span>👩 <?= htmlspecialchars($family['mother_name']) ?></span><?php endif; ?>
          <?php if ($family['phone']): ?><span>📞 <?= htmlspecialchars($family['phone']) ?></span><?php endif; ?>
          <?php if ($family['city']): ?><span>📍 <?= htmlspecialchars($family['city']) ?></span><?php endif; ?>
        </div>
      </div>
    </div>
    <div style="display:flex;gap:8px">
      <a href="/pages/families/edit.php?id=<?= $id ?>" class="btn btn-secondary">Editar</a>
      <a href="/pages/families/index.php" class="btn btn-secondary">Voltar</a>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 320px;gap:16px">
  <div class="card" style="padding:0">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
      <p style="font-weight:500;font-size:14px">Membros <span style="color:var(--text-muted);font-weight:400">(<?= count($familyMembers) ?>)</span></p>
    </div>
    <?php if (empty($familyMembers)): ?>
      <div class="empty-state" style="padding:24px">Nenhum membro vinculado.</div>
    <?php else: ?>
      <?php foreach ($familyMembers as $m):
        $initials = strtoupper(implode('', array_map(fn($p)=>$p[0], array_slice(explode(' ',$m['name']),0,2))));
        $age = $m['birth_date'] ? floor((time()-strtotime($m['birth_date']))/31557600) : null;
      ?>
        <div style="display:flex;align-items:center;gap:12px;padding:12px 18px;border-bottom:1px solid var(--border)">
          <div class="avatar"><?= $initials ?></div>
          <div style="flex:1">
            <a href="/pages/members/view.php?id=<?= $m['id'] ?>" style="font-weight:500;font-size:13px;color:var(--text);text-decoration:none"><?= htmlspecialchars($m['name']) ?></a>
            <div style="font-size:12px;color:var(--text-muted);display:flex;gap:10px;flex-wrap:wrap">
              <?php if ($age !== null): ?><span><?= $age ?> anos</span><?php endif; ?>
              <?php if ($m['cell_name']): ?><span>🔗 <?= htmlspecialchars($m['cell_name']) ?></span><?php endif; ?>
              <?php if ($m['phone']): ?><span>📞 <?= htmlspecialchars($m['phone']) ?></span><?php endif; ?>
            </div>
          </div>
          <span class="badge <?= $m['status']==='active'?'badge-green':'badge-gray' ?>"><?= $m['status']==='active'?'Ativo':'Inativo' ?></span>
          <?php if (auth_can('manage_members')): ?>
            <a href="?id=<?= $id ?>&remove_member=<?= $m['id'] ?>"
               style="color:var(--text-muted);font-size:18px;text-decoration:none"
               onclick="return confirm('Remover da família?')">×</a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php if (auth_can('manage_members')): ?>
      <div style="padding:12px 18px">
        <form method="POST" style="display:flex;gap:8px">
          <select name="member_id" class="form-control" style="flex:1">
            <option value="">Adicionar membro…</option>
            <?php foreach ($availableMembers as $m): ?>
              <?php if ($m['id'] != $family['father_id'] && $m['id'] != $family['mother_id']): ?>
                <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
          <button type="submit" name="add_member" value="1" class="btn btn-secondary" style="font-size:12px">+ Adicionar</button>
        </form>
      </div>
    <?php endif; ?>
  </div>

  <div style="display:flex;flex-direction:column;gap:16px">
    <?php if ($family['address']): ?>
    <div class="card">
      <p class="card-title">Endereço</p>
      <div style="font-size:13px;color:var(--text);line-height:1.8">
        <?php if ($family['address']): ?><div><?= htmlspecialchars($family['address']) ?></div><?php endif; ?>
        <?php if ($family['neighborhood']): ?><div><?= htmlspecialchars($family['neighborhood']) ?></div><?php endif; ?>
        <?php if ($family['city']): ?><div><?= htmlspecialchars($family['city']) ?></div><?php endif; ?>
        <?php if ($family['zip_code']): ?><div><?= htmlspecialchars($family['zip_code']) ?></div><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
    <div class="card">
      <p class="card-title">Observações pastorais</p>
      <?php if ($family['notes']): ?>
        <p style="font-size:13px;color:var(--text);line-height:1.7"><?= nl2br(htmlspecialchars($family['notes'])) ?></p>
      <?php else: ?>
        <p style="font-size:13px;color:var(--text-muted)">Nenhuma observação.</p>
      <?php endif; ?>
      <?php if (auth_can('manage_members')): ?>
        <a href="/pages/families/edit.php?id=<?= $id ?>" class="btn btn-secondary" style="margin-top:12px;font-size:12px">Editar observações</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
