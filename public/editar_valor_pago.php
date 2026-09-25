<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/verificar_sessao.php';
use App\Support\DB;

$con = DB::conn();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$pedidoId = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
if ($pedidoId <= 0) {
  http_response_code(400);
  exit('Pedido inválido.');
}
$erro = '';
$sucesso = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $valorEntrada = trim((string)($_POST['valor_pago'] ?? ''));
  $normalizado = strpos($valorEntrada, ',') !== false
    ? str_replace(',', '.', str_replace('.', '', $valorEntrada))
    : $valorEntrada;
  if ($valorEntrada === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $normalizado)) {
    $erro = 'Informe um valor válido com até duas casas decimais.';
  } else {
    $valorPago = (float)$normalizado;
    $stUpdate = $con->prepare("UPDATE pedidos SET total = ? WHERE id = ? AND excluido_em IS NULL AND status IN ('pago','fechado')");
    $stUpdate->bind_param('di', $valorPago, $pedidoId);
    $stUpdate->execute();
    $stCheck = $con->prepare("SELECT id FROM pedidos WHERE id = ? AND excluido_em IS NULL AND status IN ('pago','fechado') LIMIT 1");
    $stCheck->bind_param('i', $pedidoId);
    $stCheck->execute();
    $pedidoExiste = (bool)$stCheck->get_result()->fetch_assoc();
    $stCheck->close();
    if ($pedidoExiste) {
      header('Location: relatorio_caixa.php?atualizado=1');
      exit;
    }
    $erro = 'O pedido não foi encontrado ou não está pago.';
    $stUpdate->close();
  }
}

$st = $con->prepare("SELECT id, total, status, COALESCE(data_pagamento, data_pedido) AS data_pagamento FROM pedidos WHERE id = ? AND excluido_em IS NULL AND status IN ('pago','fechado') LIMIT 1");
$st->bind_param('i', $pedidoId);
$st->execute();
$pedido = $st->get_result()->fetch_assoc();
$st->close();
if (!$pedido) {
  http_response_code(404);
  exit('Pedido pago não encontrado.');
}
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function moeda($v) { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
?>
<!doctype html>
<html lang="pt-BR"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Editar valor pago - Bar Azerutan</title>
  <style>
    body{margin:0;background:#f6f7f9;color:#202a21;font-family:system-ui,-apple-system,"Segoe UI",sans-serif}.wrap{max-width:520px;margin:36px auto;padding:0 16px}.card{background:#fff;border:1px solid #e7ece7;border-radius:14px;padding:22px;box-shadow:0 5px 18px #1525150c}h1{margin:0 0 18px;color:#1b5e20;font-size:1.45rem}.meta{color:#667267;margin-bottom:18px}.meta strong{color:#202a21}.field{display:block;font-weight:700;margin-bottom:7px}input{box-sizing:border-box;width:100%;padding:12px;border:1px solid #cbd5cb;border-radius:8px;font:inherit;font-size:1.1rem}.actions{display:flex;gap:10px;margin-top:18px}.btn{border:0;border-radius:8px;padding:11px 15px;background:#1b5e20;color:#fff;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}.btn-secondary{background:#edf2ed;color:#344234}.error{padding:10px 12px;margin-bottom:14px;background:#fde8e7;color:#9d2420;border-radius:8px}
  </style>
</head><body>
<?php include __DIR__ . '/../views/partials/header.php'; ?>
<main class="wrap"><section class="card">
  <h1>Editar valor pago</h1>
  <div class="meta">Pedido #<?= (int)$pedido['id'] ?> · <?= h(date('d/m/Y H:i', strtotime($pedido['data_pagamento']))) ?><br>Valor atual: <strong><?= moeda($pedido['total']) ?></strong></div>
  <?php if ($erro !== ''): ?><div class="error"><?= h($erro) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="id" value="<?= (int)$pedido['id'] ?>">
    <label class="field" for="valor_pago">Novo valor pago</label>
    <input id="valor_pago" name="valor_pago" type="number" min="0" step="0.01" value="<?= h(number_format((float)$pedido['total'], 2, '.', '')) ?>" required autofocus>
    <div class="actions"><button class="btn" type="submit">Salvar valor</button><a class="btn btn-secondary" href="relatorio_caixa.php">Cancelar</a></div>
  </form>
</section></main></body></html>
