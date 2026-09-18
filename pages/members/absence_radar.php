<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require('manage_members');

$db       = db();
$churchId = current_church_id();

// Dias sem nenhum sinal de presença até virar alerta
const ABSENCE_ALERT_DAYS   = 21;
const ABSENCE_WARNING_DAYS = 14;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $memberId = (int)($_POST['member_id'] ?? 0);
    $notes    = trim($_POST['notes'] ?? '');
    $stmt = $db->prepare("SELECT id, church_id FROM members WHERE id = ? AND church_id = ?");
    $stmt->execute([$memberId, $churchId]);
    if ($target = $stmt->fetch()) {
        $db->prepare("INSERT INTO pastoral_contacts (member_id, church_id, contacted_by, contacted_at, notes) VALUES (?,?,?,CURDATE(),?)")
           ->execute([$memberId, $target['church_id'], auth_member_id(), $notes ?: null]);
    }
    header('Location: /pages/members/absence_radar.php?registered=1');
    exit;
}

// Só a igreja selecionada
$where  = 'm.church_id = :church_id';
$params = [':church_id' => $churchId];

$sql = "
    SELECT m.id, m.name, m.phone, m.join_date, c.name AS cell_name,
        (SELECT MAX(sc.checked_in_at) FROM service_checkins sc WHERE sc.member_id = m.id) AS last_culto,
        (SELECT MAX(cr.report_date) FROM cell_report_members crm
            JOIN cell_reports cr ON cr.id = crm.report_id WHERE crm.member_id = m.id) AS last_celula,
        (SELECT MAX(ma.activity_date) FROM ministry_activity_members mam
            JOIN ministry_activities ma ON ma.id = mam.activity_id
            WHERE mam.member_id = m.id AND mam.status = 'confirmed') AS last_ministerio,
        (SELECT MAX(pc.contacted_at) FROM pastoral_contacts pc WHERE pc.member_id = m.id) AS last_contact
    FROM members m
    LEFT JOIN churches ch ON ch.id = m.church_id
    LEFT JOIN cells c ON c.id = m.cell_id
    WHERE $where AND m.status = 'active'
    ORDER BY m.name
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll();

$today = time();
foreach ($members as &$m) {
    $dates = array_filter([$m['last_culto'], $m['last_celula'], $m['last_ministerio']]);
    if (empty($dates)) {
        $m['last_seen']   = null;
        $m['last_source'] = null;
        $m['days_absent'] = null;
    } else {
        $best = null; $bestSource = null;
        foreach (['last_culto' => 'Culto', 'last_celula' => 'Célula', 'last_ministerio' => 'Ministério'] as $field => $label) {
            if (!$m[$field]) continue;
            $ts = strtotime($m[$field]);
            if ($best === null || $ts > $best) { $best = $ts; $bestSource = $label; }
        }
        $m['last_seen']   = $best;
        $m['last_source'] = $bestSource;
        $m['days_absent'] = (int)floor(($today - $best) / 86400);
    }
}
unset($m);

// Sem histórico vai pro fim; entre os com histórico, mais ausente primeiro
usort($members, function($a, $b) {
    if ($a['days_absent'] === null && $b['days_absent'] === null) return strcmp($a['name'], $b['name']);
    if ($a['days_absent'] === null) return 1;
    if ($b['days_absent'] === null) return -1;
    return $b['days_absent'] <=> $a['days_absent'];
});

$countAlert   = count(array_filter($members, fn($m) => $m['days_absent'] !== null && $m['days_absent'] >= ABSENCE_ALERT_DAYS));
$countWarning = count(array_filter($members, fn($m) => $m['days_absent'] !== null && $m['days_absent'] >= ABSENCE_WARNING_DAYS && $m['days_absent'] < ABSENCE_ALERT_DAYS));
$countNoData  = count(array_filter($members, fn($m) => $m['days_absent'] === null));

$pageTitle  = 'Radar de ausência';
$activePage = 'members';
require_once __DIR__ . '/../../includes/layout.php';
?>

<?php if (isset($_GET['registered'])): ?>
  <div style="background:#E1F5EE;border:1px solid var(--accent);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#0F6E56">✓ Contato pastoral registrado.</div>
<?php endif; ?>

<div style="margin-bottom:16px">
  <a href="/pages/members/index.php" style="font-size:13px;color:var(--text-muted);text-decoration:none">← Membros</a>
</div>

<div class="card" style="margin-bottom:16px;background:#F5F5F5;border:none">
  <p style="font-size:13px;color:var(--text);line-height:1.7">
    🔔 Mostra há quanto tempo cada membro ativo não dá nenhum sinal de presença
    (culto, célula ou ministério). Quem passa de <?= ABSENCE_ALERT_DAYS ?> dias sem
    aparecer em nada entra em alerta. Como o check-in de culto e os relatórios de
    célula são recentes no sistema, o radar fica mais preciso com o passar das semanas.
  </p>
</div>

<div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap">
  <div class="card" style="flex:1;min-width:140px;text-align:center;padding:16px">
    <div style="font-size:24px;font-weight:600;color:var(--red)"><?= $countAlert ?></div>
    <div style="font-size:12px;color:var(--text-muted)">Sumiram (<?= ABSENCE_ALERT_DAYS ?>+ dias)</div>
  </div>
  <div class="card" style="flex:1;min-width:140px;text-align:center;padding:16px">
    <div style="font-size:24px;font-weight:600;color:#B45309"><?= $countWarning ?></div>
    <div style="font-size:12px;color:var(--text-muted)">Atenção (<?= ABSENCE_WARNING_DAYS ?>-<?= ABSENCE_ALERT_DAYS - 1 ?> dias)</div>
  </div>
  <div class="card" style="flex:1;min-width:140px;text-align:center;padding:16px">
    <div style="font-size:24px;font-weight:600;color:var(--text-muted)"><?= $countNoData ?></div>
    <div style="font-size:12px;color:var(--text-muted)">Sem histórico ainda</div>
  </div>
</div>

<div class="card" style="padding:0">
  <?php foreach ($members as $m):
    if ($m['days_absent'] === null) {
        $badge = ['label' => 'Sem histórico', 'color' => 'var(--text-muted)', 'bg' => '#F0F0F0'];
    } elseif ($m['days_absent'] >= ABSENCE_ALERT_DAYS) {
        $badge = ['label' => $m['days_absent'] . ' dias sumido', 'color' => '#A32D2D', 'bg' => '#FCEBEB'];
    } elseif ($m['days_absent'] >= ABSENCE_WARNING_DAYS) {
        $badge = ['label' => $m['days_absent'] . ' dias ausente', 'color' => '#854F0B', 'bg' => '#FEF3C7'];
    } else {
        $badge = ['label' => 'Em dia', 'color' => '#0F6E56', 'bg' => '#E1F5EE'];
    }
  ?>
    <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <div class="avatar" style="width:34px;height:34px;font-size:12px;flex-shrink:0">
          <?= strtoupper(substr($m['name'],0,2)) ?>
        </div>
        <div style="flex:1;min-width:180px">
          <div style="font-size:14px;font-weight:500"><?= htmlspecialchars($m['name']) ?></div>
          <div style="font-size:12px;color:var(--text-muted)">
            <?= htmlspecialchars($m['cell_name'] ?? 'Sem célula') ?>
            <?= $m['phone'] ? ' · ' . htmlspecialchars($m['phone']) : '' ?>
            <?php if ($m['last_seen']): ?>
              · última vez em <?= date('d/m/Y', $m['last_seen']) ?> (<?= $m['last_source'] ?>)
            <?php elseif ($m['join_date']): ?>
              · membro desde <?= date('d/m/Y', strtotime($m['join_date'])) ?>
            <?php endif; ?>
            <?php if ($m['last_contact']): ?>
              · <span style="color:var(--accent)">último contato pastoral em <?= date('d/m/Y', strtotime($m['last_contact'])) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <span class="badge" style="background:<?= $badge['bg'] ?>;color:<?= $badge['color'] ?>;flex-shrink:0"><?= $badge['label'] ?></span>
        <button type="button" onclick="toggleForm(<?= $m['id'] ?>)" class="btn btn-secondary" style="font-size:12px;flex-shrink:0">Registrar contato</button>
      </div>
      <form method="POST" id="form-<?= $m['id'] ?>" style="display:none;margin-top:10px;padding-top:10px;border-top:1px solid var(--border);gap:8px;align-items:flex-start" class="contact-form">
        <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
        <textarea name="notes" class="form-control" rows="2" placeholder="Como foi o contato? (opcional)" style="flex:1;font-size:13px"></textarea>
        <button type="submit" class="btn btn-primary" style="font-size:12px">Salvar</button>
      </form>
    </div>
  <?php endforeach; ?>
  <?php if (empty($members)): ?>
    <div class="empty-state" style="padding:24px">Nenhum membro ativo encontrado.</div>
  <?php endif; ?>
</div>

<?php
$extraJs = <<<JS
function toggleForm(id) {
  const f = document.getElementById('form-' + id);
  f.style.display = f.style.display === 'none' ? 'flex' : 'none';
}
JS;
require_once __DIR__ . '/../../includes/layout-footer.php';
?>
