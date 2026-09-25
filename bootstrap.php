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

if (PHP_VERSION_ID >= 80000) {
    if (file_exists($autoload1)) {
        require_once $autoload1;
    } elseif (file_exists($autoload2)) {
        require_once $autoload2;
    }
} else {
    // O autoload gerado pelo Composer interrompe a aplicação por causa de
    // psr/cache 3.x, embora as telas comuns do sistema não usem esse pacote.
    // Em PHP 7.4, carregamos as classes necessárias sob demanda.
    spl_autoload_register(function ($class) {
        $prefixes = array(
            'App\\' => __DIR__ . '/src/',
            'MercadoPago\\' => __DIR__ . '/vendor/mercadopago/dx-php/src/MercadoPago/',
            'Doctrine\\Common\\Annotations\\' => __DIR__ . '/vendor/doctrine/annotations/lib/Doctrine/Common/Annotations/',
            'Doctrine\\Common\\Lexer\\' => __DIR__ . '/vendor/doctrine/lexer/src/',
            'Doctrine\\Common\\' => __DIR__ . '/vendor/doctrine/common/src/',
            'Doctrine\\Persistence\\' => __DIR__ . '/vendor/doctrine/persistence/src/Persistence/',
            'Doctrine\\Deprecations\\' => __DIR__ . '/vendor/doctrine/deprecations/src/',
            'Psr\\Cache\\' => __DIR__ . '/vendor/psr/cache/src/'
        );
        foreach ($prefixes as $prefix => $baseDir) {
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                continue;
            }
            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
            return;
        }
    });
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
