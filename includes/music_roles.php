<?php
// Funções fixas do ministério de Música e composição-alvo de escala.
// Usado por: pages/ministries/view.php, activity_create.php, auto_scale.php

const MUSIC_ROLE_OPTIONS = [
    'Ministro(a) de Louvor',
    'Backing Vocal',
    'Guitarrista',
    'Baixista',
    'Baterista',
    'Violonista',
    'Tecladista',
];

// Quantas pessoas de cada função a escala ideal precisa
const MUSIC_ROLE_COMPOSITION = [
    'Ministro(a) de Louvor' => 1,
    'Backing Vocal'         => 3,
    'Guitarrista'           => 1,
    'Baixista'              => 1,
    'Baterista'             => 1,
    'Violonista'            => 1,
    'Tecladista'            => 1,
];

function is_music_ministry(string $name): bool {
    $n = mb_strtolower(trim($name));
    // remove acentos comuns pra casar "música", "Música e Louvor", "Ministério de Musica" etc.
    $n = strtr($n, [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c',
    ]);
    return str_contains($n, 'music');
}
