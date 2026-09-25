<?php
// public/itens_do_pedido.php
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

    $pedido_id = isset($_GET['pedido_id']) ? (int)$_GET['pedido_id'] : 0;
    if ($pedido_id <= 0) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Pedido inválido.']); exit;
    }

    // Agrupa por produto: 1 miniatura por produto com a soma das quantidades
    $sql = "
      SELECT
          ip.produto_id,
          SUM(ip.quantidade)                 AS quantidade,
          MAX(ip.preco_unitario)             AS preco_unitario,
          SUM(ip.subtotal)                   AS subtotal,
          p.nome,
          p.imagem
      FROM itens_pedido ip
      JOIN produtos p ON p.id = ip.produto_id
      JOIN pedidos ped ON ped.id = ip.pedido_id AND ped.excluido_em IS NULL
      WHERE ip.pedido_id = ?
      GROUP BY ip.produto_id, p.nome, p.imagem
      ORDER BY MAX(ip.id) DESC
    ";

    $st = $con->prepare($sql);
    $st->bind_param('i', $pedido_id);
    $st->execute();
    $rs = $st->get_result();

    $itens = [];
    $total = 0.0;

    while ($r = $rs->fetch_assoc()) {
        $qtd   = (int)($r['quantidade'] ?? 0);
        $preco = (float)($r['preco_unitario'] ?? 0);
        $sub   = (float)($r['subtotal'] ?? 0);

        $itens[] = [
            'produto_id'     => (int)$r['produto_id'],
            'quantidade'     => $qtd,
            'preco_unitario' => $preco,
            'subtotal'       => $sub,
            'nome'           => (string)($r['nome'] ?? ''),
            'imagem'         => $r['imagem'] ?? null,
        ];
        $total += $sub;
    }

    echo json_encode([
        'sucesso' => true,
        'itens'   => $itens,
        'total'   => $total,
    ]);

} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()]);
}
