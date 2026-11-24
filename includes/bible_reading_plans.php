<?php
// Planos de leitura: cada plano é só uma ORDEM diferente de percorrer os
// mesmos 1189 capítulos. O progresso (bible_reading_progress) continua
// sendo a única fonte de verdade de "lido ou não" — nenhum plano cria
// progresso próprio, só uma sequência de sugestão diferente.

const BIBLE_READING_PLAN_LABELS = [
    'canonical'      => 'Bíblia Completa',
    'mixed'          => 'Bíblia Variada',
    'chrono'         => 'Ordem Cronológica',
    'gospels'        => 'Evangelhos',
    'wisdom'         => 'Sabedoria',
    'starting_faith' => 'Começando na Fé',
];

/** Explicação curta de cada plano, mostrada ao escolher no seletor. */
const BIBLE_READING_PLAN_DESCRIPTIONS = [
    'canonical'      => 'A Bíblia inteira (1189 capítulos), na ordem em que ela é impressa: Gênesis até Apocalipse.',
    'mixed'          => 'A Bíblia inteira, mas intercalando um pouco de cada tipo a cada dia: histórico do Antigo Testamento, Novo Testamento, Salmos/Provérbios e profetas, pra variar e não ficar preso só num tipo de leitura.',
    'chrono'         => 'A Bíblia inteira, reorganizada pra seguir a ordem em que os fatos aconteceram de verdade (ex: Jó encaixado perto de Gênesis, Salmos de Davi junto da história dele, cartas de Paulo no momento em que ele as escreveu).',
    'gospels'        => 'Só a vida de Jesus: Mateus, Marcos, Lucas e João (89 capítulos). Bom pra quem quer um plano mais curto, focado em Jesus.',
    'wisdom'         => 'Os livros de sabedoria e poesia: Jó, Salmos, Provérbios, Eclesiastes e Cânticos (243 capítulos).',
    'starting_faith' => 'Pra quem está começando na fé: João, Atos e Romanos (65 capítulos), uma boa porta de entrada antes de ler a Bíblia inteira.',
];

/** Planos que são só um TRECHO da Bíblia (não cobrem os 1189 capítulos) — usado pra mensagem de conclusão certa. */
const BIBLE_READING_PARTIAL_PLANS = ['gospels', 'wisdom', 'starting_faith'];

/** Sequência completa (todos os capítulos do plano, uma vez cada) pro plano dado. */
function bible_reading_plan_sequence(PDO $db, string $planId): array {
    $books = bible_reading_books($db);
    if ($planId === 'mixed')  return bible_reading_mixed_sequence($books);
    if ($planId === 'chrono') return bible_reading_chrono_sequence($books);
    if ($planId === 'gospels')        return bible_reading_books_range_sequence($books, 39, 42); // Mateus..João
    if ($planId === 'wisdom')         return bible_reading_books_range_sequence($books, 17, 21); // Jó..Cânticos
    if ($planId === 'starting_faith') return bible_reading_books_range_sequence($books, 42, 44); // João, Atos, Romanos

    // canonical (padrão): ordem natural dos livros, Gênesis → Apocalipse
    return bible_reading_books_range_sequence($books, 0, 65);
}

/** Capítulos, em ordem natural, dos livros cujo book_order cai em [$minOrder, $maxOrder]. */
function bible_reading_books_range_sequence(array $books, int $minOrder, int $maxOrder): array {
    $seq = [];
    foreach ($books as $b) {
        $order = (int)$b['book_order'];
        if ($order < $minOrder || $order > $maxOrder) continue;
        for ($c = 1; $c <= (int)$b['chapters']; $c++) {
            $seq[] = ['book_abbrev' => $b['book_abbrev'], 'book_name' => $b['book_name'], 'chapter' => $c];
        }
    }
    return $seq;
}

/**
 * "Bíblia Variada": intercala blocos de 4 capítulos entre 4 trilhas (histórico
 * do AT, Novo Testamento, poesia/sabedoria, profetas), em rodízio, até cada
 * trilha esgotar. Dá a sensação de "um pouco de cada" (ex: Gênesis, depois
 * Novo Testamento, depois Salmos/Provérbios, e volta), sem ser tão elaborado
 * quanto uma ordem cronológica de verdade.
 */
function bible_reading_mixed_sequence(array $books): array {
    $queues = ['hist' => [], 'nt' => [], 'poetry' => [], 'prophets' => []];
    foreach ($books as $b) {
        $order = (int)$b['book_order'];
        if ($order <= 16)      $key = 'hist';      // Gênesis..Ester
        elseif ($order <= 21)  $key = 'poetry';     // Jó..Cânticos
        elseif ($order <= 38)  $key = 'prophets';   // Isaías..Malaquias
        else                   $key = 'nt';         // Mateus..Apocalipse

        for ($c = 1; $c <= (int)$b['chapters']; $c++) {
            $queues[$key][] = ['book_abbrev' => $b['book_abbrev'], 'book_name' => $b['book_name'], 'chapter' => $c];
        }
    }

    $rotation  = ['hist', 'nt', 'poetry', 'prophets'];
    $chunkSize = 4;
    $seq       = [];
    $pending   = $rotation;
    while ($pending) {
        $next = [];
        foreach ($pending as $trackKey) {
            $chunk = array_splice($queues[$trackKey], 0, $chunkSize);
            if (!$chunk) continue;
            foreach ($chunk as $item) $seq[] = $item;
            $next[] = $trackKey;
        }
        $pending = $next;
    }
    return $seq;
}

/**
 * Mapa nome em inglês (como aparece no plano cronológico de referência) →
 * abreviação usada em bible_verses nesta base.
 */
const BIBLE_CHRONO_BOOK_MAP = [
    'Genesis' => 'Gn', 'Exodus' => 'Êx', 'Leviticus' => 'Lv', 'Numbers' => 'Nm', 'Deuteronomy' => 'Dt',
    'Joshua' => 'Js', 'Judges' => 'Jz', 'Ruth' => 'Rt', '1 Samuel' => '1Sm', '2 Samuel' => '2Sm',
    '1 Kings' => '1Rs', '2 Kings' => '2Rs', '1 Chronicles' => '1Cr', '2 Chronicles' => '2Cr',
    'Ezra' => 'Ed', 'Nehemiah' => 'Ne', 'Esther' => 'Et', 'Job' => 'Jó', 'Psalm' => 'Sl',
    'Proverbs' => 'Pv', 'Ecclesiastes' => 'Ec', 'Song of Solomon' => 'Ct', 'Isaiah' => 'Is',
    'Jeremiah' => 'Jr', 'Lamentations' => 'Lm', 'Ezekiel' => 'Ez', 'Daniel' => 'Dn', 'Hosea' => 'Os',
    'Joel' => 'Jl', 'Amos' => 'Am', 'Obadiah' => 'Ob', 'Jonah' => 'Jn', 'Micah' => 'Mq', 'Nahum' => 'Na',
    'Habakkuk' => 'Hc', 'Zephaniah' => 'Sf', 'Haggai' => 'Ag', 'Zechariah' => 'Zc', 'Malachi' => 'Ml',
    'Matthew' => 'Mt', 'Mark' => 'Mc', 'Luke' => 'Lc', 'John' => 'Jo', 'Acts' => 'At', 'Romans' => 'Rm',
    '1 Corinthians' => '1Co', '2 Corinthians' => '2Co', 'Galatians' => 'Gl', 'Ephesians' => 'Ef',
    'Philippians' => 'Fp', 'Colossians' => 'Cl', '1 Thessalonians' => '1Ts', '2 Thessalonians' => '2Ts',
    '1 Timothy' => '1Tm', '2 Timothy' => '2Tm', 'Titus' => 'Tt', 'Philemon' => 'Fm', 'Hebrews' => 'Hb',
    'James' => 'Tg', '1 Peter' => '1Pe', '2 Peter' => '2Pe', '1 John' => '1Jo', '2 John' => '2Jo',
    '3 John' => '3Jo', 'Jude' => 'Jd', 'Revelation' => 'Ap',
];

/**
 * Plano cronológico de referência (365 dias), baseado numa ordem cronológica
 * pública amplamente usada (ex: biblestudytools.com/bible-reading-plan/chronological),
 * que lê os eventos na ordem em que aconteceram historicamente (ex: Jó junto
 * de Gênesis, Salmos de Davi junto da vida dele, profetas junto do reinado
 * em que atuaram, cartas de Paulo intercaladas com Atos).
 */
const BIBLE_CHRONO_RAW_DAYS = [
    'Genesis 1-3', 'Genesis 4-7', 'Genesis 8-11', 'Job 1-5', 'Job 6-9', 'Job 10-13', 'Job 14-16',
    'Job 17-20', 'Job 21-23', 'Job 24-28', 'Job 29-31', 'Job 32-34', 'Job 35-37', 'Job 38-39', 'Job 40-42',
    'Genesis 12-15', 'Genesis 16-18', 'Genesis 19-21', 'Genesis 22-24', 'Genesis 25-26', 'Genesis 27-29',
    'Genesis 30-31', 'Genesis 32-34', 'Genesis 35-37', 'Genesis 38-40', 'Genesis 41-42', 'Genesis 43-45',
    'Genesis 46-47', 'Genesis 48-50', 'Exodus 1-3', 'Exodus 4-6', 'Exodus 7-9', 'Exodus 10-12',
    'Exodus 13-15', 'Exodus 16-18', 'Exodus 19-21', 'Exodus 22-24', 'Exodus 25-27', 'Exodus 28-29',
    'Exodus 30-32', 'Exodus 33-35', 'Exodus 36-38', 'Exodus 39-40', 'Leviticus 1-4', 'Leviticus 5-7',
    'Leviticus 8-10', 'Leviticus 11-13', 'Leviticus 14-15', 'Leviticus 16-18', 'Leviticus 19-21',
    'Leviticus 22-23', 'Leviticus 24-25', 'Leviticus 26-27', 'Numbers 1-2', 'Numbers 3-4', 'Numbers 5-6',
    'Numbers 7', 'Numbers 8-10', 'Numbers 11-13', 'Numbers 14-15; Psalm 90', 'Numbers 16-17',
    'Numbers 18-20', 'Numbers 21-22', 'Numbers 23-25', 'Numbers 26-27', 'Numbers 28-30', 'Numbers 31-32',
    'Numbers 33-34', 'Numbers 35-36', 'Deuteronomy 1-2', 'Deuteronomy 3-4', 'Deuteronomy 5-7',
    'Deuteronomy 8-10', 'Deuteronomy 11-13', 'Deuteronomy 14-16', 'Deuteronomy 17-20', 'Deuteronomy 21-23',
    'Deuteronomy 24-27', 'Deuteronomy 28-29', 'Deuteronomy 30-31', 'Deuteronomy 32-34; Psalm 91',
    'Joshua 1-4', 'Joshua 5-8', 'Joshua 9-11', 'Joshua 12-15', 'Joshua 16-18', 'Joshua 19-21',
    'Joshua 22-24', 'Judges 1-2', 'Judges 3-5', 'Judges 6-7', 'Judges 8-9', 'Judges 10-12', 'Judges 13-15',
    'Judges 16-18', 'Judges 19-21', 'Ruth 1-4', '1 Samuel 1-3', '1 Samuel 4-8', '1 Samuel 9-12',
    '1 Samuel 13-14', '1 Samuel 15-17', '1 Samuel 18-20; Psalm 11, 59', '1 Samuel 21-24',
    'Psalm 7, 27, 31, 34, 52', 'Psalm 56, 120, 140-142', '1 Samuel 25-27', 'Psalm 17, 35, 54, 63',
    '1 Samuel 28-31; Psalm 18', 'Psalm 121, 123-125, 128-130', '2 Samuel 1-4', 'Psalm 6, 8-10, 14, 16, 19, 21',
    '1 Chronicles 1-2', 'Psalm 43-45, 49, 84-85, 87', '1 Chronicles 3-5', 'Psalm 73, 77-78',
    '1 Chronicles 6', 'Psalm 81, 88, 92-93', '1 Chronicles 7-10', 'Psalm 102-104',
    '2 Samuel 5:1-10; 1 Chronicles 11-12', 'Psalm 133', 'Psalm 106-107',
    '2 Samuel 5:11-25; 2 Samuel 6:1-23; 1 Chronicles 13-16', 'Psalm 1-2, 15, 22-24, 47, 68',
    'Psalm 89, 96, 100, 101, 105, 132', '2 Samuel 7; 1 Chronicles 17', 'Psalm 25, 29, 33, 36, 39',
    '2 Samuel 8-9; 1 Chronicles 18', 'Psalm 50, 53, 60, 75', '2 Samuel 10; 1 Chronicles 19; Psalm 20',
    'Psalm 65-67, 69-70', '2 Samuel 11-12; 1 Chronicles 20', 'Psalm 32, 51, 86, 122', '2 Samuel 13-15',
    'Psalm 3-4, 12-13, 28, 55', '2 Samuel 16-18', 'Psalm 26, 40, 58, 61-62, 64', '2 Samuel 19-21',
    'Psalm 5, 38, 41-42', '2 Samuel 22-23; Psalm 57', 'Psalm 95, 97-99', '2 Samuel 24; 1 Chronicles 21-22; Psalm 30',
    'Psalm 108-110', '1 Chronicles 23-25', 'Psalm 131, 138-139, 143-145', '1 Chronicles 26-29; Psalm 127',
    'Psalm 111-118', '1 Kings 1-2; Psalm 37, 71, 94', 'Psalm 119:1-88', '1 Kings 3-4; 2 Chronicles 1; Psalm 72',
    'Psalm 119:89-176', 'Song of Solomon 1-8', 'Proverbs 1-3', 'Proverbs 4-6', 'Proverbs 7-9',
    'Proverbs 10-12', 'Proverbs 13-15', 'Proverbs 16-18', 'Proverbs 19-21', 'Proverbs 22-24',
    '1 Kings 5-6; 2 Chronicles 2-3', '1 Kings 7; 2 Chronicles 4', '1 Kings 8; 2 Chronicles 5',
    '2 Chronicles 6-7; Psalm 136', 'Psalm 134, 146-150', '1 Kings 9; 2 Chronicles 8', 'Proverbs 25-26',
    'Proverbs 27-29', 'Ecclesiastes 1-6', 'Ecclesiastes 7-12', '1 Kings 10-11; 2 Chronicles 9',
    'Proverbs 30-31', '1 Kings 12-14', '2 Chronicles 10-12', '1 Kings 15:1-24; 2 Chronicles 13-16',
    '1 Kings 15:25-34; 1 Kings 16:1-34; 2 Chronicles 17', '1 Kings 17-19', '1 Kings 20-21',
    '1 Kings 22; 2 Chronicles 18', '2 Chronicles 19-23', 'Obadiah; Psalm 82-83', '2 Kings 1-4',
    '2 Kings 5-8', '2 Kings 9-11', '2 Kings 12-13; 2 Chronicles 24', '2 Kings 14; 2 Chronicles 25',
    'Jonah 1-4', '2 Kings 15; 2 Chronicles 26', 'Isaiah 1-4', 'Isaiah 5-8', 'Amos 1-5', 'Amos 6-9',
    '2 Chronicles 27; Isaiah 9-12', 'Micah 1-7', '2 Chronicles 28; 2 Kings 16-17', 'Isaiah 13-17',
    'Isaiah 18-22', 'Isaiah 23-27', '2 Kings 18:1-8; 2 Chronicles 29-31; Psalm 48', 'Hosea 1-7', 'Hosea 8-14',
    'Isaiah 28-30', 'Isaiah 31-34', 'Isaiah 35-36', 'Isaiah 37-39; Psalm 76', 'Isaiah 40-43', 'Isaiah 44-48',
    '2 Kings 18:9-37; 2 Kings 19:1-37; Psalm 46, 80, 135', 'Isaiah 49-53', 'Isaiah 54-58', 'Isaiah 59-63',
    'Isaiah 64-66', '2 Kings 20-21', '2 Chronicles 32-33', 'Nahum 1-3', '2 Kings 22-23; 2 Chronicles 34-35',
    'Zephaniah 1-3', 'Jeremiah 1-3', 'Jeremiah 4-6', 'Jeremiah 7-9', 'Jeremiah 10-13', 'Jeremiah 14-17',
    'Jeremiah 18-22', 'Jeremiah 23-25', 'Jeremiah 26-29', 'Jeremiah 30-31', 'Jeremiah 32-34',
    'Jeremiah 35-37', 'Jeremiah 38-40; Psalm 74, 79', '2 Kings 24-25; 2 Chronicles 36', 'Habakkuk 1-3',
    'Jeremiah 41-45', 'Jeremiah 46-48', 'Jeremiah 49-50', 'Jeremiah 51-52',
    'Lamentations 1; Lamentations 2; Lamentations 3:1-36', 'Lamentations 3:37-66; Lamentations 4; Lamentations 5:1-22',
    'Ezekiel 1-4', 'Ezekiel 5-8', 'Ezekiel 9-12', 'Ezekiel 13-15', 'Ezekiel 16-17', 'Ezekiel 18-19',
    'Ezekiel 20-21', 'Ezekiel 22-23', 'Ezekiel 24-27', 'Ezekiel 28-31', 'Ezekiel 32-34', 'Ezekiel 35-37',
    'Ezekiel 38-39', 'Ezekiel 40-41', 'Ezekiel 42-43', 'Ezekiel 44-45', 'Ezekiel 46-48', 'Joel 1-3',
    'Daniel 1-3', 'Daniel 4-6', 'Daniel 7-9', 'Daniel 10-12', 'Ezra 1-3', 'Ezra 4-6; Psalm 137',
    'Haggai 1-2', 'Zechariah 1-7', 'Zechariah 8-14', 'Esther 1-5', 'Esther 6-10', 'Ezra 7-10',
    'Nehemiah 1-5', 'Nehemiah 6-7', 'Nehemiah 8-10', 'Nehemiah 11-13; Psalm 126', 'Malachi 1-4',
    'Luke 1; John 1:1-14', 'Matthew 1; Luke 2:1-38', 'Matthew 2; Luke 2:39-52', 'Matthew 3; Mark 1; Luke 3',
    'Matthew 4; Luke 4-5; John 1:15-51', 'John 2-4', 'Mark 2', 'John 5', 'Matthew 12:1-21; Mark 3; Luke 6',
    'Matthew 5-7', 'Matthew 8:1-13; Luke 7', 'Matthew 11', 'Matthew 12:22-50; Luke 11',
    'Matthew 13; Luke 8', 'Matthew 8:14-34; Mark 4-5', 'Matthew 9-10', 'Matthew 14; Mark 6; Luke 9:1-17',
    'John 6', 'Matthew 15; Mark 7', 'Matthew 16; Mark 8; Luke 9:18-27', 'Matthew 17; Mark 9; Luke 9:28-62',
    'Matthew 18', 'John 7-8', 'John 9:1-41; John 10:1-21', 'Luke 10-11; John 10:22-42', 'Luke 12-13',
    'Luke 14-15', 'Luke 16; Luke 17:1-10', 'John 11', 'Luke 17:11-37; Luke 18:1-14', 'Matthew 19; Mark 10',
    'Matthew 20-21', 'Luke 18:15-43; Luke 19:1-48', 'Mark 11; John 12', 'Matthew 22; Mark 12',
    'Matthew 23; Luke 20-21', 'Mark 13', 'Matthew 24', 'Matthew 25', 'Matthew 26; Mark 14',
    'Luke 22; John 13', 'John 14-17', 'Matthew 27; Mark 15', 'Luke 23; John 18-19', 'Matthew 28; Mark 16',
    'Luke 24; John 20-21', 'Acts 1-3', 'Acts 4-6', 'Acts 7-8', 'Acts 9-10', 'Acts 11-12', 'Acts 13-14',
    'James 1-5', 'Acts 15-16', 'Galatians 1-3', 'Galatians 4-6', 'Acts 17; Acts 18:1-18',
    '1 Thessalonians 1-5; 2 Thessalonians 1-3', 'Acts 18:19-28; Acts 19:1-41', '1 Corinthians 1-4',
    '1 Corinthians 5-8', '1 Corinthians 9-11', '1 Corinthians 12-14', '1 Corinthians 15-16',
    '2 Corinthians 1-4', '2 Corinthians 5-9', '2 Corinthians 10-13', 'Acts 20:1-3; Romans 1-3',
    'Romans 4-7', 'Romans 8-10', 'Romans 11-13', 'Romans 14-16', 'Acts 20:4-38; Acts 21; Acts 22; Acts 23:1-35',
    'Acts 24-26', 'Acts 27-28', 'Colossians 1-4; Philemon', 'Ephesians 1-6', 'Philippians 1-4',
    '1 Timothy 1-6', 'Titus 1-3', '1 Peter 1-5', 'Hebrews 1-6', 'Hebrews 7-10', 'Hebrews 11-13',
    '2 Timothy 1-4', '2 Peter 1-3; Jude', '1 John 1-5', '2 John; 3 John', 'Revelation 1-5',
    'Revelation 6-11', 'Revelation 12-18', 'Revelation 19-22',
];

/** Expande "Genesis 1-3" / "Psalm 7, 27, 31" / "2 Samuel 5:1-10" etc numa lista de (book_abbrev, chapter). */
function bible_reading_chrono_parse_clause(string $clause): array {
    $clause = trim($clause);
    $bookName = null;
    foreach (BIBLE_CHRONO_BOOK_MAP as $name => $abbrev) {
        if (stripos($clause, $name) === 0) {
            // evita que "John" capture o inicio de "1 John"/"2 John"/"3 John" (nunca colide, pois
            // esses começam com dígito), mas garante o match mais longo entre nomes compostos
            if ($bookName === null || strlen($name) > strlen($bookName)) $bookName = $name;
        }
    }
    if ($bookName === null) return [];

    $abbrev = BIBLE_CHRONO_BOOK_MAP[$bookName];
    $rest   = trim(substr($clause, strlen($bookName)));
    // livros de 1 capítulo só (Obadias, Filemom, 2/3 João, Judas) aparecem sem número
    if ($rest === '') return [['book_abbrev' => $abbrev, 'chapter' => 1]];

    $chapters = [];
    foreach (explode(',', $rest) as $part) {
        $part = trim($part);
        // remove referência de versículo (ex: "5:1-10" → capítulo 5; "119:1-88" → capítulo 119)
        if (strpos($part, ':') !== false) {
            $part = trim(explode(':', $part)[0]);
        }
        if ($part === '' || !preg_match('/^\d+(-\d+)?$/', $part)) continue;
        if (strpos($part, '-') !== false) {
            [$from, $to] = array_map('intval', explode('-', $part));
            for ($c = $from; $c <= $to; $c++) $chapters[] = $c;
        } else {
            $chapters[] = (int)$part;
        }
    }
    return array_map(fn($c) => ['book_abbrev' => $abbrev, 'chapter' => $c], $chapters);
}

function bible_reading_chrono_sequence(array $books): array {
    $namesByAbbrev = [];
    foreach ($books as $b) $namesByAbbrev[$b['book_abbrev']] = $b['book_name'];

    $seen = [];
    $seq  = [];
    foreach (BIBLE_CHRONO_RAW_DAYS as $day) {
        foreach (explode(';', $day) as $clause) {
            foreach (bible_reading_chrono_parse_clause($clause) as $item) {
                $key = $item['book_abbrev'] . ':' . $item['chapter'];
                if (isset($seen[$key])) continue; // já apareceu (ex: capítulo dividido em 2 dias por versículo)
                $seen[$key] = true;
                $seq[] = [
                    'book_abbrev' => $item['book_abbrev'],
                    'book_name'   => $namesByAbbrev[$item['book_abbrev']] ?? $item['book_abbrev'],
                    'chapter'     => $item['chapter'],
                ];
            }
        }
    }
    return $seq;
}
