<?php
/**
 * Cifra como texto: acordes entre colchetes antes da sílaba, ex:
 * "[G]Tudo é [D]perda comparado a [Em]Ti" — convenção tipo ChordPro,
 * simples de digitar/colar e fácil de transpor.
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
