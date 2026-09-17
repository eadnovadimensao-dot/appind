<?php
// Gráficos inline em SVG pro dashboard — sem dependência externa.
// Paleta categórica validada (CVD-safe): azul #2a78d6, roxo #4a3aa7, vermelho #e34948.
// Specs: linha 2px, barra com topo arredondado 4px, gridline hairline 1px,
// gap de 2px entre barras vizinhas, tooltip nativo via <title> (sempre acessível).

const CHART_INK_MUTED    = '#898781';
const CHART_GRID         = '#e1e0d9';
const CHART_AXIS         = '#c3c2b7';
const CHART_TEXT_PRIMARY = '#0b0b0b';

/**
 * Gráfico de linha — uma série. $points = [['label'=>'Abr','value'=>40], ...]
 */
function render_line_chart(array $points, string $color, string $valueFormat = '%s'): string {
    if (empty($points)) return '';
    $w = 600; $h = 180; $padL = 8; $padR = 8; $padT = 16; $padB = 28;
    $plotW = $w - $padL - $padR;
    $plotH = $h - $padT - $padB;

    $values = array_column($points, 'value');
    $max = max($values);
    $min = min(0, min($values));
    $range = ($max - $min) ?: 1;
    $n = count($points);
    $step = $n > 1 ? $plotW / ($n - 1) : 0;

    $coords = [];
    foreach ($points as $i => $p) {
        $x = $padL + $i * $step;
        $y = $padT + $plotH - (($p['value'] - $min) / $range) * $plotH;
        $coords[] = [$x, $y];
    }

    $pathD = 'M' . implode(' L', array_map(fn($c) => round($c[0],1) . ' ' . round($c[1],1), $coords));

    ob_start();
    ?>
    <svg viewBox="0 0 <?= $w ?> <?= $h ?>" style="width:100%;height:auto;overflow:visible" role="img">
      <!-- gridlines -->
      <?php for ($g = 0; $g <= 2; $g++): $gy = $padT + $plotH * $g / 2; ?>
        <line x1="<?= $padL ?>" y1="<?= $gy ?>" x2="<?= $w - $padR ?>" y2="<?= $gy ?>" stroke="<?= CHART_GRID ?>" stroke-width="1"/>
      <?php endfor; ?>
      <!-- baseline -->
      <line x1="<?= $padL ?>" y1="<?= $padT + $plotH ?>" x2="<?= $w - $padR ?>" y2="<?= $padT + $plotH ?>" stroke="<?= CHART_AXIS ?>" stroke-width="1"/>
      <!-- linha -->
      <path d="<?= $pathD ?>" fill="none" stroke="<?= $color ?>" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
      <!-- pontos + hit target + tooltip nativo -->
      <?php foreach ($coords as $i => [$x, $y]): ?>
        <circle cx="<?= round($x,1) ?>" cy="<?= round($y,1) ?>" r="12" fill="transparent">
          <title><?= htmlspecialchars($points[$i]['label']) ?>: <?= htmlspecialchars(sprintf($valueFormat, $points[$i]['value'])) ?></title>
        </circle>
        <circle cx="<?= round($x,1) ?>" cy="<?= round($y,1) ?>" r="4" fill="<?= $color ?>" stroke="#fcfcfb" stroke-width="2"/>
      <?php endforeach; ?>
      <!-- rótulo do último ponto -->
      <?php [$lx, $ly] = end($coords); $lastVal = end($points)['value']; ?>
      <text x="<?= min($lx + 8, $w - 30) ?>" y="<?= max($ly - 8, 12) ?>" font-size="12" font-weight="600" fill="<?= CHART_TEXT_PRIMARY ?>" font-family="system-ui,-apple-system,sans-serif">
        <?= htmlspecialchars(sprintf($valueFormat, $lastVal)) ?>
      </text>
      <!-- eixo x -->
      <?php foreach ($coords as $i => [$x, $y]): ?>
        <text x="<?= round($x,1) ?>" y="<?= $h - 6 ?>" font-size="11" fill="<?= CHART_INK_MUTED ?>" text-anchor="middle" font-family="system-ui,-apple-system,sans-serif">
          <?= htmlspecialchars($points[$i]['label']) ?>
        </text>
      <?php endforeach; ?>
    </svg>
    <?php
    return ob_get_clean();
}

/**
 * Gráfico de barras — uma série. $points = [['label'=>'Abr','value'=>12], ...]
 */
function render_bar_chart(array $points, string $color, string $valueFormat = '%s'): string {
    if (empty($points)) return '';
    $w = 600; $h = 180; $padL = 8; $padR = 8; $padT = 20; $padB = 28;
    $plotW = $w - $padL - $padR;
    $plotH = $h - $padT - $padB;

    $values = array_column($points, 'value');
    $max = max(max($values), 1);
    $n = count($points);
    $gap = 6;
    $barW = min(24, ($plotW / $n) - $gap);
    $slot = $plotW / $n;

    ob_start();
    ?>
    <svg viewBox="0 0 <?= $w ?> <?= $h ?>" style="width:100%;height:auto;overflow:visible" role="img">
      <?php for ($g = 0; $g <= 2; $g++): $gy = $padT + $plotH * $g / 2; ?>
        <line x1="<?= $padL ?>" y1="<?= $gy ?>" x2="<?= $w - $padR ?>" y2="<?= $gy ?>" stroke="<?= CHART_GRID ?>" stroke-width="1"/>
      <?php endfor; ?>
      <line x1="<?= $padL ?>" y1="<?= $padT + $plotH ?>" x2="<?= $w - $padR ?>" y2="<?= $padT + $plotH ?>" stroke="<?= CHART_AXIS ?>" stroke-width="1"/>
      <?php foreach ($points as $i => $p):
        $barH = $max > 0 ? ($p['value'] / $max) * $plotH : 0;
        $x = $padL + $i * $slot + ($slot - $barW) / 2;
        $y = $padT + $plotH - $barH;
        $r = min(4, $barH);
      ?>
        <path d="M<?= round($x,1) ?> <?= round($y+$r,1) ?>
                 A<?= $r ?> <?= $r ?> 0 0 1 <?= round($x+$r,1) ?> <?= round($y,1) ?>
                 L<?= round($x+$barW-$r,1) ?> <?= round($y,1) ?>
                 A<?= $r ?> <?= $r ?> 0 0 1 <?= round($x+$barW,1) ?> <?= round($y+$r,1) ?>
                 L<?= round($x+$barW,1) ?> <?= round($padT+$plotH,1) ?>
                 L<?= round($x,1) ?> <?= round($padT+$plotH,1) ?> Z"
              fill="<?= $color ?>">
          <title><?= htmlspecialchars($p['label']) ?>: <?= htmlspecialchars(sprintf($valueFormat, $p['value'])) ?></title>
        </path>
        <text x="<?= round($x + $barW/2,1) ?>" y="<?= $h - 6 ?>" font-size="11" fill="<?= CHART_INK_MUTED ?>" text-anchor="middle" font-family="system-ui,-apple-system,sans-serif">
          <?= htmlspecialchars($p['label']) ?>
        </text>
      <?php endforeach; ?>
    </svg>
    <?php
    return ob_get_clean();
}

/**
 * Gráfico de barras agrupadas — duas séries.
 * $points = [['label'=>'Abr','a'=>100,'b'=>40], ...]
 * $series = [['key'=>'a','label'=>'Entradas','color'=>'#2a78d6'], ['key'=>'b','label'=>'Saídas','color'=>'#e34948']]
 */
function render_grouped_bar_chart(array $points, array $series, string $valueFormat = '%s'): string {
    if (empty($points)) return '';
    $w = 600; $h = 200; $padL = 8; $padR = 8; $padT = 20; $padB = 28;
    $plotW = $w - $padL - $padR;
    $plotH = $h - $padT - $padB;

    $allValues = [];
    foreach ($points as $p) foreach ($series as $s) $allValues[] = $p[$s['key']] ?? 0;
    $max = max(max($allValues), 1);
    $n = count($points);
    $slot = $plotW / $n;
    $innerGap = 2;
    $groupPad = 10;
    $barW = min(24, ($slot - $groupPad - $innerGap * (count($series) - 1)) / count($series));

    ob_start();
    ?>
    <svg viewBox="0 0 <?= $w ?> <?= $h ?>" style="width:100%;height:auto;overflow:visible" role="img">
      <?php for ($g = 0; $g <= 2; $g++): $gy = $padT + $plotH * $g / 2; ?>
        <line x1="<?= $padL ?>" y1="<?= $gy ?>" x2="<?= $w - $padR ?>" y2="<?= $gy ?>" stroke="<?= CHART_GRID ?>" stroke-width="1"/>
      <?php endfor; ?>
      <line x1="<?= $padL ?>" y1="<?= $padT + $plotH ?>" x2="<?= $w - $padR ?>" y2="<?= $padT + $plotH ?>" stroke="<?= CHART_AXIS ?>" stroke-width="1"/>
      <?php foreach ($points as $i => $p):
        $groupW = $barW * count($series) + $innerGap * (count($series) - 1);
        $groupX = $padL + $i * $slot + ($slot - $groupW) / 2;
        foreach ($series as $si => $s):
          $val  = $p[$s['key']] ?? 0;
          $barH = $max > 0 ? ($val / $max) * $plotH : 0;
          $x = $groupX + $si * ($barW + $innerGap);
          $y = $padT + $plotH - $barH;
          $r = min(4, $barH);
        ?>
          <path d="M<?= round($x,1) ?> <?= round($y+$r,1) ?>
                   A<?= $r ?> <?= $r ?> 0 0 1 <?= round($x+$r,1) ?> <?= round($y,1) ?>
                   L<?= round($x+$barW-$r,1) ?> <?= round($y,1) ?>
                   A<?= $r ?> <?= $r ?> 0 0 1 <?= round($x+$barW,1) ?> <?= round($y+$r,1) ?>
                   L<?= round($x+$barW,1) ?> <?= round($padT+$plotH,1) ?>
                   L<?= round($x,1) ?> <?= round($padT+$plotH,1) ?> Z"
                fill="<?= $s['color'] ?>">
            <title><?= htmlspecialchars($p['label']) ?> · <?= htmlspecialchars($s['label']) ?>: <?= htmlspecialchars(sprintf($valueFormat, $val)) ?></title>
          </path>
        <?php endforeach; ?>
        <text x="<?= round($groupX + $groupW/2,1) ?>" y="<?= $h - 6 ?>" font-size="11" fill="<?= CHART_INK_MUTED ?>" text-anchor="middle" font-family="system-ui,-apple-system,sans-serif">
          <?= htmlspecialchars($p['label']) ?>
        </text>
      <?php endforeach; ?>
    </svg>
    <div style="display:flex;gap:16px;margin-top:8px;padding-left:8px">
      <?php foreach ($series as $s): ?>
        <div style="display:flex;align-items:center;gap:6px;font-size:12px;color:<?= CHART_INK_MUTED ?>">
          <span style="display:inline-block;width:10px;height:2px;background:<?= $s['color'] ?>;border-radius:1px"></span>
          <?= htmlspecialchars($s['label']) ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

function chart_empty_state(string $message): string {
    return '<div class="empty-state" style="padding:32px 16px;font-size:13px">' . htmlspecialchars($message) . '</div>';
}
