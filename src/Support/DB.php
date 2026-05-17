<?php
namespace App\Support;

use mysqli;
use RuntimeException;

class DB
{
    private static ?mysqli $conn = null;

    public static function conn(): mysqli
    {
        if (self::$conn instanceof mysqli) {
            return self::$conn;
        }

        // carrega config/config.php
        $file = dirname(__DIR__, 2) . '/config/config.php';
        if (!is_file($file)) {
            throw new RuntimeException("Arquivo de configuração não encontrado em {$file}");
        }
        $cfg = require $file;
        if (!isset($cfg['db'])) {
            throw new RuntimeException("Configuração de banco de dados ausente em config/config.php");
        }

        $db = $cfg['db'];

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $m = new mysqli(
            $db['host'] ?? '127.0.0.1',
            $db['user'] ?? 'root',
            $db['password'] ?? '',
            $db['database'] ?? '',
            (int)($db['port'] ?? 3306)
        );

        if (!empty($db['charset'])) {
            $m->set_charset($db['charset']);
        }

        self::$conn = $m;
        return $m;
    }
}
