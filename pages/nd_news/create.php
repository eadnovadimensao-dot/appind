<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/nd_news.php';
auth_check();
if (!auth_can_create_nd_news()) {
    http_response_code(403);
    include __DIR__ . '/../../includes/403.php';
    exit;
}

$db       = db();
$memberId = auth_member_id();
$errors   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = trim($_POST['title'] ?? '');
    $eventDate   = trim($_POST['event_date'] ?? '') ?: null;
    $description = trim($_POST['description'] ?? '') ?: null;
    $expiresAt   = trim($_POST['expires_at'] ?? '');
    $visibility  = ($_POST['visibility'] ?? '') === 'sede' ? 'sede' : 'all';

    if ($title === '') $errors[] = 'Dê um nome pro story.';
    if ($expiresAt === '' || strtotime($expiresAt) <= time()) $errors[] = 'A data de expiração precisa ser no futuro.';

    $videoPath = null;
    if (empty($_FILES['video']['tmp_name']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Grave ou escolha um vídeo.';
    } else {
        $file = $_FILES['video'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExt = ['mp4', 'mov', 'webm', 'm4v'];
        if (!in_array($ext, $allowedExt, true)) {
            $errors[] = 'Formato de vídeo não aceito (use mp4, mov ou webm).';
        } elseif ($file['size'] > ND_NEWS_MAX_VIDEO_BYTES) {
            $errors[] = 'Vídeo muito grande (máximo ' . (ND_NEWS_MAX_VIDEO_BYTES / 1024 / 1024) . 'MB — grave um story mais curto).';
        } else {
            $dir = __DIR__ . '/../../public/nd_news_files/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $safeName = 'nd_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $dir . $safeName)) {
                $videoPath = '/public/nd_news_files/' . $safeName;
            } else {
                $errors[] = 'Não consegui salvar o vídeo, tenta de novo.';
            }
        }
    }

    if (empty($errors)) {
        $db->prepare("
            INSERT INTO nd_news (church_id, visibility, title, event_date, description, video_path, expires_at, created_by, created_at)
            VALUES (?,?,?,?,?,?,?,?,NOW())
        ")->execute([current_church_id(), $visibility, $title, $eventDate, $description, $videoPath, date('Y-m-d H:i:s', strtotime($expiresAt)), $memberId]);

        header('Location: /pages/nd_news/index.php?saved=1');
        exit;
    }
}

$pageTitle  = 'Novo story — ND News';
$activePage = 'nd_news';
require_once __DIR__ . '/../../includes/layout.php';
?>

<div style="margin-bottom:16px">
  <a href="/pages/nd_news/index.php" style="font-size:13px;color:var(--text-muted);text-decoration:none">← ND News</a>
</div>

<?php if (!empty($errors)): ?>
  <div style="background:#FCEBEB;border:1px solid #F09595;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#A32D2D">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
  <div class="card" style="margin-bottom:16px">
    <div class="form-group">
      <label class="form-label">Nome do evento / novidade *</label>
      <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Data do evento</label>
        <input type="date" name="event_date" class="form-control" value="<?= htmlspecialchars($_POST['event_date'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Expira em *</label>
        <input type="datetime-local" name="expires_at" class="form-control" value="<?= htmlspecialchars($_POST['expires_at'] ?? '') ?>" required>
      </div>
    </div>
    <div class="form-group" style="margin-bottom:0">
      <label class="form-label">Mais informações</label>
      <textarea name="description" class="form-control" rows="3" placeholder="Horário, local, detalhes…"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
    </div>
  </div>

  <div class="card" style="margin-bottom:16px">
    <div class="form-group" style="margin-bottom:0">
      <label class="form-label">Quem vê este story?</label>
      <select name="visibility" class="form-control">
        <option value="all" <?= ($_POST['visibility'] ?? 'all') === 'all' ? 'selected' : '' ?>>🌐 Todas as igrejas (Sede + filiais)</option>
        <option value="sede" <?= ($_POST['visibility'] ?? '') === 'sede' ? 'selected' : '' ?>>🏛️ Só a Sede</option>
      </select>
    </div>
  </div>

  <div class="card" style="margin-bottom:16px">
    <p class="card-title">🎥 Vídeo</p>
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px">No celular, abre a câmera direto pra gravar. Até <?= ND_NEWS_MAX_VIDEO_BYTES / 1024 / 1024 ?>MB.</p>
    <input type="file" name="video" accept="video/*" capture="environment" class="form-control" required>
  </div>

  <div style="display:flex;gap:10px">
    <button type="submit" class="btn btn-primary">Publicar story</button>
    <a href="/pages/nd_news/index.php" class="btn btn-secondary">Cancelar</a>
  </div>
</form>

<?php require_once __DIR__ . '/../../includes/layout-footer.php'; ?>
