<?php
// bootstrap.php - carrega autoload do composer e config global
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Ajusta timezone padrão
date_default_timezone_set('America/Recife');

// Autoload (composer PSR-4: App\)
$autoload1 = __DIR__ . '/vendor/autoload.php';
$autoload2 = __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/src/Support/DB.php';

if (file_exists($autoload1)) {
    require_once $autoload1;
} elseif (file_exists($autoload2)) {
    require_once $autoload2;
}


// Carrega config (segredos estão em config/config.php)
$configPath = __DIR__ . '/config/config.php';
if (!file_exists($configPath)) {
    die('Arquivo de configuração ausente. Copie config/config.example.php para config/config.php e preencha credenciais.');
}
$appConfig = require $configPath;

// Função helper global para pegar config
function app_config(string $path, $default = null) {
    global $appConfig;
    $ref = $appConfig;
    foreach (explode('.', $path) as $seg) {
        if (!is_array($ref) || !array_key_exists($seg, $ref)) {
            return $default;
        }
        $ref = $ref[$seg];
    }
    return $ref;
}
