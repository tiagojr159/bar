// ======================= AJUSTE PONTUAL (globais seguras) =======================
window.nmCart  = window.nmCart  || {};
window.nmTotal = window.nmTotal || 0;

window.toNumberBR = window.toNumberBR || function (v) {
  return Number(String(v || '').replace(/[^\d,.-]/g, '').replace(/\./g, '').replace(',', '.')) || 0;
};
// ===============================================================================



// --- Estado do PIX ---
let pixTimer = null;
let pixPollMs = 3500;         // intervalo de verificação
let pixMaxMs = 5 * 60 * 1000; // timeout de 5 min
let pixStartedAt = 0;
let currentPagamentoId = null;
let currentPedidoId = null;

// Chamado depois que gerar_pagamento_pix.php retornar sucesso
function iniciarPix(pedidoId, pagamentoId, valor) {
  currentPedidoId = pedidoId;
  currentPagamentoId = pagamentoId;

  $('#pix-status')
    .removeClass('text-success text-danger')
    .addClass('text-warning')
    .text('Verificando pagamento...');

  // inicia polling
  iniciarPollingPix();
}

function iniciarPollingPix() {
  pararPollingPix(); // limpa lixo anterior
  pixStartedAt = Date.now();

  // dispara uma checagem imediata
  checarPix();

  // e continua em intervalo
  pixTimer = setInterval(() => {
    // timeout de segurança
    if (Date.now() - pixStartedAt > pixMaxMs) {
      pararPollingPix();
      $('#pix-status')
        .removeClass('text-warning text-success').addClass('text-danger')
        .text('timeout');
      return;
    }
    checarPix();
  }, pixPollMs);
}

function pararPollingPix() {
  if (pixTimer) {
    clearInterval(pixTimer);
    pixTimer = null;
  }
}

function checarPix() {
  if (!currentPagamentoId && !currentPedidoId) return;
  $.getJSON('verificar_pagamento_pix.php', {
    pagamento_id: currentPagamentoId || undefined,
    pedido_id: currentPedidoId || undefined
  })
    .done(function (res) {
      if (!res || res.success !== true) return;
      const st = (res.status || '').toLowerCase();
      $('#pix-status').text(st);

      if (st === 'approved') {
        pararPollingPix();
        tocarSomPagamento();

        // Fecha a mesa no backend (se você NÃO marcou pago dentro do verificar_pagamento_pix.php)
        $.post('fechar_mesa.php', {
          pedido_id: currentPedidoId,
          forma_pagamento: 'pix'
        }).always(function () {
          // feedback visual e recarregar lista
          $('#pix-status')
            .removeClass('text-warning text-danger')
            .addClass('text-success')
            .text('pago');

          $('#payment-modal').hide();

          if (typeof carregarMesas === 'function') {
            carregarMesas();
          } else {
            location.reload();
          }
        });
      } else if (st === 'rejected' || st === 'cancelled' || st === 'refunded') {
        pararPollingPix();
        $('#pix-status')
          .removeClass('text-warning text-success')
          .addClass('text-danger')
          .text(st);
      } else {
        $('#pix-status')
          .removeClass('text-success text-danger')
          .addClass('text-warning');
      }
    });
}

// Botão "Verificar agora"
$('#btn-verificar-pix, #verificar-pix, #verify-now, #btn-verify-now').on('click', function () {
  checarPix();
});

// Fechar modal interrompe polling
$('.close, #cancel-payment-btn, #btn-fechar-pix').on('click', function () {
  pararPollingPix();
});

// Som do pagamento (toca 1x)
let jaTocouSom = false;
function tocarSomPagamento() {
  if (jaTocouSom) return;
  jaTocouSom = true;

  const audio = new Audio('assets/media/audio.mp3');
  audio.currentTime = 0;
  audio.play().catch(() => {
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      const osc = ctx.createOscillator();
      osc.frequency.value = 880;
      osc.connect(ctx.destination);
      osc.start();
      setTimeout(() => { osc.stop(); ctx.close(); }, 300);
    } catch (e) { }
  });
}









/* ===========================
   Utils globais (no topo)
   =========================== */
window.toNumber = function (v) {
  if (v == null) return 0;
  if (typeof v === 'number') return v;
  const cleaned = String(v).replace(/[^\d,.-]/g, '').replace(/\./g, '').replace(',', '.');
  const n = parseFloat(cleaned);
  return Number.isNaN(n) ? 0 : n;
};

window.formatMoney = function (v) {
  return window.toNumber(v).toLocaleString('pt-BR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });
};

// Conserta valores que vierem em centavos (ex.: 2400 -> 24.00)
window.normalizeValor = function (vRaw) {
  const v = Number(vRaw);
  if (!Number.isFinite(v)) return 0;
  if (Number.isInteger(v) && v >= 1000) return v / 100;
  return v;
};

/* ===========================
   Pagamento / Fechar Mesa
   =========================== */
$(function () {
  // Estado atual (também espelhado no window para compatibilidade)
  let currentPedidoId = null;
  let currentMesa = null;
  let currentValor = 0;
  let currentValorCobrar = 0;
  let pollInterval = null;
  window.currentPedidoId = currentPedidoId;
  window.currentMesa = currentMesa;
  window.currentValor = currentValor;

  // ---------- Abrir modal de pagamento ----------
  $(document)
    .off('click.fecharMesa', '.fechar-mesa-btn')
    .on('click.fecharMesa', '.fechar-mesa-btn', function (e) {
      e.preventDefault(); e.stopPropagation();

      const $btn = $(this);
      currentPedidoId = Number($btn.data('pedido-id'));
      currentMesa = $btn.data('mesa');

      const bruto = $btn.data('valor');
      currentValor = window.normalizeValor(bruto);
      currentValorCobrar = currentValor;

      window.currentPedidoId = currentPedidoId;
      window.currentMesa = currentMesa;
      window.currentValor = currentValor;

      $('#payment-mesa').text('Mesa ' + currentMesa);
      $('#payment-valor').text(window.formatMoney(currentValor));
      $('#valor-cobrar').val(window.formatMoney(currentValor));

      const $modal = $('#payment-modal');
      $modal.find('input[name="payment-method"]').prop('checked', false);
      $modal.find('.payment-form').hide();

      $modal.fadeIn(150);
    });

  // ---------- Fechar modal de pagamento ----------
  $(document)
    .off('click.closePay', '.close, #cancel-payment-btn')
    .on('click.closePay', '.close, #cancel-payment-btn', function (e) {
      e.preventDefault();
      $('#payment-modal').fadeOut(120);
    });

  // ---------- Troca de forma de pagamento ----------
  $(document).on('change', 'input[name="payment-method"]', function () {
    $('.payment-form').hide();
    const v = $(this).val();
    if (v === 'pix') $('#pix-payment-form').show();
    else if (v === 'dinheiro') $('#dinheiro-payment-form').show();
    else if (v === 'cartao') $('#cartao-payment-form').show();
  });

  // ---------- Campo "valor a cobrar" ----------
  $('#valor-cobrar').off('input.cobrar').on('input.cobrar', function () {
    let raw = $(this).val().replace(/\D/g, '');
    raw = (raw / 100).toFixed(2) + '';
    raw = raw.replace('.', ',').replace(/(\d)(?=(\d{3})+,)/g, '$1.');
    $(this).val(raw);

    const parsed = window.toNumber($(this).val());
    currentValorCobrar = Number.isFinite(parsed) ? parsed : currentValor;

    const recebido = window.toNumber($('#valor-recebido').val());
    const troco = Math.max(0, recebido - currentValorCobrar);
    $('#troco').text(troco.toFixed(2).replace('.', ','));
  });

  // ---------- Campo "valor recebido" ----------
  $('#valor-recebido').off('input.valor').on('input.valor', function () {
    let raw = $(this).val().replace(/\D/g, '');
    raw = (raw / 100).toFixed(2) + '';
    raw = raw.replace('.', ',').replace(/(\d)(?=(\d{3})+,)/g, '$1.');
    $(this).val(raw);

    const base = currentValorCobrar || currentValor || 0;
    const recebido = window.toNumber($(this).val());
    const troco = Math.max(0, recebido - base);
    $('#troco').text(troco.toFixed(2).replace('.', ','));
  });

  // ---------- Confirmar pagamento ----------
  $('#confirm-payment-btn').off('click.confirmPay').on('click.confirmPay', function () {
    const valorParaCobrar = window.toNumber($('#valor-cobrar').val());

    if ((valorParaCobrar || 0) <= 0) {
      $.ajax({
        url: 'fechar_mesa.php',
        type: 'POST',
        data: { pedido_id: currentPedidoId, forma_pagamento: 'cortesia', valor_cobrado: 0 },
        dataType: 'json'
      }).done(function (r) {
        if (r && r.success) {
          $('#payment-modal').hide();
          const $card = $(`.mesa-card[data-pedido-id="${currentPedidoId}"]`);
          if ($card.length) $card.fadeOut(300, function () { $(this).remove(); });
          else location.reload();
        } else {
          alert('Erro ao fechar mesa (zero): ' + (r?.error || 'desconhecido'));
        }
      }).fail(function () {
        alert('Erro ao comunicar com o servidor.');
      });
      return;
    }

    const paymentMethod = $('input[name="payment-method"]:checked').val();
    if (!paymentMethod) { alert('Selecione a forma de pagamento.'); return; }

    if (paymentMethod === 'pix') {
      $.ajax({
        url: 'gerar_pagamento_pix.php',
        type: 'POST',
        data: { pedido_id: currentPedidoId, valor: valorParaCobrar },
        dataType: 'json'
      }).done(function (resp) {
        if (resp && resp.success) {
          $('#payment-modal').hide();

          $('#pix-mesa').text('Mesa ' + currentMesa);
          $('#pix-valor').text(window.formatMoney(valorParaCobrar));
          const b64 = (resp.qr_code_base64 || '').replace(/\s+/g, '');
          $('#pix-qr-code').html('<img class="pix-qr-img" alt="QR Code PIX" src="data:image/png;base64,' + b64 + '">');
          $('#pix-code-text').val(resp.qr_code);
          $('#pix-modal').fadeIn(120);

          if (typeof window.startPixCheck === 'function') {
            window.startPixCheck(resp.pagamento_id, currentPedidoId);
          }
        } else {
          alert('Erro ao gerar pagamento PIX: ' + (resp?.error || 'desconhecido'));
        }
      }).fail(function () {
        alert('Erro ao comunicar com o servidor.');
      });

    } else if (paymentMethod === 'dinheiro') {
      const valorRecebido = window.toNumber($('#valor-recebido').val());

      $.ajax({
        url: 'fechar_mesa.php',
        type: 'POST',
        data: {
          pedido_id: currentPedidoId,
          forma_pagamento: 'dinheiro',
          valor_recebido: valorRecebido,
          valor_cobrado: valorParaCobrar
        },
        dataType: 'json'
      }).done(function (r) {
        if (r && r.success) {
          $('#payment-modal').hide();
          location.reload();
        } else {
          alert('Erro ao fechar mesa: ' + (r?.error || 'desconhecido'));
        }
      }).fail(function () {
        alert('Erro ao comunicar com o servidor.');
      });

    } else if (paymentMethod === 'cartao') {
      $.ajax({
        url: 'fechar_mesa.php',
        type: 'POST',
        data: { pedido_id: currentPedidoId, forma_pagamento: 'cartao', valor_cobrado: valorParaCobrar },
        dataType: 'json'
      }).done(function (r) {
        if (r && r.success) {
          $('#payment-modal').hide();
          location.reload();
        } else {
          alert('Erro ao fechar mesa: ' + (r?.error || 'desconhecido'));
        }
      }).fail(function () {
        alert('Erro ao comunicar com o servidor.');
      });
    }
  });



  // ---------- PIX: áudio/feedback e polling ----------
  function playNotificationSound() {
    const el = document.getElementById('notification-sound');
    if (el) {
      const p = el.play();
      if (p && typeof p.then === 'function') {
        p.then(() => { }).catch(() => { tryAlt(); });
      }
      return;
    }
    tryAlt();

    function tryAlt() {
      try {
        const apito = new Audio('assets/media/audio.mp3');
        apito.currentTime = 0;
        apito.play().catch(() => { oscillatorFallback(); });
      } catch { oscillatorFallback(); }
    }
    function oscillatorFallback() {
      try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator(); const gain = ctx.createGain();
        osc.type = 'sine'; osc.frequency.value = 880;
        gain.gain.value = 0.1; osc.connect(gain); gain.connect(ctx.destination);
        osc.start(); setTimeout(() => osc.stop(), 900);
      } catch { }
    }
  }

  window.pixPaymentId = null;
  window.pixPedidoId = null;

  window.startPixCheck = function (paymentId, pedidoId) {
    window.pixPaymentId = paymentId;
    window.pixPedidoId = pedidoId || window.currentPedidoId || null;

    $('#pix-status').text('Aguardando pagamento...');
    $('.pix-status').removeClass('approved rejected');

    if (pollInterval) clearInterval(pollInterval);

    checkPixStatus(window.pixPaymentId, window.pixPedidoId);
    pollInterval = setInterval(function () {
      checkPixStatus(window.pixPaymentId, window.pixPedidoId);
    }, 2000);
  };

  window.checkPixStatus = function (paymentId, pedidoId) {
    paymentId = paymentId || window.pixPaymentId;
    pedidoId = pedidoId || window.pixPedidoId;
    if (!paymentId) return;

    $.ajax({
      url: 'verificar_pagamento_pix.php',
      type: 'GET',
      data: { pagamento_id: paymentId, pedido_id: pedidoId },
      dataType: 'json'
    }).done(function (resp) {
      if (!resp || !resp.success) return;

      const st = String(resp.status || '').toLowerCase();
      $('#pix-status').text(st);

      if (st === 'approved') {
        if (pollInterval) clearInterval(pollInterval);
        $('.pix-status').addClass('approved');
        $('#pix-status').text('Pagamento aprovado!');

        const a = new Audio('assets/media/audio.mp3');
        a.currentTime = 0;
        a.play().catch(() => { });

        if ($('#toast-ok').length === 0) {
          $('body').append('<div id="toast-ok" style="position:fixed;right:16px;top:16px;z-index:9999;background:#28a745;color:#fff;padding:12px 16px;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,.15);display:none;font-weight:600">Pagamento aprovado!</div>');
        }
        $('#toast-ok').stop(true, true).fadeIn(150).delay(2500).fadeOut(400);

        const $qr = $('#pix-qr-code img.pix-qr-img');
        if ($qr.length) {
          const w = $qr.width();
          const h = $qr.height();
          play_pix();
          $('#pix-qr-code').html(
            `<div id="pix-ok-box" style="width:${w}px;height:${h}px;display:flex;align-items:center;justify-content:center;background:#28a745;color:#fff;font-size:2rem;font-weight:bold;border-radius:6px">Pagamento efetuado.</div>`
          );
        }

        const pid = pedidoId || window.currentPedidoId;
        setTimeout(function () {
          $('#pix-modal').hide();
          if (pid) {
            $(`.mesa-card[data-pedido-id="${pid}"]`).fadeOut(300, function () { $(this).remove(); });
          }
        }, 8000);

        return;
      } else if (['rejected', 'cancelled', 'expired'].includes(st)) {
        if (pollInterval) clearInterval(pollInterval);
        $('.pix-status').addClass('rejected');
        $('#pix-status').text(st === 'expired' ? 'Expirado' : 'Pagamento não aprovado');
      }
    });
  };


  // botão manual
  $('#verify-payment-btn').off('click.verifyPix').on('click.verifyPix', function () {
    if (pollInterval) clearInterval(pollInterval);
    checkPixStatus();
  });

  // Fechar modal PIX: limpar intervalo
  $(document)
    .off('click.closePix', '.close, #cancel-pix-btn')
    .on('click.closePix', '.close, #cancel-pix-btn', function (e) {
      e.preventDefault();
      $('#pix-modal').fadeOut(120);
      if (pollInterval) clearInterval(pollInterval);
    });
});

/* ======================================================
   Catálogo / Produtos / Abrir mesa (mantido e unificado)
   ====================================================== */
(function ($) {
  // Abrir modal do catálogo para ADICIONAR PRODUTOS na mesa corrente
  $(document).on('click', '.abrir-produtos-btn', function () {
    const mesaId = $(this).data('mesa-id');
    const pedidoId = $(this).data('pedido-id');
    const mesaNumero = $(this).data('mesa-numero');
    window.abrirModalPedido(mesaId, pedidoId, mesaNumero);
  });

  // Modal de produtos (lista + adicionar item)
  window.abrirModalPedido = function (mesaId, pedidoId, mesaNumero) {
    $('#pm-mesa-label').text('Mesa ' + mesaNumero);
    $('#pm-msg').hide().text('');
    $('#pm-grid').html('<div style="padding:12px;color:#777;">Carregando produtos...</div>');

    $('#produtos-modal').data('mesa-id', mesaId);
    $('#produtos-modal').data('pedido-id', pedidoId);

    $('#produtos-modal').fadeIn(120);

    $.getJSON('listar_produtos.php', function (resp) {
      if (!resp || !resp.sucesso) {
        $('#pm-grid').html('');
        $('#pm-msg').text(resp?.mensagem || 'Erro ao listar produtos.').show();
        return;
      }

      const produtos = resp.produtos || [];
      if (produtos.length === 0) {
        $('#pm-grid').html('<div style="padding:12px;color:#777;">Nenhum produto cadastrado.</div>');
        return;
      }

      const cards = produtos.map(p => {
        const img = p.imagem
          ? `<img src="${escapeHtml(p.imagem)}" alt="${escapeHtml(p.nome)}">`
          : `<div class="pm-noimg">sem imagem</div>`;
        return `
          <div class="pm-card" data-id="${p.id}" data-preco="${p.preco}" data-nome="${escapeHtml(p.nome)}" data-img="${p.imagem ? escapeHtml(p.imagem) : ''}">
            <div class="pm-thumb">${img}<span class="pm-price">R$ ${window.formatMoney(p.preco)}</span></div>
            <div class="pm-name" title="${escapeHtml(p.nome)}">${escapeHtml(p.nome)}</div>
          </div>
        `;
      }).join('');

      $('#pm-grid').html(cards);

      // Clique no produto => adiciona item na mesa
      $('.pm-card').off('click.addIt').on('click.addIt', function () {
        const produtoId = Number($(this).data('id'));
        const pedidoId = Number($('#produtos-modal').data('pedido-id'));
        const nome = String($(this).data('nome') || '');
        const imgUrl = String($(this).data('img') || '');

        $.post('adicionar_item.php', { pedido_id: pedidoId, produto_id: produtoId }, function (r) {
          if (!r || !r.sucesso) {
            $('#pm-msg').text(r?.mensagem || 'Não foi possível adicionar o item.').show();
            return;
          }

          $('#produtos-modal').fadeOut(120);

          const $mesaCard = $(`.mesa-card[data-pedido-id="${pedidoId}"]`);
          const $gridMesa = $mesaCard.find('.products-grid');
          $mesaCard.find('.mesa-total').text('R$ ' + window.formatMoney(r.total));

          let $thumb = $gridMesa.find(`.prod-thumb[data-prod-id="${produtoId}"]`);
          if ($thumb.length) {
            let $badge = $thumb.find('.qty-badge');
            if ($badge.length) {
              const val = parseInt($badge.text().replace('x', ''), 10) || 1;
              $badge.text('x' + (val + 1));
            } else {
              $thumb.append('<span class="qty-badge">x2</span>');
            }
          } else {
            const img = imgUrl
              ? `<img src="${escapeHtml(imgUrl)}" alt="${escapeHtml(nome)}">`
              : `<div class="noimg">sem<br>imagem</div>`;
            $gridMesa.append(
              `<div class="prod-thumb" data-prod-id="${produtoId}" title="${escapeHtml(nome)}">
                 ${img}
                 <span class="qty-badge">x1</span>
               </div>`
            );
          }
        }, 'json');
      });

    }).fail(function () {
      $('#pm-grid').html('');
      $('#pm-msg').text('Falha ao obter a lista de produtos.').show();
    });
  };

  // Fechar modal do catálogo
  $(document).on('click', '.pm-close', function () {
    $('#produtos-modal').fadeOut(120);
  });

  // Remover 1 unidade do produto (miniatura)
  $(document).on('click', '.remove-item-btn', function () {
    const pedidoId = $(this).data('pedido-id');
    const prodId = $(this).data('prod-id');
    const nomeProduto = $(this).closest('.prod-thumb').attr('title') || 'este item';

    if (!window.confirm(`Deseja remover ${nomeProduto} da mesa?`)) return;

    $.ajax({
      url: 'remover_item.php',
      type: 'POST',
      dataType: 'json',
      data: { pedido_id: pedidoId, produto_id: prodId }
    }).done(function (resp) {
      if (resp && resp.sucesso) {
        play_audio();
        location.reload();
      } else {
        alert(resp?.mensagem || 'Falha ao remover item');
      }
    }).fail(function () {
      alert('Falha ao comunicar com o servidor.');
    });
  });



  // ======================= AJUSTE PONTUAL (espelhar carrinho novo modal) =======================
  // Ao abrir o modal "Novo pedido": zera globais e DESABILITA todos os 4 botões
  $(document).on('click', '#nova-mesa-btn', function () {
    window.nmCart  = {};
    window.nmTotal = 0;
    $('#am-dinheiro-btn, #am-cartao-btn, #am-pagar-btn, #am-abrir-btn').prop('disabled', true);
  });

  // Ao clicar numa .nm-card (cards do novo modal), espelha nas globais e HABILITA os 3 botões de pagar
  $(document).on('click', '.nm-card', function (e) {
    if ($(e.target).closest('.sel-badge').length) return;
    const $c = $(this);
    const id    = Number($c.data('id'));
    const nome  = String($c.data('nome')  || '');
    const preco = Number($c.data('preco') || 0);
    const img   = String($c.data('img')   || '');

    if (!window.nmCart[id]) window.nmCart[id] = { nome, preco, img, qtd: 0 };
    window.nmCart[id].qtd += 1;
    window.nmTotal = Number(window.nmTotal || 0) + preco;

    // Habilita Dinheiro/Cartão/PIX (o problema era o PIX não ser reabilitado)
    $('#am-dinheiro-btn, #am-cartao-btn, #am-pagar-btn').prop('disabled', false);
  });
  // =============================================================================================



  // Seleção de produtos no catálogo (incrementa no carrinho)
  $(document).on('click', '.am-card', function () {
    const $c = $(this);
    const id = Number($c.data('id'));
    const nome = String($c.data('nome') || '');
    const preco = Number($c.data('preco') || 0);
    const img = String($c.data('img') || '');

    const $modal = $('#abrir-mesa-modal');
    const cart = $modal.data('cart') || {};
    let total = Number($modal.data('total') || 0);

    if (!cart[id]) cart[id] = { qtd: 0, nome, preco, img };
    cart[id].qtd += 1;
    total += preco;

    $modal.data('cart', cart).data('total', total);

    let $badge = $c.find('.sel-badge');
    if ($badge.length) { $badge.text('x' + cart[id].qtd); }
    else { $c.append(`<span class="sel-badge">x${cart[id].qtd}</span>`); }

    $('#am-total').text('R$ ' + window.formatMoney(total));
    $('#am-abrir-btn, #am-pagar-btn').prop('disabled', false);
  });

  // Fechar modal "abrir mesa"
  $(document).on('click', '.am-close', function () {
    $('#abrir-mesa-modal').fadeOut(120);
  });

  // Criar mesa + pedido + itens (apenas abrir)
  $('#am-abrir-btn').on('click', function () {
    const $modal = $('#abrir-mesa-modal');
    const cart = $modal.data('cart') || {};
    if (Object.keys(cart).length === 0) {
      $('#am-msg').text('Selecione ao menos 1 produto.').show();
      return;
    }

    $.ajax({
      url: 'abrir_mesa_e_pedido_com_itens.php',
      type: 'POST',
      data: { cart: JSON.stringify(cart) },
      dataType: 'json'
    }).done(function (resp) {
      if (!resp || !resp.sucesso) {
        $('#am-msg').text(resp?.mensagem || 'Erro ao abrir mesa.').show();
        return;
      }
      $('#abrir-mesa-modal').fadeOut(120);
      injetarMesaCard(resp);
    }).fail(function () {
      $('#am-msg').text('Falha ao comunicar com o servidor.').show();
    });
  });

  // Criar mesa + pedido + itens e já abrir PIX
  $('#am-pagar-btn').on('click', function () {
    const $modal = $('#abrir-mesa-modal');
    const cart = $modal.data('cart') || {};
    if (Object.keys(cart).length === 0) {
      $('#am-msg').text('Selecione ao menos 1 produto.').show();
      return;
    }

    $.ajax({
      url: 'abrir_mesa_e_pedido_com_itens.php',
      type: 'POST',
      data: { cart: JSON.stringify(cart) },
      dataType: 'json'
    }).done(function (resp) {
      if (!resp || !resp.sucesso) {
        $('#am-msg').text(resp?.mensagem || 'Erro ao abrir mesa.').show();
        return;
      }
      $('#abrir-mesa-modal').fadeOut(120);

      const pedidoId = resp.pedido_id;
      const mesaNumero = resp.mesa_numero;
      const valor = Number(resp.total);

      $.ajax({
        url: 'gerar_pagamento_pix.php',
        type: 'POST',
        data: { pedido_id: pedidoId, valor: valor },
        dataType: 'json'
      }).done(function (pg) {
        if (pg && pg.success) {
          $('#pix-mesa').text('Mesa ' + mesaNumero);
          $('#pix-valor').text(window.formatMoney(valor));
          $('#pix-qr-code').html('<img class="pix-qr-img" src="' + pg.qr_code_base64 + '" alt="QR Code PIX">');
          $('#pix-code-text').val(pg.qr_code);
          $('#pix-modal').show();

          const b64pg = (pg.qr_code_base64 || '').replace(/\s+/g, '');
          $('#pix-qr-code').html(
            '<img class="pix-qr-img" alt="QR Code PIX" src="data:image/png;base64,' + b64pg + '">'
          );
          $('#pix-code-text').val(pg.qr_code);
          $('#pix-modal').show();

          if (typeof window.startPixCheck === 'function') {
            window.startPixCheck(pg.pagamento_id, pedidoId);
          }
        } else {
          alert('Erro ao gerar PIX: ' + (pg?.error || 'desconhecido'));
        }
      }).fail(function () {
        alert('Erro ao comunicar com o servidor (PIX).');
      });
    }).fail(function () {
      $('#am-msg').text('Falha ao comunicar com o servidor.').show();
    });
  });

  // Helpers locais
  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, m => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[m]));
  }

  // Injeta/atualiza o card da mesa aberta
  function injetarMesaCard(resp) {
    let $card = $(`.mesa-card[data-pedido-id="${resp.pedido_id}"]`);
    if ($card.length === 0) {
      const itensHtml = (resp.itens || []).map(it => {
        const img = it.imagem
          ? `<img src="${escapeHtml(it.imagem)}" alt="${escapeHtml(it.nome)}">`
          : `<div class="noimg">sem<br>imagem</div>`;
        let thumbs = '';
        for (let i = 0; i < Math.max(1, Number(it.quantidade || 1)); i++) {
          thumbs += `
            <div class="prod-thumb" data-prod-id="${it.produto_id}" title="${escapeHtml(it.nome)}">
              <span class="price-badge">R$ ${window.formatMoney(it.preco)}</span>
              ${img}
              <button class="remove-item-btn" data-pedido-id="${resp.pedido_id}" data-prod-id="${it.produto_id}" title="Remover 1 unidade">🗑️</button>
            </div>`;
        }
        return thumbs;
      }).join('');

      const cardHtml = `
        <div class="mesa-card mesa-card--simple" data-mesa-id="${resp.mesa_id}" data-pedido-id="${resp.pedido_id}">
          <div class="mesa-head">
            <div class="mesa-pill">Mesa ${escapeHtml(resp.mesa_numero)}</div>
            <div class="mesa-total">R$ ${window.formatMoney(resp.total)}</div>
          </div>
          <div class="products-grid">${itensHtml}</div>
          <div class="mesa-actions">
            <button class="btn btn-primary fechar-mesa-btn"
                    data-mesa="${escapeHtml(resp.mesa_numero)}"
                    data-pedido-id="${resp.pedido_id}"
                    data-valor="${Number(resp.total)}">
              Fechar mesa & pagar
            </button>
            <button class="btn btn-secondary abrir-produtos-btn"
                    data-mesa-id="${resp.mesa_id}"
                    data-pedido-id="${resp.pedido_id}"
                    data-mesa-numero="${escapeHtml(resp.mesa_numero)}">
              Adicionar produtos
            </button>
          </div>
        </div>`;
      $('.mesas-grid').prepend(cardHtml);
    } else {
      $card.find('.mesa-total').text('R$ ' + window.formatMoney(resp.total));
    }
  }

  // --- Botão: DINHEIRO (cria pedido + fecha como dinheiro) ---
  $('#am-dinheiro-btn').off('click').on('click', function () {
    const cart  = window.nmCart  || {};
    const total = Number(window.nmTotal || 0);

    if (Object.keys(cart).length === 0) {
      $('#nm-msg').text('Selecione ao menos 1 produto.').show();
      return;
    }

    $.ajax({
      url: 'abrir_mesa_e_pedido_com_itens.php',
      type: 'POST',
      data: { cart: JSON.stringify(cart) },
      dataType: 'json'
    }).done(function (resp) {
      if (!resp || !resp.sucesso) {
        $('#nm-msg').text(resp?.mensagem || 'Erro ao abrir mesa.').show();
        return;
      }

      const pedidoId   = resp.pedido_id;
      const valorTotal = Number(resp.total || total || 0);

      const entradaStr = prompt('Valor recebido em DINHEIRO (ex.: 25,00):',
                                valorTotal.toLocaleString('pt-BR', { minimumFractionDigits: 2 }));
      if (entradaStr === null) return;

      const valorRecebido = window.toNumberBR ? window.toNumberBR(entradaStr) : window.toNumber(entradaStr);

      $.ajax({
        url: 'fechar_mesa.php',
        type: 'POST',
        data: {
          pedido_id: pedidoId,
          forma_pagamento: 'dinheiro',
          valor_recebido: valorRecebido,
          valor_cobrado: valorTotal
        },
        dataType: 'json'
      }).done(function (r) {
        if (r && r.success) {
          $('#nova-mesa-modal').fadeOut(120);
          location.reload();
        } else {
          alert('Erro ao fechar mesa: ' + (r?.error || 'desconhecido'));
        }
      }).fail(function () {
        alert('Erro ao comunicar com o servidor.');
      });

    }).fail(function () {
      $('#nm-msg').text('Falha ao comunicar com o servidor.').show();
    });
  });

  // --- Botão: CARTÃO (cria pedido + fecha como cartão) ---
  $('#am-cartao-btn').off('click').on('click', function () {
    const cart  = window.nmCart  || {};
    const total = Number(window.nmTotal || 0);

    if (Object.keys(cart).length === 0) {
      $('#nm-msg').text('Selecione ao menos 1 produto.').show();
      return;
    }

    $.ajax({
      url: 'abrir_mesa_e_pedido_com_itens.php',
      type: 'POST',
      data: { cart: JSON.stringify(cart) },
      dataType: 'json'
    }).done(function (resp) {
      if (!resp || !resp.sucesso) {
        $('#nm-msg').text(resp?.mensagem || 'Erro ao abrir mesa.').show();
        return;
      }

      const pedidoId   = resp.pedido_id;
      const valorTotal = Number(resp.total || total || 0);

      $.ajax({
        url: 'fechar_mesa.php',
        type: 'POST',
        data: {
          pedido_id: pedidoId,
          forma_pagamento: 'cartao',
          valor_cobrado: valorTotal
        },
        dataType: 'json'
      }).done(function (r) {
        if (r && r.success) {
          $('#nova-mesa-modal').fadeOut(120);
          location.reload();
        } else {
          alert('Erro ao fechar mesa: ' + (r?.error || 'desconhecido'));
        }
      }).fail(function () {
        alert('Erro ao comunicar com o servidor.');
      });

    }).fail(function () {
      $('#nm-msg').text('Falha ao comunicar com o servidor.').show();
    });
  });

})(jQuery);
