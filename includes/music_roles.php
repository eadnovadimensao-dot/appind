<?php
// Escala automática por rodízio, com composição de funções configurável por
// ministério (ministry_roles) — não é mais fixo pra Música.
// Usado por: pages/ministries/view.php, activity_view.php, activity_create.php,
// auto_scale.php, activity_batch_create.php, roles.php.

// Naipes disponíveis pra funções marcadas como "usa naipe" (mesclagem de
// vocais, ex: Backing Vocal). Reutilizado por qualquer ministério.
const BACKING_VOCAL_NAIPES = ['Soprano', 'Contralto', 'Tenor'];

/** Funções configuradas da escala automática de um ministério, em ordem. */
function get_ministry_roles(PDO $db, int $ministryId): array {
    $stmt = $db->prepare("SELECT * FROM ministry_roles WHERE ministry_id = ? ORDER BY position");
    $stmt->execute([$ministryId]);
    return $stmt->fetchAll();
}

// Monta o "pool" de candidatos agrupado por função, a partir dos membros do
// ministério. Usado tanto pelo sorteio avulso (auto_scale.php) quanto pelo
// sorteio em lote (activity_batch_create.php).
function build_scale_pool(PDO $db, int $ministryId, int $churchId): array {
    $members = $db->prepare("
        SELECT m.id, m.name, mm.role, mm.naipe
        FROM member_ministries mm
        JOIN members m ON m.id = mm.member_id
        WHERE mm.ministry_id = ? AND m.church_id = ?
    ");
    $members->execute([$ministryId, $churchId]);
    $pool = [];
    foreach ($members->fetchAll() as $m) {
        if (!$m['role']) continue;
        $pool[$m['role']][] = ['id' => (int)$m['id'], 'name' => $m['name'], 'naipe' => $m['naipe'] ?: null];
    }
    return $pool;
}

// Escolhe candidatos tentando mesclar naipes diferentes (Soprano/Contralto/
// Tenor) em vez de sortear solto — evita cair várias pessoas do mesmo naipe
// na escala. Prioridade: 1) uma pessoa de cada naipe real (rodízio, maximiza
// diversidade); 2) se ainda faltar vaga, gente sem naipe definido (não
// atrapalha nem ajuda a mesclagem); 3) só por último, repete naipe. Em
// qualquer nível, prioriza quem não serviu na escala anterior.
// Retorna [chosen_candidates, warning_ou_null].
function draw_naipe_mix(string $roleName, array $candidates, int $needed, array $prevMemberIds): array {
    if ($needed <= 0 || empty($candidates)) return [[], null];

    $prioritize = function (array $g) use ($prevMemberIds) {
        $fresh  = array_values(array_filter($g, fn($c) => !in_array($c['id'], $prevMemberIds, true)));
        $recent = array_values(array_filter($g, fn($c) =>  in_array($c['id'], $prevMemberIds, true)));
        shuffle($fresh);
        shuffle($recent);
        return array_merge($fresh, $recent);
    };

    // Só naipes de verdade formam grupos pro rodízio — quem não tem naipe
    // definido fica de fora, entra só depois como reserva.
    $namedGroups = [];
    foreach ($candidates as $c) {
        if ($c['naipe']) $namedGroups[$c['naipe']][] = $c;
    }
    foreach ($namedGroups as $key => $g) $namedGroups[$key] = $prioritize($g);

    // Ordem dos naipes embaralhada, pra não sempre priorizar o mesmo primeiro
    $groupKeys = array_keys($namedGroups);
    shuffle($groupKeys);
    $distinctNaipes = count($groupKeys);

    // 1) Rodízio: uma pessoa de cada naipe por rodada, até preencher as vagas
    $chosen    = [];
    $chosenIds = [];
    $round     = 0;
    while (count($chosen) < $needed) {
        $addedThisRound = false;
        foreach ($groupKeys as $key) {
            if (count($chosen) >= $needed) break;
            if (isset($namedGroups[$key][$round])) {
                $c = $namedGroups[$key][$round];
                $chosen[]    = $c;
                $chosenIds[] = $c['id'];
                $addedThisRound = true;
            }
        }
        if (!$addedThisRound) break; // esgotou todo mundo com naipe
        $round++;
    }

    // 2) e 3) Ainda falta vaga: completa com quem sobrou — sem naipe primeiro,
    // repetindo naipe só se precisar mesmo
    if (count($chosen) < $needed) {
        $remaining    = array_values(array_filter($candidates, fn($c) => !in_array($c['id'], $chosenIds, true)));
        $unclassified = array_values(array_filter($remaining, fn($c) => !$c['naipe']));
        $repeats      = array_values(array_filter($remaining, fn($c) =>  $c['naipe']));
        foreach (array_merge($prioritize($unclassified), $prioritize($repeats)) as $c) {
            if (count($chosen) >= $needed) break;
            $chosen[]    = $c;
            $chosenIds[] = $c['id'];
        }
    }

    $warning = null;
    if ($needed > 1 && $distinctNaipes > 0 && $distinctNaipes < $needed && count($candidates) >= $needed) {
        $warning = "$roleName: só $distinctNaipes naipe(s) cadastrado(s) entre os disponíveis pra $needed vaga(s) — não deu pra mesclar tudo.";
    }

    return [$chosen, $warning];
}

// Sorteia uma escala a partir do pool de candidatos e da composição de
// funções configurada pro ministério, evitando repetir quem serviu na
// escala anterior (passada em $prevMemberIds) quando dá pra evitar.
// Retorna ['assignments' => [member_id => role], 'warnings' => [string, ...]].
function draw_scale(PDO $db, int $ministryId, array $pool, array $prevMemberIds): array {
    $roles       = get_ministry_roles($db, $ministryId);
    $assignments = []; // member_id => role
    $warnings    = [];

    // Funções que sempre entram, sem rodízio: escala todo mundo que tem essa função
    foreach (array_filter($roles, fn($r) => $r['always_include']) as $r) {
        $candidates = $pool[$r['name']] ?? [];
        if (empty($candidates)) {
            $warnings[] = "{$r['name']}: nenhum membro cadastrado nessa função.";
            continue;
        }
        foreach ($candidates as $c) {
            $assignments[$c['id']] = $r['name'];
        }
    }

    foreach (array_filter($roles, fn($r) => !$r['always_include']) as $r) {
        $role       = $r['name'];
        $needed     = (int)$r['quantity_needed'];
        $candidates = $pool[$role] ?? [];

        // Compatibilidade com o comportamento histórico da Música: sobra de
        // Ministro(a) de Louvor que não foi escalado como ministro da semana
        // entra também na disputa pelas vagas de Backing Vocal.
        if ($role === 'Backing Vocal') {
            $extraMinistros = array_filter(
                $pool['Ministro(a) de Louvor'] ?? [],
                fn($c) => !isset($assignments[$c['id']])
            );
            $candidates = array_merge($candidates, array_values($extraMinistros));
        }

        if ($r['use_naipe']) {
            [$chosen, $naipeWarning] = draw_naipe_mix($role, $candidates, $needed, $prevMemberIds);
            if ($naipeWarning) $warnings[] = $naipeWarning;
        } else {
            $fresh  = array_values(array_filter($candidates, fn($c) => !in_array($c['id'], $prevMemberIds, true)));
            $recent = array_values(array_filter($candidates, fn($c) =>  in_array($c['id'], $prevMemberIds, true)));

            shuffle($fresh);
            shuffle($recent);

            $chosen = array_slice($fresh, 0, $needed);
            if (count($chosen) < $needed) {
                $chosen = array_merge($chosen, array_slice($recent, 0, $needed - count($chosen)));
            }
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
