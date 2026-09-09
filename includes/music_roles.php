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
    return $n === 'música' || $n === 'musica';
}
