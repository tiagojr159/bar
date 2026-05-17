<?php
// index.php (raiz do projeto) -> redireciona para public/mesas.php

declare(strict_types=1);

// Monta a URL relativa para /public/mesas.php, respeitando o subdiretório (ex.: /bar)
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
$dest = $base . '/mesas.php';

// Evita cache do redirect
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Redireciona (302)
header('Location: ' . $dest, true, 302);
exit;
