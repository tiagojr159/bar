<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/verificar_sessao.php';

use App\Support\DB;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('America/Recife');
$con = DB::conn();
if (!($con instanceof mysqli)) {
  http_response_code(500);
  exit('Erro: conexão MySQLi não inicializada.');
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function moeda($v) { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function dataBR($v) { return $v ? date('d/m/Y H:i', strtotime($v)) : '-'; }

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
  http_response_code(400);
  exit('Caixa inválido.');
}

$stCaixa = $con->prepare('SELECT id, usuario_nome, data_abertura FROM abertura_caixa WHERE id = ? LIMIT 1');
$stCaixa->bind_param('i', $id);
$stCaixa->execute();
$caixa = $stCaixa->get_result()->fetch_assoc();
$stCaixa->close();
if (!$caixa) {
  http_response_code(404);
  exit('Caixa não encontrado.');
}

$stFim = $con->prepare('SELECT data_abertura FROM abertura_caixa WHERE data_abertura > ? OR (data_abertura = ? AND id > ?) ORDER BY data_abertura ASC, id ASC LIMIT 1');
$stFim->bind_param('ssi', $caixa['data_abertura'], $caixa['data_abertura'], $id);
$stFim->execute();
$fim = $stFim->get_result()->fetch_assoc()['data_abertura'] ?? null;
$stFim->close();

if ($fim !== null) {
  $sql = "SELECT p.id AS pedido_id, COALESCE(p.data_pagamento, p.data_pedido) AS hora_venda,
                 pr.nome AS produto, i.quantidade, i.subtotal,
                 CASE WHEN i.quantidade > 0 THEN i.subtotal / i.quantidade ELSE 0 END AS preco_unitario
            FROM itens_pedido i
            JOIN pedidos p ON p.id = i.pedido_id
            JOIN produtos pr ON pr.id = i.produto_id
           WHERE p.excluido_em IS NULL AND p.status IN ('pago','fechado')
             AND COALESCE(p.data_pagamento, p.data_pedido) >= ?
             AND COALESCE(p.data_pagamento, p.data_pedido) < ?
           ORDER BY hora_venda ASC, p.id ASC, pr.nome ASC";
  $st = $con->prepare($sql);
  $st->bind_param('ss', $caixa['data_abertura'], $fim);
} else {
  $sql = "SELECT p.id AS pedido_id, COALESCE(p.data_pagamento, p.data_pedido) AS hora_venda,
                 pr.nome AS produto, i.quantidade, i.subtotal,
                 CASE WHEN i.quantidade > 0 THEN i.subtotal / i.quantidade ELSE 0 END AS preco_unitario
            FROM itens_pedido i
            JOIN pedidos p ON p.id = i.pedido_id
            JOIN produtos pr ON pr.id = i.produto_id
           WHERE p.excluido_em IS NULL AND p.status IN ('pago','fechado')
             AND COALESCE(p.data_pagamento, p.data_pedido) >= ?
             AND COALESCE(p.data_pagamento, p.data_pedido) <= NOW()
           ORDER BY hora_venda ASC, p.id ASC, pr.nome ASC";
  $st = $con->prepare($sql);
  $st->bind_param('s', $caixa['data_abertura']);
}
$st->execute();
$vendas = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
$total = 0.0;
foreach ($vendas as $venda) $total += (float)$venda['subtotal'];
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="refresh" content="180">
  <title>Detalhe do caixa #<?= (int)$caixa['id'] ?> - Bar Azerutan</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;background:#f6f7f9;color:#111}
    main{max-width:1100px;margin:24px auto;padding:0 16px}.card{background:#fff;border:1px solid #eee;border-radius:12px;margin-bottom:16px;overflow:hidden}
    h1{font-size:1.35rem;margin:0;padding:16px;border-bottom:1px solid #eee}.content{padding:16px}.muted{color:#666}.btn{display:inline-block;background:#0d6efd;color:#fff;border-radius:8px;padding:8px 12px;text-decoration:none;margin-bottom:14px}
    .summary{display:flex;gap:24px;flex-wrap:wrap}.summary strong{display:block;font-size:1.2rem;margin-top:4px}
    table{width:100%;border-collapse:collapse}th,td{padding:10px;text-align:left;border-bottom:1px solid #eee}th{background:#fafafa}td.money,th.money{text-align:right;white-space:nowrap}
    @media(max-width:600px){.table-wrap{overflow-x:auto}table{min-width:650px}}
  </style>
</head>
<body>
<?php include __DIR__ . '/../views/partials/header.php'; ?>
<main>
  <a class="btn" href="relatorios.php">&larr; Voltar aos relatórios</a>
  <section class="card">
    <h1>Vendas do caixa #<?= (int)$caixa['id'] ?></h1>
    <div class="content summary">
      <div><span class="muted">Abertura</span><strong><?= h(dataBR($caixa['data_abertura'])) ?></strong></div>
      <div><span class="muted">Usuário</span><strong><?= h($caixa['usuario_nome'] ?: '-') ?></strong></div>
      <div><span class="muted">Fechamento do intervalo</span><strong><?= h($fim ? dataBR($fim) : 'Caixa atual') ?></strong></div>
      <div><span class="muted">Total vendido</span><strong><?= moeda($total) ?></strong></div>
    </div>
  </section>
  <section class="card">
    <h1>Produtos e horários das vendas</h1>
    <?php if (!$vendas): ?>
      <div class="content muted">Nenhuma venda registrada neste caixa.</div>
    <?php else: ?>
      <div class="content table-wrap">
        <table>
          <thead><tr><th>Hora da venda</th><th>Pedido</th><th>Produto</th><th>Quantidade</th><th class="money">Valor unitário</th><th class="money">Subtotal</th></tr></thead>
          <tbody>
            <?php foreach ($vendas as $venda): ?>
              <tr>
                <td><?= h(dataBR($venda['hora_venda'])) ?></td>
                <td>#<?= (int)$venda['pedido_id'] ?></td>
                <td><?= h($venda['produto']) ?></td>
                <td><?= (int)$venda['quantidade'] ?></td>
                <td class="money"><?= moeda($venda['preco_unitario']) ?></td>
                <td class="money"><?= moeda($venda['subtotal']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot><tr><th colspan="5" class="money">Total do caixa</th><th class="money"><?= moeda($total) ?></th></tr></tfoot>
        </table>
      </div>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
