<?php
// public/abrir_mesa.php (corrigido para App\Support\DB)
use App\Support\DB;

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');

// Se este arquivo for acessado direto e o autoload não estiver carregado,
// descomente a linha abaixo:
// require_once __DIR__ . '/../bootstrap.php';

try {
  // ==== DB via App\Support\DB ====
  $cx = DB::conn();
  if (!($cx instanceof mysqli)) { throw new Exception('Conexão MySQLi não inicializada.'); }
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
  $cx->set_charset('utf8mb4');

  // ===== Entradas (podem vir vazias) =====
  $numero     = isset($_POST['numero']) ? (int)$_POST['numero'] : 0;
  $descricao  = trim($_POST['descricao'] ?? '');
  $capacidade = isset($_POST['capacidade']) ? (int)$_POST['capacidade'] : 0;

  // Valores padrão
  if ($capacidade <= 0) { $capacidade = 4; }

  // Se não informarem número, pegar o próximo (MAX(numero)+1)
  if ($numero <= 0) {
    $rs = $cx->query("SELECT COALESCE(MAX(numero),0)+1 AS prox FROM mesas");
    $proximo = (int)($rs->fetch_assoc()['prox'] ?? 1);
    $numero = max(1, $proximo);
  }

  // Evita mesa ativa duplicada com o mesmo número
  $st = $cx->prepare('SELECT id FROM mesas WHERE numero = ? AND ativa = 1 LIMIT 1');
  $st->bind_param('i', $numero);
  $st->execute();
  if ($st->get_result()->fetch_assoc()){
    echo json_encode(['sucesso' => false, 'mensagem' => 'Já existe uma mesa ativa com esse número.']); 
    exit;
  }
  $st->close();

  // Cria mesa
  $st = $cx->prepare('INSERT INTO mesas (numero, descricao, capacidade, ativa) VALUES (?, ?, ?, 1)');
  $st->bind_param('isi', $numero, $descricao, $capacidade);
  $st->execute();
  $mesa_id = $st->insert_id;
  $st->close();

  // Cria pedido “aberto” para a mesa
  $usuario_id = $_SESSION['usuario_id'] ?? null;
  if ($usuario_id){
    $st = $cx->prepare("INSERT INTO pedidos (mesa_id, usuario_id, status, total) VALUES (?, ?, 'aberto', 0)");
    $st->bind_param('ii', $mesa_id, $usuario_id);
  } else {
    $st = $cx->prepare("INSERT INTO pedidos (mesa_id, status, total) VALUES (?, 'aberto', 0)");
    $st->bind_param('i', $mesa_id);
  }
  $st->execute();
  $pedido_id = $st->insert_id;
  $st->close();

  echo json_encode([
    'sucesso'     => true,
    'mesa_id'     => $mesa_id,
    'pedido_id'   => $pedido_id,
    'mesa_numero' => $numero
  ]);

} catch (Throwable $e) {
  http_response_code(200);
  echo json_encode(['sucesso' => false, 'mensagem' => 'Erro: '.$e->getMessage()]);
}
