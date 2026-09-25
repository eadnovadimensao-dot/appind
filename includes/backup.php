<?php
// Backup diário do banco, feito em PHP puro (a hospedagem não tem shell_exec nem
// mysqldump liberado). Guarda uma cópia .sql.gz fora da pasta pública e, se houver
// e-mail configurado (backup_email), manda uma cópia criptografada pra fora do servidor.

const BACKUP_KEEP_FILES = 14;
// Só a estrutura: conteúdo estático e grande, dá pra reimportar do arquivo original
const BACKUP_SKIP_DATA  = ['bible_verses'];

function backup_dir(): string {
    $dir = dirname(__DIR__, 2) . '/backups_nd';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    return $dir;
}

function backup_set(PDO $db, string $key, string $value): void {
    $db->prepare("INSERT INTO church_settings (church_id, `key`, `value`) VALUES (?, ?, ?)
                  ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()")
       ->execute([SEDE_ID, $key, $value]);
}

/** Gera o .sql.gz de hoje e devolve o caminho. */
function backup_dump(PDO $db): string {
    $file = backup_dir() . '/backup_' . date('Y-m-d_His') . '.sql.gz';
    $gz = gzopen($file, 'wb9');
    if (!$gz) throw new RuntimeException('não consegui criar o arquivo de backup');

    gzwrite($gz, "-- Backup inTakt/Igreja " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

    $tables = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    foreach ($tables as [$table]) {
        $create = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
        gzwrite($gz, "DROP TABLE IF EXISTS `$table`;\n$create;\n\n");
        if (in_array($table, BACKUP_SKIP_DATA, true)) {
            gzwrite($gz, "-- dados de `$table` não incluídos\n\n");
            continue;
        }

        $rows = $db->query("SELECT * FROM `$table`", PDO::FETCH_NUM);
        $batch = [];
        foreach ($rows as $row) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $db->quote((string)$v), $row);
            $batch[] = '(' . implode(',', $vals) . ')';
            if (count($batch) >= 100) {
                gzwrite($gz, "INSERT INTO `$table` VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch) gzwrite($gz, "INSERT INTO `$table` VALUES\n" . implode(",\n", $batch) . ";\n");
        gzwrite($gz, "\n");
    }

    gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n-- fim do backup\n");
    gzclose($gz);
    return $file;
}

/** Apaga os mais antigos, mantendo só BACKUP_KEEP_FILES. */
function backup_rotate(): void {
    $files = glob(backup_dir() . '/backup_*.sql.gz') ?: [];
    sort($files);
    foreach (array_slice($files, 0, max(0, count($files) - BACKUP_KEEP_FILES)) as $old) @unlink($old);
}

/** Manda uma cópia zipada e criptografada (AES-256) pro e-mail de backup. */
function backup_email(PDO $db, string $gzFile): bool {
    $to       = setting('backup_email', '', SEDE_ID);
    $password = setting('backup_zip_password', '', SEDE_ID);
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if ($to === '' || $password === '' || !file_exists($autoload) || !class_exists('ZipArchive')) return false;
    require_once $autoload;

    $zipFile = sys_get_temp_dir() . '/' . basename($gzFile, '.sql.gz') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
    $zip->addFile($gzFile, basename($gzFile));
    $zip->setEncryptionName(basename($gzFile), ZipArchive::EM_AES_256, $password);
    $zip->close();

    try {
        $smtpUser = setting('smtp_user', '', SEDE_ID);
        $smtpPort = (int)setting('smtp_port', '587', SEDE_ID);
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = setting('smtp_host', 'localhost', SEDE_ID);
        $mail->Port       = $smtpPort;
        $mail->SMTPAuth   = $smtpUser !== '';
        $mail->Username   = $smtpUser;
        $mail->Password   = setting('smtp_pass', '', SEDE_ID);
        $mail->SMTPSecure = $smtpPort == 465
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(setting('church_email', '', SEDE_ID) ?: $smtpUser, 'Backup Igreja Manager');
        $mail->addAddress($to);
        $mail->Subject = 'Backup do banco ' . date('d/m/Y');
        $mail->Body    = "Backup automático do banco em anexo (zip com senha AES-256).\nGuarde a senha em local seguro: sem ela o arquivo não abre.";
        $mail->addAttachment($zipFile);
        $mail->send();
        return true;
    } finally {
        @unlink($zipFile);
    }
}

/** Roda tudo e registra o resultado. Nunca lança exceção pra fora. */
function backup_run(PDO $db): array {
    try {
        $file = backup_dump($db);
        backup_rotate();
        $emailed = false;
        try { $emailed = backup_email($db, $file); }
        catch (\Throwable $e) { backup_set($db, 'backup_last_error', 'email: ' . $e->getMessage()); }
        backup_set($db, 'backup_last_ok', date('Y-m-d H:i:s'));
        return ['ok' => true, 'file' => $file, 'size' => filesize($file), 'emailed' => $emailed];
    } catch (\Throwable $e) {
        backup_set($db, 'backup_last_error', date('Y-m-d H:i:s') . ' ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/** Chamada pelo cron a cada minuto: a partir das 3h, faz o backup do dia se ainda não houver. */
function backup_daily_if_due(PDO $db): void {
    if ((int)date('G') < 3) return;
    $today = glob(backup_dir() . '/backup_' . date('Y-m-d') . '_*.sql.gz');
    if ($today) return;

    // Se falhou, não fica tentando a cada minuto: espera 30 min entre tentativas
    $last = setting('backup_last_attempt', '', SEDE_ID);
    if ($last !== '' && strtotime($last) > time() - 1800) return;
    backup_set($db, 'backup_last_attempt', date('Y-m-d H:i:s'));
    backup_run($db);
}
