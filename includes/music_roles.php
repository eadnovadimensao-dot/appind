<?php
// Funções fixas do ministério de Música e composição-alvo de escala.
// Usado por: pages/ministries/view.php, activity_create.php, auto_scale.php

const MUSIC_ROLE_OPTIONS = [
    'Pastor(a) da Base de Adoração',
    'Ministro(a) de Louvor',
    'Backing Vocal',
    'Guitarrista',
    'Baixista',
    'Baterista',
    'Violonista',
    'Tecladista',
];

// Quantas pessoas de cada função a escala ideal precisa (rodízio normal)
const MUSIC_ROLE_COMPOSITION = [
    'Ministro(a) de Louvor' => 1,
    'Backing Vocal'         => 3,
    'Guitarrista'           => 1,
    'Baixista'              => 1,
    'Baterista'             => 1,
    'Violonista'            => 1,
    'Tecladista'            => 1,
];

// Funções que não entram no rodízio: todo membro marcado com essa função é
// escalado sempre, em toda escala gerada automaticamente.
const MUSIC_ALWAYS_INCLUDE_ROLES = [
    'Pastor(a) da Base de Adoração',
];

// Monta o "pool" de candidatos agrupado por função, a partir dos membros do
// ministério. Usado tanto pelo sorteio avulso (auto_scale.php) quanto pelo
// sorteio em lote (activity_batch_create.php).
function build_music_pool(PDO $db, int $ministryId, int $churchId): array {
    $members = $db->prepare("
        SELECT m.id, m.name, mm.role
        FROM member_ministries mm
        JOIN members m ON m.id = mm.member_id
        WHERE mm.ministry_id = ? AND m.church_id = ?
    ");
    $members->execute([$ministryId, $churchId]);
    $pool = [];
    foreach ($members->fetchAll() as $m) {
        if (!$m['role']) continue;
        $pool[$m['role']][] = ['id' => (int)$m['id'], 'name' => $m['name']];
    }
    return $pool;
}

// Sorteia uma escala a partir do pool de candidatos, evitando repetir quem
// serviu na escala anterior (passada em $prevMemberIds) quando dá pra evitar.
// Retorna ['assignments' => [member_id => role], 'warnings' => [string, ...]].
function draw_music_scale(array $pool, array $prevMemberIds): array {
    $assignments = []; // member_id => role
    $warnings    = [];

    // Funções que sempre entram, sem rodízio: escala todo mundo que tem essa função
    foreach (MUSIC_ALWAYS_INCLUDE_ROLES as $role) {
        $candidates = $pool[$role] ?? [];
        if (empty($candidates)) {
            $warnings[] = "$role: nenhum membro cadastrado nessa função.";
            continue;
        }
        foreach ($candidates as $c) {
            $assignments[$c['id']] = $role;
        }
    }

    foreach (MUSIC_ROLE_COMPOSITION as $role => $needed) {
        $candidates = $pool[$role] ?? [];

        // Ministros de louvor que não foram escalados como o ministro da semana
        // entram também na disputa pelas vagas de Backing Vocal.
        if ($role === 'Backing Vocal') {
            $extraMinistros = array_filter(
                $pool['Ministro(a) de Louvor'] ?? [],
                fn($c) => !isset($assignments[$c['id']])
            );
            $candidates = array_merge($candidates, array_values($extraMinistros));
        }

        $fresh  = array_values(array_filter($candidates, fn($c) => !in_array($c['id'], $prevMemberIds, true)));
        $recent = array_values(array_filter($candidates, fn($c) =>  in_array($c['id'], $prevMemberIds, true)));

        shuffle($fresh);
        shuffle($recent);

        $chosen = array_slice($fresh, 0, $needed);
        if (count($chosen) < $needed) {
            $chosen = array_merge($chosen, array_slice($recent, 0, $needed - count($chosen)));
        }

        foreach ($chosen as $c) {
            $assignments[$c['id']] = $role;
        }

        if (count($chosen) < $needed) {
            $warnings[] = "$role: só " . count($chosen) . " de $needed disponível(is).";
        }
    }

    return ['assignments' => $assignments, 'warnings' => $warnings];
}

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
