<?php
// Leitura Bíblica: checklist pessoal de capítulos lidos, sem data nem prazo.
// Cada membro marca o que já leu, no próprio ritmo. A "sugestão" de próximo
// capítulo é sempre relativa ao progresso da própria pessoa (ordem canônica,
// Gênesis a Apocalipse), nunca a um calendário compartilhado.

/**
 * Estrutura da Bíblia a partir do que já está carregado em bible_verses:
 * lista ordenada de livros com a quantidade de capítulos de cada um.
 */
function bible_reading_books(PDO $db): array {
    static $books = null;
    if ($books !== null) return $books;
    $books = $db->query("
        SELECT book_order, book_abbrev, book_name, MAX(chapter) AS chapters
        FROM bible_verses GROUP BY book_order, book_abbrev, book_name ORDER BY book_order
    ")->fetchAll();
    return $books;
}

/** Total de capítulos da Bíblia (soma de todos os livros). */
function bible_reading_total_chapters(PDO $db): int {
    static $total = null;
    if ($total !== null) return $total;
    $total = 0;
    foreach (bible_reading_books($db) as $b) $total += (int)$b['chapters'];
    return $total;
}

/** Capítulos já marcados como lidos por um membro: {"Gn:1": true, "Gn:2": true, ...}. */
function bible_reading_progress_set(PDO $db, int $memberId): array {
    $stmt = $db->prepare("SELECT book_abbrev, chapter FROM bible_reading_progress WHERE member_id = ?");
    $stmt->execute([$memberId]);
    $set = [];
    foreach ($stmt->fetchAll() as $r) $set[$r['book_abbrev'] . ':' . $r['chapter']] = true;
    return $set;
}

/** Primeiro capítulo não lido na ordem canônica (Gênesis 1 em diante). Null se já leu tudo. */
function bible_reading_next_suggestion(PDO $db, array $progressSet): ?array {
    foreach (bible_reading_books($db) as $b) {
        for ($c = 1; $c <= (int)$b['chapters']; $c++) {
            if (!isset($progressSet[$b['book_abbrev'] . ':' . $c])) {
                return ['book_abbrev' => $b['book_abbrev'], 'book_name' => $b['book_name'], 'chapter' => $c];
            }
        }
    }
    return null;
}
