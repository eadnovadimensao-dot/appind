<?php
// Copie este arquivo para database.php e preencha com as credenciais reais.
// database.php é ignorado pelo git (.gitignore) e nunca deve ser commitado.

define('DB_HOST', 'localhost');
define('DB_NAME', 'nome_do_banco');
define('DB_USER', 'usuario_do_banco');
define('DB_PASS', 'senha_do_banco');

define('APP_NAME', 'Igreja Manager');
define('APP_URL',  'https://app.igrejanovadimensao.com.br');
define('SEDE_ID',  1); // ID fixo da sede — nunca muda

// CHURCH_ID dinâmico: filial selecionada pelo supermaster, ou filial do usuário logado, ou sede
function current_church_id(): int {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['user']['viewing_branch_id'])) {
        return (int)$_SESSION['user']['viewing_branch_id'];
    }
    return (int)($_SESSION['user']['church_id'] ?? SEDE_ID);
}

define('CHURCH_ID', 1); // mantido para queries que não dependem de filial

// Veja config/database.php (não versionado) para as funções db(), church_settings(),
// setting() e os helpers de cor — mantenha-as idênticas ao copiar este arquivo.
