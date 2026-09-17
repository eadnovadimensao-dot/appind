<?php
// Helpers pra exportação de relatórios — página formatada pra impressão
// (o navegador salva como PDF) e download de CSV. Sem dependência externa.

/**
 * Abre uma página de impressão padronizada: cabeçalho com logo/nome da
 * igreja, título, subtítulo (ex: período) e data de geração. Chame
 * print_page_end() no final.
 */
function print_page_start(string $title, string $subtitle = '', ?int $churchId = null): void {
    $churchId     = $churchId ?? current_church_id();
    $churchName   = setting('church_name', APP_NAME, $churchId);
    $logoUrl      = setting('church_logo_url', '', $churchId);
    $primaryColor = setting('primary_color', '#012a36', $churchId);
    $accentColor  = setting('accent_color', '#1D9E75', $churchId);
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($title) ?> · <?= htmlspecialchars($churchName) ?></title>
  <style>
    * { box-sizing: border-box; }
    body {
      font-family: system-ui, -apple-system, sans-serif;
      color: #1a2332;
      max-width: 880px;
      margin: 0 auto;
      padding: 32px 24px 60px;
      background: #fff;
    }
    .print-toolbar {
      position: sticky; top: 0; background: #fff;
      display: flex; justify-content: flex-end; gap: 8px;
      padding: 12px 0; margin-bottom: 8px; border-bottom: 1px solid #e5e7eb;
    }
    .print-btn {
      background: <?= htmlspecialchars($accentColor) ?>; color: #fff; border: none;
      border-radius: 8px; padding: 9px 16px; font-size: 13px; font-weight: 500;
      cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
    }
    .print-btn.secondary { background: #f3f4f6; color: #374151; }
    .print-header {
      display: flex; align-items: center; gap: 14px;
      padding-bottom: 18px; margin-bottom: 20px; border-bottom: 2px solid <?= htmlspecialchars($primaryColor) ?>;
    }
    .print-logo {
      width: 48px; height: 48px; border-radius: 10px; overflow: hidden; flex-shrink: 0;
      background: <?= htmlspecialchars($primaryColor) ?>; display: flex; align-items: center; justify-content: center;
      color: #fff; font-size: 20px;
    }
    .print-header h1 { font-size: 18px; font-weight: 600; margin: 0 0 2px; }
    .print-header .church-name { font-size: 13px; color: #6b7280; }
    .print-meta { font-size: 12px; color: #9ca3af; margin-bottom: 24px; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; padding: 8px 10px; border-bottom: 2px solid #e5e7eb; }
    td { padding: 8px 10px; border-bottom: 1px solid #f0f0f0; }
    .print-footer { margin-top: 32px; font-size: 11px; color: #9ca3af; text-align: center; }
    @media print {
      .print-toolbar { display: none; }
      body { padding: 0; max-width: 100%; }
      a { color: inherit; text-decoration: none; }
    }
  </style>
</head>
<body>
  <div class="print-toolbar">
    <button class="print-btn" onclick="window.print()">🖨️ Imprimir / Salvar PDF</button>
    <a href="javascript:history.back()" class="print-btn secondary">← Voltar</a>
  </div>
  <div class="print-header">
    <div class="print-logo">
      <?php if ($logoUrl): ?>
        <img src="<?= htmlspecialchars($logoUrl) ?>" style="width:100%;height:100%;object-fit:cover">
      <?php else: ?>✝<?php endif; ?>
    </div>
    <div>
      <h1><?= htmlspecialchars($title) ?></h1>
      <div class="church-name"><?= htmlspecialchars($churchName) ?><?= $subtitle ? ' · ' . htmlspecialchars($subtitle) : '' ?></div>
    </div>
  </div>
    <?php
}

function print_page_end(): void {
    ?>
  <div class="print-footer">
    Gerado em <?= date('d/m/Y \à\s H:i') ?> pelo sistema de gestão.
  </div>
</body>
</html>
    <?php
}

/**
 * Gera e envia um arquivo CSV pro navegador (download direto), com BOM
 * UTF-8 pra abrir certo no Excel. $rows = array de arrays associativos;
 * as chaves do primeiro item viram o cabeçalho se $headers não for dado.
 */
function csv_download(string $filename, array $rows, ?array $headers = null): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8

    if ($headers === null && !empty($rows)) {
        $headers = array_keys($rows[0]);
    }
    if ($headers) fputcsv($out, $headers, ';');

    foreach ($rows as $row) {
        fputcsv($out, $row, ';');
    }
    fclose($out);
    exit;
}
