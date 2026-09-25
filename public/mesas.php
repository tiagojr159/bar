<?php
// mesas.php

// Sessão
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/verificar_sessao.php'; // redireciona se não logado

use App\Support\DB;

$conexao = DB::conn();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Apurado do caixa: vendas pagas desde a abertura mais recente.
$rsAbertura = $conexao->query("SELECT MAX(data_abertura) AS data_abertura FROM abertura_caixa");
$ultimaAbertura = $rsAbertura ? ($rsAbertura->fetch_assoc()['data_abertura'] ?? null) : null;
$apuradoCaixa = 0.0;
if ($ultimaAbertura !== null) {
  $stApurado = $conexao->prepare("SELECT COALESCE(SUM(total), 0) AS total FROM pedidos WHERE excluido_em IS NULL AND status IN ('pago','fechado') AND COALESCE(data_pagamento, data_pedido) >= ? AND COALESCE(data_pagamento, data_pedido) <= NOW()");
  $stApurado->bind_param('s', $ultimaAbertura);
  $stApurado->execute();
  $apuradoCaixa = (float)($stApurado->get_result()->fetch_assoc()['total'] ?? 0);
  $stApurado->close();
}

// Buscar mesas ativas com pedidos em aberto
$sql = "
   SELECT m.id,
          m.numero,
          m.descricao,
          m.capacidade,
          p.id    AS pedido_id,
          p.total AS pedido_total
     FROM mesas m
     JOIN (
       SELECT p1.*
         FROM pedidos p1
         JOIN (
           SELECT mesa_id, MAX(id) AS max_id
             FROM pedidos
            WHERE excluido_em IS NULL AND status = 'aberto'
            GROUP BY mesa_id
         ) ult
           ON ult.mesa_id = p1.mesa_id
          AND ult.max_id  = p1.id
     ) p
       ON p.mesa_id = m.id
    ORDER BY m.numero
";
$resultado = $conexao->query($sql);
$mesas = [];

if ($resultado->num_rows > 0) {
  while ($row = $resultado->fetch_assoc()) {
    // Agrupar por mesa
    if (!isset($mesas[$row['id']])) {
      $mesas[$row['id']] = [
        'id' => $row['id'],
        'numero' => $row['numero'],
        'descricao' => $row['descricao'],
        'capacidade' => $row['capacidade'],
        'pedido_id' => $row['pedido_id'],
        'total' => $row['pedido_total'] ?? 0,
        'produtos' => []
      ];
    }
  }
}

// Buscar produtos de cada pedido aberto
foreach ($mesas as $mesa_id => $mesa) {
  if ($mesa['pedido_id']) {
    $sql_produtos = "
      SELECT ip.id, ip.produto_id, ip.quantidade, ip.preco_unitario, ip.subtotal,
             p.nome, p.imagem
      FROM itens_pedido ip
      JOIN produtos p ON ip.produto_id = p.id
      WHERE ip.pedido_id = {$mesa['pedido_id']}
    ";
    $resultado_produtos = $conexao->query($sql_produtos);
    if ($resultado_produtos->num_rows > 0) {
      while ($produto = $resultado_produtos->fetch_assoc()) {
        $mesas[$mesa_id]['produtos'][] = $produto;
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mesas - Bar Azerutan</title>
  <link rel="stylesheet" href="assets/css/mesas.css">

  <!-- Estilo mínimo para a “div azul” das notificações -->
  <style>
    #notificacoes-container {
      position: fixed;
      right: 16px;
      bottom: 16px;
      z-index: 9999;
      display: flex;
      flex-direction: column;
      gap: 12px;
      max-width: min(360px, 92vw);
    }

    .caixa-resumo{display:flex;justify-content:space-between;align-items:center;gap:16px;margin:0 0 24px;padding:18px 22px;border-radius:12px;background:#fff;border:1px solid #e4ece4;box-shadow:0 4px 14px rgba(0,0,0,.06);text-decoration:none;color:#1b5e20;transition:transform .15s,box-shadow .15s}
    .caixa-resumo:hover{transform:translateY(-2px);box-shadow:0 7px 20px rgba(0,0,0,.1)}
    .caixa-resumo small{display:block;color:#667267;font-size:.9rem;margin-bottom:4px}
    .caixa-resumo strong{font-size:1.7rem}
    .caixa-resumo-link{font-weight:700;white-space:nowrap}
    @media(max-width:600px){.caixa-resumo{align-items:flex-start;flex-direction:column}.caixa-resumo strong{font-size:1.45rem}}

    .notif-card {
      background: #007bff;
      color: #fff;
      border-radius: 12px;
      box-shadow: 0 8px 22px rgba(0, 0, 0, .18);
      padding: 10px 12px;
      display: flex;
      gap: 10px;
      align-items: center;
      animation: notifIn .2s ease-out;
    }

    .notif-card img {
      width: 64px;
      height: 64px;
      object-fit: cover;
      border-radius: 8px;
      background: #0b5ed7
    }

    .notif-body {
      flex: 1;
      min-width: 0
    }

    .notif-title {
      font-weight: 800;
      line-height: 1.1;
      margin: 0 0 4px
    }

    .notif-sub {
      opacity: .95;
      font-size: .9rem
    }

    .notif-actions .btn-att {
      background: #fff;
      border: 0;
      border-radius: 8px;
      color: #0b5ed7;
      padding: 8px 10px;
      font-weight: 800;
      cursor: pointer
    }

    .notif-actions .btn-att:hover {
      filter: brightness(.95)
    }

    @keyframes notifIn {
      from {
        opacity: 0;
        transform: translateY(6px)
      }

      to {
        opacity: 1;
        transform: translateY(0)
      }
    }

    /* Acabamento visual da tela de mesas */
    body{font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f7f4;color:#17251b}
    .container{width:min(1240px,calc(100% - 40px));max-width:none;margin:36px auto 60px;padding:0}
    .page-header{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:20px}
    .page-header h2{margin:0;color:#173f28;font-size:clamp(1.55rem,2.5vw,2rem);font-weight:750;letter-spacing:-.035em;line-height:1.2}
    .page-header .btn{flex:0 0 auto;min-height:46px;padding:0 20px;border-radius:12px;background:#087c45;color:#fff;font-size:.94rem;font-weight:700;box-shadow:0 6px 14px rgba(8,124,69,.18);transition:background .18s,transform .18s,box-shadow .18s}
    .page-header .btn:hover{background:#06683a;transform:translateY(-1px);box-shadow:0 9px 20px rgba(8,124,69,.22)}
    .caixa-resumo{position:relative;isolation:isolate;min-height:92px;box-sizing:border-box;margin:0 0 26px;padding:20px 24px;border:1px solid #e3ebe3;border-radius:18px;background:linear-gradient(115deg,#fff 0%,#fbfdf9 100%);box-shadow:0 8px 26px rgba(20,48,28,.055);color:#174d2c;overflow:hidden}
    .caixa-resumo:before{content:"";position:absolute;z-index:-1;right:-36px;top:-66px;width:190px;height:190px;border-radius:50%;background:rgba(115,181,115,.09)}
    .caixa-resumo small{color:#738075;font-size:.83rem;font-weight:600;letter-spacing:.015em;margin-bottom:7px}
    .caixa-resumo strong{font-size:1.75rem;font-weight:800;letter-spacing:-.035em;font-variant-numeric:tabular-nums}
    .caixa-resumo-link{padding:10px 13px;border-radius:10px;background:#edf6ee;color:#17633a;font-size:.87rem;transition:background .18s}
    .caixa-resumo:hover .caixa-resumo-link{background:#e1f0e3}
    .mesas-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,300px),1fr));align-items:start;gap:18px}
    .mesa-card--simple{padding:17px;border:1px solid #e7ece7;border-radius:18px;margin:0;background:#fff;box-shadow:0 5px 18px rgba(20,48,28,.045);transition:transform .18s,box-shadow .18s,border-color .18s}
    .mesa-card--simple:hover{transform:translateY(-3px);border-color:#d4e2d5;box-shadow:0 13px 28px rgba(20,48,28,.09)}
    .mesa-head{margin-bottom:15px}
    .mesa-pill{padding:7px 11px;background:#eff6ef;color:#285d3b;font-size:.86rem;font-weight:700;letter-spacing:.01em}
    .mesa-total{color:#173f28;font-size:1.08rem;font-weight:800;font-variant-numeric:tabular-nums}
    .products-grid{grid-template-columns:repeat(auto-fill,minmax(68px,74px));gap:9px;min-height:0}
    .prod-thumb{aspect-ratio:1;border:1px solid #edf0ed;border-radius:13px;background:#f4f6f3;box-shadow:0 2px 6px rgba(15,40,20,.05)}
    .prod-thumb img{transition:transform .25s}
    .prod-thumb:hover img{transform:scale(1.06)}
    .price-badge{left:5px;top:5px;padding:4px 6px;border-radius:7px;background:rgba(18,35,24,.82);font-size:.65rem;font-weight:700;backdrop-filter:blur(6px)}
    .remove-item-btn{right:5px;bottom:5px;width:28px;height:28px;padding:0;display:grid;place-items:center;background:rgba(170,48,48,.92);font-size:.8rem;box-shadow:0 2px 8px rgba(0,0,0,.15)}
    .remove-item-btn{color:transparent!important;font-size:0!important;z-index:4}
    .remove-item-btn:before,.remove-item-btn:after{content:"";position:absolute;left:50%;top:50%;width:15px;height:2px;border-radius:2px;background:#fff;transform:translate(-50%,-50%) rotate(45deg);pointer-events:none}
    .remove-item-btn:after{transform:translate(-50%,-50%) rotate(-45deg)}
    .mesa-actions{gap:9px;margin-top:16px;padding:13px 0 0;background:transparent;border-top:1px solid #eef1ee}
    .mesa-actions .btn{flex:1 1 0;min-height:42px;padding:0 12px;border-radius:10px;font-size:.82rem;font-weight:700;transition:background .16s,transform .16s}
    .mesa-actions .btn:hover{transform:translateY(-1px)}
    .mesa-actions .btn-primary{background:#087c45}.mesa-actions .btn-primary:hover{background:#06683a}
    .mesa-actions .btn-secondary{background:#eef2ef;color:#435448}.mesa-actions .btn-secondary:hover{background:#e2e9e3}
    .no-mesas{padding:54px 28px;border:1px solid #e6ece6;border-radius:20px;background:linear-gradient(145deg,#fff,#f9fcf8);box-shadow:0 10px 30px rgba(20,48,28,.05)}
    .no-mesas p{color:#657267;font-size:1.05rem}
    .no-mesas .btn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 18px;border-radius:11px;background:#087c45;color:#fff;font-weight:700}
    @media(max-width:640px){.container{width:calc(100% - 28px);margin:24px auto 44px}.page-header{align-items:stretch;flex-direction:column;gap:13px}.page-header .btn{width:100%;justify-content:center}.caixa-resumo{padding:17px 18px}.caixa-resumo strong{font-size:1.55rem}.mesas-grid{grid-template-columns:1fr}.mesa-card--simple{padding:15px}.products-grid{grid-template-columns:repeat(auto-fill,minmax(62px,70px))}}
    /* Modal de novo pedido, redesenhado */
    #nova-mesa-modal.modal{position:fixed;inset:0;z-index:10020;background:rgba(8,20,13,.72);backdrop-filter:blur(8px);padding:0;overflow:hidden}
    #nova-mesa-modal .modal-content.modal-produtos{position:relative;display:flex;flex-direction:column;width:min(1080px,calc(100vw - 48px));height:min(88vh,920px);max-height:88vh;margin:6vh auto;border:1px solid rgba(255,255,255,.5);border-radius:26px;background:#f5f7f3;box-shadow:0 32px 100px rgba(0,0,0,.35);overflow:hidden}
    #nova-mesa-modal .modal-header{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:20px;padding:23px 30px;background:linear-gradient(112deg,#12351f,#0b6c3d);color:#fff;border:0}
    #nova-mesa-modal .modal-title-group{min-width:0}
    #nova-mesa-modal .modal-kicker{display:block;margin-bottom:5px;color:#b9e0c2;font-size:.72rem;font-weight:800;letter-spacing:.16em}
    #nova-mesa-modal .modal-header h3{margin:0;color:#fff;font-size:1.55rem;font-weight:800;letter-spacing:-.035em;line-height:1.15}
    #nova-mesa-modal .modal-title-group p{margin:7px 0 0;color:rgba(255,255,255,.78);font-size:.92rem;line-height:1.4}
    #nova-mesa-modal .modal-header .close{flex:0 0 42px;width:42px;height:42px;border:1px solid rgba(255,255,255,.25);border-radius:14px;background:rgba(255,255,255,.12);color:#fff;font-size:24px;transition:background .16s,transform .16s}
    #nova-mesa-modal .modal-header .close:hover{background:rgba(255,255,255,.22);transform:rotate(4deg)}
    #nova-mesa-modal .modal-body{flex:1 1 auto;min-height:0;overflow:auto;padding:24px 28px;background:radial-gradient(ellipse at top left,rgba(222,239,223,.58),transparent 45%),#f5f7f3;-webkit-overflow-scrolling:touch}
    #nm-grid.pm-grid{grid-template-columns:repeat(auto-fill,minmax(185px,1fr));gap:16px}
    #nova-mesa-modal .pm-card{position:relative;border:1px solid #e5eae4;border-radius:19px;background:#fff;box-shadow:0 4px 14px rgba(24,47,29,.055);transition:transform .18s,border-color .18s,box-shadow .18s}
    #nova-mesa-modal .pm-card:hover{transform:translateY(-4px);border-color:#a9cdb0;box-shadow:0 14px 28px rgba(24,47,29,.12)}
    #nova-mesa-modal .pm-card:active{transform:scale(.985)}
    #nova-mesa-modal .pm-thumb{position:relative;aspect-ratio:1.15/1;overflow:hidden;background:#e9eee8}
    #nova-mesa-modal .pm-thumb img{width:100%;height:100%;object-fit:cover;transition:transform .35s}
    #nova-mesa-modal .pm-card:hover .pm-thumb img{transform:scale(1.045)}
    #nova-mesa-modal .pm-noimg{width:100%;height:100%;display:grid;place-items:center;padding:14px;color:#718074;background:linear-gradient(145deg,#edf2eb,#e2e9e1);font-size:.9rem}
    #nova-mesa-modal .pm-name{min-height:55px;padding:13px 14px 15px;color:#1e3023;font-size:1.04rem;font-weight:750;line-height:1.28;white-space:normal;overflow:visible;text-overflow:clip;box-sizing:border-box}
    #nova-mesa-modal .pm-price,#nova-mesa-modal .pm-stock,#nova-mesa-modal .sel-badge{z-index:2;min-height:34px;display:inline-flex;align-items:center;justify-content:center;padding:7px 11px;border:1px solid rgba(255,255,255,.3);border-radius:11px;color:#fff;font-size:1rem;font-weight:900;line-height:1;font-variant-numeric:tabular-nums;box-shadow:0 4px 12px rgba(0,0,0,.24)}
    #nova-mesa-modal .pm-price{position:absolute!important;display:inline-flex!important;left:10px!important;top:10px!important;right:auto!important;bottom:auto!important;visibility:visible!important;opacity:1!important;background:rgba(20,34,24,.94);backdrop-filter:blur(8px)}
    #nova-mesa-modal .pm-stock{position:absolute!important;top:auto!important;right:auto!important;left:10px!important;bottom:10px!important;background:rgba(25,37,28,.84);backdrop-filter:blur(8px)}
    #nova-mesa-modal .sel-badge{position:absolute!important;right:9px!important;top:9px!important;z-index:10!important;width:38px!important;height:38px!important;min-width:38px!important;min-height:38px!important;padding:0!important;border:2px solid #fff!important;border-radius:50%!important;display:grid!important;place-items:center!important;background:#b94036!important;color:transparent!important;font-size:0!important;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.25);transition:transform .15s,background .15s}
    #nova-mesa-modal .sel-badge:before,#nova-mesa-modal .sel-badge:after{content:"";position:absolute;left:50%;top:50%;width:19px;height:3px;border-radius:3px;background:#fff;transform:translate(-50%,-50%) rotate(45deg);pointer-events:none}
    #nova-mesa-modal .sel-badge:after{transform:translate(-50%,-50%) rotate(-45deg)}
    #nova-mesa-modal .sel-badge:hover{transform:scale(1.08);background:#a52f27}
    #nova-mesa-modal .sel-qty{position:absolute;right:10px;bottom:10px;z-index:3;min-width:34px;height:32px;padding:0 8px;display:grid;place-items:center;border:1px solid rgba(255,255,255,.35);border-radius:10px;background:#087c45;color:#fff;font-size:1rem;font-weight:900;line-height:1;font-variant-numeric:tabular-nums;box-shadow:0 4px 12px rgba(0,0,0,.24)}
    /* Preço sempre visível no modal que adiciona itens a uma mesa aberta */
    #produtos-modal .pm-thumb{position:relative;overflow:hidden}
    #produtos-modal .pm-price{position:absolute!important;z-index:5!important;top:9px!important;left:9px!important;right:auto!important;bottom:auto!important;display:inline-flex!important;align-items:center;justify-content:center;min-height:34px;padding:6px 10px;border:1px solid rgba(255,255,255,.65);border-radius:10px;background:rgba(18,28,21,.94);color:#fff!important;font-size:1rem!important;font-weight:900!important;line-height:1!important;font-variant-numeric:tabular-nums;white-space:nowrap;opacity:1!important;visibility:visible!important;text-shadow:none!important;box-shadow:0 3px 10px rgba(0,0,0,.35)}
    #nova-mesa-modal .nova-mesa-footer{flex:0 0 auto;display:grid!important;grid-template-columns:205px minmax(0,1fr);align-items:center;gap:22px;padding:17px 24px calc(17px + env(safe-area-inset-bottom));background:#fff;border:0;border-top:1px solid #e5ebe5;box-shadow:0 -8px 24px rgba(25,48,29,.055)}
    #nova-mesa-modal .pedido-total-box{display:flex;flex-direction:column;gap:4px;min-width:0}
    #nova-mesa-modal .pedido-total-label{color:#78847a;font-size:.7rem;font-weight:850;letter-spacing:.13em}
    #nova-mesa-modal #am-total{color:#105c32;font-size:2rem;font-weight:900;letter-spacing:-.045em;font-variant-numeric:tabular-nums;white-space:nowrap;line-height:1.05}
    #nova-mesa-modal .pedido-actions{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px!important}
    #nova-mesa-modal .pedido-actions .btn{min-width:0;min-height:50px;padding:9px 12px;border:0;border-radius:13px;font-size:.93rem;font-weight:800;line-height:1.15;white-space:normal;transition:transform .16s,box-shadow .16s,background .16s}
    #nova-mesa-modal .pedido-actions .btn:not(:disabled):hover{transform:translateY(-2px);box-shadow:0 7px 16px rgba(15,48,24,.15)}
    #nova-mesa-modal .pedido-actions .btn:disabled{opacity:.45;filter:saturate(.45)}
    #nova-mesa-modal #am-abrir-btn{background:#edf2ed;color:#35483a}
    #nova-mesa-modal #am-pagar-btn{background:#087c45;color:#fff}
    #nova-mesa-modal #am-dinheiro-btn,#nova-mesa-modal #am-cartao-btn{background:#fff;color:#2a4632;border:1px solid #dce6dc}
    #nova-mesa-modal #nm-msg{margin:14px 0 0;padding:12px 14px;border:1px solid #f0d1c8;border-radius:12px;background:#fff5f2;color:#a43d2b;font-size:.95rem;font-weight:700}
    @media(max-width:700px){#nova-mesa-modal.modal{padding:0}#nova-mesa-modal .modal-content.modal-produtos{width:100vw;height:calc(100dvh - 8px);max-height:calc(100dvh - 8px);margin:8px 0 0;border-radius:25px 25px 0 0;border-bottom:0}#nova-mesa-modal .modal-header{padding:19px 20px 17px}#nova-mesa-modal .modal-header h3{font-size:1.4rem}#nova-mesa-modal .modal-title-group p{font-size:.9rem}#nova-mesa-modal .modal-header .close{flex-basis:40px;width:40px;height:40px}#nova-mesa-modal .modal-body{padding:17px 15px 22px}#nm-grid.pm-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}#nova-mesa-modal .pm-name{min-height:54px;padding:12px 12px 14px;font-size:1rem}#nova-mesa-modal .nova-mesa-footer{grid-template-columns:1fr;gap:12px;padding:13px 15px calc(13px + env(safe-area-inset-bottom))}#nova-mesa-modal .pedido-total-box{flex-direction:row;justify-content:space-between;align-items:baseline}#nova-mesa-modal .pedido-total-label{font-size:.72rem}#nova-mesa-modal #am-total{font-size:1.8rem}#nova-mesa-modal .pedido-actions{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px!important}#nova-mesa-modal .pedido-actions .btn{min-height:46px;font-size:.91rem}}
    @media(max-width:360px){#nova-mesa-modal .modal-header{padding-left:16px;padding-right:16px}#nova-mesa-modal .modal-body{padding-left:11px;padding-right:11px}#nova-mesa-modal .pm-price,#nova-mesa-modal .pm-stock{min-height:32px;font-size:.94rem;padding:6px 9px}#nova-mesa-modal .sel-badge{width:36px;height:36px;font-size:1.45rem}#nova-mesa-modal .sel-qty{min-width:32px;height:30px;font-size:.95rem}#nova-mesa-modal .pedido-actions .btn{font-size:.86rem}}
  </style>
</head>

<body>

  <?php include __DIR__ . '/../views/partials/header.php'; ?>

  <div class="container">
    <div class="page-header">
      <h2>Mesas Ativas</h2>
      <button id="nova-mesa-btn" class="btn btn-primary">+ Novo pedido</button>
    </div>

    <a class="caixa-resumo" href="relatorio_caixa.php" aria-label="Abrir relatório das vendas desde a última abertura do caixa">
      <span><small>Apurado desde a última abertura do caixa</small><strong>R$ <?= number_format($apuradoCaixa, 2, ',', '.') ?></strong></span>
      <span class="caixa-resumo-link">Ver relatório →</span>
    </a>

    <div class="mesas-grid">
      <?php if (empty($mesas)): ?>
        <div class="no-mesas">
          <p>Nenhuma mesa ativa no momento.</p>
          <button type="button" class="btn" onclick="document.getElementById('nova-mesa-btn').click()">Abrir Novo Pedido</button>
        </div>
      <?php else: ?>
        <?php foreach ($mesas as $mesa): ?>
          <?php if (!empty($mesa['pedido_id'])): ?>
            <div class="mesa-card mesa-card--simple"
              data-mesa-id="<?= (int)$mesa['id'] ?>"
              data-pedido-id="<?= (int)$mesa['pedido_id'] ?>">

              <!-- Cabeçalho minimalista -->
              <div class="mesa-head">
                <div class="mesa-pill">Mesa <?= htmlspecialchars($mesa['numero']) ?></div>
                <div class="mesa-total">R$ <?= number_format((float)$mesa['total'], 2, ',', '.') ?></div>
              </div>

              <!-- Só fotos dos produtos (duplicando a imagem conforme quantidade) -->
              <div class="products-grid" title="Produtos da mesa <?= htmlspecialchars($mesa['numero']) ?>">
                <?php foreach ($mesa['produtos'] as $p): ?>
                  <?php
                  $qtd = max(1, (int)$p['quantidade']);
                  for ($i = 0; $i < $qtd; $i++):
                  ?>
                    <div class="prod-thumb"
                      data-prod-id="<?= (int)$p['produto_id'] ?>"
                      title="<?= htmlspecialchars($p['nome']) ?>">
                      <span class="price-badge">
                        R$ <?= number_format((float)$p['preco_unitario'], 2, ',', '.') ?>
                      </span>

                      <?php if (!empty($p['imagem'])): ?>
                        <img src="<?= htmlspecialchars($p['imagem']) ?>" alt="<?= htmlspecialchars($p['nome']) ?>">
                      <?php else: ?>
                        <div class="noimg">sem<br>imagem</div>
                      <?php endif; ?>

                      <button class="remove-item-btn"
                        data-pedido-id="<?= (int)$mesa['pedido_id'] ?>"
                        data-prod-id="<?= (int)$p['produto_id'] ?>"
                        title="Remover 1 unidade">🗑️</button>
                    </div>
                  <?php endfor; ?>
                <?php endforeach; ?>
              </div>

              <!-- Ações -->
              <div class="mesa-actions">
                <button class="btn btn-primary fechar-mesa-btn"
                  data-mesa="<?= htmlspecialchars($mesa['numero']) ?>"
                  data-pedido-id="<?= (int)$mesa['pedido_id'] ?>"
                  data-valor="<?= number_format((float)$mesa['total'], 2, '.', '') ?>">
                  Fechar mesa & pagar
                </button>

                <button class="btn btn-secondary abrir-produtos-btn"
                  data-mesa-id="<?= (int)$mesa['id'] ?>"
                  data-pedido-id="<?= (int)$mesa['pedido_id'] ?>"
                  data-mesa-numero="<?= htmlspecialchars($mesa['numero']) ?>">
                  Adicionar produtos
                </button>
              </div>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- ========== Container fixo para as notificações azuis ========== -->
  <div id="notificacoes-container"></div>

  <!-- Modal: Nova Mesa (sem campos; só a seleção de produtos) -->
  <div id="nova-mesa-modal" class="modal" style="display:none;">
    <div class="modal-content modal-produtos">
      <div class="modal-header">
        <div class="modal-title-group">
          <span class="modal-kicker">NOVO PEDIDO</span>
          <h3>Monte seu pedido</h3>
          <p>Escolha os produtos. Toque novamente para adicionar mais unidades.</p>
        </div>
        <button type="button" class="close nova-mesa-close" aria-label="Fechar seleção">&times;</button>
      </div>

      <div class="modal-body">
        <div id="nm-grid" class="pm-grid"></div>
        <div id="nm-msg" class="pm-msg" style="display:none;"></div>
      </div>

      <div class="modal-footer nova-mesa-footer">
        <div class="pedido-total-box"><span class="pedido-total-label">TOTAL DO PEDIDO</span><strong id="am-total">R$ 0,00</strong></div>
        <div class="pedido-actions">
          <button id="am-abrir-btn" class="btn btn-secondary" disabled>Abrir mesa</button>
          <button id="am-pagar-btn" class="btn btn-primary" disabled>PIX</button>
          <button id="am-dinheiro-btn" class="btn btn-secondary" disabled>Dinheiro</button>
          <button id="am-cartao-btn" class="btn btn-secondary" disabled>Cartão</button>
        </div>
      </div>

    </div>
  </div>

  <!-- Modal: Produtos (adicionar em uma mesa já aberta) -->
  <div id="produtos-modal" class="modal" style="display:none;">
    <div class="modal-content modal-produtos">
      <div class="modal-header">
        <h3>Adicionar produtos à <span id="pm-mesa-label"></span></h3>
        <span class="close pm-close">&times;</span>
      </div>
      <div class="modal-body">
        <div id="pm-grid" class="pm-grid"></div>
        <div id="pm-msg" class="pm-msg" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary pm-close">Fechar</button>
      </div>
    </div>
  </div>



  <!-- Modal de Pagamento -->
  <div id="payment-modal" class="modal" style="display:none;">
    <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="dlg-title">
      <button class="close" aria-label="Fechar" onclick="document.getElementById('payment-modal').style.display='none'">×</button>

      <h3 id="dlg-title">Fechar Mesa</h3>
      <p><strong id="payment-mesa">Mesa</strong></p>
      <p>Total: <strong id="payment-valor">R$ 0,00</strong></p>

      <!-- Valor a cobrar -->
      <div id="vc-wrapper">
        <label for="valor-cobrar">Valor a cobrar</label>
        <input id="valor-cobrar" type="text" inputmode="decimal" placeholder="0,00" autocomplete="off" />
        <small>Use para aplicar desconto/ajuste antes do pagamento.</small>
      </div>

      <hr>

      <!-- Métodos de pagamento (segmentados) -->
      <div class="pay-methods" role="tablist" aria-label="Método de pagamento">
        <label class="pm-option" data-method="pix" role="tab" aria-selected="false" tabindex="0">
          <input type="radio" name="payment-method" value="pix"> PIX
        </label>
        <label class="pm-option" data-method="dinheiro" role="tab" aria-selected="false" tabindex="0">
          <input type="radio" name="payment-method" value="dinheiro"> Dinheiro
        </label>
        <label class="pm-option" data-method="cartao" role="tab" aria-selected="false" tabindex="0">
          <input type="radio" name="payment-method" value="cartao"> Cartão
        </label>
      </div>

      <!-- Form PIX -->
      <div id="pix-payment-form" class="payment-form" style="display:none;">
        <p>Você confirmará na próxima tela (QR Code).</p>
      </div>

      <!-- Form Dinheiro -->
      <div id="dinheiro-payment-form" class="payment-form" style="display:none;">
        <label for="valor-recebido">Valor recebido</label>
        <input id="valor-recebido" type="text" inputmode="decimal" placeholder="0,00" autocomplete="off" />
        <div>Troco: <strong id="troco">0,00</strong></div>
      </div>

      <!-- Form Cartão -->
      <div id="cartao-payment-form" class="payment-form" style="display:none;">
        <p>Pagamento será registrado como cartão (via POS externo).</p>
      </div>

      <!-- Ações fixas -->
      <div class="modal-actions">
        <button id="cancel-payment-btn" class="btn btn-secondary">Cancelar</button>
        <button id="confirm-payment-btn" class="btn btn-primary">Confirmar</button>
      </div>
    </div>
  </div>


  <!-- Modal PIX -->
  <div id="pix-modal" class="modal" style="display:none;">
    <div class="modal-content">
      <button class="close" aria-label="Fechar">×</button>

      <h3>Pagamento PIX</h3>
      <p><strong id="pix-mesa">Mesa</strong></p>
      <p>Valor: <strong id="pix-valor">R$ 0,00</strong></p>

      <div id="pix-qr-code" style="margin:12px 0; text-align:center;">
        <img id="pix-qr-img" src="" alt="QR Code PIX" style="width:250px;height:250px;object-fit:contain;">
        <textarea id="pix-codigo" readonly rows="3"></textarea>
      </div>
      <div style="display:none;">
        <label>Código copia-e-cola</label>
        <textarea id="pix-code-text" rows="3" readonly></textarea>
      </div>

      <div class="pix-status" style="margin-top:8px;">
        Status: <span id="pix-status">Aguardando pagamento...</span>
      </div>

      <div class="modal-actions" style="margin-top:12px;">
        <button id="cancel-pix-btn" class="btn btn-secondary">Fechar</button>
        <button id="verify-payment-btn" class="btn btn-primary">Verificar agora</button>
      </div>
    </div>
  </div>



  <script>
    function play_audio() {
      const audio = new Audio('assets/media/audio.mp3');
      audio.currentTime = 0;
      audio.play().catch(err => console.error('Erro ao tocar áudio:', err));
    }

    function play_pix() {
      const audio = new Audio('assets/media/pix.mp3');
      audio.currentTime = 0;
      audio.play().catch(err => console.error('Erro ao tocar áudio:', err));
    }
  </script>






  <audio id="notification-sound" preload="auto">
    <source src="assets/media/audio.mp3" type="audio/mpeg">
  </audio>

  <!-- Elemento de áudio para notificação (duplicado no original, mantido) -->
  <audio id="notification-sound" preload="auto">
    <source src="data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmwhBSuBzvLZiTYIG2m98OScTgwOUarm7blmFgU7k9n1unEiBC13yO/eizEIHWq+8+OWT" type="audio/wav">
  </audio>

  <!-- Scripts -->
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="assets/js/mesas.js"></script>

  <!-- ========== Script de integração das notificações + auto-atualização ==========
       Só adiciona comportamentos; não altera fluxos existentes. -->
  <script>
    (function() {
      const $container = $('#notificacoes-container');
      const shown = new Set(); // evita duplicar cartõezinhos
      let lastFetchIds = new Set(); // para tocar som apenas quando realmente vier item novo

      // Util: tocar som seguro
      function tocarSom() {
        const el = document.getElementById('notification-sound');
        if (!el) return;
        try {
          el.currentTime = 0;
          const p = el.play();
          if (p && typeof p.then === 'function') p.catch(() => {});
        } catch (e) {}
      }

      // Renderiza uma notificação (div azul)
      function renderNotif(n) {
        const nid = 'notif-' + n.id;
        const img = n.imagem ? String(n.imagem) : 'sem_imagem.png';
        const pagoTxt = n.pago_pix ? '✅ Pago no PIX' : '⌛ Aguardando PIX';

        const $existente = $('#' + nid);

        if ($existente.length) {
          // 👉 já existe: atualiza status e subtítulo
          $existente.find('.notif-sub')
            .text(`Mesa ${Number(n.mesa_numero||0)} • Qtd: ${Number(n.quantidade||1)}`);
          $existente.find('.notif-pix-status').text(pagoTxt);
          return;
        }

        // 👉 ainda não existe: cria card novo
        const $card = $(`
    <div class="notif-card" id="${nid}">
      <img src="${$('<div>').text(img).html()}" alt="${$('<div>').text(n.nome||'').html()}">
      <div class="notif-body">
        <div class="notif-title">${$('<div>').text(n.nome || '').html()}</div>
        <div class="notif-sub">Mesa ${Number(n.mesa_numero||0)} • Qtd: ${Number(n.quantidade||1)}</div>
        <div class="notif-pix-status">${pagoTxt}</div>
      </div>
      <div class="notif-actions">
        <button class="btn-att" data-id="${Number(n.id)}" title="Marcar como atendido">Atendido</button>
      </div>
    </div>
  `);

        // Clique em “Atendido”
        $card.on('click', '.btn-att', function() {
          const id = Number($(this).data('id') || 0);
          $.post('verificar_notificacoes.php', {
            op: 'atender',
            id: id
          }, function(resp) {
            if (resp && resp.sucesso) {
              $card.fadeOut(200, function() {
                $(this).remove();
              });
              play_audio(); // toca som ao atender
            }
          }, 'json');
        });

        $container.append($card);
      }


      // Consulta a API a cada 2s
      function checarNotificacoes() {
        $.getJSON('verificar_notificacoes.php', function(resp) {
          if (!resp || resp.sucesso !== true || !Array.isArray(resp.dados)) return;

          // detectar “novidade” para tocar som
          const currentIds = new Set(resp.dados.map(d => d.id));
          let temNovo = false;
          for (const id of currentIds)
            if (!lastFetchIds.has(id)) {
              temNovo = true;
              break;
            }
          lastFetchIds = currentIds;

          if (temNovo) tocarSom();

          resp.dados.forEach(renderNotif);
        });
      }

      // Atualiza automaticamente a grade das mesas (sem recarregar a página)
      function refreshMesasGrid() {
        // Substitui apenas o conteúdo interno de .mesas-grid
        // (usa a própria página como fonte; não cria endpoints novos)
        $('.mesas-grid').load(window.location.href + ' .mesas-grid > *');
      }

      // Intervalos de 2 segundos
      setInterval(checarNotificacoes, 2000);
      setInterval(refreshMesasGrid, 2000);
    })();
  </script>

  <!-- ================== (Seu script local existente) ================== -->
  <script>
    $(function() {
      // ---------------- Carrinho do modal "Nova mesa" ----------------
      let nmCart = {}; // { produtoId: { nome, preco, img, qtd } }
      let nmTotal = 0;

      // helpers locais (nomes diferentes para não brigar com mesas.js)
      const moneyBR = (v) => Number(v || 0).toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
      const esc = (s) => String(s || '').replace(/[&<>"']/g, m => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
      } [m]));

      // ---------- Abrir modal "Nova mesa" (carrega produtos e reseta estado) ----------
      $('#nova-mesa-btn').on('click', function() {
        nmCart = {};
        nmTotal = 0;
        $('#am-total').text('R$ 0,00');
        $('#am-abrir-btn, #am-pagar-btn, #am-dinheiro-btn, #am-cartao-btn').prop('disabled', true);

        $('#nm-msg').hide().text('');
        $('#nm-grid').html('<div style="padding:12px;color:#777;">Carregando produtos...</div>');

        // abre modal zerado
        $('#nova-mesa-modal')
          .data('mesa-id', null)
          .data('pedido-id', null)
          .fadeIn(120);

        // carrega catálogo
        $.getJSON('listar_produtos.php', function(resp) {
          if (!resp || !resp.sucesso) {
            $('#nm-grid').empty();
            $('#nm-msg').text(resp?.mensagem || 'Erro ao listar produtos.').show();
            return;
          }

          const cards = (resp.produtos || []).map(p => {
            const img = p.imagem ?
              `<img src="${esc(p.imagem)}" alt="${esc(p.nome)}">` :
              `<div class="pm-noimg">sem imagem</div>`;
            return `
            <div class="pm-card nm-card"
                 data-id="${p.id}"
                 data-preco="${p.preco}"
                 data-nome="${esc(p.nome)}"
                 data-img="${p.imagem ? esc(p.imagem) : ''}">
              <div class="pm-thumb">
                ${img}
                <span class="pm-price" style="left:8px;">R$ ${moneyBR(p.preco)}</span>
                <span class="pm-stock" style="right:8px;"> ${p.estoque}</span>
              </div>
              <div class="pm-name" title="${esc(p.nome)}">${esc(p.nome)}</div>
            </div>`;
          }).join('');

          $('#nm-grid').html(cards);
        }).fail(function() {
          $('#nm-grid').empty();
          $('#nm-msg').text('Falha ao obter a lista de produtos.').show();
        });
      });

      // ---------- Clique no produto: NÃO fecha o modal; só incrementa no carrinho ----------
      $(document).on('click', '.nm-card', function() {
        const $c = $(this);
        const id = Number($c.data('id'));
        const nome = String($c.data('nome') || '');
        const preco = Number($c.data('preco') || 0);
        const img = String($c.data('img') || '');

        if (!nmCart[id]) nmCart[id] = {
          nome,
          preco,
          img,
          qtd: 0
        };
        nmCart[id].qtd += 1;
        nmTotal += preco;

        // Controle de remoção e quantidade selecionada.
        const $thumb = $c.find('.pm-thumb');
        if (!$thumb.find('.sel-badge').length) {
          $thumb.append(`<button type="button" class="sel-badge" data-id="${id}" aria-label="Remover ${esc(nome)}">&times;</button><span class="sel-qty" aria-label="Quantidade">1</span>`);
        }
        $thumb.find('.sel-qty').text(nmCart[id].qtd);

        // atualiza total + habilita botões
        $('#am-total').text('R$ ' + moneyBR(nmTotal));
        $('#am-abrir-btn, #am-pagar-btn, #am-dinheiro-btn, #am-cartao-btn').prop('disabled', false);
      });

      $(document).on('click', '#nm-grid .sel-badge', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const id = Number($(this).data('id'));
        const item = nmCart[id];
        if (!item) return;
        nmTotal = Math.max(0, nmTotal - (Number(item.preco) * Number(item.qtd)));
        delete nmCart[id];
        if (window.nmCart) delete window.nmCart[id];
        window.nmTotal = Math.max(0, Number(window.nmTotal || 0) - (Number(item.preco) * Number(item.qtd)));
        $(this).closest('.pm-thumb').find('.sel-badge, .sel-qty').remove();
        $('#am-total').text('R$ ' + moneyBR(nmTotal));
        if (Object.keys(nmCart).length === 0) {
          $('#am-abrir-btn, #am-pagar-btn, #am-dinheiro-btn, #am-cartao-btn').prop('disabled', true);
        }
      });

      // ---------- Botão: ABRIR MESA (sem pagamento agora) ----------
      $('#am-abrir-btn').off('click').on('click', function() {
        if (Object.keys(nmCart).length === 0) {
          $('#nm-msg').text('Selecione ao menos 1 produto.').show();
          return;
        }

        $.ajax({
          url: 'abrir_mesa_e_pedido_com_itens.php',
          type: 'POST',
          data: {
            cart: JSON.stringify(nmCart)
          },
          dataType: 'json'
        }).done(function(resp) {
          if (!resp || !resp.sucesso) {
            $('#nm-msg').text(resp?.mensagem || 'Erro ao abrir mesa.').show();
            return;
          }
          // fecha modal e recarrega
          $('#nova-mesa-modal').fadeOut(120);
          location.reload();
        }).fail(function() {
          $('#nm-msg').text('Falha ao comunicar com o servidor.').show();
        });
      });

      // ---------- Botão: EFETUAR PAGAMENTO (abre PIX já com os itens) ----------
      $('#am-pagar-btn').off('click').on('click', function() {
        if (Object.keys(nmCart).length === 0) {
          $('#nm-msg').text('Selecione ao menos 1 produto.').show();
          return;
        }

        $.ajax({
          url: 'abrir_mesa_e_pedido_com_itens.php',
          type: 'POST',
          data: {
            cart: JSON.stringify(nmCart)
          },
          dataType: 'json'
        }).done(function(resp) {
          if (!resp || !resp.sucesso) {
            $('#nm-msg').text(resp?.mensagem || 'Erro ao abrir mesa para pagamento.').show();
            return;
          }

          const pedidoId = resp.pedido_id;
          const mesaNumero = resp.mesa_numero;
          const valorTotal = Number(resp.total || nmTotal || 0);

          // Gera PIX
          $.ajax({
            url: 'gerar_pagamento_pix.php',
            type: 'POST',
            data: {
              pedido_id: pedidoId,
              valor: valorTotal
            },
            dataType: 'json'
          }).done(function(pg) {
            if (!pg || !pg.success) {
              $('#nm-msg').text(pg?.error || 'Erro ao gerar PIX.').show();
              return;
            }

            // Preenche e exibe modal do PIX
            const b64 = (pg.qr_code_base64 || '').replace(/\s+/g, '');
            $('#pix-mesa').text('Mesa ' + mesaNumero);
            $('#pix-valor').text(moneyBR(valorTotal));
            $('#pix-qr-code').html('<img class="pix-qr-img" alt="QR Code PIX" src="data:image/png;base64,' + b64 + '">');
            $('#pix-code-text').val(pg.qr_code);

            $('#nova-mesa-modal').hide();
            $('#pix-modal').fadeIn(120);

            // Inicia polling (usa função global já existente)
            if (typeof window.startPixCheck === 'function') {
              window.startPixCheck(pg.pagamento_id, pedidoId);
            }
          }).fail(function() {
            $('#nm-msg').text('Falha ao comunicar com o servidor (PIX).').show();
          });
        }).fail(function() {
          $('#nm-msg').text('Falha ao comunicar com o servidor.').show();
        });
      });

      // ---------- Fechar modal ----------
      $(document).on('click', '.nova-mesa-close', function() {
        $('#nova-mesa-modal').fadeOut(120);
      });
    });
  </script>

</body>

</html>
