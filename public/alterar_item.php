<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
header('Content-Type: application/json');
require_once 'conexao.php';

try {
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
  $cx = Conexao::getInstance();

  $item_id = (int)($_POST['item_id'] ?? 0);
  $delta   = (int)($_POST['delta'] ?? 0);
  if ($item_id <= 0 || $delta === 0) { echo json_encode(['sucesso'=>false,'mensagem'=>'Dados inválidos.']); exit; }

  // pega item e pedido
  $st = $cx->prepare("SELECT pedido_id, quantidade, preco_unitario FROM itens_pedido WHERE id = ?");
  $st->bind_param('i', $item_id);
  $st->execute();
  $it = $st->get_result()->fetch_assoc(); $st->close();
  if (!$it) { echo json_encode(['sucesso'=>false,'mensagem'=>'Item inexistente.']); exit; }

  $novaQtd = $it['quantidade'] + $delta;
  if ($novaQtd <= 0) {
    $st = $cx->prepare("DELETE FROM itens_pedido WHERE id = ?");
    $st->bind_param('i', $item_id);
    $st->execute(); $st->close();
  } else {
    $novoSubtotal = $novaQtd * (float)$it['preco_unitario'];
    $st = $cx->prepare("UPDATE itens_pedido SET quantidade = ?, subtotal = ? WHERE id = ?");
    $st->bind_param('idi', $novaQtd, $novoSubtotal, $item_id);
    $st->execute(); $st->close();
  }

  // atualiza total e devolve itens
  $_POST['pedido_id'] = $it['pedido_id'];
  require 'recalcular_total_e_listar.php';
} catch (Throwable $e) {
  echo json_encode(['sucesso'=>false,'mensagem'=>'Erro: '.$e->getMessage()]);
}
