<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db       = db();
$churchId = current_church_id();
$search   = trim($_GET['q'] ?? '');

$where  = ['f.church_id = ?'];
$params = [$churchId];

if ($search) {
    $where[]  = 'f.name LIKE ?';
    $params[] = "%$search%";
}

$families = $db->prepare("
    SELECT f.*,
           mf.name AS father_name,
           mm.name AS mother_name,
           COUNT(m.id) AS member_count
    FROM families f
    LEFT JOIN members mf ON mf.id = f.father_id
    LEFT JOIN members mm ON mm.id = f.mother_id
    LEFT JOIN members m  ON m.family_id = f.id AND m.status = 'active'
    WHERE " . implode(' AND ', $where) . "
    GROUP BY f.id
    ORDER BY f.name ASC
");
$families->execute($params);
$families = $families->fetchAll();

$pageTitle    = 'Famílias';
$activePage   = 'families';
$topbarAction = ['href' => '/pages/families/create.php', 'label' => 'Nova família'];
require_once __DIR__ . '/../../includes/layout.php';
?>

<!-- Busca -->
<form method="GET" style="display:flex;gap:10px;margin-bottom:20px">
  <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
         placeholder="Buscar família…"
         class="form-control" style="max-width:320px">
  <button type="submit" class="btn btn-secondary">Buscar</button>
  <?php if ($search): ?>
    <a href="/pages/families/index.php" class="btn btn-secondary">Limpar</a>
  <?php endif; ?>
</form>

<?php if (empty($families)): ?>
  <div class="empty-state" style="padding:60px">
    <p style="font-size:32px;margin-bottom:8px">👨‍👩‍👧‍👦</p>
    <p>Nenhuma família cadastrada.</p>
    <a href="/pages/families/create.php" class="btn btn-primary" style="margin-top:16px">+ Nova família</a>
  </div>
<?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px">
    <?php foreach ($families as $f): ?>
      <div class="card" style="cursor:pointer" onclick="location='/pages/families/view.php?id=<?= $f['id'] ?>'">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:10px">
          <div style="display:flex;align-items:center;gap:10px">
            <div style="width:42px;height:42px;border-radius:10px;background:var(--accent-lt);display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0">
              👨‍👩‍👧‍👦
            </div>
            <div>
              <div style="font-weight:500;font-size:15px"><?= htmlspecialchars($f['name']) ?></div>
              <div style="font-size:12px;color:var(--text-muted)">
                <?= $f['member_count'] ?> membro(s)
              </div>
            </div>
          </div>
          <?php if (!$f['active']): ?>
            <span class="badge badge-gray">Inativa</span>
          <?php endif; ?>
        </div>

        <!-- Casal responsável -->
        <?php if ($f['father_name'] || $f['mother_name']): ?>
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;display:flex;flex-wrap:wrap;gap:8px">
            <?php if ($f['father_name']): ?>
              <span>👨 <?= htmlspecialchars($f['father_name']) ?></span>
            <?php endif; ?>
            <?php if ($f['mother_name']): ?>
              <span>👩 <?= htmlspecialchars($f['mother_name']) ?></span>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($f['city']): ?>
          <div style="font-size:12px;color:var(--text-muted)">
            📍 <?= htmlspecialchars($f['neighborhood'] ? $f['neighborhood'] . ', ' . $f['city'] : $f['city']) ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
