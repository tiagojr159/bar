<?php
// public/recalcular_total.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Autoload + config + DB
require_once __DIR__ . '/../bootstrap.php';

use App\Support\DB;

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $con = DB::conn(); // mysqli
    if (!($con instanceof mysqli)) { throw new Exception('Conexão MySQLi não inicializada.'); }
    $con->set_charset('utf8mb4');

    $pedido_id = isset($_POST['pedido_id']) ? (int)$_POST['pedido_id'] : 0;
    if ($pedido_id <= 0) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Pedido inválido.']); exit;
    }

    // Recalcula o total com prepared statement
    $sqlSum = "SELECT COALESCE(SUM(subtotal),0) AS t FROM itens_pedido WHERE pedido_id = ?";
    $st = $con->prepare($sqlSum);
    $st->bind_param('i', $pedido_id);
    $st->execute();
    $res = $st->get_result()->fetch_assoc();
    $st->close();

    $total = (float)($res['t'] ?? 0);

    $st = $con->prepare("UPDATE pedidos SET total = ? WHERE id = ? AND excluido_em IS NULL");
    $st->bind_param('di', $total, $pedido_id);
    $st->execute();
    $st->close();

    // Retorna a mesma estrutura do endpoint de itens (reuso)
    $_GET['pedido_id'] = $pedido_id;

    // Se você já renomeou o listar_itens.php conforme orientado, aponte para ele:
    // require __DIR__ . '/itens_do_pedido.php';
    // Caso ainda use o nome antigo:
    require __DIR__ . '/itens_do_pedido.php';

} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()]);
}
