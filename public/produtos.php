<?php
// public/produtos.php — Lista + CRUD + Entrada rápida no estoque (ícone ➕)

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/verificar_sessao.php';

use App\Support\DB;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$con = DB::conn();
if (!($con instanceof mysqli)) {
  http_response_code(500);
  exit('Conexão MySQLi não inicializada.');
}

/* ===================== Helpers ===================== */
function jexit(bool $ok, array $data = [], int $http = 200): void {
  header('Content-Type: application/json; charset=utf-8');
  http_response_code($http);
  echo json_encode(
    $ok ? (['sucesso' => true] + $data) : (['sucesso' => false] + $data),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
  );
  exit;
}
function moeda($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function h($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

/**
 * Retorna produtos com:
 * - estoque_atual: p.estoque
 * - vendidos: soma de itens em pedidos com status <> 'cancelado'
 * - entrada_historica: vendidos + estoque_atual
 */
function selectProdutoView(mysqli $con, int $id = 0): array {
  $sql = "
    SELECT
      p.id,
      p.nome,
      p.descricao,
      p.preco,
      p.estoque                                       AS estoque_atual,
      p.imagem,
      p.ativo,
      COALESCE(SUM(CASE WHEN ped.status <> 'cancelado' AND ped.excluido_em IS NULL THEN ip.quantidade ELSE 0 END), 0) AS vendidos
    FROM produtos p
    LEFT JOIN itens_pedido ip ON ip.produto_id = p.id
    LEFT JOIN pedidos ped     ON ped.id       = ip.pedido_id
    " . ($id > 0 ? "WHERE p.id = ? " : "WHERE p.ativo = 1 ") . "
    GROUP BY p.id
    ORDER BY p.nome
  ";

  if ($id > 0) {
    $st = $con->prepare($sql);
    $st->bind_param('i', $id);
  } else {
    $st = $con->prepare($sql);
  }
  $st->execute();
  $res = $st->get_result();

  if ($id > 0) {
    $r = $res->fetch_assoc() ?: [];
    if (!$r) return [];
    $estoqueAtual = (int)$r['estoque_atual'];
    $vendidos     = (int)$r['vendidos'];
    return [
      'id'                => (int)$r['id'],
      'nome'              => (string)$r['nome'],
      'descricao'         => (string)($r['descricao'] ?? ''),
      'preco'             => (float)$r['preco'],
      'imagem'            => (string)($r['imagem'] ?? ''),
      'ativo'             => (int)($r['ativo'] ?? 1),
      'estoque_atual'     => $estoqueAtual,
      'vendidos'          => $vendidos,
      'entrada_historica' => max($estoqueAtual + $vendidos, 0),
    ];
  }

  $out = [];
  while ($r = $res->fetch_assoc()) {
    $estoqueAtual = (int)$r['estoque_atual'];
    $vendidos     = (int)$r['vendidos'];
    $out[] = [
      'id'                => (int)$r['id'],
      'nome'              => (string)$r['nome'],
      'preco'             => (float)$r['preco'],
      'imagem'            => (string)($r['imagem'] ?? ''),
      'estoque_atual'     => $estoqueAtual,
      'vendidos'          => $vendidos,
      'entrada_historica' => max($estoqueAtual + $vendidos, 0),
    ];
  }
  return $out;
}

/* =============== Upload & resize (300x450) =============== */
function ensureDir(string $dir): void {
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
}
function imageFromString(string $blob) {
  $img = @imagecreatefromstring($blob);
  if (!$img) throw new RuntimeException('Arquivo de imagem inválido.');
  return $img;
}
function saveResizedProductImage(array $file): string {
  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Falha no upload (código ' . $file['error'] . ').');
  }
  $blob = file_get_contents($file['tmp_name']);
  if ($blob === false) throw new RuntimeException('Não foi possível ler o arquivo.');

  $src = imageFromString($blob);
  $sw = imagesx($src); $sh = imagesy($src);
  if ($sw < 1 || $sh < 1) throw new RuntimeException('Dimensões inválidas.');

  $tw = 300; $th = 450;
  $srcRatio = $sw / $sh; $dstRatio = $tw / $th;

  if ($srcRatio > $dstRatio) { $newH = $th; $newW = (int)round($th * $srcRatio); }
  else { $newW = $tw; $newH = (int)round($tw / $srcRatio); }

  $temp = imagecreatetruecolor($newW, $newH);
  imagecopyresampled($temp, $src, 0, 0, 0, 0, $newW, $newH, $sw, $sh);

  $dst = imagecreatetruecolor($tw, $th);
  $offX = (int)max(0, floor(($newW - $tw) / 2));
  $offY = (int)max(0, floor(($newH - $th) / 2));
  imagecopy($dst, $temp, 0, 0, $offX, $offY, $tw, $th);

  imagedestroy($src); imagedestroy($temp);

  $baseDir = __DIR__ . '/uploads/produtos';
  ensureDir($baseDir);
  $name = bin2hex(random_bytes(8)) . '.jpg';
  $abs  = $baseDir . '/' . $name;
  $rel  = 'uploads/produtos/' . $name;

  if (!imagejpeg($dst, $abs, 82)) {
    imagedestroy($dst);
    throw new RuntimeException('Falha ao salvar imagem.');
  }
  imagedestroy($dst);
  return $rel;
}

/* ===================== Endpoints AJAX ===================== */
if (isset($_GET['action'])) {
  try {
    $action = $_GET['action'];

    if ($action === 'buscar') {
      $id = (int)($_GET['id'] ?? 0);
      if ($id <= 0) jexit(false, ['mensagem' => 'ID inválido'], 400);
      $p = selectProdutoView($con, $id);
      if (!$p) jexit(false, ['mensagem' => 'Produto não encontrado'], 404);
      jexit(true, ['produto' => $p]);
    }

    if ($action === 'salvar') {
      // id (>0 edita), nome*, descricao, preco*, estoque_atual*, imagem(URL opcional), ativo, foto(upload opcional)
      $id        = (int)($_POST['id'] ?? 0);
      $nome      = trim((string)($_POST['nome'] ?? ''));
      $descricao = trim((string)($_POST['descricao'] ?? ''));
      $preco     = (float)($_POST['preco'] ?? 0);
      $estoque   = (int)($_POST['estoque'] ?? 0); // ESTOQUE ATUAL
      $imagemUrl = trim((string)($_POST['imagem'] ?? ''));
      $ativo     = (int)($_POST['ativo'] ?? 1);

      if ($nome === '' || $preco < 0 || $estoque < 0) {
        jexit(false, ['mensagem' => 'Preencha Nome, Preço e Estoque atual válidos.'], 400);
      }

      $imagemFinal = $imagemUrl;
      if (!empty($_FILES['foto']) && ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $imagemFinal = saveResizedProductImage($_FILES['foto']);
      }

      if ($id > 0) {
        $st = $con->prepare("UPDATE produtos SET nome=?, descricao=?, preco=?, estoque=?, imagem=?, ativo=? WHERE id=?");
        $st->bind_param('ssdisii', $nome, $descricao, $preco, $estoque, $imagemFinal, $ativo, $id);
        $st->execute();
      } else {
        $st = $con->prepare("INSERT INTO produtos (nome, descricao, preco, estoque, imagem, ativo) VALUES (?,?,?,?,?,?)");
        $st->bind_param('ssdisi', $nome, $descricao, $preco, $estoque, $imagemFinal, $ativo);
        $st->execute();
        $id = $con->insert_id;
      }

      $p = selectProdutoView($con, $id);
      jexit(true, ['produto' => $p]);
    }

    /* ===== NOVO: incrementar estoque (entrada rápida) ===== */
    if ($action === 'incrementar') {
      $id    = (int)($_POST['id'] ?? 0);
      $qtd   = (int)($_POST['qtd'] ?? 0);
      if ($id <= 0 || $qtd <= 0) {
        jexit(false, ['mensagem' => 'Informe um ID válido e uma quantidade positiva.'], 400);
      }

      $st = $con->prepare("UPDATE produtos SET estoque = GREATEST(estoque + ?, 0) WHERE id = ?");
      $st->bind_param('ii', $qtd, $id);
      $st->execute();

      $p = selectProdutoView($con, $id);
      jexit(true, ['produto' => $p]);
    }

    if ($action === 'excluir') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) jexit(false, ['mensagem' => 'ID inválido'], 400);
      $st = $con->prepare("UPDATE produtos SET ativo=0 WHERE id=?");
      $st->bind_param('i', $id);
      $st->execute();
      jexit(true);
    }

    jexit(false, ['mensagem' => 'Ação inválida'], 400);
  } catch (Throwable $e) {
    jexit(false, ['mensagem' => $e->getMessage()], 500);
  }
}

/* ===================== Página (lista) ===================== */
$produtos = selectProdutoView($con);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Produtos disponíveis - Bar Azerutan</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    body{font-family:system-ui, Segoe UI, Roboto, Ubuntu,'Helvetica Neue', Arial, sans-serif;margin:0;background:#f6f7f9;color:#111}
    .container{max-width:1100px;margin:20px auto;padding:0 16px}
    .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px}
    .card{background:#fff;border:1px solid #eee;border-radius:12px;overflow:hidden;display:flex;flex-direction:column}
    .card img{width:100%;height:160px;object-fit:cover;background:#fafafa}
    .noimg{width:100%;height:160px;background:#e9ecef;display:flex;align-items:center;justify-content:center;color:#777;font-size:.9rem}
    .info{padding:10px}
    .info h4{margin:0 0 6px 0;font-size:1.05rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .preco{font-weight:700;color:#0d6efd}
    .meta{display:flex;gap:8px;margin-top:6px;font-size:.9rem;color:#333;flex-wrap:wrap;align-items:center}
    .pill{background:#eef2ff;border:1px solid #dde3ff;border-radius:999px;padding:2px 8px}
    .warn{background:#fff6e5;border:1px solid #ffe4b5}
    .pill-actions{display:inline-flex;gap:6px;margin-left:auto}
    .pill-btn{display:inline-flex;align-items:center;gap:6px;background:#f3f6ff;border:1px solid #dfe6ff;border-radius:999px;padding:3px 8px;cursor:pointer}
    .pill-btn i{font-size:.9rem}
    .btn{background:#0d6efd;color:#fff;border:0;border-radius:8px;padding:8px 12px;cursor:pointer;text-decoration:none}
    .btn.secondary{background:#6c757d}
    .btn-danger{background:#dc3545}
    .btn-primary{background:#0d6efd}
    .prod-actions{display:flex;gap:8px;margin-top:10px}
    .btn-mini{padding:6px 10px;font-size:.9rem;border-radius:6px}

    /* Modal próprio */
    .app-modal{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:10000;display:none;overflow-y:auto}
    .app-modal-content{margin:5vh auto;background:#fff;border:1px solid #eee;border-radius:12px;overflow:hidden;max-width:min(560px,95vw);max-height:90vh;display:flex;flex-direction:column}
    .app-modal-header,.app-modal-footer{padding:10px 12px;border-bottom:1px solid #eee;display:flex;align-items:center;justify-content:space-between}
    .app-modal-footer{border-bottom:none;border-top:1px solid #eee}
    .app-modal-body{padding:12px;overflow-y:auto;flex:1}
    .form-row{display:flex;flex-direction:column;margin-bottom:10px}
    .form-row.two{flex-direction:row;gap:12px}
    .form-row.two>div{flex:1;display:flex;flex-direction:column}
    .form-row label{font-weight:600;margin-bottom:4px}
    .form-row input,.form-row textarea,.form-row select{padding:8px;border:1px solid #ddd;border-radius:8px}
  </style>
</head>
<body>

  <?php include __DIR__ . '/../views/partials/header.php'; ?>

  <div class="container">
    <div style="display:flex;justify-content:space-between;align-items:center;margin:10px 0 16px;">
      <h2 style="margin:0">Produtos disponíveis</h2>
      <button id="btn-novo-produto" class="btn">+ Novo produto</button>
    </div>

    <?php if (empty($produtos)): ?>
      <p>Nenhum produto cadastrado.</p>
    <?php else: ?>
      <div class="grid">
        <?php foreach ($produtos as $p): ?>
          <div class="card" data-id="<?= (int)$p['id'] ?>">
            <?php if (!empty($p['imagem'])): ?>
              <img src="<?= h($p['imagem']) ?>" alt="<?= h($p['nome']) ?>">
            <?php else: ?>
              <div class="noimg">Sem imagem</div>
            <?php endif; ?>
            <div class="info">
              <h4><?= h($p['nome']) ?></h4>
              <div class="preco"><?= moeda($p['preco']) ?></div>

              <div class="meta">
                <span class="pill">Entrada (hist.): <?= (int)$p['entrada_historica'] ?></span>
                <span class="pill <?= (int)$p['estoque_atual'] <= 3 ? 'warn' : '' ?>">
                  Estoque: <strong class="estoque-num"><?= (int)$p['estoque_atual'] ?></strong>
                </span>

                <!-- Ícone/atalho para entrada rápida -->
                <span class="pill-actions">
                  <button type="button" class="pill-btn btn-entrada-rapida" title="Adicionar ao estoque" data-id="<?= (int)$p['id'] ?>">
                    <i class="fa fa-plus"></i> Entrada
                  </button>
                </span>
              </div>

              <div class="prod-actions">
                <button type="button" class="btn btn-mini btn-primary btn-editar" data-id="<?= (int)$p['id'] ?>">
                  <i class="fa fa-edit"></i> Alterar
                </button>
                <button type="button" class="btn btn-mini btn-danger btn-excluir" data-id="<?= (int)$p['id'] ?>">
                  <i class="fa fa-trash"></i> Excluir
                </button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Modal CRUD Produto -->
  <div id="produto-modal" class="app-modal" style="display:none;">
    <div class="app-modal-content">
      <div class="app-modal-header">
        <h3 id="pm-title">Produto</h3>
        <span class="close pm-close" style="cursor:pointer">&times;</span>
      </div>
      <div class="app-modal-body">
        <form id="produto-form">
          <input type="hidden" name="id" value="">
          <div class="form-row">
            <label>Nome*</label>
            <input type="text" name="nome" required>
          </div>
          <div class="form-row">
            <label>Descrição</label>
            <textarea name="descricao" rows="2"></textarea>
          </div>
          <div class="form-row two">
            <div>
              <label>Preço*</label>
              <input type="number" name="preco" required min="0" step="0.01">
            </div>
            <div>
              <label><strong>Estoque atual*</strong></label>
              <input type="number" name="estoque" required min="0" step="1">
            </div>
          </div>
          <div class="form-row">
            <label>Link da foto (URL)</label>
            <input type="url" name="imagem" id="pm-imagem">
            <div style="font-size:.85rem;color:#666;margin-top:4px">
              Se enviar um arquivo abaixo, o upload terá prioridade sobre a URL.
            </div>
            <div id="pm-preview" style="margin-top:6px;"></div>
          </div>
          <div class="form-row">
            <label>Foto (upload) — será redimensionada para 300×450</label>
            <input type="file" name="foto" id="pm-file" accept="image/*">
            <div id="pm-preview-file" style="margin-top:6px;"></div>
          </div>
          <div class="form-row">
            <label>Ativo</label>
            <select name="ativo">
              <option value="1">Sim</option>
              <option value="0">Não</option>
            </select>
          </div>
        </form>
        <div id="pm-msg" style="margin-top:8px;color:#b00020;display:none;"></div>
      </div>
      <div class="app-modal-footer" style="display:flex;gap:8px;justify-content:flex-end">
        <button id="pm-salvar" class="btn">Salvar</button>
        <button class="btn secondary pm-close">Cancelar</button>
      </div>
    </div>
  </div>

  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script>
  (function($){
    const escapeHtml = s => String(s||'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
    const money = v => Number(v||0).toFixed(2).replace('.', ',');

    // Abrir modal "Novo"
    $('#btn-novo-produto').on('click', function(){
      resetForm();
      $('#pm-title').text('Novo produto');
      $('#produto-modal').fadeIn(120);
    });

    // Fechar modal
    $(document).on('click', '.pm-close', function(){
      $('#produto-modal').fadeOut(120);
    });

    // Preview por arquivo
    $('#pm-file').on('change', function(){
      const file = this.files && this.files[0];
      const $pvf = $('#pm-preview-file');
      $pvf.empty();
      if (file){
        const url = URL.createObjectURL(file);
        $pvf.html('<img src="'+url+'" alt="preview" style="max-width:120px;max-height:120px;border-radius:8px;border:1px solid #eee">');
      }
    });

    // Salvar (Create/Update)
    $('#pm-salvar').on('click', function(){
      const form = document.getElementById('produto-form');
      /*if (!form.checkValidity()){
        $('#pm-msg').text('Preencha os campos obrigatórios.').show();
        return;
      }*/
      $('#pm-msg').hide().text('');

      const fd = new FormData(form);
      $.ajax({
        url: 'produtos.php?action=salvar',
        method: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        dataType: 'json'
      }).done(function(resp){
        if (!resp || !resp.sucesso){
          $('#pm-msg').text(resp?.mensagem || 'Erro ao salvar.').show();
          return;
        }
        upsertCard(resp.produto);
        $('#produto-modal').fadeOut(120);
      }).fail(function(){
        $('#pm-msg').text('Falha na comunicação com o servidor.').show();
      });
    });

    // Editar
    $(document).on('click', '.btn-editar', function(){
      const id = Number($(this).data('id'));
      $.getJSON('produtos.php?action=buscar',{id})
        .done(function(resp){
          if (!resp || !resp.sucesso){
            alert(resp?.mensagem || 'Erro ao carregar produto.');
            return;
          }
          fillForm(resp.produto);
          $('#pm-title').text('Editar produto');
          $('#produto-modal').fadeIn(120);
        })
        .fail(function(){ alert('Falha na comunicação com o servidor.'); });
    });

    // Excluir (soft delete)
    $(document).on('click', '.btn-excluir', function(){
      const id = Number($(this).data('id'));
      if (!confirm('Remover este produto do catálogo (inativar)?')) return;
      $.post('produtos.php?action=excluir',{id},function(resp){
        if (!resp || !resp.sucesso){
          alert(resp?.mensagem || 'Erro ao excluir.');
          return;
        }
        $('.card[data-id="'+id+'"]').remove();
      },'json').fail(function(){ alert('Falha na comunicação com o servidor.'); });
    });

    /* ===== NOVO: Entrada rápida (ícone ➕) ===== */
    $(document).on('click', '.btn-entrada-rapida', function(){
      const id = Number($(this).data('id'));
      const qtdStr = prompt('Quantidade para adicionar ao estoque:','');
      if (qtdStr === null) return; // cancelado
      const qtd = parseInt(qtdStr, 10);
      if (!Number.isFinite(qtd) || qtd <= 0){
        alert('Informe um número inteiro positivo.');
        return;
      }

      $.post('produtos.php?action=incrementar',{id, qtd}, function(resp){
        if (!resp || !resp.sucesso){
          alert(resp?.mensagem || 'Erro ao registrar entrada.');
          return;
        }
        upsertCard(resp.produto); // atualiza card
      }, 'json').fail(function(){
        alert('Falha na comunicação com o servidor.');
      });
    });

    /* ===== Helpers ===== */
    function resetForm(){
      $('#produto-form')[0].reset();
      $('#produto-form [name="id"]').val('');
      $('#produto-form [name="ativo"]').val('1');
      $('#pm-msg').hide().text('');
      $('#pm-preview, #pm-preview-file').empty();
    }
    function fillForm(p){
      $('#produto-form [name="id"]').val(p.id || '');
      $('#produto-form [name="nome"]').val(p.nome || '');
      $('#produto-form [name="descricao"]').val(p.descricao || '');
      $('#produto-form [name="preco"]').val(p.preco || '');
      $('#produto-form [name="estoque"]').val(p.estoque_atual ?? p.estoque ?? 0);
      $('#produto-form [name="imagem"]').val(p.imagem || '');
      $('#produto-form [name="ativo"]').val(p.ativo ? '1' : '0');
    }
    function upsertCard(p){
      const cardSel = '.card[data-id="'+p.id+'"]';
      const img = p.imagem
        ? '<img src="'+escapeHtml(p.imagem)+'" alt="'+escapeHtml(p.nome)+'">'
        : '<div class="noimg">Sem imagem</div>';

      const html = `
      <div class="card" data-id="${p.id}">
        ${img}
        <div class="info">
          <h4>${escapeHtml(p.nome)}</h4>
          <div class="preco">R$ ${money(p.preco)}</div>
          <div class="meta">
            <span class="pill">Entrada (hist.): ${Number(p.entrada_historica ?? 0)}</span>
            <span class="pill ${Number(p.estoque_atual ?? 0) <= 3 ? 'warn' : ''}">
              Estoque: <strong class="estoque-num">${Number(p.estoque_atual ?? 0)}</strong>
            </span>
            <span class="pill-actions">
              <button type="button" class="pill-btn btn-entrada-rapida" title="Adicionar ao estoque" data-id="${p.id}">
                <i class="fa fa-plus"></i> Entrada
              </button>
            </span>
          </div>
          <div class="prod-actions">
            <button type="button" class="btn btn-mini btn-primary btn-editar" data-id="${p.id}">
              <i class="fa fa-edit"></i> Alterar
            </button>
            <button type="button" class="btn btn-mini btn-danger btn-excluir" data-id="${p.id}">
              <i class="fa fa-trash"></i> Excluir
            </button>
          </div>
        </div>
      </div>`;
      if ($(cardSel).length) $(cardSel).replaceWith(html);
      else $('.grid').prepend(html);
    }
  })(jQuery);
  </script>
</body>
</html>
