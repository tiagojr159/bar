<?php
// abertura_caixa.php

// Sessão e verificações
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/verificar_sessao.php'; // garante login

use App\Support\DB;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('America/Recife');

// ===== Dados do usuário logado (ajuste se seus nomes de sessão forem outros) =====
$usuarioId   = isset($_SESSION['usuario_id'])   ? (int)$_SESSION['usuario_id']   : (isset($_SESSION['usuario']['id'])   ? (int)$_SESSION['usuario']['id']   : null);
$usuarioNome = isset($_SESSION['usuario_nome']) ? (string)$_SESSION['usuario_nome'] : (isset($_SESSION['usuario']['nome']) ? (string)$_SESSION['usuario']['nome'] : 'Usuário');
$nivelRaw    = $_SESSION['usuario_nivel']               ?? ($_SESSION['usuario']['usuario_nivel'] ?? null);
$isAdmin     = ((string)$nivelRaw === '1');

// Conexão
$con = DB::conn();
if (!($con instanceof mysqli)) {
  http_response_code(500);
  exit('Erro: conexão MySQLi não inicializada.');
}

$flash = null;

// ===== Ação: abrir caixa (qualquer usuário logado) =====
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['acao'] ?? '') === 'abrir') {
  $sql = "INSERT INTO abertura_caixa (usuario_id, usuario_nome, data_abertura) VALUES (?, ?, NOW())";
  $st  = $con->prepare($sql);
  $uid = $usuarioId ?: null;
  $unm = $usuarioNome ?: 'Usuário';
  $st->bind_param('is', $uid, $unm);
  $st->execute();
  $st->close();

  // ================== AJUSTE PONTUAL: reset da numeração de mesas ==================
  try {
    $con->begin_transaction();

    // (1) Opcional mas recomendado: fechar pedidos ainda abertos do ciclo anterior
    $con->query("UPDATE pedidos SET status = 'fechado' WHERE LOWER(status) = 'aberto'");

    // (2) Resetar a numeração visível das mesas para reiniciar do 1 no próximo ciclo.
    // Se a coluna 'numero' NÃO for UNIQUE, você pode usar 0:
    // $con->query("UPDATE mesas SET numero = 0");

    // Se a coluna 'numero' for UNIQUE, usar NULL evita violação de chave única,
    // e MAX(numero) vai ignorar NULL, permitindo recomeçar do 1.
    $con->query("UPDATE mesas SET numero = NULL");

    $con->commit();
  } catch (Throwable $e) {
    // Em caso de erro, apenas registra e segue sem travar a abertura do caixa
    if ($con->errno) {
      $con->rollback();
    }
    // Você pode logar o erro com seu logger padrão, ex. error_log($e->getMessage());
  }
  // ================================================================================

  header('Location: abertura_caixa.php?ok=1');
  exit;
}

// ===== Admin-only: editar data_abertura =====
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['acao'] ?? '') === 'editar') {
  if (!$isAdmin) {
    http_response_code(403);
    exit('Sem permissão.');
  }
  $id = (int)($_POST['id'] ?? 0);
  $dt = trim((string)($_POST['data_abertura'] ?? ''));

  // input type="datetime-local" vem no formato "YYYY-MM-DDTHH:MM"
  if ($id > 0 && $dt !== '') {
    // Converte para "Y-m-d H:i:s"
    $ts = DateTime::createFromFormat('Y-m-d\TH:i', $dt);
    if ($ts === false) {
      $flash = ['type' => 'err', 'msg' => 'Data inválida.'];
    } else {
      $fmt = $ts->format('Y-m-d H:i:s');
      $st = $con->prepare("UPDATE abertura_caixa SET data_abertura=? WHERE id=? LIMIT 1");
      $st->bind_param('si', $fmt, $id);
      $st->execute();
      $st->close();
      $flash = ['type' => 'ok', 'msg' => 'Data de abertura atualizada.'];
    }
  }
}

// ===== Admin-only: excluir registro =====
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['acao'] ?? '') === 'excluir') {
  if (!$isAdmin) {
    http_response_code(403);
    exit('Sem permissão.');
  }
  $id = (int)($_POST['id'] ?? 0);
  if ($id > 0) {
    $st = $con->prepare("DELETE FROM abertura_caixa WHERE id=? LIMIT 1");
    $st->bind_param('i', $id);
    $st->execute();
    $st->close();
    $flash = ['type' => 'ok', 'msg' => 'Registro excluído.'];
  }
}

// Lista de aberturas (mais recentes primeiro)
$sqlList = "
  SELECT id, usuario_id, usuario_nome, data_abertura
    FROM abertura_caixa
   ORDER BY data_abertura DESC, id DESC
";
$rs = $con->query($sqlList);
$registros = $rs->fetch_all(MYSQLI_ASSOC);

// helpers
function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function dataBR($ts)
{
  return $ts ? date('d/m/Y H:i', strtotime($ts)) : '-';
}
function toDatetimeLocal($ts)
{
  // retorna no formato aceito pelo input datetime-local (YYYY-MM-DDTHH:MM)
  return $ts ? date('Y-m-d\TH:i', strtotime($ts)) : '';
}

?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="utf-8">
  <title>Abertura de Caixa</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    body {
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, 'Helvetica Neue', Arial, sans-serif;
      margin: 0;
      background: #f6f7f9;
      color: #111
    }

    .container {
      max-width: 1000px;
      margin: 20px auto;
      padding: 0 16px
    }

    .card {
      background: #fff;
      border: 1px solid #eee;
      border-radius: 12px;
      margin-bottom: 16px;
      overflow: hidden
    }

    .card h3 {
      margin: 0;
      padding: 12px 14px;
      border-bottom: 1px solid #eee
    }

    .card .content {
      padding: 12px 14px
    }

    .btn {
      display: inline-block;
      background: #0d6efd;
      color: #fff;
      border: 0;
      border-radius: 10px;
      padding: 10px 14px;
      cursor: pointer;
      text-decoration: none;
      font-weight: 700
    }

    .btn.gray {
      background: #6c757d
    }

    .btn.red {
      background: #dc3545
    }

    .btn.outline {
      background: #fff;
      color: #0d6efd;
      border: 1px solid #0d6efd
    }

    .table-actions {
      display: flex;
      gap: 8px;
      align-items: center
    }

    table {
      width: 100%;
      border-collapse: collapse
    }

    th,
    td {
      padding: 10px;
      border-bottom: 1px solid #eee;
      text-align: left
    }

    th {
      background: #fafafa
    }

    .header-actions {
      display: flex;
      gap: 10px;
      align-items: center;
      flex-wrap: wrap
    }

    .muted {
      color: #666
    }

    .flash {
      padding: 10px 12px;
      border-radius: 10px;
      margin-bottom: 12px;
      font-weight: 600
    }

    .flash.ok {
      background: #d1e7dd;
      border: 1px solid #badbcc;
      color: #0f5132
    }

    .flash.err {
      background: #f8d7da;
      border: 1px solid #f5c2c7;
      color: #842029
    }

    /* ===== Modal pequeno (admin) ===== */
    .modal {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .45);
      display: none;
      z-index: 9999
    }

    .modal.open {
      display: block
    }

    .modal-content {
      width: min(420px, 95vw);
      margin: 10vh auto;
      background: #fff;
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 12px 44px rgba(0, 0, 0, .25)
    }

    .modal-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 10px 12px;
      border-bottom: 1px solid #eee
    }

    .modal-header h4 {
      margin: 0;
      font-size: 1.05rem
    }

    .modal-body {
      padding: 12px
    }

    .modal-footer {
      padding: 10px 12px;
      border-top: 1px solid #eee;
      display: flex;
      gap: 8px;
      justify-content: flex-end
    }

    .close {
      border: 0;
      background: transparent;
      font-size: 20px;
      cursor: pointer
    }

    label {
      display: block;
      margin: .4rem 0 .25rem
    }

    input[type="datetime-local"] {
      width: 100%;
      padding: 10px;
      border: 1px solid #ddd;
      border-radius: 8px
    }
  </style>
</head>

<body>

  <?php include __DIR__ . '/../views/partials/header.php'; ?>

  <div class="container">

    <div class="card">
      <h3>Abertura de Caixa</h3>
      <div class="content">
        <?php if (isset($_GET['ok'])): ?>
          <div class="flash ok">Caixa aberto com sucesso.</div>
        <?php endif; ?>
        <?php if ($flash && $flash['type'] === 'ok'): ?>
          <div class="flash ok"><?= h($flash['msg']) ?></div>
        <?php elseif ($flash): ?>
          <div class="flash err"><?= h($flash['msg']) ?></div>
        <?php endif; ?>

        <div class="header-actions">
          <form method="post" onsubmit="return confirm('Confirmar abertura de caixa agora?')">
            <input type="hidden" name="acao" value="abrir">
            <button type="submit" class="btn">Abrir Caixa</button>
          </form>
          <span class="muted">Usuário: <strong><?= h($usuarioNome) ?></strong></span>
          <?php if ($isAdmin): ?>
            <span class="muted">• Nível: Admin</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="card">
      <h3>Histórico de Aberturas</h3>
      <div class="content" style="overflow:auto">
        <?php if (empty($registros)): ?>
          <div class="muted">Nenhuma abertura registrada.</div>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Data/Hora</th>
                <th>Usuário</th>
                <th>ID Usuário</th>
                <?php if ($isAdmin): ?><th style="width:180px">Ações</th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($registros as $row): ?>
                <tr data-row-id="<?= (int)$row['id'] ?>"
                  data-row-data="<?= h(toDatetimeLocal($row['data_abertura'])) ?>"
                  data-row-user="<?= h($row['usuario_nome']) ?>">
                  <td><?= (int)$row['id'] ?></td>
                  <td><?= h(dataBR($row['data_abertura'])) ?></td>
                  <td><?= h($row['usuario_nome']) ?></td>
                  <td><?= $row['usuario_id'] !== null ? (int)$row['usuario_id'] : '-' ?></td>
                  <?php if ($isAdmin): ?>
                    <td class="table-actions">
                      <!-- AJUSTE PONTUAL: botão que abre o modal para alterar o registro -->
                      <button
                        class="btn outline btn-editar"
                        type="button"
                        title="Editar abertura"
                        data-id="<?= (int)$row['id'] ?>"
                        data-data="<?= h(toDatetimeLocal($row['data_abertura'])) ?>"
                        data-user="<?= h($row['usuario_nome']) ?>">Editar</button>
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

  <?php if ($isAdmin): ?>
    <!-- ===== Modal Admin (editar/excluir) ===== -->
    <div id="admin-modal" class="modal" aria-hidden="true" role="dialog" aria-labelledby="admin-modal-title">
      <div class="modal-content">
        <div class="modal-header">
          <h4 id="admin-modal-title">Gerenciar abertura</h4>
          <button class="close" type="button" aria-label="Fechar" onclick="closeAdminModal()">×</button>
        </div>
        <div class="modal-body">
          <form id="form-editar" method="post" style="margin-bottom:8px">
            <input type="hidden" name="acao" value="editar">
            <input type="hidden" name="id" id="edit-id" value="">
            <label for="edit-data">Data/Hora da abertura</label>
            <input type="datetime-local" id="edit-data" name="data_abertura" required>
            <div class="modal-footer" style="padding:10px 0 0;border-top:none;justify-content:flex-start;gap:8px">
              <button type="submit" class="btn">Salvar</button>
              <button type="button" class="btn gray" onclick="closeAdminModal()">Cancelar</button>
            </div>
          </form>

          <form id="form-excluir" method="post" onsubmit="return confirm('Tem certeza que deseja excluir este registro?')">
            <input type="hidden" name="acao" value="excluir">
            <input type="hidden" name="id" id="del-id" value="">
            <div class="modal-footer" style="justify-content:space-between;border-top:1px solid #eee;margin-top:12px">
              <div class="muted" id="info-registro"></div>
              <button type="submit" class="btn red">Excluir</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <script>
      function openAdminModalFrom(id, data, user) {
        document.getElementById('edit-id').value = id;
        document.getElementById('del-id').value = id;
        document.getElementById('edit-data').value = data || '';
        document.getElementById('info-registro').textContent = 'Registro #' + id + ' — ' + (user || '');

        const m = document.getElementById('admin-modal');
        m.classList.add('open');
        m.setAttribute('aria-hidden', 'false');
      }

      function openAdminModal(row) {
        const id = row.getAttribute('data-row-id');
        const data = row.getAttribute('data-row-data'); // formato datetime-local
        const user = row.getAttribute('data-row-user');
        openAdminModalFrom(id, data, user);
      }

      function closeAdminModal() {
        const m = document.getElementById('admin-modal');
        m.classList.remove('open');
        m.setAttribute('aria-hidden', 'true');
      }

      document.addEventListener('click', function(ev) {
        // AJUSTE PONTUAL: suporta clique no botão .btn-editar com data-*
        const btn = ev.target.closest('.btn-editar');
        if (btn) {
          const id = btn.getAttribute('data-id');
          const data = btn.getAttribute('data-data');
          const user = btn.getAttribute('data-user');

          if (id) { // preferir dados do botão
            openAdminModalFrom(id, data, user);
          } else {
            const row = btn.closest('tr');
            if (row) openAdminModal(row);
          }
        }

        // fechar clicando fora do conteúdo
        const modal = document.getElementById('admin-modal');
        if (ev.target === modal) {
          closeAdminModal();
        }
      });

      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
          closeAdminModal();
        }
      });
    </script>
  <?php endif; ?>

</body>

</html>