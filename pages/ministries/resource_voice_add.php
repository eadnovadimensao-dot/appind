<?php
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/auth.php";
auth_check();

$db    = db();
$resId = (int)($_POST['resource_id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM ministry_resources WHERE id = ?");
$stmt->execute([$resId]);
$res = $stmt->fetch();
if (!$res) { header('Location: /pages/ministries/index.php'); exit; }

auth_require_ministry((int)$res['ministry_id']);

$voiceName = trim($_POST['voice_name'] ?? '');

if ($voiceName !== '' && !empty($_FILES['voice_file']['tmp_name']) && $_FILES['voice_file']['error'] === UPLOAD_ERR_OK) {
    $file       = $_FILES['voice_file'];
    $allowedExt = ['mp3', 'wav', 'm4a', 'ogg', 'aac'];
    $ext        = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $maxSize    = 50 * 1024 * 1024;

    if ($file['size'] <= $maxSize && in_array($ext, $allowedExt, true) && $file['size'] > 0) {
        $dir = __DIR__ . '/../../public/ministry_files/' . $res['ministry_id'] . '/voices/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $safeName = 'voice_' . uniqid() . '.' . $ext;
        if (move_uploaded_file($file['tmp_name'], $dir . $safeName)) {
            $filePath = '/public/ministry_files/' . $res['ministry_id'] . '/voices/' . $safeName;
            $posStmt = $db->prepare("SELECT COALESCE(MAX(position),-1)+1 FROM ministry_resource_voices WHERE resource_id = ?");
            $posStmt->execute([$resId]);
            $pos = (int)$posStmt->fetchColumn();

            $ins = $db->prepare("
                INSERT INTO ministry_resource_voices (resource_id, voice_name, file_path, file_name, file_size, position)
                VALUES (?,?,?,?,?,?)
            ");
            $ins->execute([$resId, $voiceName, $filePath, $file['name'], $file['size'], $pos]);
        }
    }
}

header('Location: /pages/ministries/resource_edit.php?id=' . $resId);
exit;
