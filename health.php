<?php
// Endpoint para monitor externo (ex.: UptimeRobot, tipo "HTTP(s)", a cada 5 min).
// Responde 200 com "OK" quando tudo está em dia e 503 com "PROBLEMA: ..." quando não.
// Como não depende do cron, é o que avisa quando o próprio cron para de rodar.
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/health.php';

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Content-Type: text/plain; charset=utf-8');

if (!hash_equals('3f9c1e7a5b2d48f6a0c9e1d7b4a86532', (string)($_GET['key'] ?? ''))) { http_response_code(403); exit('forbidden'); }

try {
    $problems = [];
    foreach (health_checks(db()) as $name => $r) {
        if (!$r['ok']) $problems[] = "$name: {$r['detail']}";
    }
} catch (\Throwable $e) {
    $problems = ['erro ao verificar: ' . $e->getMessage()];
}

if ($problems) {
    http_response_code(503);
    echo "PROBLEMA: " . implode(' | ', $problems) . "\n";
} else {
    echo "OK\n";
}
