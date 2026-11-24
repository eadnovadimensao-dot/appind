<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/bible_reading.php';
auth_check();

$db       = db();
$churchId = current_church_id();
$memberId = auth_member_id();
$total    = bible_reading_total_chapters($db);

$me = $db->prepare("SELECT cell_id FROM members WHERE id = ?");
$me->execute([$memberId]);
$myCellId = $me->fetchColumn() ?: null;

$myCellName = null;
if ($myCellId) {
    $c = $db->prepare("SELECT name FROM cells WHERE id = ?");
    $c->execute([$myCellId]);
    $myCellName = $c->fetchColumn();
}

$meg = $db->prepare("SELECT bible_reading_group_id FROM members WHERE id = ?");
$meg->execute([$memberId]);
$myGroupId = $meg->fetchColumn() ?: null;

$myGroupName = null;
if ($myGroupId) {
    $g = $db->prepare("SELECT name FROM bible_reading_groups WHERE id = ?");
    $g->execute([$myGroupId]);
    $myGroupName = $g->fetchColumn();
}

function bible_reading_ranking_rows(PDO $db, int $churchId, int $total, ?string $filterColumn = null, ?int $filterValue = null): array {
    $sql = "
        SELECT m.id, m.name, COUNT(brp.member_id) AS read_count
        FROM members m
        LEFT JOIN bible_reading_progress brp ON brp.member_id = m.id
        WHERE m.church_id = ? AND m.status = 'active'
    ";
    $params = [$churchId];
    if ($filterColumn !== null) {
        $sql .= " AND m.$filterColumn = ?";
        $params[] = $filterValue;
    }
    $sql .= " GROUP BY m.id, m.name HAVING read_count > 0 ORDER BY read_count DESC LIMIT 50";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['read_count'] = (int)$r['read_count'];
        $r['percent']    = $total ? round($r['read_count'] / $total * 100) : 0;
    }
    return $rows;
}

$generalRows = bible_reading_ranking_rows($db, $churchId, $total);
$cellRows    = $myCellId  ? bible_reading_ranking_rows($db, $churchId, $total, 'cell_id', (int)$myCellId) : [];
$groupRows   = $myGroupId ? bible_reading_ranking_rows($db, $churchId, $total, 'bible_reading_group_id', (int)$myGroupId) : [];

function bible_reading_ranking_list_html(array $rows, int $memberId): string {
    if (!$rows) return '<p style="font-size:13px;color:var(--text-muted);padding:10px 0">Ninguém marcou capítulos lidos ainda.</p>';
    $medals = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
    $html = '';
    $pos = 0;
    foreach ($rows as $r) {
        $pos++;
        $isMe  = (int)$r['id'] === $memberId;
        $medal = $medals[$pos] ?? ($pos . 'º');
        $html .= '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border-radius:8px;margin-bottom:6px;'
               . ($isMe ? 'background:var(--accent);color:white' : 'border:1px solid var(--border)') . '">'
               . '<div style="display:flex;align-items:center;gap:10px;min-width:0">'
               . '<span style="font-size:13px;font-weight:700;min-width:28px">' . $medal . '</span>'
               . '<span style="font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' . htmlspecialchars($r['name']) . ($isMe ? ' (você)' : '') . '</span>'
               . '</div>'
               . '<span style="font-size:12px;font-weight:600;white-space:nowrap">' . $r['read_count'] . ' cap. · ' . $r['percent'] . '%</span>'
               . '</div>';
    }
    return $html;
}

$pageTitle  = 'Ranking de Leitura';
$activePage = 'bible_reading';
require_once __DIR__ . '/../../includes/layout.php';
?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px;flex-wrap:wrap">
  <a href="/pages/bible_reading/index.php" style="font-size:13px;color:var(--accent)">← Voltar pra leitura</a>
  <a href="/pages/bible_reading/groups.php" style="font-size:13px;color:var(--accent);font-weight:600">👥 Grupos de leitura</a>
</div>

<div class="card" style="margin-bottom:16px">
  <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap">
    <button type="button" id="tab-general-btn" class="btn btn-primary" style="font-size:12px" onclick="showRankingTab('general')">🌍 Geral</button>
    <button type="button" id="tab-cell-btn" class="btn btn-secondary" style="font-size:12px" onclick="showRankingTab('cell')" <?= $myCellId ? '' : 'disabled title="Você não está em nenhuma célula"' ?>>🔗 Minha célula<?= $myCellName ? ' (' . htmlspecialchars($myCellName) . ')' : '' ?></button>
    <button type="button" id="tab-group-btn" class="btn btn-secondary" style="font-size:12px" onclick="showRankingTab('group')" <?= $myGroupId ? '' : 'disabled title="Você não está em nenhum grupo"' ?>>👥 Meu grupo<?= $myGroupName ? ' (' . htmlspecialchars($myGroupName) . ')' : '' ?></button>
  </div>

  <div id="tab-general">
    <?= bible_reading_ranking_list_html($generalRows, $memberId) ?>
  </div>
  <div id="tab-cell" style="display:none">
    <?= bible_reading_ranking_list_html($cellRows, $memberId) ?>
  </div>
  <div id="tab-group" style="display:none">
    <?php if (!$myGroupId): ?>
      <p style="font-size:13px;color:var(--text-muted);padding:10px 0">Você ainda não está em nenhum grupo. <a href="/pages/bible_reading/groups.php" style="color:var(--accent);font-weight:600">Ver grupos disponíveis →</a></p>
    <?php else: ?>
      <?= bible_reading_ranking_list_html($groupRows, $memberId) ?>
    <?php endif; ?>
  </div>
</div>

<?php
$extraJs = <<<JS
function showRankingTab(tab) {
  document.getElementById('tab-general').style.display = tab === 'general' ? 'block' : 'none';
  document.getElementById('tab-cell').style.display     = tab === 'cell'    ? 'block' : 'none';
  document.getElementById('tab-group').style.display    = tab === 'group'   ? 'block' : 'none';
  document.getElementById('tab-general-btn').className  = 'btn ' + (tab === 'general' ? 'btn-primary' : 'btn-secondary');
  document.getElementById('tab-cell-btn').className     = 'btn ' + (tab === 'cell'    ? 'btn-primary' : 'btn-secondary');
  document.getElementById('tab-group-btn').className    = 'btn ' + (tab === 'group'   ? 'btn-primary' : 'btn-secondary');
}
JS;
require_once __DIR__ . '/../../includes/layout-footer.php';
?>
