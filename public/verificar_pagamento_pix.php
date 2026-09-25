<?php

use App\Support\DB; // <-- importa a classe DB logo no topo

// public/verificar_pagamento_pix.php
header('Content-Type: application/json; charset=utf-8');

try {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();

  require_once __DIR__ . '/../bootstrap.php';

  // Token do config
  $config = require dirname(__DIR__) . '/config/config.php';
  $access_token = $config['mercadopago']['access_token'] ?? '';
  if ($access_token === '') {
    echo json_encode(['success' => false, 'error' => 'MP_ACCESS_TOKEN não configurado.']);
    exit;
  }

  $con = DB::conn();
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
  $con->set_charset('utf8mb4');

  // ==== Parâmetros ====
  $pagamento_id = isset($_REQUEST['pagamento_id']) ? trim($_REQUEST['pagamento_id']) : '';
  $pedido_id    = isset($_REQUEST['pedido_id']) ? (int)$_REQUEST['pedido_id'] : 0;

  if ($pedido_id > 0) {
    $stAtivo = $con->prepare("SELECT id FROM pedidos WHERE id = ? AND excluido_em IS NULL LIMIT 1");
    $stAtivo->bind_param('i', $pedido_id);
    $stAtivo->execute();
    $pedidoAtivo = $stAtivo->get_result()->fetch_assoc();
    $stAtivo->close();
    if (!$pedidoAtivo) {
      echo json_encode(['success' => false, 'error' => 'Pedido indisponível']);
      exit;
    }
  }

  if ($pagamento_id === '' && $pedido_id > 0) {
    $st = $con->prepare("SELECT pagamento_id FROM pedidos WHERE id=? AND excluido_em IS NULL LIMIT 1");
    $st->bind_param('i', $pedido_id);
    $st->execute();
    $st->bind_result($pag_id_db);
    $st->fetch();
    $st->close();
    $pagamento_id = $pag_id_db ?: '';
  }

  if ($pagamento_id === '') {
    echo json_encode(['success' => false, 'error' => 'pagamento_id ausente']);
    exit;
  }

  // ==== Consulta ao Mercado Pago ====
  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL            => "https://api.mercadopago.com/v1/payments/{$pagamento_id}",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
      "Authorization: Bearer {$access_token}",
      "Content-Type: application/json"
    ],
  ]);
  $resp = curl_exec($ch);
  if ($resp === false) throw new Exception('Falha ao consultar MP: ' . curl_error($ch));
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($http < 200 || $http >= 300) {
    echo json_encode(['success' => false, 'error' => "MP HTTP {$http}", 'raw' => $resp]);
    exit;
  }

  $data     = json_decode($resp, true) ?: [];
  $status   = $data['status'] ?? 'unknown';
  $paid_at  = $data['date_approved'] ?? null;

  // ==== Atualização do pedido (idempotente) ====
  // ==== Atualização do pedido (idempotente) ====
  if ($status === 'approved' && $pedido_id > 0) {
    $forma = 'pix';
    $st2 = $con->prepare("
    UPDATE pedidos
       SET status='pago',
           forma_pagamento=?,
           data_pagamento=IFNULL(data_pagamento, NOW())
     WHERE id=? AND excluido_em IS NULL");
    $st2->bind_param('si', $forma, $pedido_id);
    $st2->execute();
    $st2->close();

    // === NOVO BLOCO: Reduz estoque dos produtos ===
    $stmt = $con->prepare("SELECT i.produto_id, i.quantidade FROM itens_pedido i JOIN pedidos p ON p.id = i.pedido_id WHERE i.pedido_id = ? AND p.excluido_em IS NULL");
    $stmt->bind_param('i', $pedido_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($item = $result->fetch_assoc()) {
      $produto_id = (int)$item['produto_id'];
      $quantidade = (int)$item['quantidade'];

      // Evita estoque negativo
      $upd = $con->prepare("UPDATE produtos SET estoque = GREATEST(estoque - ?, 0) WHERE id = ?");
      $upd->bind_param('ii', $quantidade, $produto_id);
      $upd->execute();
      $upd->close();
    }

    $stmt->close();
  }


  echo json_encode([
    'success'       => true,
    'status'        => $status,
    'paid_at'       => $paid_at,
    'pagamento_id'  => $pagamento_id,
  ]);
} catch (Throwable $e) {
  http_response_code(200);
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
