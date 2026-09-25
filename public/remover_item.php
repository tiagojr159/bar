<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../bootstrap.php';

use App\Support\DB;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  // Método
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido']); exit;
  }

  // Parâmetros
  $pedido_id  = isset($_POST['pedido_id'])  ? (int)$_POST['pedido_id']  : 0;
  $produto_id = isset($_POST['produto_id']) ? (int)$_POST['produto_id'] : 0;
  if ($pedido_id <= 0 || $produto_id <= 0) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Parâmetros inválidos']); exit;
  }

  // Conexão
  $con = DB::conn();
  if (!($con instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Conexão inválida']); exit;
  }

  $con->begin_transaction();

  // (opcional) garante pedido em aberto
  $st = $con->prepare("SELECT id FROM pedidos WHERE id = ? AND excluido_em IS NULL AND status = 'aberto' LIMIT 1");
  $st->bind_param('i', $pedido_id);
  $st->execute();
  if ($st->get_result()->num_rows === 0) {
    throw new Exception('Pedido não encontrado ou não está aberto.');
  }

  // Busca o último item desse produto no pedido
  $st = $con->prepare("
      SELECT id, quantidade, preco_unitario
        FROM itens_pedido
       WHERE pedido_id = ? AND produto_id = ?
       ORDER BY id DESC
       LIMIT 1
  ");
  $st->bind_param('ii', $pedido_id, $produto_id);
  $st->execute();
  $item = $st->get_result()->fetch_assoc();

  if (!$item) {
    throw new Exception('Item não encontrado neste pedido.');
  }

  $item_id   = (int)$item['id'];
  $qtd_atual = (int)$item['quantidade'];
  $preco     = (float)$item['preco_unitario'];

  if ($qtd_atual > 1) {
    $nova_qtd = $qtd_atual - 1;
    $novo_sub = $preco * $nova_qtd;

    $up = $con->prepare("UPDATE itens_pedido SET quantidade = ?, subtotal = ? WHERE id = ?");
    $up->bind_param('idi', $nova_qtd, $novo_sub, $item_id);
    $up->execute();
  } else {
    $del = $con->prepare("DELETE FROM itens_pedido WHERE id = ?");
    $del->bind_param('i', $item_id);
    $del->execute();

    if ($del->affected_rows === 0) {
      throw new Exception('Nenhuma unidade para remover.');
    }
  }

  // Recalcula total do pedido
  $st = $con->prepare("SELECT COALESCE(SUM(subtotal),0) AS soma FROM itens_pedido WHERE pedido_id = ?");
  $st->bind_param('i', $pedido_id);
  $st->execute();
  $soma = (float)($st->get_result()->fetch_assoc()['soma'] ?? 0);

  $upPed = $con->prepare("UPDATE pedidos SET total = ? WHERE id = ? AND excluido_em IS NULL");
  $upPed->bind_param('di', $soma, $pedido_id);
  $upPed->execute();

  $con->commit();

  echo json_encode([
    'sucesso'    => true,
    'mensagem'   => 'Item removido',
    'pedido_id'  => $pedido_id,
    'produto_id' => $produto_id,
    'total'      => $soma
  ]);

} catch (Throwable $e) {
  if (isset($con) && $con instanceof mysqli) {
    try { $con->rollback(); } catch (\Throwable $ignored) {}
  }
  http_response_code(500);
  echo json_encode(['sucesso' => false, 'mensagem' => $e->getMessage()]);
}
