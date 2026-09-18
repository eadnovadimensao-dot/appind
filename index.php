<?php
// Raiz do sistema: sempre manda pro dashboard (que leva ao login se não estiver logado).
// no-store evita que navegador/operadora guardem uma cópia antiga desse redirecionamento.
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Location: /dashboard.php");
exit;
