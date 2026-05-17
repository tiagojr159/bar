<?php
// public/adicionar_item.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Autoload + config + DB (traz App\Support\DB)
require_once __DIR__ . '/../bootstrap.php';

use App\Support\DB;

try {
    // ===== Conexão =====
    $con = DB::conn(); // mysqli
    if (!($con instanceof mysqli)) { throw new Exception('Conexão MySQLi não inicializada.'); }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $con->set_charset('utf8mb4');

    // ===== Entradas =====
    $pedido_id  = isset($_POST['pedido_id'])  ? (int)$_POST['pedido_id']  : 0;
    $produto_id = isset($_POST['produto_id']) ? (int)$_POST['produto_id'] : 0;

    if ($pedido_id <= 0 || $produto_id <= 0) {
        echo json_encode(['sucesso'=>false, 'mensagem'=>'Parâmetros inválidos']); exit;
    }

    $con->begin_transaction();

    // 1) Pedido precisa estar ABERTO
    $st = $con->prepare("SELECT id FROM pedidos WHERE id=? AND status='aberto' LIMIT 1");
    $st->bind_param('i', $pedido_id);
    $st->execute();
    if ($st->get_result()->num_rows === 0) {
        throw new Exception('Pedido não encontrado ou já fechado.');
    }
    $st->close();

    // 2) Busca produto + estoque atual (e valida ativo)
    $st = $con->prepare("SELECT preco, estoque, nome FROM produtos WHERE id=? AND ativo=1 LIMIT 1");
    $st->bind_param('i', $produto_id);
    $st->execute();
    $prod = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$prod) { throw new Exception('Produto não encontrado ou inativo.'); }

    $preco        = (float)$prod['preco'];
    $estoqueAtual = (int)$prod['estoque'];
    $nomeProduto  = (string)($prod['nome'] ?? '');

    // 2a) Opcional: bloquear se sem estoque
    if ($estoqueAtual <= 0) {
        throw new Exception('Sem estoque disponível para este produto.');
    }

    // 3) Insere 1 unidade no itens_pedido
    $st = $con->prepare("
        INSERT INTO itens_pedido (pedido_id, produto_id, quantidade, preco_unitario, subtotal)
        VALUES (?, ?, 1, ?, ?)
    ");
    $st->bind_param('iidd', $pedido_id, $produto_id, $preco, $preco);
    $st->execute();
    $st->close();

    // 4) Baixa 1 do estoque do produto
    $st = $con->prepare("UPDATE produtos SET estoque = GREATEST(estoque - 1, 0) WHERE id=? AND ativo=1");
    $st->bind_param('i', $produto_id);
    $st->execute();
    $st->close();

    // 5) Recalcula total do pedido
    $st = $con->prepare("
        UPDATE pedidos p
           SET p.total = (SELECT COALESCE(SUM(subtotal),0) FROM itens_pedido WHERE pedido_id = ?)
         WHERE p.id = ?
    ");
    $st->bind_param('ii', $pedido_id, $pedido_id);
    $st->execute();
    $st->close();

    // 6) Lê total atualizado e estoque atual pós-baixa
    $st = $con->prepare("SELECT total FROM pedidos WHERE id=?");
    $st->bind_param('i', $pedido_id);
    $st->execute();
    $total = (float)($st->get_result()->fetch_assoc()['total'] ?? 0.0);
    $st->close();

    $st = $con->prepare("SELECT estoque FROM produtos WHERE id=?");
    $st->bind_param('i', $produto_id);
    $st->execute();
    $novoEstoque = (int)($st->get_result()->fetch_assoc()['estoque'] ?? 0);
    $st->close();

    $con->commit();

    echo json_encode([
        'sucesso'       => true,
        'total'         => $total,
        'produto_id'    => $produto_id,
        'produto_nome'  => $nomeProduto,
        'estoque_atual' => $novoEstoque, // útil para atualizar a UI dos cards de produto
    ]);

} catch (Throwable $e) {
    if (isset($con) && $con instanceof mysqli && $con->errno) { $con->rollback(); }
    echo json_encode(['sucesso'=>false, 'mensagem'=>$e->getMessage()]);
}
