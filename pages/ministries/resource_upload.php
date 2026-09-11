<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
auth_check();

$db         = db();
$ministryId = (int)($_POST['ministry_id'] ?? 0);

auth_require_ministry($ministryId);

$stmt = $db->prepare("SELECT * FROM ministries WHERE id = ?");
$stmt->execute([$ministryId]);
$mn = $stmt->fetch();
if (!$mn) { header('Location: /pages/ministries/index.php'); exit; }

$title        = trim($_POST['title'] ?? '');
$type         = ($_POST['type'] ?? 'material') === 'song' ? 'song' : 'material';
$keyTone      = trim($_POST['key_tone'] ?? '') ?: null;
$category     = trim($_POST['category'] ?? '') ?: 'Geral';
$description  = trim($_POST['description'] ?? '');
$externalUrl  = trim($_POST['external_url'] ?? '');  // Referência (YouTube)
$materialsUrl = trim($_POST['materials_url'] ?? ''); // Materiais (Drive — multitracks, cifra…)

if ($title === '') {
    header('Location: /pages/ministries/resources.php?ministry_id=' . $ministryId . '&error=titulo');
    exit;
}

$filePath = null;
$fileName = null;
$fileSize = null;

if (!empty($_FILES['file']['tmp_name']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
    $file       = $_FILES['file'];
    $allowedExt = ['pdf','doc','docx','xls','xlsx','ppt','pptx','mp3','wav','m4a','ogg',
                   'mp4','mov','webm','jpg','jpeg','png','gif','webp','zip','txt'];
    $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $maxSize = 50 * 1024 * 1024; // 50 MB — pra arquivo maior (áudio bruto, vídeo), usar link (Drive/YouTube)

    // Arquivo grande demais ou tipo não permitido: erro específico, não falha silenciosa
    if ($file['size'] > $maxSize) {
        header('Location: /pages/ministries/resources.php?ministry_id=' . $ministryId . '&error=tamanho');
        exit;
    }
    if (!in_array($ext, $allowedExt, true)) {
        header('Location: /pages/ministries/resources.php?ministry_id=' . $ministryId . '&error=tipo');
        exit;
    }

    if ($file['size'] > 0) {
        $dir = __DIR__ . '/../../public/ministry_files/' . $ministryId . '/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $safeName = 'mr_' . uniqid() . '.' . $ext;
        if (move_uploaded_file($file['tmp_name'], $dir . $safeName)) {
            $filePath = '/public/ministry_files/' . $ministryId . '/' . $safeName;
            $fileName = $file['name'];
            $fileSize = $file['size'];
        }
    }
}

if (!$filePath && $externalUrl === '' && $materialsUrl === '') {
    header('Location: /pages/ministries/resources.php?ministry_id=' . $ministryId . '&error=arquivo');
    exit;
}

$ins = $db->prepare("
    INSERT INTO ministry_resources
      (ministry_id, church_id, category, type, title, key_tone, description, file_path, file_name, file_size, external_url, materials_url, created_by)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
");
$ins->execute([
    $ministryId,
    $mn['church_id'],
    $category,
    $type,
    $title,
    $keyTone,
    $description ?: null,
    $filePath,
    $fileName,
    $fileSize,
    $externalUrl ?: null,
    $materialsUrl ?: null,
    auth_member_id(),
]);

header('Location: /pages/ministries/resources.php?ministry_id=' . $ministryId . '&added=1');
exit;
