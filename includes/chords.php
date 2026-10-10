<?php
/**
 * Cifra como texto, em dois formatos possíveis no mesmo campo:
 *
 * 1) Simples — acorde entre colchetes antes da sílaba:
 *    "[G]Tudo é [D]perda comparado a [Em]Ti"
 *
 * 2) Com seções (estilo "mapa da música") — blocos nomeados, acorde
 *    numa linha, letra embaixo. Sem lista de mapa separada: a ordem
 *    das seções no mapa é simplesmente a ordem em que elas aparecem
 *    no texto, cada uma pode levar um número de repetição:
 *
 *    [Verso]
 *    nota: Toda banda, dinâmica média
 *    G              Bm
 *    És a vida,    és o amor
 *
 *    [Refrão (2x)]
 *    ...
 *
 * render_chord_chart() detecta automaticamente qual dos dois foi usado
 * (se tem alguma linha "[Seção]" sozinha, é o formato 2) e cai pro
 * formato simples se não achar nenhuma seção.
 *
 * O parse de transposição em si roda no cliente (public/js/chord-transpose.js);
 * aqui só marcamos cada acorde com <span class="chord"> pra o JS achar.
 */

function render_chord_sheet(string $text): string {
    // Sem nl2br() aqui — o CSS do .chord-sheet usa white-space:pre-wrap,
    // que já preserva as quebras de linha originais. Usar os dois juntos
    // duplicava as quebras (uma do <br>, outra do \n preservado).
    $escaped = htmlspecialchars($text);
    return preg_replace('/\[([^\[\]]+)\]/', '<span class="chord">$1</span>', $escaped);
}

/** Um "token" isolado parece acorde? (G, Bm7, Gadd9, Asus4, D/F#…) */
function chord_token_looks_like_chord(string $token): bool {
    return (bool)preg_match('/^[A-G][#b]?[a-zA-Z0-9]*(\/[A-G][#b]?)?$/', $token);
}

/** A linha inteira é só acordes (com espaço entre eles)? */
function is_chord_line(string $line): bool {
    $trimmed = trim($line);
    if ($trimmed === '') return false;
    foreach (preg_split('/\s+/', $trimmed) as $token) {
        if (!chord_token_looks_like_chord($token)) return false;
    }
    return true;
}

/** Marca cada acorde da linha com <span class="chord">, preservando o espaçamento original (alinha com a letra embaixo). */
function render_chord_line(string $line): string {
    return preg_replace_callback('/\S+/', function ($m) {
        return '<span class="chord">' . htmlspecialchars($m[0]) . '</span>';
    }, $line);
}

function chart_normalize(string $s): string {
    $map = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c'];
    return strtr(mb_strtolower($s), $map);
}

/** Cor do badge por tipo de seção, por palavra-chave (aceita qualquer nome digitado; cai pro cinza se não reconhecer). */
function chart_section_color(string $title): string {
    $n = chart_normalize($title);
    if (str_contains($n, 'refrao') && str_contains($n, 'pre')) return 'badge-amber';
    if (str_contains($n, 'refrao'))      return 'badge-green';
    if (str_contains($n, 'verso'))       return 'badge-blue';
    if (str_contains($n, 'ponte'))       return 'badge-purple';
    if (str_contains($n, 'final'))       return 'badge-red';
    return 'badge-gray';
}

/**
 * Quebra o texto em seções: array de
 * ['title', 'repeat' => int, 'note' => string|null, 'body' => linhas cruas].
 * Cabeçalho aceita repetição opcional: "[Refrão]" ou "[Refrão (2x)]".
 * "nota:" pode aparecer mais de uma vez na mesma seção (vira várias linhas).
 */
function parse_chord_chart(string $text): array {
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $sections = [];
    $curIdx = -1;

    foreach ($lines as $line) {
        $trim = trim($line);

        if (preg_match('/^\[(.+?)(?:\s*\(\s*(\d+)\s*x\s*\))?\]$/i', $trim, $m)) {
            $sections[] = [
                'title'  => trim($m[1]),
                'repeat' => (isset($m[2]) && $m[2] !== '') ? (int)$m[2] : 1,
                'note'   => null,
                'body'   => [],
            ];
            $curIdx = count($sections) - 1;
            continue;
        }

        if ($curIdx === -1) continue; // texto solto antes de qualquer [Seção] — ignora

        if (preg_match('/^nota\s*:\s*(.+)$/i', $trim, $m)) {
            $noteLine = trim($m[1]);
            $sections[$curIdx]['note'] = $sections[$curIdx]['note'] === null
                ? $noteLine
                : $sections[$curIdx]['note'] . "\n" . $noteLine;
            continue;
        }

        $sections[$curIdx]['body'][] = $line; // mantém espaçamento original (alinha acorde/letra)
    }

    return $sections;
}

/**
 * Renderiza a cifra inteira. Detecta sozinho se tem estrutura de seção;
 * se não tiver nenhuma, cai pro formato simples (render_chord_sheet).
 * O "mapa" no topo é só a lista das seções na ordem em que foram
 * digitadas — sem precisar escrever isso separado.
 */
function render_chord_chart(string $text): string {
    $sections = parse_chord_chart($text);
    if (empty($sections)) {
        // Sem seção nenhuma: formato simples, continua funcionando igual antes.
        return '<div class="chord-sheet">' . render_chord_sheet($text) . '</div>';
    }

    $html = '<div class="chart-map">';
    foreach ($sections as $i => $sec) {
        $label = htmlspecialchars($sec['title']) . ($sec['repeat'] > 1 ? ' ×' . $sec['repeat'] : '');
        $html .= '<a href="#chart-sec-' . $i . '" class="badge ' . chart_section_color($sec['title']) . '" style="text-decoration:none">' . $label . '</a>';
    }
    $html .= '</div>';

    foreach ($sections as $i => $sec) {
        $bodyLines = [];
        foreach ($sec['body'] as $line) {
            $bodyLines[] = is_chord_line($line) ? render_chord_line($line) : htmlspecialchars($line);
        }
        $html .= '<div id="chart-sec-' . $i . '" class="chart-section" style="scroll-margin-top:16px">';
        $html .= '<div class="chart-section-header">';
        $html .= '<span class="badge ' . chart_section_color($sec['title']) . '">' . htmlspecialchars($sec['title']);
        if ($sec['repeat'] > 1) $html .= ' ×' . $sec['repeat'];
        $html .= '</span>';
        if ($sec['note']) {
            $html .= '<span class="chart-note">' . nl2br(htmlspecialchars($sec['note'])) . '</span>';
        }
        $html .= '</div>';
        $html .= '<div class="chord-sheet">' . implode("\n", $bodyLines) . '</div>';
        $html .= '</div>';
    }

    return $html;
}
