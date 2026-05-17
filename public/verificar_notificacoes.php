<?php
//verificar_noticicacoes.php
require __DIR__ . '/../bootstrap.php';
use App\Support\DB;

$con = DB::conn();
header('Content-Type: application/json');

$op = $_GET['op'] ?? $_POST['op'] ?? '';

/**
 * Marcar notificação como atendida
 */
if ($op === 'atender') {
  $id = (int)($_POST['id'] ?? 0);

  if ($id <= 0) {
    echo json_encode(['sucesso' => false, 'erro' => 'ID inválido']);
    exit;
  }

  // 1. Buscar a notificação e pedido associados
  $sql = "
    SELECT n.id, n.pedido_id,
           p.status, p.forma_pagamento
      FROM notificacoes_pedido n
 LEFT JOIN pedidos p ON p.id = n.pedido_id
     WHERE n.id = ?
     LIMIT 1
  ";
  $stmt = $con->prepare($sql);
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res->fetch_assoc();

  if (!$row) {
    echo json_encode(['sucesso' => false, 'erro' => 'Notificação não encontrada']);
    exit;
  }

  $pedidoId = (int)$row['pedido_id'];
  $status   = strtolower((string)($row['status'] ?? ''));
  $forma    = strtolower((string)($row['forma_pagamento'] ?? ''));

  // 2. Atualizar estado da notificação
  $stmt2 = $con->prepare("UPDATE notificacoes_pedido SET estado = 'atendido' WHERE id = ?");
  $stmt2->bind_param('i', $id);
  $stmt2->execute();

  // 3. Se o pedido estiver pago via PIX e ainda estiver "aberto", fechar
  $foiPago = $forma === 'pix' && in_array($status, ['pago', 'paid', 'concluido', 'fechado'], true);
  if ($foiPago && $status === 'pago') {
    $stmt3 = $con->prepare("UPDATE pedidos SET status = 'fechado' WHERE id = ?");
    $stmt3->bind_param('i', $pedidoId);
    $stmt3->execute();
  }

  echo json_encode(['sucesso' => true]);
  exit;
}


/**
 * Listar notificações pendentes + status do pedido (se pago via PIX)
 * - Considera como pago PIX quando: pedidos.forma_pagamento = 'pix'
 *   e pedidos.status em ('fechado','pago','paid','concluido').
 */
$sql = "
  SELECT
      n.id,
      n.pedido_id,
      n.mesa_numero,
      n.quantidade,
      p.nome,
      p.imagem,
      ped.status           AS pedido_status,
      ped.forma_pagamento  AS pedido_fp
  FROM notificacoes_pedido n
  JOIN produtos p   ON p.id = n.produto_id
  LEFT JOIN pedidos ped ON ped.id = n.pedido_id
  WHERE n.estado = 'pendente'
  ORDER BY n.id ASC
  LIMIT 10
";

$res = $con->query($sql);
$dados = [];

while ($row = $res->fetch_assoc()) {
  // Normaliza e determina se está pago via PIX
  $status = strtolower((string)($row['pedido_status'] ?? ''));
  $fp     = strtolower((string)($row['pedido_fp'] ?? ''));
  $pagoPix = ($fp === 'pix' && in_array($status, ['fechado','pago','paid','concluido'], true));

  $dados[] = [
    'id'          => (int)$row['id'],
    'pedido_id'   => isset($row['pedido_id']) ? (int)$row['pedido_id'] : null,
    'mesa_numero' => (int)$row['mesa_numero'],
    'quantidade'  => (int)$row['quantidade'],
    'nome'        => (string)$row['nome'],
    'imagem'      => (string)($row['imagem'] ?? ''),
    'pago_pix'    => $pagoPix,                 // <- use este campo para exibir na “div azul”
    'status'      => (string)$row['pedido_status'],   // opcional: útil para depurar/mostrar
    'forma'       => (string)$row['pedido_fp']        // opcional
  ];
}

echo json_encode(['sucesso' => true, 'dados' => $dados]);
