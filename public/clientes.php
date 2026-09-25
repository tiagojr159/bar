<?php
// clientes.php — página pública (sem login)

// Torna a página pública para o verificar_sessao.php
if (!defined('PUBLIC_PAGE')) {
  define('PUBLIC_PAGE', true);
}

// Sessão (sem exigir login)
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

// Opcional: cria uma sessão de visitante para o header.php não “estranhar”
if (empty($_SESSION['usuario_id']) && empty($_SESSION['logado']) && empty($_SESSION['user']['guest'])) {
  $_SESSION['user'] = [
    'id'        => 0,
    'nome'      => 'Visitante',
    'usuario_id'=> '999999',
    'guest'     => true,
  ];
}

require __DIR__ . '/../bootstrap.php';

// NÃO exigir login nesta página (apenas inicializa variáveis de sessão de header)
require __DIR__ . '/verificar_sessao.php';

use App\Support\DB;

$con = DB::conn();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$con->set_charset('utf8mb4');

// ===== AJAX interno =====
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
  header('Content-Type: application/json; charset=utf-8');
  $op = $_GET['op'] ?? $_POST['op'] ?? '';

  // Buscar mesa por número (pedido aberto + itens)
  if ($op === 'mesa_by_numero') {
    $numero = isset($_GET['numero']) ? (int)$_GET['numero'] : 0;
    if ($numero <= 0) { echo json_encode(['success'=>false,'error'=>'Número de mesa inválido']); exit; }

    $sql = "
      SELECT m.id AS mesa_id, m.numero AS mesa_numero, m.descricao, m.capacidade,
             p.id AS pedido_id, p.total AS pedido_total
        FROM mesas m
        LEFT JOIN (
          SELECT p1.*
            FROM pedidos p1
            JOIN (
              SELECT mesa_id, MAX(id) AS max_id
                FROM pedidos
               WHERE excluido_em IS NULL AND status = 'aberto'
               GROUP BY mesa_id
            ) ult ON ult.mesa_id = p1.mesa_id AND ult.max_id = p1.id
        ) p ON p.mesa_id = m.id
       WHERE m.numero = {$numero}
       LIMIT 1
    ";
    $res = $con->query($sql);
    if (!$res || $res->num_rows === 0) { echo json_encode(['success'=>false,'error'=>'Mesa não encontrada']); exit; }

    $mesa = $res->fetch_assoc();
    if (empty($mesa['pedido_id'])) {
      echo json_encode(['success'=>true,'mesa'=>[
        'mesa_id'=>(int)$mesa['mesa_id'],
        'mesa_numero'=>(int)$mesa['mesa_numero'],
        'pedido_id'=>null,
        'total'=>0,
        'itens'=>[]
      ]]); exit;
    }

    $pid = (int)$mesa['pedido_id'];
    $sqlIt = "
      SELECT ip.id, ip.produto_id, ip.quantidade, ip.preco_unitario, ip.subtotal,
             p.nome, p.imagem
        FROM itens_pedido ip
        JOIN produtos p ON p.id = ip.produto_id
       WHERE ip.pedido_id = {$pid}
       ORDER BY ip.id
    ";
    $ri = $con->query($sqlIt);
    $itens = [];
    while ($ri && $row = $ri->fetch_assoc()) {
      $itens[] = [
        'id'             => (int)$row['id'],
        'produto_id'     => (int)$row['produto_id'],
        'quantidade'     => (int)$row['quantidade'],
        'preco_unitario' => (float)$row['preco_unitario'],
        'subtotal'       => (float)$row['subtotal'],
        'nome'           => (string)$row['nome'],
        'imagem'         => (string)($row['imagem'] ?? '')
      ];
    }

    echo json_encode(['success'=>true,'mesa'=>[
      'mesa_id'=>(int)$mesa['mesa_id'],
      'mesa_numero'=>(int)$mesa['mesa_numero'],
      'pedido_id'=>(int)$mesa['pedido_id'],
      'total'=>(float)$mesa['pedido_total'],
      'itens'=>$itens
    ]]); exit;
  }

  // Inserir notificação de pedido (pós adicionar item)
  if ($op === 'notify') {
    $pedido_id   = isset($_POST['pedido_id']) ? (int)$_POST['pedido_id'] : 0;
    $mesa_numero = isset($_POST['mesa_numero']) ? (int)$_POST['mesa_numero'] : 0;
    $produto_id  = isset($_POST['produto_id']) ? (int)$_POST['produto_id'] : 0;
    $quantidade  = isset($_POST['quantidade']) ? (int)$_POST['quantidade'] : 1;

    if ($pedido_id<=0 || $mesa_numero<=0 || $produto_id<=0) {
      echo json_encode(['success'=>false,'error'=>'Parâmetros inválidos']); exit;
    }
    if ($quantidade<=0) $quantidade = 1;

    $stmt = $con->prepare("INSERT INTO notificacoes_pedido (pedido_id, mesa_numero, produto_id, quantidade, estado) VALUES (?, ?, ?, ?, 'pendente')");
    $stmt->bind_param('iiii', $pedido_id, $mesa_numero, $produto_id, $quantidade);
    try {
      $ok = $stmt->execute();
      echo json_encode(['success'=> (bool)$ok ]);
    } catch (\Throwable $e) {
      echo json_encode(['success'=>false,'error'=>'Falha ao registrar notificação']);
    }
    exit;
  }

  // NOVO: abrir (ou assegurar) mesa e pedido "aberto" pelo número (usado na UI pública)
  if ($op === 'open_for_mesa') {
    $mesa_numero = isset($_POST['mesa_numero']) ? (int)$_POST['mesa_numero'] : 0;
    if ($mesa_numero <= 0) { echo json_encode(['success'=>false,'error'=>'Número de mesa inválido']); exit; }

    // 1) garante mesa pelo número
    $stmt = $con->prepare("SELECT id FROM mesas WHERE numero=? LIMIT 1");
    $stmt->bind_param('i', $mesa_numero);
    $stmt->execute();
    $stmt->bind_result($mesa_id);
    $stmt->fetch();
    $stmt->close();

    if (!$mesa_id) {
      $desc = "Mesa {$mesa_numero}";
      $cap  = 0;
      $stmt = $con->prepare("INSERT INTO mesas (numero, descricao, capacidade) VALUES (?, ?, ?)");
      $stmt->bind_param('isi', $mesa_numero, $desc, $cap);
      $stmt->execute();
      $mesa_id = $stmt->insert_id;
      $stmt->close();
    }

    // 2) procura último pedido em aberto dessa mesa
    $stmt = $con->prepare("
      SELECT p.id
        FROM pedidos p
       WHERE p.mesa_id=? AND p.excluido_em IS NULL AND p.status='aberto'
       ORDER BY p.id DESC
       LIMIT 1
    ");
    $stmt->bind_param('i', $mesa_id);
    $stmt->execute();
    $stmt->bind_result($pedido_id);
    $stmt->fetch();
    $stmt->close();

    // 3) se não houver, cria um
    if (!$pedido_id) {
      $total = 0.0;
      $status = 'aberto';
      $stmt = $con->prepare("INSERT INTO pedidos (mesa_id, total, status) VALUES (?, ?, ?)");
      $stmt->bind_param('ids', $mesa_id, $total, $status);
      $stmt->execute();
      $pedido_id = $stmt->insert_id;
      $stmt->close();
    }

    echo json_encode([
      'success'     => true,
      'mesa_id'     => (int)$mesa_id,
      'mesa_numero' => (int)$mesa_numero,
      'pedido_id'   => (int)$pedido_id,
    ]);
    exit;
  }

  echo json_encode(['success' => false, 'error' => 'Operação inválida']); exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Clientes - Bar Azerutan</title>

  <!-- Mantém o tema global -->
  <link rel="stylesheet" href="assets/css/mesas.css">

  <!-- CSS específico desta página (APENAS VISUAL) -->
  <style>
    /* ===== Ocultar menu/gaveta/ícone de mesas SOMENTE nesta página ===== */
    body.clientes-mode .hambtn,
    body.clientes-mode .mesas-btn,
    body.clientes-mode .nav-overlay,
    body.clientes-mode .nav-drawer { display:none !important; }

    /* --------- Abas (destaque melhor) --------- */
    .tabs{
      display:flex; gap:10px; margin: 12px auto 20px; flex-wrap:wrap;
      justify-content:center; align-items:center;
      background:#f0f2f5; padding:8px; border-radius:12px;
      box-shadow: 0 2px 10px rgba(0,0,0,.06) inset;
      max-width: 520px;
    }
    .tab-btn{
      position:relative;
      border:1px solid transparent;
      background:#ffffff;
      color:#00a650;
      padding:10px 18px;
      border-radius:999px;
      cursor:pointer;
      font-weight:800;
      letter-spacing:.2px;
      transition: all .18s ease;
      box-shadow: 0 1px 2px rgba(0,0,0,.04);
    }
    .tab-btn:hover{ transform: translateY(-1px); box-shadow:0 6px 18px rgba(0,0,0,.08); }
    .tab-btn.active{
      color:#fff;
      border-color: transparent;
      background: linear-gradient(135deg, #00b35f 0%, #00904d 100%);
      box-shadow: 0 10px 24px rgba(0, 166, 80, .28);
    }
    .tab-btn.active::after{
      content:"";
      position:absolute; left:50%; transform:translateX(-50%);
      bottom:-6px; width:36px; height:6px;
      border-radius:999px;
      background: rgba(0,166,80,.18);
      filter: blur(6px);
    }

    /* --------- Cards básicos --------- */
    .row {display:grid; grid-template-columns: 1fr; gap:16px;}
    @media (min-width: 992px){ .row {grid-template-columns: 1fr 1fr;} }

    .card {background:#fff; border:1px solid #eee; border-radius:12px; padding:16px;}

    .grid {display:grid; gap:10px;}
    .divider {height:1px; background:#eee; margin:12px 0;}
    .muted {color:#666; text-align:center;}

    /* --------- Centralizar FORMULÁRIOS --------- */
    #tab-mesa .label{ display:block; text-align:center; font-weight:800; margin-bottom:12px; }
    #tab-mesa .inline{
      justify-content:center !important;
      align-items:center;
      gap:10px;
    }
    #tab-mesa .inline input[type="number"]{
      flex: 0 1 220px;
      max-width: 260px;
      text-align:center;
      font-size:1rem;
      height:46px;
    }
    #tab-mesa .inline .btn{
      flex: 0 0 auto;
      height:46px;
      padding:0 18px;
      font-weight:800;
      border-radius:10px;
    }

    #tab-cardapio .inline{
      justify-content:center !important;
      gap:14px;
      text-align:center;
    }
    #tab-cardapio .inline > div{ font-size:1.05rem; }
    #tab-cardapio #nm-pagar-pix{
      min-width: 180px;
      height:46px;
      border-radius:10px;
      font-weight:800;
      box-shadow: 0 10px 22px rgba(0,166,80,.18);
    }

    #mesa-view{
      display:flex; align-items:center; justify-content:center;
      min-height: 42vh; padding: 8px;
    }
    #mesa-view .mesa-card{ width:min(680px,100%); margin:0 auto; }
    #mesa-view .products-grid{
      display:grid; grid-template-columns:repeat(auto-fill, minmax(64px, 1fr)); gap:8px;
    }
    @media (max-width:420px){
      #mesa-view .products-grid{ grid-template-columns:repeat(auto-fill, minmax(56px, 1fr)); }
    }
    .mesa-actions {flex-wrap:wrap}
    .mesa-actions .btn {flex:1 1 180px}

    .modal-content.modal-produtos{ max-height:92dvh; display:flex; flex-direction:column; overflow:hidden; width:min(980px,95vw); }
    .modal-content.modal-produtos .modal-body{ flex:1 1 auto; overflow:auto; -webkit-overflow-scrolling:touch; padding-bottom:12px; }
    .modal-content { width:calc(100vw - 24px); max-width:520px }
    @media (min-width:768px){ .modal-content { width:min(520px, 90vw) } }

    .pm-grid{ display:grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap:10px }
    @media (max-width: 420px){ .pm-grid{ grid-template-columns: repeat(auto-fill, minmax(104px, 1fr)) } }

    .pm-price, .pm-stock {
      position:absolute; top:8px; background:rgba(0,0,0,.7); color:#fff;
      padding:2px 6px; border-radius:6px; font-size:.8rem; font-weight:600;
    }
    .pm-price{left:8px} .pm-stock{right:8px}

    #pix-modal .modal-content{ max-width:520px; width:calc(100vw - 32px); max-height:calc(100vh - 32px); overflow:auto; }
    #pix-qr-code{display:flex;justify-content:center;align-items:center;margin:12px 0;}
    #pix-qr-code .pix-qr-img{display:block;width:min(360px,80vw);height:min(360px,55vh);object-fit:contain;image-rendering:pixelated;}
    #pix-status{font-weight:700;}

    #tab-cardapio.card > .grid { flex: 1 1 auto !important; overflow-y: auto !important; -webkit-overflow-scrolling: touch; padding-right: 6px; }
    #tab-cardapio.card > .inline { flex: 0 0 auto !important; position: sticky !important; bottom: 0 !important; background: #fff !important; padding-top: 8px !important; margin-top: 8px !important; border-top: 1px solid #eee !important; }
    #tab-cardapio #nm-grid.pm-grid { display: grid !important; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)) !important; gap: 10px !important; max-height: none !important; }
    @media (max-width: 420px) { #tab-cardapio #nm-grid.pm-grid { grid-template-columns: repeat(auto-fill, minmax(104px, 1fr)) !important; } }

    #produtos-modal .modal-content.modal-produtos { max-height: 92dvh !important; width: min(980px, 95vw) !important; display: flex !important; flex-direction: column !important; overflow: hidden !important; }
    #produtos-modal .modal-content.modal-produtos .modal-body { flex: 1 1 auto !important; overflow-y: auto !important; -webkit-overflow-scrolling: touch; padding-bottom: 12px !important; }
    #produtos-modal .pm-grid { display: grid !important; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)) !important; gap: 10px !important; }
    @media (max-width: 420px) { #produtos-modal .pm-grid { grid-template-columns: repeat(auto-fill, minmax(104px, 1fr)) !important; } }
    .pm-price, .pm-stock {
      position: absolute; top: 8px; background: rgba(14, 176, 71, 0.7);
      color: #fff; padding: 2px 6px; border-radius: 6px; font-size: .8rem; font-weight: 600;
    }
    .pm-price { left: 8px } .pm-stock { right: 8px }
  </style>
</head>
<body class="clientes-mode">

<?php include __DIR__ . '/../views/partials/header.php'; ?>

<div class="container">
  <div class="tabs">
    <button class="tab-btn active" data-tab="tab-mesa">Ver Mesa</button>
    <button class="tab-btn" data-tab="tab-cardapio">Cardápio (novo pedido)</button>
  </div>

  <div class="tabs">
    <!-- TAB 1: VER MESA -->
    <section id="tab-mesa" class="card">
      <label class="label">Digite o número da mesa</label>
      <div class="inline">
        <input id="inp-mesa-numero" type="number" min="1" placeholder="Ex.: 12" />
        <button id="btn-carregar-mesa" class="btn btn-primary" style="max-width:180px;">Carregar</button>
      </div>

      <div id="mesa-view" style="margin-top:16px;">
        <div class="muted">Nenhuma mesa carregada ainda.</div>
      </div>
    </section>

    <!-- TAB 2: CARDÁPIO (NOVO PEDIDO) -->
    <section id="tab-cardapio" class="card" style="display:none;">
      <div class="grid">
        <div class="muted">Toque nos itens para adicionar ao carrinho e gere o PIX.</div>
        <div id="nm-grid" class="pm-grid"></div>
        <div id="nm-msg" class="pm-msg" style="display:none;"></div>
      </div>
      <div class="divider"></div>
      <div class="inline" style="justify-content:space-between; align-items:center;">
        <div style="font-weight:700;">Total: <span id="nm-total">R$ 0,00</span></div>
        <button id="nm-pagar-pix" class="btn btn-primary" disabled>Gerar PIX</button>
      </div>
    </section>
  </div>
</div>

<!-- Modal: Produtos (adicionar em uma mesa já aberta) -->
<div id="produtos-modal" class="modal" style="display:none;">
  <div class="modal-content modal-produtos">
    <div class="modal-header">
      <h3>Adicionar produtos à <span id="pm-mesa-label"></span></h3>
      <span class="close pm-close" role="button" aria-label="Fechar">&times;</span>
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

<!-- Modal PIX -->
<div id="pix-modal" class="modal" style="display:none;">
  <div class="modal-content">
    <button class="close" aria-label="Fechar">×</button>
    <h3>Pagamento PIX</h3>
    <p><strong id="pix-mesa">Mesa</strong></p>
    <p>Valor: <strong id="pix-valor">R$ 0,00</strong></p>

    <div id="pix-qr-code" style="margin:12px 0; text-align:center;"></div>

    <div class="pix-copy">
      <label for="pix-code-text">Código copia-e-cola</label>
      <textarea id="pix-code-text" rows="3" readonly style="width:100%; box-sizing:border-box;"></textarea>
      <button id="btn-copy-pix" type="button" class="btn btn-secondary" style="margin-top:8px;">Copiar</button>
    </div>

    <div class="pix-status" style="margin-top:8px;">
      Status: <span id="pix-status">Aguardando pagamento...</span>
    </div>

    <div class="modal-actions" style="margin-top:12px; display:flex; gap:8px; justify-content:flex-end;">
      <button id="cancel-pix-btn" class="btn btn-secondary">Fechar</button>
      <button id="verify-payment-btn" class="btn btn-primary">Verificar agora</button>
    </div>
  </div>
</div>

<!-- Sons -->
<audio id="notification-sound" preload="auto">
  <source src="assets/media/audio.mp3" type="audio/mpeg">
</audio>
<audio id="pix-sound" preload="auto">
  <source src="assets/media/pix.mp3" type="audio/mpeg">
</audio>

<!-- jQuery + JS da página -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="assets/js/clientes.js"></script>

<!-- ===== Fallbacks/Complementos: Toast + Som + Polling 2s (só se o clientes.js não definiu) ===== -->
<script>
/* Helpers simples */
function esc(s){return String(s||'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}
function moneyBR(v){return Number(v||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});}

/* Toast verde reutilizável (define se não existir) */
if (typeof window.toastOk !== 'function') {
  window.toastOk = function () {
    if ($('#toast-ok').length === 0) {
      $('body').append(
        '<div id="toast-ok" style="position:fixed;right:16px;top:16px;z-index:9999;background:#28a745;color:#fff;padding:12px 16px;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,.15);display:none;font-weight:600">Pagamento aprovado!</div>'
      );
    }
    $('#toast-ok').stop(true, true).fadeIn(150).delay(2500).fadeOut(400);
  };
}

/* Som de pagamento (define se não existir) */
if (typeof window.playPaymentSound !== 'function') {
  window.playPaymentSound = function () {
    const elPix = document.getElementById('pix-sound');
    const elGen = document.getElementById('notification-sound');
    const el = elPix || elGen;
    if (el) {
      const p = el.play();
      if (p && typeof p.then === 'function') p.catch(()=>{});
    } else {
      try {
        const ctx = new (window.AudioContext||window.webkitAudioContext)();
        const osc = ctx.createOscillator(), g = ctx.createGain();
        osc.type='sine'; osc.frequency.value=880; g.gain.value=0.15;
        osc.connect(g); g.connect(ctx.destination);
        osc.start(); setTimeout(()=>{osc.stop(); ctx.close();}, 300);
      } catch(e){}
    }
  };
}

/* Polling de PIX a cada 2 segundos (se não existir ainda) */
(function(){
  if (typeof window.startPixCheck === 'function' && typeof window.checkPixStatus === 'function') {
    // já definido pelo clientes.js — apenas reforça o intervalo de 2s se for 3s
    // (não forçamos override aqui para evitar conflitos)
    return;
  }

  let pollInterval = null;
  window.pixPaymentId = null;
  window.pixPedidoId  = null;

  window.startPixCheck = function (paymentId, pedidoId) {
    window.pixPaymentId = paymentId;
    window.pixPedidoId  = pedidoId || null;
    $('#pix-status').text('Aguardando pagamento...');
    if (pollInterval) clearInterval(pollInterval);
    checkPixStatus();
    pollInterval = setInterval(checkPixStatus, 2000); // 2s
  };

  window.checkPixStatus = function () {
    if (!window.pixPaymentId) return;
    $.ajax({
      url: 'verificar_pagamento_pix.php',
      type: 'GET',
      dataType: 'json',
      data: { pagamento_id: window.pixPaymentId, pedido_id: window.pixPedidoId }
    }).done(function (r) {
      if (!r || !r.success) return;
      const st = String(r.status || '').toLowerCase();
      $('#pix-status').text(st);

      if (st === 'approved') {
        if (pollInterval) clearInterval(pollInterval);

        // Som + Toast + “placa” verde
        playPaymentSound();
        toastOk();

        const $qr = $('#pix-qr-code img.pix-qr-img');
        if ($qr.length) {
          const w = $qr.width(), h = $qr.height();
          $('#pix-qr-code').html(
            `<div id="pix-ok-box" style="width:${w}px;height:${h}px;display:flex;align-items:center;justify-content:center;background:#28a745;color:#fff;font-size:2rem;font-weight:bold;border-radius:6px">Pagamento efetuado.</div>`
          );
        }

        // Fecha e atualiza a mesa carregada (se houver)
        setTimeout(function(){
          $('#pix-modal').fadeOut(120);
          const num = parseInt($('#inp-mesa-numero').val(), 10);
          if (num) carregarMesaPorNumero(num, true);
        }, 2000);

      } else if (['rejected','cancelled','expired','refunded'].includes(st)) {
        if (pollInterval) clearInterval(pollInterval);
      }
    });
  };

  // Botões de “verificar agora”
  $(document).on('click', '#verify-payment-btn, #btn-verificar-pix, #verificar-pix, #verify-now, #btn-verify-now', function(){
    if (pollInterval) clearInterval(pollInterval);
    checkPixStatus();
  });

  // Fechar modal PIX para o polling
  $(document).on('click', '.close, #cancel-pix-btn', function(){
    if (pollInterval) clearInterval(pollInterval);
  });
})();
</script>

<!-- Interações básicas da página (tabs + carregar mesa + cardápio) -->
<script>
/* Tabs */
$(document).on('click', '.tab-btn', function () {
  const tab = $(this).data('tab');
  $('.tab-btn').removeClass('active');
  $(this).addClass('active');

  if (tab === 'tab-mesa') {
    $('#tab-mesa').show();
    $('#tab-cardapio').hide();
  } else {
    $('#tab-mesa').hide();
    $('#tab-cardapio').show();
    if (typeof carregarCardapio === 'function') carregarCardapio();
  }
});

/* Render da mesa (mantém o mesmo visual do seu clientes.js) */
function renderMesa(m) {
  if (!m) {
    $('#mesa-view').html('<div class="muted">Mesa não encontrada.</div>');
    return;
  }
  const itensHtml = (m.itens || []).map(it => {
    const qtd = Math.max(1, Number(it.quantidade || 1));
    const img = it.imagem ? `<img src="${esc(it.imagem)}" alt="${esc(it.nome)}">` : `<div class="noimg">sem<br>imagem</div>`;
    let thumbs = '';
    for (let i = 0; i < qtd; i++) {
      thumbs += `
        <div class="prod-thumb" data-prod-id="${it.produto_id}" title="${esc(it.nome)}">
          <span class="price-badge">R$ ${moneyBR(it.preco_unitario)}</span>
          ${img}
        </div>`;
    }
    return thumbs;
  }).join('');

  const canPix = !!m.pedido_id && Number(m.total) > 0;

  const card = `
    <div class="mesa-card mesa-card--simple" data-mesa-id="${m.mesa_id || ''}" data-pedido-id="${m.pedido_id || ''}">
      <div class="mesa-head">
        <div class="mesa-pill">Mesa ${esc(m.mesa_numero)}</div>
        <div class="mesa-total">R$ ${moneyBR(m.total || 0)}</div>
      </div>
      <div class="products-grid">${itensHtml || '<div class="muted">Sem itens.</div>'}</div>
      <div class="mesa-actions">
        <button class="btn btn-secondary abrir-produtos-btn"
                data-mesa-id="${m.mesa_id || ''}"
                data-pedido-id="${m.pedido_id || ''}"
                data-mesa-numero="${esc(m.mesa_numero)}">
          Adicionar produtos
        </button>
        <button class="btn btn-primary pagar-pix-mesa-btn"
                data-mesa-numero="${esc(m.mesa_numero)}"
                data-pedido-id="${m.pedido_id || ''}"
                data-total="${Number(m.total || 0)}"
                ${canPix ? '' : 'disabled'}>
          Pagar via PIX
        </button>
      </div>
    </div>
  `;
  $('#mesa-view').html(card);
}

/* Carregar mesa por número + auto-refresh leve (a cada 3s para UI; PIX é 2s) */
let mesaRefreshTimer = null;
let mesaRefreshNumeroAtual = null;

function stopMesaAutoRefresh() {
  if (mesaRefreshTimer) {
    clearInterval(mesaRefreshTimer);
    mesaRefreshTimer = null;
  }
}

function carregarMesaPorNumero(numero, silent) {
  if (!silent) $('#mesa-view').html('<div class="muted">Carregando...</div>');
  $.getJSON('clientes.php', { ajax: 1, op: 'mesa_by_numero', numero: numero })
    .done(function (r) {
      if (!r || !r.success) {
        $('#mesa-view').html('<div class="muted">Mesa não encontrada.</div>');
        stopMesaAutoRefresh(); mesaRefreshNumeroAtual = null; return;
      }
      renderMesa(r.mesa);

      mesaRefreshNumeroAtual = numero;
      stopMesaAutoRefresh();
      mesaRefreshTimer = setInterval(function () {
        if (mesaRefreshNumeroAtual) {
          $.getJSON('clientes.php', { ajax: 1, op: 'mesa_by_numero', numero: mesaRefreshNumeroAtual })
            .done(function (rr) {
              if (rr && rr.success) renderMesa(rr.mesa);
            });
        }
      }, 3000);
    }).fail(function () {
      $('#mesa-view').html('<div class="muted">Falha ao carregar mesa.</div>');
      stopMesaAutoRefresh();
      mesaRefreshNumeroAtual = null;
    });
}

/* UI: botões principais */
$('#btn-carregar-mesa').on('click', function () {
  const n = parseInt($('#inp-mesa-numero').val(), 10);
  if (!n || n <= 0) { alert('Informe um número de mesa válido.'); return; }
  stopMesaAutoRefresh();
  mesaRefreshNumeroAtual = null;
  carregarMesaPorNumero(n);
});
$('#inp-mesa-numero').on('keydown', function (e) {
  if (e.key === 'Enter' || e.keyCode === 13) {
    e.preventDefault();
    $('#btn-carregar-mesa').trigger('click');
  }
});

/* Abrir produtos (reaproveita endpoints existentes) */
$(document).on('click', '.abrir-produtos-btn', function () {
  const mesaId     = $(this).data('mesa-id');       // pode estar vazio
  const pedidoId   = $(this).data('pedido-id');     // pode estar vazio
  const mesaNumero = Number($(this).data('mesa-numero') || 0);

  $('#pm-mesa-label').text('Mesa ' + mesaNumero);
  $('#pm-grid').html('<div style="padding:12px;color:#777;">Carregando produtos...</div>');
  $('#pm-msg').hide().text('');
  $('#produtos-modal')
    .data('mesa-id', mesaId || '')
    .data('pedido-id', pedidoId || '')
    .data('mesa-numero', mesaNumero)
    .fadeIn(120);

  $.getJSON('listar_produtos.php', function (resp) {
    if (!resp || !resp.sucesso) {
      $('#pm-grid').empty(); $('#pm-msg').text(resp?.mensagem || 'Erro ao listar.').show(); return;
    }
    const cards = (resp.produtos || []).map(p => {
      const img = p.imagem ? `<img src="${esc(p.imagem)}" alt="${esc(p.nome)}">`
                           : `<div class="pm-noimg">sem imagem</div>`;
      return `
        <div class="pm-card" data-id="${p.id}" data-preco="${p.preco}" data-nome="${esc(p.nome)}" data-img="${p.imagem ? esc(p.imagem) : ''}">
          <span class="pm-price">R$ ${moneyBR(p.preco)}</span>
          <div class="pm-thumb">${img}</div>
          <div class="pm-name" title="${esc(p.nome)}">${esc(p.nome)}</div>
        </div>`;
    }).join('');
    $('#pm-grid').html(cards);
  }).fail(function () {
    $('#pm-grid').empty(); $('#pm-msg').text('Falha ao obter lista de produtos.').show();
  });
});

/* Click de produto no modal: abre pedido se não existir e adiciona item; notifica */
$(document).on('click', '.pm-card', function () {
  const produtoId = Number($(this).data('id'));
  const $modal = $('#produtos-modal');
  let pedidoId = Number($modal.data('pedido-id') || 0);
  const mesaNumero = Number($modal.data('mesa-numero') || 0);

  function addItem(pId) {
    $.post('adicionar_item.php', { pedido_id: pId, produto_id: produtoId }, function (r) {
      if (!r || !r.sucesso) { $('#pm-msg').text(r?.mensagem || 'Não foi possível adicionar.').show(); return; }

      // 🔔 notificação
      $.post('clientes.php?ajax=1', { op: 'notify', pedido_id: pId, mesa_numero: mesaNumero, produto_id: produtoId, quantidade: 1 });

      $('#produtos-modal').fadeOut(120);
      const n = parseInt($('#inp-mesa-numero').val(), 10);
      if (n) carregarMesaPorNumero(n, true);
    }, 'json').fail(function () {
      $('#pm-msg').text('Falha ao comunicar com o servidor.').show();
    });
  }

  if (!pedidoId) {
    $.post('clientes.php?ajax=1', { op: 'open_for_mesa', mesa_numero: mesaNumero }, function (r) {
      if (!r || !r.success || !r.pedido_id) { $('#pm-msg').text('Não foi possível abrir o pedido.').show(); return; }
      pedidoId = Number(r.pedido_id);
      $modal.data('pedido-id', pedidoId);
      addItem(pedidoId);
    }, 'json').fail(function () {
      $('#pm-msg').text('Falha ao abrir pedido automaticamente.').show();
    });
  } else {
    addItem(pedidoId);
  }
});

/* Pagar via PIX (mesa já existente OU abrir primeira vez e puxar total) */
$(document).on('click', '.pagar-pix-mesa-btn', function () {
  let pedidoId   = Number($(this).data('pedido-id') || 0);
  const mesaNumero = Number($(this).data('mesa-numero') || 0);
  let valor      = Number($(this).data('total') || 0);

  function gerarPix(pId, total) {
    if (!pId || total <= 0) { alert('Adicione itens antes de pagar.'); return; }
    $.ajax({
      url: 'gerar_pagamento_pix.php',
      type: 'POST',
      dataType: 'json',
      data: { pedido_id: pId, valor: total }
    }).done(function (pg) {
      if (!pg || !pg.success) { alert(pg?.error || 'Erro ao gerar PIX.'); return; }
      const b64 = (pg.qr_code_base64 || '').replace(/\s+/g, '');
      $('#pix-mesa').text('Mesa ' + mesaNumero);
      $('#pix-valor').text('R$ ' + moneyBR(total));
      $('#pix-qr-code').html('<img class="pix-qr-img" alt="QR Code PIX" src="data:image/png;base64,' + b64 + '">');
      $('#pix-code-text').val(pg.qr_code);
      $('#pix-modal').fadeIn(120);
      if (typeof window.startPixCheck === 'function') {
        window.startPixCheck(pg.pagamento_id, pId);
      }
    }).fail(function () { alert('Falha ao comunicar com o servidor (PIX).'); });
  }

  if (!pedidoId) {
    $.post('clientes.php?ajax=1', { op: 'open_for_mesa', mesa_numero: mesaNumero }, function (r) {
      if (!r || !r.success || !r.pedido_id) { alert('Não foi possível abrir pedido automaticamente.'); return; }
      pedidoId = Number(r.pedido_id);
      // atualiza total antes de gerar o PIX
      $.getJSON('clientes.php', { ajax: 1, op: 'mesa_by_numero', numero: mesaNumero }).done(function (rr) {
        if (rr && rr.success) {
          valor = Number(rr.mesa?.total || 0);
          gerarPix(pedidoId, valor);
        } else {
          alert('Falha ao obter total do pedido.');
        }
      }).fail(function () { alert('Falha ao obter total do pedido.'); });
    }, 'json').fail(function () {
      alert('Falha ao abrir pedido automaticamente.');
    });
  } else {
    gerarPix(pedidoId, valor);
  }
});

/* Botões do modal PIX */
$('#verify-payment-btn').on('click', function () {
  if (typeof window.checkPixStatus === 'function') window.checkPixStatus();
});
$('.close, #cancel-pix-btn').on('click', function () {
  $('#pix-modal').fadeOut(120);
});
</script>

</body>
</html>
