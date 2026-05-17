/* assets/js/clientes.js
 * Página pública (Clientes): Ver Mesa e Cardápio c/ PIX
 * - Auto-refresh da mesa a cada 3s (apenas UI)
 * - Polling do PIX a cada 2s (som + toast + “placa verde”)
 * - Enter para pesquisar mesa
 * - Notificações de pedido
 * - Abre pedido automaticamente quando necessário (open_for_mesa)
 */

/* ========= Helpers ========= */
function esc(s) {
  return String(s || '').replace(/[&<>"']/g, m => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#039;'
  }[m]));
}
function toNumber(v) {
  if (v == null) return 0;
  if (typeof v === 'number') return v;
  const cleaned = String(v).replace(/[^\d,.-]/g, '').replace(/\./g, '').replace(',', '.');
  const n = parseFloat(cleaned);
  return Number.isNaN(n) ? 0 : n;
}
function moneyBR(v) {
  return Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/* ========= Toast & Som ========= */
window.toastOk = function () {
  if ($('#toast-ok').length === 0) {
    $('body').append(
      '<div id="toast-ok" style="position:fixed;right:16px;top:16px;z-index:9999;background:#28a745;color:#fff;padding:12px 16px;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,.15);display:none;font-weight:600">Pagamento aprovado!</div>'
    );
  }
  $('#toast-ok').stop(true, true).fadeIn(150).delay(2500).fadeOut(400);
};

window.playPaymentSound = function () {
  const elPix = document.getElementById('pix-sound');
  const elGen = document.getElementById('notification-sound');
  const el = elPix || elGen;
  if (el) {
    const p = el.play();
    if (p && typeof p.then === 'function') p.catch(() => {});
  } else {
    // Fallback: bipa com oscillator
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      const osc = ctx.createOscillator(), gain = ctx.createGain();
      osc.type = 'sine'; osc.frequency.value = 880; gain.gain.value = 0.15;
      osc.connect(gain); gain.connect(ctx.destination);
      osc.start(); setTimeout(() => { osc.stop(); ctx.close(); }, 300);
    } catch {}
  }
};

/* ========= Estado global PIX ========= */
let pollInterval = null;
window.pixPaymentId = null;
window.pixPedidoId = null;

function startPixCheck(paymentId, pedidoId) {
  window.pixPaymentId = paymentId;
  window.pixPedidoId  = pedidoId || null;
  $('#pix-status').text('Aguardando pagamento...');
  if (pollInterval) clearInterval(pollInterval);
  checkPixStatus();
  pollInterval = setInterval(checkPixStatus, 2000); // 2s
}

function checkPixStatus() {
  if (!window.pixPaymentId) return;
  $.ajax({
    url: 'verificar_pagamento_pix.php',
    type: 'GET',
    dataType: 'json',
    data: { pagamento_id: window.pixPaymentId, pedido_id: window.pixPedidoId }
  }).done(function (r) {
    if (!r || r.success !== true) return;
    const st = String(r.status || '').toLowerCase();
    $('#pix-status').text(st);

    if (st === 'approved') {
      if (pollInterval) clearInterval(pollInterval);

      // Som + Toast
      playPaymentSound();
      toastOk();

      // “Placa” verde no lugar do QR
      const $qr = $('#pix-qr-code img.pix-qr-img');
      if ($qr.length) {
        const w = $qr.width();
        const h = $qr.height();
        $('#pix-qr-code').html(
          `<div id="pix-ok-box" style="width:${w}px;height:${h}px;display:flex;align-items:center;justify-content:center;background:#28a745;color:#fff;font-size:2rem;font-weight:bold;border-radius:6px">Pagamento efetuado.</div>`
        );
      }

      // Fecha modal e recarrega a mesa (se estiver carregada)
      setTimeout(function () {
        $('#pix-modal').fadeOut(120);
        const num = parseInt($('#inp-mesa-numero').val(), 10);
        if (num) carregarMesaPorNumero(num, /*silent*/ true);
      }, 2000);

    } else if (['rejected', 'cancelled', 'expired', 'refunded'].includes(st)) {
      if (pollInterval) clearInterval(pollInterval);
      // Pode-se mostrar um toast de erro aqui se quiser
    }
  });
}

/* Botões do modal PIX (verificar manualmente / fechar) */
$(document).on('click', '#verify-payment-btn, #btn-verificar-pix, #verificar-pix, #verify-now, #btn-verify-now', function () {
  if (pollInterval) clearInterval(pollInterval);
  checkPixStatus();
});
$(document).on('click', '.close, #cancel-pix-btn', function () {
  if (pollInterval) clearInterval(pollInterval);
  $('#pix-modal').fadeOut(120);
});

/* ========= Tabs ========= */
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
    carregarCardapio();
  }
});

/* ========= Render da mesa ========= */
function renderMesa(m) {
  if (!m) {
    $('#mesa-view').html('<div class="muted">Mesa não encontrada.</div>');
    return;
  }
  const itensHtml = (m.itens || []).map(it => {
    const qtd = Math.max(1, Number(it.quantidade || 1));
    const img = it.imagem ? `<img src="${esc(it.imagem)}" alt="${esc(it.nome)}">`
                           : `<div class="noimg">sem<br>imagem</div>`;
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

/* ========= Auto-refresh da mesa (UI) ========= */
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
            .done(function (rr) { if (rr && rr.success) renderMesa(rr.mesa); });
        }
      }, 3000);
    }).fail(function () {
      $('#mesa-view').html('<div class="muted">Falha ao carregar mesa.</div>');
      stopMesaAutoRefresh();
      mesaRefreshNumeroAtual = null;
    });
}

/* ========= UI principal (carregar mesa) ========= */
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

/* ========= Modal de produtos (adicionar em mesa) ========= */
$(document).on('click', '.abrir-produtos-btn', function () {
  const mesaId     = $(this).data('mesa-id');       // pode vir vazio
  const pedidoId   = $(this).data('pedido-id');     // pode vir vazio
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
      $('#pm-grid').empty();
      $('#pm-msg').text(resp?.mensagem || 'Erro ao listar produtos.').show();
      return;
    }
    const cards = (resp.produtos || []).map(p => {
      const img = p.imagem
        ? `<img src="${esc(p.imagem)}" alt="${esc(p.nome)}">`
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
    $('#pm-grid').empty();
    $('#pm-msg').text('Falha ao obter a lista de produtos.').show();
  });
});

/* Clique no produto do modal:
   - Se não existir pedido: cria com open_for_mesa
   - Depois adiciona item
   - Registra notificação
   - Fecha modal e atualiza mesa (se estiver exibida)
*/
$(document).on('click', '.pm-card', function () {
  const produtoId = Number($(this).data('id'));
  const $modal = $('#produtos-modal');
  let pedidoId = Number($modal.data('pedido-id') || 0);
  const mesaNumero = Number($modal.data('mesa-numero') || 0);

  function addItem(pId) {
    $.post('adicionar_item.php', { pedido_id: pId, produto_id: produtoId }, function (r) {
      if (!r || !r.sucesso) { $('#pm-msg').text(r?.mensagem || 'Não foi possível adicionar.').show(); return; }

      // Notificação (qtd 1)
      $.post('clientes.php?ajax=1', { op: 'notify', pedido_id: pId, mesa_numero: mesaNumero, produto_id: produtoId, quantidade: 1 });

      $('#produtos-modal').fadeOut(120);
      const n = parseInt($('#inp-mesa-numero').val(), 10);
      if (n) carregarMesaPorNumero(n, /*silent*/ true);
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

/* ========= Pagamento PIX a partir da mesa ========= */
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

      startPixCheck(pg.pagamento_id, pId);
    }).fail(function () {
      alert('Falha ao comunicar com o servidor (PIX).');
    });
  }

  if (!pedidoId) {
    // Abre pedido automático, depois pega total atualizado e gera PIX
    $.post('clientes.php?ajax=1', { op: 'open_for_mesa', mesa_numero: mesaNumero }, function (r) {
      if (!r || !r.success || !r.pedido_id) { alert('Não foi possível abrir pedido automaticamente.'); return; }
      pedidoId = Number(r.pedido_id);
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

/* ========= Cardápio (novo pedido + PIX) ========= */
let nmCart = {}; // { id: { nome, preco, img, qtd } }
let nmTotal = 0;

function carregarCardapio() {
  if ($('#nm-grid').children().length > 0) return;

  $('#nm-grid').html('<div style="padding:12px;color:#777;">Carregando produtos...</div>');
  $('#nm-msg').hide().text('');

  $.getJSON('listar_produtos.php', function (resp) {
    if (!resp || !resp.sucesso) {
      $('#nm-grid').empty(); $('#nm-msg').text(resp?.mensagem || 'Erro ao listar.').show(); return;
    }

    const cards = (resp.produtos || []).map(p => {
      const img = p.imagem
        ? `<img src="${esc(p.imagem)}" alt="${esc(p.nome)}">`
        : `<div class="pm-noimg">sem imagem</div>`;

      return `
        <div class="pm-card nm-card"
             data-id="${p.id}"
             data-preco="${p.preco}"
             data-nome="${esc(p.nome)}"
             data-img="${p.imagem ? esc(p.imagem) : ''}">
          <div class="pm-thumb">
            ${img}
            <span class="pm-price">R$ ${moneyBR(p.preco)}</span>
            <span class="pm-stock"> ${p.estoque}</span>
          </div>
          <div class="pm-name" title="${esc(p.nome)}">${esc(p.nome)}</div>
        </div>`;
    }).join('');

    $('#nm-grid').html(cards);
  }).fail(function () {
    $('#nm-grid').empty(); $('#nm-msg').text('Falha ao obter lista de produtos.').show();
  });
}

/* Clicar no card do cardápio → incrementa no carrinho */
$(document).on('click', '.nm-card', function () {
  const $c = $(this);
  const id   = Number($c.data('id'));
  const nome = String($c.data('nome') || '');
  const preco= Number($c.data('preco') || 0);
  const img  = String($c.data('img') || '');

  if (!nmCart[id]) nmCart[id] = { nome, preco, img, qtd: 0 };
  nmCart[id].qtd += 1;
  nmTotal += preco;

  let $badge = $c.find('.sel-badge');
  if ($badge.length) { $badge.text('x' + nmCart[id].qtd); }
  else {
    $c.append('<span class="sel-badge" style="position:absolute;right:8px;top:8px;background:#0d6efd;color:#fff;font-weight:700;padding:2px 8px;border-radius:999px;font-size:.78rem;">x1</span>');
  }

  $('#nm-total').text('R$ ' + moneyBR(nmTotal));
  $('#nm-pagar-pix').prop('disabled', nmTotal <= 0);
});

/* Gerar PIX para o carrinho (cria mesa e pedido automaticamente) */
$('#nm-pagar-pix').on('click', function () {
  if (Object.keys(nmCart).length === 0) {
    $('#nm-msg').text('Selecione ao menos 1 produto.').show();
    return;
  }
  $('#nm-msg').hide().text('');

  $.ajax({
    url: 'abrir_mesa_e_pedido_com_itens.php',
    type: 'POST',
    dataType: 'json',
    data: { cart: JSON.stringify(nmCart) }
  }).done(function (resp) {
    if (!resp || !resp.sucesso) {
      $('#nm-msg').text(resp?.mensagem || 'Erro ao abrir mesa.').show();
      return;
    }

    const pedidoId   = Number(resp.pedido_id);
    const mesaNumero = Number(resp.mesa_numero);
    const valorTotal = Number(resp.total || nmTotal || 0);

    // Notificações consolidadas por item
    const notifies = [];
    Object.keys(nmCart).forEach(idStr => {
      const pid = parseInt(idStr, 10);
      const qtd = Number(nmCart[pid].qtd || 1);
      notifies.push($.post('clientes.php?ajax=1', { op: 'notify', pedido_id: pedidoId, mesa_numero: mesaNumero, produto_id: pid, quantidade: qtd }));
    });

    $.when.apply($, notifies).always(function () {
      $.ajax({
        url: 'gerar_pagamento_pix.php',
        type: 'POST',
        dataType: 'json',
        data: { pedido_id: pedidoId, valor: valorTotal }
      }).done(function (pg) {
        if (!pg || !pg.success) {
          $('#nm-msg').text(pg?.error || 'Erro ao gerar PIX.').show();
          return;
        }

        const b64 = (pg.qr_code_base64 || '').replace(/\s+/g, '');
        $('#pix-mesa').text('Mesa ' + mesaNumero);
        $('#pix-valor').text('R$ ' + moneyBR(valorTotal));
        $('#pix-qr-code').html('<img class="pix-qr-img" alt="QR Code PIX" src="data:image/png;base64,' + b64 + '">');
        $('#pix-code-text').val(pg.qr_code);
        $('#pix-modal').fadeIn(120);

        startPixCheck(pg.pagamento_id, pedidoId);

        // Limpa carrinho visual
        nmCart = {}; nmTotal = 0;
        $('#nm-total').text('R$ 0,00');
        $('#nm-pagar-pix').prop('disabled', true);
        $('.nm-card .sel-badge').remove();
      }).fail(function () {
        $('#nm-msg').text('Falha ao comunicar com o servidor (PIX).').show();
      });
    });

  }).fail(function () {
    $('#nm-msg').text('Falha ao comunicar com o servidor.').show();
  });
});

/* ====== Fechar modal de produtos ====== */
$(document).on('click', '.pm-close', function () {
  $('#produtos-modal').fadeOut(120);
});
