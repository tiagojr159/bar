<?php
if (!headers_sent()) header('Content-Type: application/json');
require_once 'conexao.php';

try {
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
  $cx = Conexao::getInstance();

  $pedido_id = (int)($_GET['pedido_id'] ?? $_POST['pedido_id'] ?? 0);
  if ($pedido_id <= 0) { echo json_encode(['sucesso'=>false,'mensagem'=>'Pedido inválido.']); exit; }

  $sql = "SELECT ip.id, ip.quantidade, ip.preco_unitario, ip.subtotal, p.nome
          FROM itens_pedido ip
          JOIN produtos p ON p.id = ip.produto_id
          JOIN pedidos ped ON ped.id = ip.pedido_id AND ped.excluido_em IS NULL
          WHERE ip.pedido_id = ?
          ORDER BY ip.id DESC";
  $st = $cx->prepare($sql);
  $st->bind_param('i', $pedido_id);
  $st->execute();
  $rs = $st->get_result();

  $itens = []; $total = 0;
  while ($r = $rs->fetch_assoc()){
    $itens[] = $r;
    $total += (float)$r['subtotal'];
  }
  echo json_encode(['sucesso' => true, 'itens' => $itens, 'total' => $total]);
} catch (Throwable $e) {
  echo json_encode(['sucesso' => false, 'mensagem' => 'Erro: '.$e->getMessage()]);
}
