<?php include __DIR__ . '/../views/partials/header.php'; ?>
<?php
// public/pedido_detalhe.php

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/verificar_sessao.php';

use App\Support\DB;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('America/Recife');

// ------- Helpers -------
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function moeda($v){ return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function toMoney($str){
  $s = str_replace(['.', ','], ['', '.'], preg_replace('/[^\d,\.]/','',$str));
  return (float)$s;
}

// ------- Conexão -------
$con = DB::conn();
if (!($con instanceof mysqli)) {
  http_response_code(500);
  exit('Erro: conexão MySQLi não inicializada.');
}

// ------- Carrega pedido -------
$pedido_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($pedido_id <= 0) { http_response_code(400); exit('ID inválido.'); }

// Mensagens
$flash = null;

// ===================== AÇÕES EXTRA: “Exibir como Mesa” =====================
// >>> POST: vincular a uma mesa (criar se não existir) ou criar mesa automática
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['_mesa_action'])) {
  try {
    if ($_POST['_mesa_action'] === 'criar_auto') {
      // cria mesa com número MAX(numero)+1 e ativa
      $rs = $con->query("SELECT COALESCE(MAX(numero), 0) AS mx FROM mesas");
      $mx = (int)($rs->fetch_assoc()['mx'] ?? 0);
      $novoNumero = $mx + 1;

      $st = $con->prepare("INSERT INTO mesas (numero, ativa, descricao, capacidade) VALUES (?, 1, '', 0)");
      $st->bind_param('i', $novoNumero);
      $st->execute();
      $novaMesaId = $st->insert_id;
      $st->close();

      $st2 = $con->prepare("UPDATE pedidos SET mesa_id=? WHERE id=? AND excluido_em IS NULL");
      $st2->bind_param('ii', $novaMesaId, $pedido_id);
      $st2->execute();
      $st2->close();

      $flash = ['type'=>'ok','msg'=>"Mesa #$novoNumero criada e vinculada ao pedido."];

    } elseif ($_POST['_mesa_action'] === 'vincular_numero') {
      $numero = (int)($_POST['mesa_numero'] ?? 0);
      if ($numero <= 0) throw new Exception('Número da mesa inválido.');

      // existe mesa com esse número?
      $st = $con->prepare("SELECT id, ativa FROM mesas WHERE numero=? LIMIT 1");
      $st->bind_param('i', $numero);
      $st->execute();
      $rs = $st->get_result()->fetch_assoc();
      $st->close();

      if ($rs) {
        $mesaId = (int)$rs['id'];
        // ativa se estiver inativa
        if (!(int)$rs['ativa']) {
          $con->query("UPDATE mesas SET ativa=1 WHERE id={$mesaId} LIMIT 1");
        }
      } else {
        // cria mesa com esse número já ativa
        $stC = $con->prepare("INSERT INTO mesas (numero, ativa, descricao, capacidade) VALUES (?, 1, '', 0)");
        $stC->bind_param('i', $numero);
        $stC->execute();
        $mesaId = $stC->insert_id;
        $stC->close();
      }

      // vincula pedido
      $stL = $con->prepare("UPDATE pedidos SET mesa_id=? WHERE id=? AND excluido_em IS NULL");
      $stL->bind_param('ii', $mesaId, $pedido_id);
      $stL->execute();
      $stL->close();

      $flash = ['type'=>'ok','msg'=>"Pedido vinculado à mesa #$numero."];
    }
  } catch (Throwable $e) {
    $flash = ['type'=>'error','msg'=>'Falha ao preparar mesa: '.$e->getMessage()];
  }
}
// =================== FIM AÇÕES EXTRA ===================


// Atualiza (POST) campos do pedido (já existia)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !isset($_POST['_mesa_action'])) {
  $novo_status         = isset($_POST['status']) ? trim($_POST['status']) : '';
  $nova_forma          = isset($_POST['forma_pagamento']) ? trim($_POST['forma_pagamento']) : null;
  $novo_total_pago_str = isset($_POST['total_pago']) ? trim($_POST['total_pago']) : '';
  $novo_total_pago     = $novo_total_pago_str !== '' ? toMoney($novo_total_pago_str) : null;

  $status_ok = ['aberto','pago','fechado','cancelado'];
  if (!in_array(strtolower($novo_status), $status_ok, true)) {
    $flash = ['type'=>'error','msg'=>'Status inválido.'];
  } else {
    $cols = []; $params = []; $types = '';
    $cols[] = 'status=?';           $params[] = $novo_status;      $types .= 's';
    if ($nova_forma !== null && $nova_forma !== '') {
      $cols[] = 'forma_pagamento=?'; $params[] = $nova_forma;      $types .= 's';
    }
    if ($novo_total_pago !== null) {
      $cols[] = 'total=?';           $params[] = $novo_total_pago; $types .= 'd';
    }
    if ($novo_status === 'pago') {
      $cols[] = 'data_pagamento = IFNULL(data_pagamento, NOW())';
    }

    if ($cols) {
      $sql = 'UPDATE pedidos SET '.implode(',', $cols).' WHERE id=? AND excluido_em IS NULL LIMIT 1';
      $types .= 'i';
      $params[] = $pedido_id;

      $st = $con->prepare($sql);
      $st->bind_param($types, ...$params);
      $st->execute();
      $st->close();

      $flash = ['type'=>'ok','msg'=>'Pedido atualizado com sucesso.'];
    }
  }
}

// Consulta principal do pedido + mesa + usuário (ADICIONEI m.ativa)
$sql = "
  SELECT p.id, p.mesa_id, p.usuario_id, p.data_pedido, p.status, p.forma_pagamento,
         p.data_pagamento, p.total, p.pagamento_id,
         m.numero AS mesa_numero, m.ativa AS mesa_ativa,
         u.nome  AS usuario_nome, u.email AS usuario_email
  FROM pedidos p
  LEFT JOIN mesas m   ON m.id = p.mesa_id
  LEFT JOIN usuarios u ON u.id = p.usuario_id
  WHERE p.id = ? AND p.excluido_em IS NULL
  LIMIT 1
";
$st = $con->prepare($sql);
$st->bind_param('i', $pedido_id);
$st->execute();
$pedido = $st->get_result()->fetch_assoc();
$st->close();
if (!$pedido) { die('Pedido não encontrado.'); }

// Valor gerado (somatório dos itens)
$sqlItens = "
  SELECT ip.id, ip.produto_id, ip.quantidade, ip.preco_unitario, ip.subtotal,
         pr.nome, pr.imagem
  FROM itens_pedido ip
  JOIN produtos pr ON pr.id = ip.produto_id
  WHERE ip.pedido_id = ?
";
$stI = $con->prepare($sqlItens);
$stI->bind_param('i', $pedido_id);
$stI->execute();
$itens = $stI->get_result()->fetch_all(MYSQLI_ASSOC);
$stI->close();

$valorGerado = 0.0;
foreach ($itens as $it) { $valorGerado += (float)$it['subtotal']; }

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Pedido #<?= (int)$pedido['id'] ?> - Detalhes</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,'Helvetica Neue',Arial,sans-serif;margin:0;background:#f6f7f9;color:#111}
    .wrap{max-width:1000px;margin:18px auto;padding:0 16px}
    .card{background:#fff;border:1px solid #eee;border-radius:12px;margin-bottom:16px;overflow:hidden}
    .card h3{margin:0;padding:12px 14px;border-bottom:1px solid #eee}
    .card .content{padding:12px 14px}
    .grid{display:grid;gap:12px}
    .grid.cols-2{grid-template-columns:repeat(2,1fr)}
    .muted{color:#666}
    .badge{display:inline-block;border-radius:999px;padding:2px 8px;font-size:.8rem;color:#fff}
    .b-aberto{background:#ffc107;color:#111}
    .b-pago{background:#28a745}
    .b-fechado{background:#6c757d}
    .b-cancelado{background:#dc3545}
    label{display:block;margin:.4rem 0 .2rem}
    input[type="text"], select{width:100%;padding:8px;border:1px solid #ddd;border-radius:8px;box-sizing:border-box}
    .btn{display:inline-block;background:#0d6efd;color:#fff;border:0;border-radius:8px;padding:8px 12px;text-decoration:none;cursor:pointer}
    .btn.gray{background:#6c757d}
    table{width:100%;border-collapse:collapse}
    th,td{padding:8px 10px;border-bottom:1px solid #eee;text-align:left}
    th{background:#fafafa;font-weight:600}
    .flash{padding:10px 12px;border-radius:8px;margin:8px 0;font-weight:600}
    .flash.ok{background:#d1e7dd;border:1px solid #badbcc;color:#0f5132}
    .flash.err{background:#f8d7da;border:1px solid #f5c2c7;color:#842029}
    @media (max-width:760px){ .grid.cols-2{grid-template-columns:1fr} }
    .note{background:#fff3cd;border:1px solid #ffe69c;color:#664d03;border-radius:8px;padding:10px 12px;margin:10px 0}
  </style>
</head>
<body>
  <div class="wrap">
    <a class="btn gray" href="relatorios.php"  rel="noopener">Voltar aos relatórios</a>

    <div class="card">
      <h3>Pedido #<?= (int)$pedido['id'] ?></h3>
      <div class="content">
        <?php if ($flash): ?>
          <div class="flash <?= $flash['type']==='ok'?'ok':'err' ?>">
            <?= h($flash['msg']) ?>
          </div>
        <?php endif; ?>

        <!-- >>> BLOCO: aviso e ações de Mesa quando não aparece nas Mesas -->
        <?php
          $stAtual = strtolower($pedido['status']);
          $naoApareceNasMesas = (
            $stAtual === 'aberto' &&
            (
              empty($pedido['mesa_id']) ||
              (isset($pedido['mesa_ativa']) && (int)$pedido['mesa_ativa'] === 0) ||
              ((float)$pedido['total'] <= 0.0) // seu grid de mesas oculta total=0
            )
          );
        ?>
        <?php if ($naoApareceNasMesas): ?>
          <div class="note">
            Este pedido está <strong>aberto</strong>, mas pode não aparecer nas <strong>Mesas</strong>
            porque não tem mesa vinculada, a mesa está inativa ou o total é 0,00.
            <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
              <form method="post" style="display:flex;gap:8px;align-items:center;">
                <input type="hidden" name="_mesa_action" value="vincular_numero">
                <label for="mesa_numero" style="margin:0">Vincular à mesa nº</label>
                <input type="text" id="mesa_numero" name="mesa_numero" placeholder="ex.: 103" style="width:120px">
                <button class="btn" type="submit">Vincular/Ativar</button>
              </form>

              <form method="post">
                <input type="hidden" name="_mesa_action" value="criar_auto">
                <button class="btn" type="submit">Criar nova mesa e vincular</button>
              </form>

              <a class="btn gray" href="mesas.php" target="_blank" rel="noopener">Abrir tela de Mesas</a>
            </div>
          </div>
        <?php endif; ?>
        <!-- <<< fim bloco ações Mesa -->

        <div class="grid cols-2">
          <div>
            <div class="muted">Mesa</div>
            <div><strong><?= h($pedido['mesa_numero'] ?? '-') ?></strong> <?= isset($pedido['mesa_ativa']) && !$pedido['mesa_ativa'] ? '<span class="muted">(inativa)</span>' : '' ?></div>
          </div>
          <div>
            <div class="muted">Data do pedido</div>
            <div><strong><?= h(date('d/m/Y H:i', strtotime($pedido['data_pedido']))) ?></strong></div>
          </div>
          <div>
            <div class="muted">Status atual</div>
            <?php
              $st = strtolower($pedido['status']);
              $map = ['aberto'=>'b-aberto','pago'=>'b-pago','fechado'=>'b-fechado','cancelado'=>'b-cancelado'];
              $cls = $map[$st] ?? 'b-aberto';
            ?>
            <div><span class="badge <?= $cls ?>"><?= strtoupper(h($st)) ?></span></div>
          </div>
          <div>
            <div class="muted">Forma de pagamento</div>
            <div><strong><?= h($pedido['forma_pagamento'] ?? '-') ?></strong></div>
          </div>
          <div>
            <div class="muted">Valor gerado (itens)</div>
            <div><strong><?= moeda($valorGerado) ?></strong></div>
          </div>
          <div>
            <div class="muted">Valor pago (coluna total)</div>
            <div><strong><?= moeda($pedido['total']) ?></strong></div>
          </div>
          <div>
            <div class="muted">Pagamento ID (MP)</div>
            <div><code><?= h($pedido['pagamento_id'] ?? '-') ?></code></div>
          </div>
          <div>
            <div class="muted">Data do pagamento</div>
            <div><strong><?= $pedido['data_pagamento'] ? h(date('d/m/Y H:i', strtotime($pedido['data_pagamento']))) : '-' ?></strong></div>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <h3>Editar pedido</h3>
      <div class="content">
        <form method="post">
          <div class="grid cols-2">
            <div>
              <label for="status">Status</label>
              <select id="status" name="status" required>
                <?php
                  $opts = ['aberto'=>'Aberto', 'pago'=>'Pago', 'fechado'=>'Fechado', 'cancelado'=>'Cancelado'];
                  foreach ($opts as $val=>$lab):
                ?>
                  <option value="<?= $val ?>" <?= strtolower($pedido['status'])===$val?'selected':'' ?>>
                    <?= $lab ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label for="forma_pagamento">Forma de pagamento</label>
              <select id="forma_pagamento" name="forma_pagamento">
                <?php
                  $formas = ['pix'=>'PIX','dinheiro'=>'Dinheiro','cartao'=>'Cartão','-'=>'(não informado)'];
                  $fp = strtolower((string)$pedido['forma_pagamento']);
                  foreach ($formas as $val=>$lab):
                ?>
                  <option value="<?= $val==='-'?'':$val ?>" <?= $fp===$val?'selected':'' ?>><?= $lab ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label for="total_pago">Valor pago (edita coluna total)</label>
              <input type="text" id="total_pago" name="total_pago" value="<?= number_format((float)$pedido['total'], 2, ',', '.') ?>" placeholder="0,00">
              <small class="muted">Formato: 0,00 — este campo grava em <strong>pedidos.total</strong>.</small>
            </div>

            <div>
              <label>Valor gerado (somatório dos itens)</label>
              <input type="text" value="<?= number_format($valorGerado, 2, ',', '.') ?>" readonly>
            </div>
          </div>

          <div style="margin-top:10px;display:flex;gap:8px;">
            <button class="btn" type="submit">Salvar alterações</button>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <h3>Quem fez o pedido</h3>
      <div class="content">
        <div class="grid cols-2">
          <div>
            <div class="muted">Usuário</div>
            <div><strong><?= h($pedido['usuario_nome'] ?? '-') ?></strong></div>
          </div>
          <div>
            <div class="muted">E-mail</div>
            <div><strong><?= h($pedido['usuario_email'] ?? '-') ?></strong></div>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <h3>Itens do pedido</h3>
      <div class="content">
        <?php if (empty($itens)): ?>
          <div class="muted">Sem itens lançados.</div>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>Produto</th>
                <th>Qtd</th>
                <th>Preço</th>
                <th>Subtotal</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($itens as $it): ?>
                <tr>
                  <td><?= h($it['nome']) ?></td>
                  <td><?= (int)$it['quantidade'] ?></td>
                  <td><?= moeda($it['preco_unitario']) ?></td>
                  <td><?= moeda($it['subtotal']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <th colspan="3" style="text-align:right;">Total gerado</th>
                <th><?= moeda($valorGerado) ?></th>
              </tr>
            </tfoot>
          </table>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <script>
    // formata 0,00 enquanto digita
    (function(){
      const el = document.getElementById('total_pago');
      if (!el) return;
      el.addEventListener('input', function(e){
        let v = e.target.value.replace(/\D/g,'');
        v = (v/100).toFixed(2)+'';
        v = v.replace('.',',').replace(/(\d)(?=(\d{3})+,)/g,'$1.');
        e.target.value = v;
      });
    })();
  </script>
</body>
</html>
