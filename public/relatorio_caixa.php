<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/verificar_sessao.php';
use App\Support\DB;

$con = DB::conn();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$rs = $con->query("SELECT MAX(data_abertura) AS data_abertura FROM abertura_caixa");
$ultimaAbertura = $rs ? ($rs->fetch_assoc()['data_abertura'] ?? null) : null;
$mesas = array();
$total = 0.0;
if ($ultimaAbertura !== null) {
  $sql = "SELECT p.id AS pedido_id, p.mesa_id, p.total AS valor_pago,
                 m.numero AS mesa_numero,
                 COALESCE(p.data_pagamento, p.data_pedido) AS data_pagamento,
                 i.quantidade, i.subtotal,
                 pr.nome AS produto_nome, pr.imagem AS produto_imagem
            FROM pedidos p
            LEFT JOIN mesas m ON m.id = p.mesa_id
            JOIN itens_pedido i ON i.pedido_id = p.id
            JOIN produtos pr ON pr.id = i.produto_id
           WHERE p.status IN ('pago','fechado')
             AND COALESCE(p.data_pagamento, p.data_pedido) >= ?
             AND COALESCE(p.data_pagamento, p.data_pedido) <= NOW()
           ORDER BY COALESCE(p.data_pagamento, p.data_pedido) ASC, p.id ASC, pr.nome ASC";
  $st = $con->prepare($sql);
  $st->bind_param('s', $ultimaAbertura);
  $st->execute();
  $linhas = $st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();
  foreach ($linhas as $linha) {
    $mesaKey = $linha['mesa_id'] !== null ? (string)$linha['mesa_id'] : 'sem-mesa';
    if (!isset($mesas[$mesaKey])) {
      $mesas[$mesaKey] = array('numero' => $linha['mesa_numero'], 'pedidos' => array());
    }
    $pedidoId = (int)$linha['pedido_id'];
    if (!isset($mesas[$mesaKey]['pedidos'][$pedidoId])) {
      $mesas[$mesaKey]['pedidos'][$pedidoId] = array(
        'id' => $pedidoId,
        'data_pagamento' => $linha['data_pagamento'],
        'total' => (float)$linha['valor_pago'],
        'itens' => array()
      );
      $total += (float)$linha['valor_pago'];
    }
    $qtd = (int)$linha['quantidade'];
    $subtotal = (float)$linha['subtotal'];
    $mesas[$mesaKey]['pedidos'][$pedidoId]['itens'][] = array(
      'nome' => $linha['produto_nome'],
      'imagem' => $linha['produto_imagem'],
      'quantidade' => $qtd
    );
  }
}
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function moeda($v) { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
?>
<!doctype html>
<html lang="pt-BR"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Apurado do caixa - Bar Azerutan</title>
  <style>
    body{margin:0;background:#f6f7f9;color:#202a21;font-family:system-ui,-apple-system,"Segoe UI",sans-serif}
    main{max-width:1050px;margin:28px auto;padding:0 18px}.top{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:20px}
    h1{margin:0;font-size:1.65rem;color:#1b5e20}.back{color:#1b5e20;font-weight:700;text-decoration:none}
    .panel{background:#fff;border:1px solid #e7ece7;border-radius:14px;box-shadow:0 5px 18px #1525150c;overflow:hidden}.panel h2{font-size:1.1rem;margin:0;padding:16px 18px;border-bottom:1px solid #edf0ed}
    .pedido{display:grid;grid-template-columns:76px minmax(0,1fr) 110px;align-items:center;gap:12px;padding:11px 16px;border-bottom:1px solid #edf0ed}.pedido-hora{font-weight:650;color:#465246;font-variant-numeric:tabular-nums}.pedido-icones{display:flex;flex-wrap:wrap;align-items:center;gap:8px;min-width:0;padding:3px 5px 3px 3px}.item-icone{position:relative;flex:0 0 46px;width:46px;height:46px}.item-img{width:46px;height:46px;object-fit:cover;border-radius:9px;background:#f0f2f0;border:1px solid #e5ebe5;box-sizing:border-box}.item-sem-imagem{display:flex;align-items:center;justify-content:center;color:#839083;font-size:.58rem;text-align:center}.item-qtd{position:absolute;right:-5px;bottom:-5px;min-width:18px;height:18px;padding:0 4px;border-radius:10px;background:#1b5e20;color:#fff;font-size:.68rem;font-weight:800;display:flex;align-items:center;justify-content:center;box-sizing:border-box}.pedido-valor{text-align:right;font-weight:750;color:#1b5e20;white-space:nowrap;text-decoration:none}.pedido-valor:hover{text-decoration:underline}.total-geral{display:flex;justify-content:space-between;align-items:center;padding:17px 18px;background:#edf7ee;color:#1b5e20;font-size:1.1rem;font-weight:850}.empty{padding:24px;color:#667267}
    @media(max-width:600px){main{margin:18px auto}.top{align-items:flex-start;flex-direction:column}.pedido{grid-template-columns:58px minmax(0,1fr) 82px;gap:8px;padding:10px}.item-icone{width:40px;height:40px;flex-basis:40px}.item-img{width:40px;height:40px}.pedido-hora,.pedido-valor{font-size:.86rem}}
  </style>
</head><body>
<?php include __DIR__ . '/../views/partials/header.php'; ?>
<main>
  <div class="top"><h1>Apurado do caixa</h1><a class="back" href="mesas.php">&larr; Voltar às mesas</a></div>
  <section class="panel"><h2>Vendas por mesa, em ordem de pagamento</h2>
    <?php if (!$ultimaAbertura || !$mesas): ?><div class="empty">Nenhum item vendido desde a última abertura do caixa.</div>
    <?php else: ?>
      <?php foreach ($mesas as $mesa): ?>
          <?php foreach ($mesa['pedidos'] as $pedido): ?>
            <article class="pedido" title="Pedido #<?= (int)$pedido['id'] ?>">
              <time class="pedido-hora" datetime="<?= h(date('c', strtotime($pedido['data_pagamento']))) ?>"><?= h(date('H:i', strtotime($pedido['data_pagamento']))) ?></time>
              <div class="pedido-icones" aria-label="Itens do pedido">
                <?php foreach ($pedido['itens'] as $item): ?>
                  <span class="item-icone" title="<?= h($item['nome']) ?> · <?= (int)$item['quantidade'] ?> un.">
                    <?php if (!empty($item['imagem'])): ?><img class="item-img" src="<?= h($item['imagem']) ?>" alt="<?= h($item['nome']) ?>">
                    <?php else: ?><span class="item-img item-sem-imagem">Sem imagem</span><?php endif; ?>
                    <?php if ((int)$item['quantidade'] > 1): ?><span class="item-qtd"><?= (int)$item['quantidade'] ?></span><?php endif; ?>
                  </span>
                <?php endforeach; ?>
              </div>
              <a class="pedido-valor" href="editar_valor_pago.php?id=<?= (int)$pedido['id'] ?>" title="Editar valor pago"><?= moeda($pedido['total']) ?></a>
            </article>
          <?php endforeach; ?>
      <?php endforeach; ?>
      <div class="total-geral"><span>Somatório</span><span><?= moeda($total) ?></span></div>
    <?php endif; ?>
  </section>
</main></body></html>
