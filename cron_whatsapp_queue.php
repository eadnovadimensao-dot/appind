<?php
// Processa a fila de WhatsApp aos poucos, com pausa humanizada entre envios,
// pra reduzir o risco de bloqueio do número no WhatsApp.
// Configurar como Cron Job no cPanel rodando a cada 1 minuto, chamando esta URL:
//   https://app.igrejanovadimensao.com.br/cron_whatsapp_queue.php?key=153aba36067d74d99991e4f1b3c1414165dfbf6b
//
// Comando sugerido no cPanel (Cron Jobs → Add New Cron Job → a cada minuto):
//   curl -s "https://app.igrejanovadimensao.com.br/cron_whatsapp_queue.php?key=153aba36067d74d99991e4f1b3c1414165dfbf6b" >/dev/null 2>&1

require_once __DIR__ . '/config/database.php';

// Evitar cache de página (LiteSpeed) nesse endpoint — ele precisa rodar de verdade a cada chamada
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$SECRET = '153aba36067d74d99991e4f1b3c1414165dfbf6b';
if (($_GET['key'] ?? '') !== $SECRET) { http_response_code(403); exit('forbidden'); }

set_time_limit(90);

$db = db();

// Até 4 mensagens por execução — o cron rodando a cada minuto já dá uma
// cadência humana; a pausa abaixo evita rajada mesmo dentro dessa leva.
$batch = $db->query("
    SELECT * FROM whatsapp_queue
    WHERE status = 'pending' AND attempts < 3
    ORDER BY created_at ASC
    LIMIT 4
")->fetchAll();

$sent = 0;
$failed = 0;

foreach ($batch as $i => $item) {
    $buttons = $item['buttons'] ? json_decode($item['buttons'], true) : null;
    $ok = $buttons
        ? send_whatsapp_buttons($item['phone'], $item['message'], $buttons, $item['church_id'])
        : send_whatsapp($item['phone'], $item['message'], $item['church_id']);

    if ($ok) {
        $db->prepare("UPDATE whatsapp_queue SET status='sent', sent_at=NOW() WHERE id=?")->execute([$item['id']]);
        $sent++;
    } else {
        $attempts = $item['attempts'] + 1;
        $status   = $attempts >= 3 ? 'failed' : 'pending';
        $db->prepare("UPDATE whatsapp_queue SET attempts=?, status=? WHERE id=?")->execute([$attempts, $status, $item['id']]);
        if ($status === 'failed') $failed++;
    }

    // Pausa humanizada entre envios (não pausa depois do último item da leva)
    if ($i < count($batch) - 1) {
        sleep(rand(4, 9));
    }
}

// Limpeza: some com registros antigos já processados (mantém a tabela enxuta)
$db->exec("DELETE FROM whatsapp_queue WHERE status IN ('sent','failed') AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");

header('Content-Type: text/plain; charset=utf-8');
echo "Processed: " . count($batch) . " | sent: $sent | failed: $failed\n";
