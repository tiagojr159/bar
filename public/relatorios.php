<?php
// relatorios.php
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

require __DIR__ . '/../bootstrap.php';

use App\Support\DB;

/** Conexão */
$con = DB::conn();
if (!($con instanceof mysqli)) {
  die('Erro: conexão MySQLi não inicializada.');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$nivelUsuario = $_SESSION['usuario_nivel'] ?? ($_SESSION['usuario']['usuario_nivel'] ?? null);
$isAdmin = ((string)$nivelUsuario === '1');
if (empty($_SESSION['csrf_excluir_pedido'])) {
  $_SESSION['csrf_excluir_pedido'] = bin2hex(random_bytes(32));
}
$mensagemExclusao = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['acao'] ?? '') === 'excluir_pedido') {
  if (!$isAdmin) {
    http_response_code(403);
    exit('Apenas o administrador pode apagar pedidos.');
  }
  $token = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals($_SESSION['csrf_excluir_pedido'], $token)) {
    http_response_code(403);
    exit('Solicitação inválida. Atualize a página e tente novamente.');
  }
  $pedidoExcluir = (int)($_POST['pedido_id'] ?? 0);
  if ($pedidoExcluir <= 0) {
    $mensagemExclusao = 'Pedido inválido.';
  } else {
    try {
      $stDelPedido = $con->prepare('UPDATE pedidos SET excluido_em = NOW() WHERE id = ? AND excluido_em IS NULL LIMIT 1');
      $stDelPedido->bind_param('i', $pedidoExcluir);
      $stDelPedido->execute();
      $apagado = $stDelPedido->affected_rows > 0;
      $stDelPedido->close();
      $mensagemExclusao = $apagado ? 'Pedido removido da lista. O histórico e os itens foram preservados.' : 'Pedido não encontrado.';
    } catch (Throwable $e) {
      $mensagemExclusao = 'Não foi possível apagar o pedido.';
    }
  }
}

/** Período (padrão: últimos 7 dias) */
date_default_timezone_set('America/Recife');
$hoje        = date('Y-m-d');
$ini_default = date('Y-m-d', strtotime('-7 days'));

$inicio = isset($_GET['inicio']) && $_GET['inicio'] !== '' ? $_GET['inicio'] : $ini_default;
$fim    = isset($_GET['fim'])    && $_GET['fim']    !== '' ? $_GET['fim']    : $hoje;

$inicio = DateTime::createFromFormat('Y-m-d', $inicio) ? $inicio : $ini_default;
$fim    = DateTime::createFromFormat('Y-m-d', $fim)    ? $fim    : $hoje;

$inicio_ts = $inicio . ' 00:00:00';
$fim_ts    = $fim    . ' 23:59:59';

/* =========================================================
 * (1) PRODUTOS EM ESTOQUE — ALINHADO À TELA DE PRODUTOS
 *     Aqui usamos APENAS produtos.estoque como “Estoque”.
 *     Nada de subtrair vendas de novo.
 * =======================================================*/
$sqlEstoque = "
  SELECT
    p.id,
    p.nome,
    p.preco,
    p.imagem,
    p.estoque AS estoque_atual
  FROM produtos p
  WHERE p.ativo = 1
  ORDER BY p.nome
";
$estoqueRs = $con->query($sqlEstoque);

$estoque           = [];
$valorEstoqueTotal = 0.0;
$qtdEstoqueTotal   = 0;

while ($r = $estoqueRs->fetch_assoc()) {
  $preco         = (float)$r['preco'];
  $qtd           = (int)$r['estoque_atual'];
  $valorItem     = $preco * $qtd;

  $estoque[] = [
    'id'            => (int)$r['id'],
    'nome'          => (string)$r['nome'],
    'preco'         => $preco,
    'imagem'        => (string)($r['imagem'] ?? ''),
    'estoque_atual' => $qtd,
    'valor'         => $valorItem,
  ];

  $qtdEstoqueTotal   += $qtd;
  $valorEstoqueTotal += $valorItem;
}

/* =========================================================
 * (2) Resumo por status no período selecionado
 * =======================================================*/
$sqlResumo = "
  SELECT status, COUNT(*) AS qtd, COALESCE(SUM(total),0) AS soma
  FROM pedidos
  WHERE excluido_em IS NULL AND data_pedido BETWEEN ? AND ?
  GROUP BY status
";
$stResumo = $con->prepare($sqlResumo);
$stResumo->bind_param('ss', $inicio_ts, $fim_ts);
$stResumo->execute();
$resResumo = $stResumo->get_result();

$resumo = [
  'aberto'    => ['qtd' => 0, 'soma' => 0.0],
  'pago'      => ['qtd' => 0, 'soma' => 0.0],
  'fechado'   => ['qtd' => 0, 'soma' => 0.0],
  'cancelado' => ['qtd' => 0, 'soma' => 0.0],
];
while ($r = $resResumo->fetch_assoc()) {
  $key = strtolower($r['status']);
  if (!isset($resumo[$key])) $resumo[$key] = ['qtd' => 0, 'soma' => 0.0];
  $resumo[$key]['qtd']  = (int)$r['qtd'];
  $resumo[$key]['soma'] = (float)$r['soma'];
}

/* 2b) Listagem de pedidos no período (com mesa) */
$sqlPedidos = "
  SELECT p.id, p.mesa_id, p.data_pedido, p.status, p.total,
         m.numero AS mesa_numero
  FROM pedidos p
  LEFT JOIN mesas m ON m.id = p.mesa_id
  WHERE p.excluido_em IS NULL AND p.data_pedido BETWEEN ? AND ?
  ORDER BY p.data_pedido DESC
";
$stPed = $con->prepare($sqlPedidos);
$stPed->bind_param('ss', $inicio_ts, $fim_ts);
$stPed->execute();
$pedidos = $stPed->get_result()->fetch_all(MYSQLI_ASSOC);

/* =========================================================
 * (3) Faturamento por dia (somente pedidos fechados)
 * =======================================================*/
$sqlFat = "
  SELECT DATE(p.data_pedido) AS dia, COALESCE(SUM(p.total),0) AS faturamento
  FROM pedidos p
  WHERE p.excluido_em IS NULL AND p.status = 'fechado'
    AND p.data_pedido BETWEEN ? AND ?
  GROUP BY DATE(p.data_pedido)
  ORDER BY dia DESC
";
$stFat = $con->prepare($sqlFat);
$stFat->bind_param('ss', $inicio_ts, $fim_ts);
$stFat->execute();
$faturamento = $stFat->get_result()->fetch_all(MYSQLI_ASSOC);

/* =========================================================
 * (4) Caixa de produtos (usa a MESMA base do catálogo)
 * =======================================================*/
$caixaProdutosQtd   = $qtdEstoqueTotal;
$caixaProdutosValor = $valorEstoqueTotal;

/* =========================================================
 * (5) Vendidos desde a última abertura de caixa (mantido)
 * =======================================================*/
$ultimaAbertura = null;
$rsUlt = $con->query("SELECT MAX(data_abertura) AS dt FROM abertura_caixa");
if ($rsUlt) {
  $rowUlt = $rsUlt->fetch_assoc();
  $ultimaAbertura = $rowUlt['dt'] ?? null; // datetime MySQL
}

$vendidosUltAbertura = [];
$totalItensUltAbert  = 0;
$totalValorUltAbert  = 0.0;

if ($ultimaAbertura !== null) {
  $sqlVendUlt = "
    SELECT
      pr.id,
      pr.nome,
      pr.imagem,
      COALESCE(SUM(i.quantidade),0) AS qtd_vendida,
      COALESCE(SUM(i.subtotal),0)   AS valor_vendido
    FROM itens_pedido i
    JOIN pedidos p   ON p.id = i.pedido_id
    JOIN produtos pr ON pr.id = i.produto_id
    WHERE p.excluido_em IS NULL AND p.status IN ('pago','fechado')
      AND COALESCE(p.data_pagamento, p.data_pedido) >= ?
      AND COALESCE(p.data_pagamento, p.data_pedido) <= NOW()
    GROUP BY pr.id, pr.nome, pr.imagem
    HAVING qtd_vendida > 0
    ORDER BY qtd_vendida DESC, pr.nome ASC
  ";
  $stVU = $con->prepare($sqlVendUlt);
  $stVU->bind_param('s', $ultimaAbertura);
  $stVU->execute();
  $vendidosUltAbertura = $stVU->get_result()->fetch_all(MYSQLI_ASSOC);
  $stVU->close();

  foreach ($vendidosUltAbertura as $v) {
    $totalItensUltAbert += (int)$v['qtd_vendida'];
    $totalValorUltAbert += (float)$v['valor_vendido'];
  }
}

/* =========================================================
 * (6) Vendidos entre aberturas de caixa (mantido)
 * =======================================================*/
$aberturas = [];
$rsA = $con->query("
  SELECT id, usuario_id, usuario_nome, data_abertura
  FROM abertura_caixa
  ORDER BY data_abertura ASC, id ASC
");
if ($rsA && $rsA->num_rows > 0) {
  while ($r = $rsA->fetch_assoc()) $aberturas[] = $r;
}

$intervalos = [];
if (!empty($aberturas)) {
  $n = count($aberturas);
  for ($i = 0; $i < $n; $i++) {
    $ini = $aberturas[$i]['data_abertura']; // inclusive
    $fim = ($i < $n - 1) ? $aberturas[$i+1]['data_abertura'] : null; // exclusivo; null => NOW()
    $intervalos[] = [
      'ini' => $ini,
      'fim' => $fim,
      'id'  => (int)$aberturas[$i]['id'],
      'usuario_nome' => (string)$aberturas[$i]['usuario_nome'],
      'usuario_id'   => $aberturas[$i]['usuario_id'] !== null ? (int)$aberturas[$i]['usuario_id'] : null,
    ];
  }
}

$vendidosPorIntervalo = [];
if (!empty($intervalos)) {
  $sqlBaseIntervalo = "
    SELECT
      pr.id,
      pr.nome,
      pr.imagem,
      COALESCE(SUM(i.quantidade),0) AS qtd_vendida,
      COALESCE(SUM(i.subtotal),0)   AS valor_vendido
    FROM itens_pedido i
    JOIN pedidos p   ON p.id = i.pedido_id
    JOIN produtos pr ON pr.id = i.produto_id
    WHERE p.excluido_em IS NULL AND p.status IN ('pago','fechado')
      AND COALESCE(p.data_pagamento, p.data_pedido) >= ?
      AND COALESCE(p.data_pagamento, p.data_pedido) <  ?
    GROUP BY pr.id, pr.nome, pr.imagem
    HAVING qtd_vendida > 0
    ORDER BY qtd_vendida DESC, pr.nome ASC
  ";
  $sqlBaseIntervaloOpenEnd = "
    SELECT
      pr.id,
      pr.nome,
      pr.imagem,
      COALESCE(SUM(i.quantidade),0) AS qtd_vendida,
      COALESCE(SUM(i.subtotal),0)   AS valor_vendido
    FROM itens_pedido i
    JOIN pedidos p   ON p.id = i.pedido_id
    JOIN produtos pr ON pr.id = i.produto_id
    WHERE p.excluido_em IS NULL AND p.status IN ('pago','fechado')
      AND COALESCE(p.data_pagamento, p.data_pedido) >= ?
      AND COALESCE(p.data_pagamento, p.data_pedido) <= NOW()
    GROUP BY pr.id, pr.nome, pr.imagem
    HAVING qtd_vendida > 0
    ORDER BY qtd_vendida DESC, pr.nome ASC
  ";

  foreach ($intervalos as $iv) {
    if ($iv['fim'] !== null) {
      $stI = $con->prepare($sqlBaseIntervalo);
      $stI->bind_param('ss', $iv['ini'], $iv['fim']);
    } else {
      $stI = $con->prepare($sqlBaseIntervaloOpenEnd);
      $stI->bind_param('s', $iv['ini']);
    }
    $stI->execute();
    $lista = $stI->get_result()->fetch_all(MYSQLI_ASSOC);
    $stI->close();

    $totQ = 0; $totV = 0.0;
    foreach ($lista as $ln) { $totQ += (int)$ln['qtd_vendida']; $totV += (float)$ln['valor_vendido']; }

    $vendidosPorIntervalo[] = [
      'ini' => $iv['ini'], 'fim' => $iv['fim'],
      'abertura_id' => $iv['id'],
      'usuario_nome' => $iv['usuario_nome'],
      'usuario_id'   => $iv['usuario_id'],
      'lista' => $lista, 'tot_qtd' => $totQ, 'tot_valor' => $totV,
    ];
  }
}

/* =========================================================
 * (7) Somatório diário por usuário — pagos/fechados
 * =======================================================*/
$sqlDiaUsuario = "
  SELECT
    DATE(COALESCE(p.data_pagamento, p.data_pedido)) AS dia,
    COALESCE(u.nome, 'Sem usuário')                AS usuario,
    COALESCE(SUM(p.total),0)                       AS soma
  FROM pedidos p
  LEFT JOIN usuarios u ON u.id = p.usuario_id
  WHERE p.excluido_em IS NULL AND p.status IN ('pago','fechado')
    AND DATE(COALESCE(p.data_pagamento, p.data_pedido)) >= ?
    AND DATE(COALESCE(p.data_pagamento, p.data_pedido)) <= ?
  GROUP BY DATE(COALESCE(p.data_pagamento, p.data_pedido)), usuario
  ORDER BY dia DESC, usuario ASC
";
$stDU = $con->prepare($sqlDiaUsuario);
$stDU->bind_param('ss', $inicio, $fim);
$stDU->execute();
$diaUsuario = $stDU->get_result()->fetch_all(MYSQLI_ASSOC);

$duGrouped = [];
foreach ($diaUsuario as $r) {
  $d = $r['dia'];
  $duGrouped[$d][] = ['usuario' => (string)$r['usuario'], 'soma' => (float)$r['soma']];
}

/** Helpers */
function moeda($v){ return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function dBR($ts){ return $ts ? date('d/m/Y H:i', strtotime($ts)) : '-'; }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Relatórios - Bar Azerutan</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    body{font-family:system-ui,-apple-system, Segoe UI, Roboto, Ubuntu,'Helvetica Neue', Arial, sans-serif;margin:0;background:#f6f7f9;color:#111}
    .container{max-width:1100px;margin:20px auto;padding:0 16px}
    .card{background:#fff;border:1px solid #eee;border-radius:12px;margin-bottom:16px;overflow:hidden}
    .card h3{margin:0;padding:12px 14px;border-bottom:1px solid #eee}
    .card .content{padding:12px 14px}
    table{width:100%;border-collapse:collapse}
    th,td{padding:8px 10px;border-bottom:1px solid #eee;text-align:left}
    th{background:#fafafa;font-weight:600}
    tr:hover td{background:#fafcff}
    .muted{color:#666}
    .kpis{display:flex;gap:12px;flex-wrap:wrap}
    .kpi{background:#fafafa;border:1px solid #eee;border-radius:10px;padding:10px 12px;min-width:220px}
    .filter{display:flex;gap:8px;align-items:center;margin-bottom:12px}
    input[type="date"]{padding:6px 8px;border:1px solid #ddd;border-radius:8px}
    .btn{display:inline-block;background:#0d6efd;color:#fff;border:0;border-radius:8px;padding:8px 12px;text-decoration:none;cursor:pointer}
    .btn.secondary{background:#6c757d}
    .prod-flex{display:flex;gap:10px;align-items:center}
    .prod-thumb{width:40px;height:40px;border-radius:8px;background:#f0f0f0;overflow:hidden;display:inline-flex;align-items:center;justify-content:center}
    .prod-thumb img{width:100%;height:100%;object-fit:cover}
    .badge{display:inline-block;background:#111;color:#fff;border-radius:999px;padding:2px 8px;font-size:.8rem}
    .status-aberto{background:#ffc107;color:#111}
    .status-fechado{background:#28a745}
    .btn-danger{display:inline-block;background:#dc3545;color:#fff;border:0;border-radius:8px;padding:6px 9px;cursor:pointer;font:inherit;font-size:.85rem}
    .flash-delete{padding:10px 12px;margin-bottom:12px;border-radius:8px;background:#fff3cd;color:#664d03}
  </style>
</head>
<body>
  <?php include __DIR__ . '/../views/partials/header.php'; ?>
  <div class="container">
    <?php if ($mensagemExclusao !== ''): ?><div class="flash-delete"><?= h($mensagemExclusao) ?></div><?php endif; ?>

    <div class="card">
      <h3>Período</h3>
      <div class="content">
        <form class="filter" method="get">
          <label>De:</label>
          <input type="date" name="inicio" value="<?= h($inicio) ?>">
          <label>Até:</label>
          <input type="date" name="fim" value="<?= h($fim) ?>">
          <button class="btn" type="submit">Aplicar</button>
          <a class="btn secondary" href="relatorios.php">Limpar</a>
        </form>
      </div>
    </div>

    <!-- Caixa de produtos (estoque) -->
    <div class="card">
      <h3>Caixa de produtos (estoque)</h3>
      <div class="content kpis">
        <div class="kpi">
          <div class="muted">Quantidade total em estoque</div>
          <div style="font-size:1.4rem;font-weight:700;"><?= (int)$caixaProdutosQtd ?></div>
        </div>
        <div class="kpi">
          <div class="muted">Valor total do estoque (preço de venda)</div>
          <div style="font-size:1.4rem;font-weight:700;"><?= moeda($caixaProdutosValor) ?></div>
          <div class="muted" style="font-size:.85rem;margin-top:6px;">* Se possuir preço de custo, troque a fórmula para custo × estoque</div>
        </div>
      </div>
    </div>






















    
    <!-- Produtos em estoque (CORRIGIDO) -->
    <div class="card">
      <h3>Produtos em estoque</h3>
      <div class="content">
        <?php if (empty($estoque)): ?>
          <div class="muted">Nenhum produto cadastrado.</div>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>Produto</th>
                <th>Preço</th>
                <th>Estoque</th>
                <th>Valor em estoque</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($estoque as $p): ?>
                <tr>
                  <td>
                    <div class="prod-flex">
                      <span class="prod-thumb">
                        <?php if (!empty($p['imagem'])): ?>
                          <img src="<?= h($p['imagem']) ?>" alt="<?= h($p['nome']) ?>">
                        <?php endif; ?>
                      </span>
                      <?= h($p['nome']) ?>
                    </div>
                  </td>
                  <td><?= moeda($p['preco']) ?></td>
                  <td><?= (int)$p['estoque_atual'] ?></td> <!-- exatamente produtos.estoque -->
                  <td><?= moeda($p['valor']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <th colspan="3" style="text-align:right;">Total</th>
                <th><?= moeda($valorEstoqueTotal) ?></th>
              </tr>
            </tfoot>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- Pedidos por status -->
    <div class="card">
      <h3>Pedidos por status (<?= h($inicio) ?> a <?= h($fim) ?>)</h3>
      <div class="content">
        <div class="kpis">
          <div class="kpi">
            <div class="muted">Abertos</div>
            <div style="font-size:1.4rem;font-weight:700;"><?= (int)($resumo['aberto']['qtd'] ?? 0) ?></div>
            <div class="muted">Total aberto: <?= moeda($resumo['aberto']['soma'] ?? 0) ?></div>
          </div>
          <div class="kpi">
            <div class="muted">Pagos</div>
            <div style="font-size:1.4rem;font-weight:700;"><?= (int)($resumo['pago']['qtd'] ?? 0) ?></div>
            <div class="muted">Total pago: <?= moeda($resumo['pago']['soma'] ?? 0) ?></div>
          </div>
          <div class="kpi">
            <div class="muted">Fechados</div>
            <div style="font-size:1.4rem;font-weight:700;"><?= (int)($resumo['fechado']['qtd'] ?? 0) ?></div>
            <div class="muted">Total fechado: <?= moeda($resumo['fechado']['soma'] ?? 0) ?></div>
          </div>
          <?php if (($resumo['cancelado']['qtd'] ?? 0) > 0): ?>
            <div class="kpi">
              <div class="muted">Cancelados</div>
              <div style="font-size:1.4rem;font-weight:700;"><?= (int)$resumo['cancelado']['qtd'] ?></div>
              <div class="muted">Total cancelado: <?= moeda($resumo['cancelado']['soma']) ?></div>
            </div>
          <?php endif; ?>
        </div>

        <div style="margin-top:12px;overflow:auto">
          <?php if (empty($pedidos)): ?>
            <div class="muted">Nenhum pedido no período.</div>
          <?php else: ?>
            <table>
              <thead>
                <tr>
                  <th>ID Pedido</th>
                  <th>Mesa</th>
                  <th>Data</th>
                  <th>Status</th>
                  <th>Total</th>
                  <?php if ($isAdmin): ?><th>Ações</th><?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($pedidos as $pd): ?>
                  <tr>
                    <td>
                      <a href="pedido_detalhe.php?id=<?= (int)$pd['id'] ?>" target="_blank" rel="noopener">
                        #<?= (int)$pd['id'] ?>
                      </a>
                    </td>
                    <td><?= h($pd['mesa_numero'] ?? '-') ?></td>
                    <td><?= h(date('d/m/Y H:i', strtotime($pd['data_pedido']))) ?></td>
                    <td>
                      <?php
                      $st = strtolower($pd['status']);
                      $classe = 'status-aberto';
                      if ($st === 'pago')       $classe = 'status-pago';
                      elseif ($st === 'aberto') $classe = 'status-aberto';
                      elseif ($st === 'fechado')$classe = 'status-fechado';
                      ?>
                      <span class="badge <?= $classe ?>"><?= strtoupper($st) ?></span>
                    </td>
                    <td><?= moeda($pd['total']) ?></td>
                    <?php if ($isAdmin): ?>
                      <td>
                        <form method="post" onsubmit="return confirm('Marcar o pedido #<?= (int)$pd['id'] ?> como excluído? O histórico será preservado.');" style="margin:0">
                          <input type="hidden" name="acao" value="excluir_pedido">
                          <input type="hidden" name="pedido_id" value="<?= (int)$pd['id'] ?>">
                          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_excluir_pedido']) ?>">
                          <button type="submit" class="btn-danger">Apagar</button>
                        </form>
                      </td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Vendidos desde a última abertura de caixa -->
    <div class="card">
      <h3>Produtos vendidos desde a última abertura de caixa</h3>
      <div class="content">
        <?php if ($ultimaAbertura === null): ?>
          <div class="muted">Ainda não há registros em <strong>abertura_caixa</strong>.</div>
        <?php else: ?>
          <div class="muted" style="margin-bottom:8px;">
            Considerando vendas entre <strong><?= h(dBR($ultimaAbertura)) ?></strong> e <strong><?= h(dBR(date('Y-m-d H:i:s'))) ?></strong>.
          </div>
          <div class="kpis" style="margin-bottom:10px">
            <div class="kpi">
              <div class="muted">Quantidade total de itens vendidos</div>
              <div style="font-size:1.4rem;font-weight:700;"><?= (int)$totalItensUltAbert ?></div>
            </div>
            <div class="kpi">
              <div class="muted">Valor total vendido (itens)</div>
              <div style="font-size:1.4rem;font-weight:700;"><?= moeda($totalValorUltAbert) ?></div>
            </div>
          </div>

          <?php if (empty($vendidosUltAbertura)): ?>
            <div class="muted">Nenhuma venda registrada nesse intervalo.</div>
          <?php else: ?>
            <table>
              <thead>
                <tr>
                  <th>Produto</th>
                  <th style="width:120px">Qtd vendida</th>
                  <th style="width:160px">Valor vendido</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($vendidosUltAbertura as $v): ?>
                  <tr>
                    <td>
                      <div class="prod-flex">
                        <span class="prod-thumb">
                          <?php if (!empty($v['imagem'])): ?>
                            <img src="<?= h($v['imagem']) ?>" alt="<?= h($v['nome']) ?>">
                          <?php endif; ?>
                        </span>
                        <?= h($v['nome']) ?>
                      </div>
                    </td>
                    <td><?= (int)$v['qtd_vendida'] ?></td>
                    <td><?= moeda($v['valor_vendido']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr>
                  <th style="text-align:right;">Totais</th>
                  <th><?= (int)$totalItensUltAbert ?></th>
                  <th><?= moeda($totalValorUltAbert) ?></th>
                </tr>
              </tfoot>
            </table>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Vendidos entre aberturas -->
    <div class="card">
      <h3>Produtos vendidos entre aberturas de caixa</h3>
      <div class="content">
        <?php if (empty($vendidosPorIntervalo)): ?>
          <div class="muted">Não há intervalos de abertura de caixa para exibir.</div>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>Data da abertura</th>
                <th style="width:220px">Valor vendido no intervalo</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($vendidosPorIntervalo as $blk): ?>
                <tr>
                  <td>
                    <?= h(dBR($blk['ini'])) ?>
                    <?php if (!empty($blk['usuario_nome'])): ?>
                      <div class="muted" style="font-size:.85rem;">
                        Abertura #<?= (int)$blk['abertura_id'] ?> — <?= h($blk['usuario_nome']) ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td><?= moeda($blk['tot_valor']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- Somatório diário por usuário -->
    <div class="card">
      <h3>Somatório diário por usuário</h3>
      <div class="content">
        <?php if (empty($duGrouped)): ?>
          <div class="muted">Sem registros de pagamento no período selecionado.</div>
        <?php else: ?>
          <?php $brl = function($v){ return 'R$ ' . number_format((float)$v, 2, ',', '.'); }; ?>
          <?php foreach ($duGrouped as $dia => $rows): ?>
            <h4 style="margin:10px 0 6px"><?= h(date('d/m/Y', strtotime($dia))) ?></h4>
            <table>
              <thead>
                <tr>
                  <th>Usuário</th>
                  <th style="width:180px">Total do dia</th>
                </tr>
              </thead>
              <tbody>
                <?php $subtotal = 0.0; ?>
                <?php foreach ($rows as $r): $subtotal += (float)$r['soma']; ?>
                  <tr>
                    <td><?= h($r['usuario']) ?></td>
                    <td><?= $brl($r['soma']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr>
                  <th style="text-align:right;">Subtotal do dia</th>
                  <th><?= $brl($subtotal) ?></th>
                </tr>
              </tfoot>
            </table>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Faturamento por dia -->
    <div class="card">
      <h3>Faturamento por dia (pedidos fechados)</h3>
      <div class="content">
        <?php if (empty($faturamento)): ?>
          <div class="muted">Sem faturamento no período selecionado.</div>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>Dia</th>
                <th>Faturamento</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($faturamento as $f): ?>
                <tr>
                  <td><?= h(date('d/m/Y', strtotime($f['dia']))) ?></td>
                  <td><?= moeda($f['faturamento']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <?php $fatTotal = array_sum(array_map(fn($x) => (float)$x['faturamento'], $faturamento)); ?>
              <tr>
                <th style="text-align:right;">Total no período</th>
                <th><?= moeda($fatTotal) ?></th>
              </tr>
            </tfoot>
          </table>
        <?php endif; ?>
      </div>
    </div>

  </div>
</body>
</html>
