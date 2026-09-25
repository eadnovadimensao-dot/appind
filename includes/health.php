<?php
// Verificações de saúde do sistema. Usado por health.php (monitor externo, que também
// pega o cron parado) e pelo próprio cron (alerta por e-mail de problemas que o cron
// consegue ver: backup, fila travada, WhatsApp desconectado).
require_once __DIR__ . '/backup.php';

const HEALTH_CRON_MAX_AGE_MIN   = 5;
const HEALTH_QUEUE_STUCK_MIN    = 30;
const HEALTH_BACKUP_MAX_AGE_H   = 26;
const HEALTH_ALERT_REPEAT_HOURS = 6;

/** Marca que o cron rodou agora. */
function health_heartbeat(PDO $db): void {
    backup_set($db, 'cron_heartbeat', date('Y-m-d H:i:s'));
}

/** @return array<string, array{ok: bool, detail: string}> */
function health_checks(PDO $db, bool $checkWhatsapp = true): array {
    $out = [];

    $hb  = $db->query("SELECT `value` FROM church_settings WHERE church_id = " . SEDE_ID . " AND `key` = 'cron_heartbeat'")->fetchColumn();
    $age = $hb ? (time() - strtotime($hb)) / 60 : null;
    $out['cron'] = $age === null || $age > HEALTH_CRON_MAX_AGE_MIN
        ? ['ok' => false, 'detail' => 'o cron não roda há ' . ($age === null ? 'tempo indeterminado' : round($age) . ' min') . ' (último sinal: ' . ($hb ?: 'nunca') . ')']
        : ['ok' => true,  'detail' => 'último sinal há ' . round($age) . ' min'];

    $stuck = (int)$db->query("SELECT COUNT(*) FROM whatsapp_queue WHERE status = 'pending'
        AND COALESCE(not_before, created_at) < DATE_SUB(NOW(), INTERVAL " . HEALTH_QUEUE_STUCK_MIN . " MINUTE)")->fetchColumn();
    $out['fila'] = $stuck > 0
        ? ['ok' => false, 'detail' => "$stuck mensagem(ns) do WhatsApp esperando há mais de " . HEALTH_QUEUE_STUCK_MIN . " min além do horário"]
        : ['ok' => true,  'detail' => 'fila em dia'];

    $bk = $db->query("SELECT `value` FROM church_settings WHERE church_id = " . SEDE_ID . " AND `key` = 'backup_last_ok'")->fetchColumn();
    $bkAge = $bk ? (time() - strtotime($bk)) / 3600 : null;
    $out['backup'] = $bkAge === null || $bkAge > HEALTH_BACKUP_MAX_AGE_H
        ? ['ok' => false, 'detail' => 'último backup bem-sucedido: ' . ($bk ?: 'nunca')]
        : ['ok' => true,  'detail' => 'último backup: ' . $bk];

    if ($checkWhatsapp) {
        $out['whatsapp'] = zapi_is_connected(SEDE_ID)
            ? ['ok' => true,  'detail' => 'conectado']
            : ['ok' => false, 'detail' => 'número do WhatsApp desconectado (a fila fica parada até reconectar)'];
    }
    return $out;
}

function health_alert_email(PDO $db, string $subject, string $body): void {
    $to = setting('alert_email', '', SEDE_ID) ?: setting('backup_email', '', SEDE_ID);
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if ($to === '' || !file_exists($autoload)) return;
    require_once $autoload;
    $mail = smtp_mailer('Alerta Igreja Manager');
    $mail->addAddress($to);
    $mail->Subject = $subject;
    $mail->Body    = $body;
    $mail->send();
}

/**
 * Roda no cron (no máximo a cada 10 min): avisa por e-mail quando algo falha em
 * 2 verificações seguidas (evita alarme falso), repete a cada 6h enquanto não
 * resolver e avisa quando volta ao normal. O cron parado não passa por aqui
 * (não há quem rode): esse caso é coberto pelo monitor externo em health.php.
 */
function health_alert_run(PDO $db): void {
    $last = setting('health_last_run', '', SEDE_ID);
    if ($last !== '' && strtotime($last) > time() - 600) return;
    backup_set($db, 'health_last_run', date('Y-m-d H:i:s'));

    foreach (health_checks($db) as $name => $r) {
        if ($name === 'cron') continue;
        $failKey  = "health_fail_$name";
        $alertKey = "health_alerted_$name";
        $fails    = (int)setting($failKey, '0', SEDE_ID);
        $alerted  = setting($alertKey, '', SEDE_ID);

        if ($r['ok']) {
            if ($alerted !== '') {
                health_alert_email($db, "Resolvido: $name", "O problema de '$name' foi resolvido.\n{$r['detail']}");
                backup_set($db, $alertKey, '');
            }
            if ($fails) backup_set($db, $failKey, '0');
            continue;
        }

        backup_set($db, $failKey, (string)($fails + 1));
        if ($fails + 1 < 2) continue;
        if ($alerted !== '' && strtotime($alerted) > time() - HEALTH_ALERT_REPEAT_HOURS * 3600) continue;

        health_alert_email($db, "Problema no sistema: $name", "Verificação '$name' falhou:\n{$r['detail']}\n\nDetectado em " . date('d/m/Y H:i') . '.');
        backup_set($db, $alertKey, date('Y-m-d H:i:s'));
    }
}
