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

// Só uma execução por vez (o lock some sozinho quando a execução termina): se duas
// chamadas coincidirem (várias linhas de cron, execução manual), a segunda sai
// em vez de mandar a mesma mensagem duas vezes.
if (!$db->query("SELECT GET_LOCK('wa_queue_cron', 0)")->fetchColumn()) {
    header('Content-Type: text/plain; charset=utf-8');
    exit("busy\n");
}

// Rotinas do culto. Se qualquer uma falhar, não pode impedir o envio da fila logo abaixo.
// 1) Garante os próximos domingos criados a partir do modelo (idempotente)
try {
    require_once __DIR__ . '/includes/service_generate.php';
    generate_upcoming_services($db);
} catch (\Throwable $e) {
    error_log('cron gerar cultos: ' . $e->getMessage());
}
// 2) Devocional agendado pra hoje: a partir das 6h enfileira pros membros
try {
    require_once __DIR__ . '/includes/devotional.php';
    queue_due_devotionals($db);
} catch (Throwable $e) {
    error_log('cron devocional: ' . $e->getMessage());
}
// 3) Convida pro check-in quem ainda não foi convidado nos cultos de hoje
try {
    require_once __DIR__ . '/includes/service_checkin.php';
    queue_service_checkins_for_today($db);
} catch (\Throwable $e) {
    error_log('cron checkin: ' . $e->getMessage());
}

// Até 4 mensagens por execução — o cron rodando a cada minuto já dá uma
// cadência humana; a pausa abaixo evita rajada mesmo dentro dessa leva.
$batch = $db->query("
    SELECT * FROM whatsapp_queue
    WHERE status = 'pending' AND attempts < 3
      AND (not_before IS NULL OR not_before <= NOW())
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
