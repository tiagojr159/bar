<?php
// public/adicionar_itens.php (ou o nome do seu arquivo atual)
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');

// Autoload + config + DB
require_once __DIR__ . '/../bootstrap.php';

use App\Support\DB;

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $con = DB::conn(); // mysqli
    if (!($con instanceof mysqli)) { throw new Exception('Conexão MySQLi não inicializada.'); }
    $con->set_charset('utf8mb4');

    $pedido_id = isset($_POST['pedido_id']) ? (int)$_POST['pedido_id'] : 0;
    $json      = $_POST['itens'] ?? '[]';
    $itens     = json_decode($json, true) ?: [];

    if ($pedido_id <= 0 || empty($itens)) {
        echo json_encode(['sucesso'=>false,'mensagem'=>'Dados inválidos.']); exit;
    }

    // valida pedido aberto
    $st = $con->prepare("SELECT status FROM pedidos WHERE id=? LIMIT 1");
    $st->bind_param('i', $pedido_id);
    $st->execute();
    $p = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$p || strtolower($p['status']) !== 'aberto') {
        echo json_encode(['sucesso'=>false,'mensagem'=>'Pedido não está aberto.']); exit;
    }

    // transação
    $con->begin_transaction();

    // prepares
    $selProd = $con->prepare("SELECT preco FROM produtos WHERE id=? AND ativo=1 LIMIT 1");
    $selItem = $con->prepare("SELECT id, quantidade, preco_unitario FROM itens_pedido WHERE pedido_id=? AND produto_id=? LIMIT 1");
    $insItem = $con->prepare("INSERT INTO itens_pedido (pedido_id, produto_id, quantidade, preco_unitario, subtotal) VALUES (?, ?, ?, ?, ?)");
    $updItem = $con->prepare("UPDATE itens_pedido SET quantidade=?, subtotal=? WHERE id=?");

    // (Opcional) baixar estoque quando adicionar itens:
    // $updEstq = $con->prepare("UPDATE produtos SET estoque = GREATEST(estoque - ?, 0) WHERE id=? AND ativo=1");

    foreach ($itens as $it) {
        $prod_id = (int)($it['produto_id'] ?? 0);
        $qtd     = (int)($it['quantidade'] ?? 0);
        if ($prod_id <= 0 || $qtd <= 0) { continue; }

        // produto
        $selProd->bind_param('i', $prod_id);
        $selProd->execute();
        $prod = $selProd->get_result()->fetch_assoc();
        if (!$prod) { continue; }
        $preco = (float)$prod['preco'];

        // item existente?
        $selItem->bind_param('ii', $pedido_id, $prod_id);
        $selItem->execute();
        $ex = $selItem->get_result()->fetch_assoc();

        if ($ex) {
            $novaQtd = (int)$ex['quantidade'] + $qtd;
            $sub     = $novaQtd * $preco;
            $updItem->bind_param('idi', $novaQtd, $sub, $ex['id']);
            $updItem->execute();
        } else {
            $sub = $qtd * $preco;
            $insItem->bind_param('iiidd', $pedido_id, $prod_id, $qtd, $preco, $sub);
            $insItem->execute();
        }

        // (Opcional) baixar estoque:
        // $updEstq->bind_param('ii', $qtd, $prod_id);
        // $updEstq->execute();
    }

    // atualiza total (prepared)
    $sqlSum = "SELECT COALESCE(SUM(subtotal),0) AS t FROM itens_pedido WHERE pedido_id = ?";
    $st = $con->prepare($sqlSum);
    $st->bind_param('i', $pedido_id);
    $st->execute();
    $sum = $st->get_result()->fetch_assoc();
    $st->close();

    $t = (float)($sum['t'] ?? 0.0);

    $st = $con->prepare("UPDATE pedidos SET total=? WHERE id=?");
    $st->bind_param('di', $t, $pedido_id);
    $st->execute();
    $st->close();

    $con->commit();

    echo json_encode(['sucesso'=>true, 'total'=>$t]);

} catch (Throwable $e) {
    if (isset($con) && $con instanceof mysqli && $con->errno) {
        $con->rollback();
    }
    echo json_encode(['sucesso'=>false,'mensagem'=>'Erro: '.$e->getMessage()]);
}
