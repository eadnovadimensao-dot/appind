<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mini_charts.php';
require_once __DIR__ . '/includes/nd_news.php';
auth_check();

$db         = db();
$churchId   = current_church_id();
$canFinance = auth_can('manage_finance');
$ndNewsItems  = nd_news_active_items($db, $churchId);
$ndMemberId   = auth_member_id();
$ndLastSeenAt = $ndMemberId ? nd_news_last_seen($db, $ndMemberId) : null;
$ndHasUnseen  = nd_news_has_unseen($ndNewsItems, $ndLastSeenAt);
$ndLastSeenTs = $ndLastSeenAt ? strtotime($ndLastSeenAt) : 0;

// ── Tendências (últimos 6 meses) ─────────────────────────────
$monthAbbrevPt = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
$monthKeys = []; // 'YYYY-MM' dos últimos 6 meses, do mais antigo pro mais recente
for ($i = 5; $i >= 0; $i--) {
    $monthKeys[] = date('Y-m', strtotime("-$i months"));
}

// Crescimento de membros: total cadastrado até o fim de cada mês (cumulativo)
$memberGrowth = [];
$memberGrowthStmt = $db->prepare("SELECT COUNT(*) FROM members WHERE church_id=? AND created_at <= ?");
foreach ($monthKeys as $mk) {
    $monthEnd = date('Y-m-t 23:59:59', strtotime($mk . '-01'));
    $memberGrowthStmt->execute([$churchId, $monthEnd]);
    $memberGrowth[] = [
        'label' => $monthAbbrevPt[(int)substr($mk, 5, 2) - 1],
        'value' => (int)$memberGrowthStmt->fetchColumn(),
    ];
}

// Frequência média em células por mês
$cellAttendanceByMonth = [];
$caStmt = $db->prepare("
    SELECT DATE_FORMAT(cr.report_date, '%Y-%m') AS ym, AVG(cr.total_present) AS avg_present
    FROM cell_reports cr
    JOIN cells c ON c.id = cr.cell_id
    WHERE c.church_id = ? AND cr.report_date >= ?
    GROUP BY ym
");
$caStmt->execute([$churchId, $monthKeys[0] . '-01']);
foreach ($caStmt->fetchAll() as $row) { $cellAttendanceByMonth[$row['ym']] = (float)$row['avg_present']; }
$cellAttendance = [];
$hasCellData = false;
foreach ($monthKeys as $mk) {
    $val = round($cellAttendanceByMonth[$mk] ?? 0, 1);
    if (isset($cellAttendanceByMonth[$mk])) $hasCellData = true;
    $cellAttendance[] = ['label' => $monthAbbrevPt[(int)substr($mk, 5, 2) - 1], 'value' => $val];
}

// Financeiro: entradas x saídas por mês (só se puder ver)
$financeTrend = [];
$hasFinanceData = false;
if ($canFinance) {
    $ftByMonth = [];
    $ftStmt = $db->prepare("
        SELECT DATE_FORMAT(entry_date, '%Y-%m') AS ym,
               COALESCE(SUM(CASE WHEN type='income'  THEN amount END), 0) AS income,
               COALESCE(SUM(CASE WHEN type='expense' THEN amount END), 0) AS expense
        FROM finance_entries
        WHERE church_id = ? AND entry_date >= ?
        GROUP BY ym
    ");
    $ftStmt->execute([$churchId, $monthKeys[0] . '-01']);
    foreach ($ftStmt->fetchAll() as $row) { $ftByMonth[$row['ym']] = $row; }
    foreach ($monthKeys as $mk) {
        if (isset($ftByMonth[$mk])) $hasFinanceData = true;
        $financeTrend[] = [
            'label'   => $monthAbbrevPt[(int)substr($mk, 5, 2) - 1],
            'income'  => (float)($ftByMonth[$mk]['income']  ?? 0),
            'expense' => (float)($ftByMonth[$mk]['expense'] ?? 0),
        ];
    }
}

// KPIs
$totalMembers = $db->query("SELECT COUNT(*) FROM members WHERE church_id=$churchId AND status='active'")->fetchColumn();
$totalCells   = $db->query("SELECT COUNT(*) FROM cells WHERE church_id=$churchId AND active=1")->fetchColumn();

// Aniversariantes da semana
$birthdays = $db->prepare("
    SELECT name,
           DATE_FORMAT(birth_date, '%d/%m') AS bday
    FROM members
    WHERE church_id = ?
      AND status = 'active'
      AND birth_date IS NOT NULL
      AND DAYOFYEAR(birth_date) BETWEEN DAYOFYEAR(CURDATE()) AND DAYOFYEAR(CURDATE()) + 6
    ORDER BY DAYOFYEAR(birth_date)
    LIMIT 5
");
$birthdays->execute([$churchId]);
$birthdays = $birthdays->fetchAll();

// Financeiro do mês (só se puder ver)
$finance = ['income' => 0, 'expense' => 0];
if ($canFinance) {
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN type='income'  THEN amount END), 0) AS income,
            COALESCE(SUM(CASE WHEN type='expense' THEN amount END), 0) AS expense
        FROM finance_entries
        WHERE church_id = ?
          AND MONTH(entry_date) = MONTH(CURDATE())
          AND YEAR(entry_date)  = YEAR(CURDATE())
    ");
    $stmt->execute([$churchId]);
    $finance = $stmt->fetch();
}

// Próximo culto
$nextService = $db->prepare("
    SELECT s.*, m.name AS preacher_name
    FROM services s
    LEFT JOIN members m ON m.id = s.preacher_id
    WHERE s.church_id=? AND s.service_date >= CURDATE()
    ORDER BY s.service_date ASC LIMIT 1
");
$nextService->execute([$churchId]);
$nextService = $nextService->fetch();

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
require_once __DIR__ . '/includes/layout.php';
?>

<?php if (isset($_GET['no_access'])): ?>
  <div style="background:#FEF3C7;border:1px solid #FCD34D;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#854F0B">
    🔒 Você não tem permissão para acessar essa área.
  </div>
<?php endif; ?>

<?php if ($ndNewsItems): ?>
<!-- ND News: stories em video -->
<div class="card" style="margin-bottom:12px">
  <p class="card-title">📰 ND News</p>
  <div style="display:flex;gap:14px;overflow-x:auto;padding:4px 2px 0">
  <?php foreach ($ndNewsItems as $i => $it):
      $isUnseen = strtotime($it['created_at']) > $ndLastSeenTs;
      $ring     = $isUnseen ? 'linear-gradient(45deg,#F58529,#DD2A7B,#8134AF,#515BD4)' : 'var(--border)';
  ?>
    <div onclick="openNdStories(<?= $i ?>)" style="flex-shrink:0;text-align:center;cursor:pointer;width:64px">
      <div style="width:56px;height:56px;border-radius:50%;background:<?= $ring ?>;display:flex;align-items:center;justify-content:center;margin:0 auto 4px;padding:2px">
        <div style="width:100%;height:100%;border-radius:50%;background:white;display:flex;align-items:center;justify-content:center;font-size:20px">🎥</div>
      </div>
      <div style="font-size:11px;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($it['title']) ?></div>
    </div>
  <?php endforeach; ?>
  </div>
</div>

<div id="nd-push-nudge" style="display:none;margin-bottom:20px;background:#E1F0F5;border:1px solid #9FCBDC;border-radius:8px;padding:12px 16px;font-size:13px;color:#0B4E66;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
  <span>🔔 Ative as notificações pra saber na hora quando tiver um story novo no ND News.</span>
  <span style="display:flex;gap:8px;flex-shrink:0">
    <button type="button" class="btn btn-primary" style="font-size:12px" onclick="enablePushNotifications();document.getElementById('nd-push-nudge').style.display='none'">Ativar</button>
    <button type="button" class="btn btn-secondary" style="font-size:12px" onclick="ndDismissPushNudge()">Agora não</button>
  </span>
</div>
<?php endif; ?>

<!-- KPIs -->
<div class="kpi-grid" style="margin-bottom:20px">
  <div class="kpi">
    <div class="kpi-icon">👥</div>
    <div class="kpi-label">Membros ativos</div>
    <div class="kpi-value"><?= $totalMembers ?></div>
  </div>
  <div class="kpi">
    <div class="kpi-icon">🔗</div>
    <div class="kpi-label">Células ativas</div>
    <div class="kpi-value"><?= $totalCells ?></div>
  </div>
  <?php if ($canFinance): ?>
  <div class="kpi">
    <div class="kpi-icon">💰</div>
    <div class="kpi-label">Entradas do mês</div>
    <div class="kpi-value" style="color:var(--green)">R$ <?= number_format($finance['income'],2,',','.') ?></div>
  </div>
  <div class="kpi">
    <div class="kpi-icon">📊</div>
    <div class="kpi-label">Saldo do mês</div>
    <?php $saldo = $finance['income'] - $finance['expense']; ?>
    <div class="kpi-value" style="color:<?= $saldo>=0?'var(--green)':'var(--red)' ?>">
      R$ <?= number_format($saldo,2,',','.') ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

  <!-- Próximo culto -->
  <?php if ($nextService): ?>
  <div class="card">
    <p class="card-title">⛪ Próximo culto</p>
    <div style="font-size:16px;font-weight:500;margin-bottom:6px"><?= htmlspecialchars($nextService['title']) ?></div>
    <div style="font-size:13px;color:var(--text-muted);display:flex;flex-direction:column;gap:4px">
      <span>📅 <?= date('d/m/Y', strtotime($nextService['service_date'])) ?>
        <?= $nextService['time_start'] ? ' às ' . substr($nextService['time_start'],0,5) : '' ?>
      </span>
      <?php if ($nextService['preacher_name']): ?>
        <span>🎤 <?= htmlspecialchars($nextService['preacher_name']) ?></span>
      <?php endif; ?>
      <?php if ($nextService['sermon_title']): ?>
        <span>📖 <?= htmlspecialchars($nextService['sermon_title']) ?></span>
      <?php endif; ?>
    </div>
    <a href="/pages/services/view.php?id=<?= $nextService['id'] ?>"
       class="btn btn-secondary" style="margin-top:14px;font-size:12px">Ver programação</a>
  </div>
  <?php endif; ?>

  <!-- Aniversariantes -->
  <div class="card">
    <p class="card-title">🎂 Aniversariantes da semana</p>
    <?php if (empty($birthdays)): ?>
      <p style="font-size:13px;color:var(--text-muted)">Nenhum aniversariante esta semana.</p>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:8px">
        <?php foreach ($birthdays as $b): ?>
          <div style="display:flex;align-items:center;gap:10px">
            <div class="avatar"><?= strtoupper(substr($b['name'],0,2)) ?></div>
            <div>
              <div style="font-weight:500;font-size:13px"><?= htmlspecialchars($b['name']) ?></div>
              <div style="font-size:12px;color:var(--text-muted)"><?= $b['bday'] ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($canFinance): ?>
  <!-- Resumo financeiro -->
  <div class="card">
    <p class="card-title">💰 Financeiro do mês</p>
    <div style="display:flex;flex-direction:column;gap:12px">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <span style="font-size:13px;color:var(--text-muted)">Entradas</span>
        <span style="font-weight:500;color:var(--green)">R$ <?= number_format($finance['income'],2,',','.') ?></span>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center">
        <span style="font-size:13px;color:var(--text-muted)">Saídas</span>
        <span style="font-weight:500;color:var(--red)">R$ <?= number_format($finance['expense'],2,',','.') ?></span>
      </div>
      <div style="border-top:1px solid var(--border);padding-top:12px;display:flex;justify-content:space-between;align-items:center">
        <span style="font-weight:500">Saldo</span>
        <span style="font-weight:600;font-size:15px;color:<?= $saldo>=0?'var(--green)':'var(--red)' ?>">
          R$ <?= number_format($saldo,2,',','.') ?>
        </span>
      </div>
    </div>
    <a href="/pages/finance/index.php" class="btn btn-secondary" style="margin-top:14px;font-size:12px">Ver financeiro</a>
  </div>
  <?php endif; ?>

</div>

<!-- Tendências -->
<p style="font-size:13px;font-weight:500;color:var(--text-muted);margin:24px 0 12px">📈 Tendências (últimos 6 meses)</p>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

  <div class="card">
    <p class="card-title">👥 Crescimento de membros</p>
    <?= render_line_chart($memberGrowth, '#2a78d6', '%d membros') ?>
  </div>

  <div class="card">
    <p class="card-title">🔗 Frequência média em células</p>
    <?php if ($hasCellData): ?>
      <?= render_bar_chart($cellAttendance, '#4a3aa7', '%.1f pessoas') ?>
    <?php else: ?>
      <?= chart_empty_state('Ainda não há relatórios de célula suficientes pra mostrar uma tendência.') ?>
    <?php endif; ?>
  </div>

  <?php if ($canFinance): ?>
  <div class="card" style="grid-column:1 / -1">
    <p class="card-title">💰 Entradas × Saídas por mês</p>
    <?php if ($hasFinanceData): ?>
      <?= render_grouped_bar_chart($financeTrend, [
        ['key' => 'income',  'label' => 'Entradas', 'color' => '#2a78d6'],
        ['key' => 'expense', 'label' => 'Saídas',    'color' => '#e34948'],
      ], 'R$ %.2f') ?>
    <?php else: ?>
      <?= chart_empty_state('Ainda não há lançamentos financeiros suficientes pra mostrar uma tendência.') ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>

<?php if ($ndNewsItems): ?>
<style>
@keyframes ndFadeUp { from { opacity:0; transform:translateY(14px); } to { opacity:1; transform:translateY(0); } }
.nd-anim { opacity:0; animation: ndFadeUp .5s ease forwards; }
.nd-anim-1 { animation-delay: .15s; }
.nd-anim-2 { animation-delay: .35s; }
.nd-anim-3 { animation-delay: .55s; }
</style>

<!-- Visualizador de stories em tela cheia -->
<div id="nd-stories-overlay" style="display:none;position:fixed;inset:0;background:#000;z-index:2000;align-items:center;justify-content:center">
  <div style="position:relative;width:100%;max-width:420px;height:100%;background:#000;overflow:hidden">
    <div id="nd-progress-row" style="position:absolute;top:10px;left:10px;right:10px;display:flex;gap:4px;z-index:3"></div>
    <button type="button" onclick="closeNdStories()" style="position:absolute;top:18px;right:12px;z-index:3;background:none;border:none;color:white;font-size:28px;line-height:1;cursor:pointer">×</button>
    <video id="nd-story-video" style="width:100%;height:100%;object-fit:contain;background:#000" playsinline></video>
    <div id="nd-story-overlay-text" style="position:absolute;left:0;right:0;bottom:0;padding:24px 18px 32px;background:linear-gradient(to top, rgba(0,0,0,.75), transparent)"></div>
    <div onclick="prevNdStory()" style="position:absolute;left:0;top:40px;bottom:0;width:30%;z-index:2"></div>
    <div onclick="nextNdStory()" style="position:absolute;right:0;top:40px;bottom:0;width:30%;z-index:2"></div>
  </div>
</div>
<?php endif; ?>

<?php
if ($ndNewsItems):
$ndStoriesJson = json_encode(array_map(fn($it) => [
    'id'          => (int)$it['id'],
    'title'       => $it['title'],
    'event_date'  => $it['event_date'] ? date_pt($it['event_date']) : null,
    'description' => $it['description'],
    'video'       => $it['video_path'],
], $ndNewsItems), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

// Clicou num push de story novo? /dashboard.php?story=<id> abre direto nele.
$ndAutoOpenIndex = 'null';
if (isset($_GET['story'])) {
    foreach ($ndNewsItems as $i => $it) {
        if ((int)$it['id'] === (int)$_GET['story']) { $ndAutoOpenIndex = $i; break; }
    }
}
$ndHasUnseenJs = $ndHasUnseen ? 'true' : 'false';

$extraJs = <<<JS
const ndStories = {$ndStoriesJson};
let ndIndex = 0;

function ndDismissPushNudge() {
  document.getElementById('nd-push-nudge').style.display = 'none';
  try { localStorage.setItem('nd_push_nudge_dismissed', '1'); } catch (e) {}
}

function ndEscapeHtml(s) {
  return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function openNdStories(startIndex) {
  ndIndex = startIndex;
  document.getElementById('nd-stories-overlay').style.display = 'flex';
  ndRenderStory();
  fetch('/pages/nd_news/mark_seen.php', { method: 'POST' }).catch(() => {});
}

function closeNdStories() {
  document.getElementById('nd-stories-overlay').style.display = 'none';
  document.getElementById('nd-story-video').pause();
}

function nextNdStory() {
  ndIndex++;
  if (ndIndex >= ndStories.length) { closeNdStories(); return; }
  ndRenderStory();
}

function prevNdStory() {
  ndIndex = Math.max(0, ndIndex - 1);
  ndRenderStory();
}

function ndRenderStory() {
  const s = ndStories[ndIndex];

  const row = document.getElementById('nd-progress-row');
  row.innerHTML = '';
  ndStories.forEach((_, i) => {
    const bar = document.createElement('div');
    bar.style.flex = '1';
    bar.style.height = '3px';
    bar.style.borderRadius = '2px';
    bar.style.background = 'rgba(255,255,255,.35)';
    bar.style.overflow = 'hidden';
    if (i < ndIndex) bar.innerHTML = '<div style="width:100%;height:100%;background:white"></div>';
    if (i === ndIndex) bar.innerHTML = '<div id="nd-progress-fill" style="width:0%;height:100%;background:white"></div>';
    row.appendChild(bar);
  });

  const video = document.getElementById('nd-story-video');
  video.src = s.video;
  video.currentTime = 0;
  video.play().catch(() => {});

  let html = '<div class="nd-anim nd-anim-1" style="color:white;font-size:18px;font-weight:700">' + ndEscapeHtml(s.title) + '</div>';
  if (s.event_date) html += '<div class="nd-anim nd-anim-2" style="color:rgba(255,255,255,.9);font-size:13px;margin-top:4px">📅 ' + ndEscapeHtml(s.event_date) + '</div>';
  if (s.description) html += '<div class="nd-anim nd-anim-3" style="color:rgba(255,255,255,.92);font-size:13px;margin-top:6px;line-height:1.4">' + ndEscapeHtml(s.description) + '</div>';
  document.getElementById('nd-story-overlay-text').innerHTML = html;
}

document.getElementById('nd-story-video').addEventListener('ended', nextNdStory);
document.getElementById('nd-story-video').addEventListener('timeupdate', function () {
  const fill = document.getElementById('nd-progress-fill');
  if (fill && this.duration) fill.style.width = (this.currentTime / this.duration * 100) + '%';
});

// Veio de um push de story novo (/dashboard.php?story=<id>)? Abre direto nele.
const ndAutoOpenIndex = {$ndAutoOpenIndex};
if (ndAutoOpenIndex !== null) openNdStories(ndAutoOpenIndex);

// Tem story novo e a pessoa ainda não ativou push? Convida a ativar.
if ({$ndHasUnseenJs} && 'serviceWorker' in navigator && 'PushManager' in window) {
  navigator.serviceWorker.getRegistration().then(function (reg) {
    if (!reg) return;
    reg.pushManager.getSubscription().then(function (sub) {
      if (sub) return;
      let dismissed = false;
      try { dismissed = localStorage.getItem('nd_push_nudge_dismissed') === '1'; } catch (e) {}
      if (dismissed) return;
      const nudge = document.getElementById('nd-push-nudge');
      if (nudge) nudge.style.display = 'flex';
    });
  }).catch(() => {});
}
JS;
endif;
require_once __DIR__ . '/includes/layout-footer.php';
?>
