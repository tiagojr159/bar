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
            WHERE status = 'aberto'
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
  </style>
</head>

<body>

  <?php include __DIR__ . '/../views/partials/header.php'; ?>

  <div class="container">
    <div class="page-header">
      <h2>Mesas Ativas</h2>
      <button id="nova-mesa-btn" class="btn btn-primary">+ Novo pedido</button>
    </div>

    <div class="mesas-grid">
      <?php if (empty($mesas)): ?>
        <div class="no-mesas">
          <p>Nenhuma mesa ativa no momento.</p>
          <a href="pedidos.php" class="btn">Abrir Novo Pedido</a>
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
        <h3>Selecione os produtos</h3>
        <span class="close nova-mesa-close">&times;</span>
      </div>

      <div class="modal-body">
        <div id="nm-grid" class="pm-grid"></div>
        <div id="nm-msg" class="pm-msg" style="display:none;"></div>
      </div>

      <div class="modal-footer" style="display:flex;gap:8px;align-items:center;justify-content:space-between;">
        <div style="font-weight:700;">Total: <span id="am-total">R$ 0,00</span></div>
        <div style="display:flex;gap:8px;">
          <button id="am-abrir-btn" class="btn btn-secondary" disabled>Abrir mesa</button>
          <button id="am-pagar-btn" class="btn btn-primary" disabled>PIX</button>

          <!-- NOVOS (mínima alteração visual) -->
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
        $('#am-dinheiro-btn, #am-cartao-btn').prop('disabled', false);

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

        // badge de quantidade na imagem
        const $thumb = $c.find('.pm-thumb');
        let $badge = $thumb.find('.sel-badge');
        if ($badge.length) {
          $badge.text('x' + nmCart[id].qtd);
        } else {
          $thumb.append('<span class="sel-badge">x1</span>');
        }

        // atualiza total + habilita botões
        $('#am-total').text('R$ ' + moneyBR(nmTotal));
        $('#am-abrir-btn, #am-pagar-btn, #am-dinheiro-btn, #am-cartao-btn').prop('disabled', false);
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