<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_check();

$db         = db();
$activityId = (int)($_POST['activity_id'] ?? 0);
$title      = trim($_POST['title'] ?? '');
$key        = trim($_POST['key_tone'] ?? '') ?: null;
$link       = trim($_POST['reference_link'] ?? '') ?: null;

if ($activityId && $title !== '') {
    $ministryId = $db->prepare("SELECT ministry_id FROM ministry_activities WHERE id = ?");
    $ministryId->execute([$activityId]);
    $ministryId = $ministryId->fetchColumn();

    if ($ministryId && auth_can_manage_ministry((int)$ministryId)) {
        // Cifra/partitura anexada (opcional)
        $filePath = null;
        $fileName = null;
        if (!empty($_FILES['file']['tmp_name']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $file       = $_FILES['file'];
            $allowedExt = ['pdf','jpg','jpeg','png','doc','docx'];
            $ext        = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $maxSize    = 10 * 1024 * 1024; // 10 MB

            if (in_array($ext, $allowedExt, true) && $file['size'] > 0 && $file['size'] <= $maxSize) {
                $dir = __DIR__ . '/../../public/ministry_files/' . $ministryId . '/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $safeName = 'song_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $dir . $safeName)) {
                    $filePath = '/public/ministry_files/' . $ministryId . '/' . $safeName;
                    $fileName = $file['name'];
                }
            }
        }

        $pos = (int)$db->query("SELECT COALESCE(MAX(position),-1)+1 FROM ministry_activity_songs WHERE activity_id = $activityId")->fetchColumn();
        $db->prepare("
            INSERT INTO ministry_activity_songs (activity_id, title, key_tone, reference_link, file_path, file_name, position)
            VALUES (?,?,?,?,?,?,?)
        ")->execute([$activityId, $title, $key, $link, $filePath, $fileName, $pos]);
    }
}

header('Location: /pages/ministries/activity_view.php?id=' . $activityId);
exit;
