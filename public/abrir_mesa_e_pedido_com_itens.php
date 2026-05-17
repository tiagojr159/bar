<?php
// abrir_mesa_e_pedido_com_itens.php
header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once dirname(__DIR__) . '/vendor/autoload.php'; // carrega autoload do Composer

use App\Support\DB;







try {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();

  // ==== CONEXÃO via App\Support\DB ====
  $con = DB::conn();
  if (!($con instanceof mysqli)) { throw new Exception('Conexão MySQLi não inicializada.'); }
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
  $con->set_charset('utf8mb4');

  // --------- Entrada ---------
  $cartJson = $_POST['cart'] ?? '';
  if (!$cartJson) { echo json_encode(['sucesso'=>false,'mensagem'=>'Carrinho vazio']); exit; }
  $cart = json_decode($cartJson, true);
  if (!is_array($cart) || empty($cart)) { echo json_encode(['sucesso'=>false,'mensagem'=>'Carrinho inválido']); exit; }

  $con->begin_transaction();

  // 1) próximo número de mesa
  $res = $con->query("SELECT COALESCE(MAX(numero),0)+1 AS prox FROM mesas");
  $prox = (int)($res->fetch_assoc()['prox'] ?? 1);

  // 2) cria mesa
  $st = $con->prepare("INSERT INTO mesas (numero, descricao, capacidade, ativa) VALUES (?, '', 4, 1)");
  $st->bind_param('i', $prox);
  $st->execute();
  $mesa_id = $con->insert_id;

  // 3) cria pedido
  $st = $con->prepare("INSERT INTO pedidos (mesa_id, usuario_id, data_pedido, status, total, pagamento_id) VALUES (?, 1, NOW(), 'aberto', 0, NULL)");
  $st->bind_param('i', $mesa_id);
  $st->execute();
  $pedido_id = $con->insert_id;

  // 4) insere itens e atualiza estoque
  $total = 0.0;

  $getProd = $con->prepare("SELECT nome, preco, imagem, estoque FROM produtos WHERE id=? AND ativo=1 LIMIT 1");
  $insItem = $con->prepare("INSERT INTO itens_pedido (pedido_id, produto_id, quantidade, preco_unitario, subtotal) VALUES (?, ?, 1, ?, ?)");
  $updEstq = $con->prepare("UPDATE produtos SET estoque = GREATEST(estoque - 1, 0) WHERE id=? AND ativo=1");

  $itensResp = [];

  foreach ($cart as $prodId => $info) {
    $prodId = (int)$prodId;
    $qtd = max(1, (int)($info['qtd'] ?? 0));

    // lê produto
    $getProd->bind_param('i', $prodId);
    $getProd->execute();
    $p = $getProd->get_result()->fetch_assoc();
    if (!$p) continue;

    $preco  = (float)$p['preco'];
    $nome   = (string)($p['nome'] ?? '');
    $imagem = $p['imagem'] ?? null;

    // 1 linha por unidade + baixa estoque
    for ($i = 0; $i < $qtd; $i++) {
      $insItem->bind_param('iidd', $pedido_id, $prodId, $preco, $preco);
      $insItem->execute();

      $updEstq->bind_param('i', $prodId);
      $updEstq->execute();

      $total += $preco;
    }

    $itensResp[] = [
      'produto_id' => $prodId,
      'nome'       => $nome,
      'imagem'     => $imagem,
      'preco'      => $preco,
      'quantidade' => $qtd,
    ];
  }

  // 5) atualiza total do pedido
  $st = $con->prepare("UPDATE pedidos SET total=? WHERE id=?");
  $st->bind_param('di', $total, $pedido_id);
  $st->execute();

  $con->commit();

  echo json_encode([
    'sucesso'     => true,
    'mesa_id'     => $mesa_id,
    'mesa_numero' => $prox,
    'pedido_id'   => $pedido_id,
    'total'       => $total,
    'itens'       => $itensResp,
  ]);
} catch (Throwable $e) {
  if (isset($con) && $con instanceof mysqli && $con->errno) { $con->rollback(); }
  echo json_encode(['sucesso'=>false,'mensagem'=>$e->getMessage()]);
}
