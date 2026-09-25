<?php include __DIR__ . '/../views/partials/header.php'; ?>


<?php
// pedidos.php — Relatório de pedidos (até 300 registros)

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
require __DIR__ . '/../bootstrap.php';

use App\Support\DB;
$con = DB::conn();
if (!($con instanceof mysqli)) {
  http_response_code(500);
  die('Conexão MySQLi não inicializada.');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ---------- Helpers ----------
function h($s)
{
  return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
function moeda($v)
{
  return 'R$ ' . number_format((float)$v, 2, ',', '.');
}

// ---------- Filtros ----------
date_default_timezone_set('America/Recife'); // ajuste se necessário

$hoje      = date('Y-m-d');
$inicioPad = date('Y-m-d', strtotime('-30 days'));

$ini = isset($_GET['ini']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['ini']) ? $_GET['ini'] : $inicioPad;
$fim = isset($_GET['fim']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fim']) ? $_GET['fim'] : $hoje;
$stt = isset($_GET['status']) ? trim($_GET['status']) : ''; // '', 'pago', 'aberto', 'cancelado'...

// normaliza fim para fim do dia
$fimTs = DateTime::createFromFormat('Y-m-d H:i:s', $fim . ' 23:59:59') ?: new DateTime($fim . ' 23:59:59');
$iniTs = DateTime::createFromFormat('Y-m-d H:i:s', $ini . ' 00:00:00') ?: new DateTime($ini . ' 00:00:00');
$fimStr = $fimTs->format('Y-m-d H:i:s');
$iniStr = $iniTs->format('Y-m-d H:i:s');

// ---------- Consulta principal (limite 300) ----------
$sql = "
  SELECT p.id, p.mesa_id, p.usuario_id, p.data_pedido, p.status, p.total, p.pagamento_id,
         m.numero AS mesa_numero
  FROM pedidos p
  LEFT JOIN mesas m ON m.id = p.mesa_id
  WHERE p.excluido_em IS NULL AND p.data_pedido BETWEEN ? AND ?
";
$params = [$iniStr, $fimStr];
$types  = 'ss';

if ($stt !== '') {
  $sql .= " AND p.status = ? ";
  $params[] = $stt;
  $types   .= 's';
}

$sql .= " ORDER BY p.data_pedido DESC LIMIT 300";
$st = $con->prepare($sql);
$st->bind_param($types, ...$params);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);

// ---------- Resumo ----------
$sumSql = "
  SELECT
    SUM(total)                                     AS sum_total,
    SUM(CASE WHEN status = 'pago'   THEN total END) AS sum_pago,
    SUM(CASE WHEN status <> 'pago'  THEN total END) AS sum_aberto,
    SUM(CASE WHEN status = 'pago'   THEN 1 ELSE 0 END) AS cnt_pago,
    SUM(CASE WHEN status <> 'pago'  THEN 1 ELSE 0 END) AS cnt_aberto,
    COUNT(*) AS cnt_total
  FROM pedidos
  WHERE excluido_em IS NULL AND data_pedido BETWEEN ? AND ?
";
$sumTypes = 'ss';
$sumParams = [$iniStr, $fimStr];

if ($stt !== '') {
  $sumSql .= " AND status = ? ";
  $sumTypes .= 's';
  $sumParams[] = $stt;
}
$st2 = $con->prepare($sumSql);
$st2->bind_param($sumTypes, ...$sumParams);
$st2->execute();
$sum = $st2->get_result()->fetch_assoc() ?: [
  'sum_total' => 0,
  'sum_pago' => 0,
  'sum_aberto' => 0,
  'cnt_pago' => 0,
  'cnt_aberto' => 0,
  'cnt_total' => 0
];
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="utf-8">
  <title>Relatório de Pedidos - Bar Azerutan</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <style>
    :root {
      --bg: #f6f7f9;
      --card: #fff;
      --text: #111;
      --muted: #666;
      --primary: #0d6efd;
      --danger: #dc3545;
      --ok: #198754;
      --border: #e6e6e6;
    }

    body {
      font-family: system-ui, Segoe UI, Roboto, Ubuntu, Arial, sans-serif;
      margin: 0;
      background: var(--bg);
      color: var(--text)
    }

    .btn {
      background: var(--primary);
      color: #fff;
      border: 0;
      border-radius: 8px;
      padding: 8px 12px;
      cursor: pointer;
      text-decoration: none
    }

    .btn.secondary {
      background: #6c757d
    }

    .btn:hover {
      opacity: .95
    }

    .container {
      max-width: 1100px;
      margin: 20px auto;
      padding: 0 16px
    }

    .card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 14px
    }

    .filters {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      align-items: flex-end;
      margin-bottom: 14px
    }

    .filters .field {
      display: flex;
      flex-direction: column
    }

    .filters input,
    .filters select {
      padding: 8px;
      border: 1px solid #ddd;
      border-radius: 8px;
      min-width: 170px
    }

    .summary {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 10px;
      margin-bottom: 14px
    }

    .summary .box {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 12px
    }

    .summary .box h4 {
      margin: 0 0 6px 0;
      font-size: .95rem;
      color: var(--muted)
    }

    .summary .val {
      font-weight: 700
    }

    table {
      width: 100%;
      border-collapse: collapse;
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 12px;
      overflow: hidden
    }

    thead th {
      background: #f0f2f5;
      text-align: left;
      padding: 10px;
      border-bottom: 1px solid var(--border);
      font-size: .92rem
    }

    tbody td {
      padding: 10px;
      border-bottom: 1px solid var(--border);
      font-size: .95rem
    }

    tbody tr:hover {
      background: #fafbfc
    }

    .badge {
      display: inline-block;
      padding: 4px 8px;
      border-radius: 999px;
      font-size: .8rem;
      font-weight: 600
    }

    .badge.ok {
      background: #eaf7ee;
      color: #0f6b3c;
      border: 1px solid #bfe3c9
    }

    .badge.no {
      background: #fdecec;
      color: #842029;
      border: 1px solid #f5c2c7
    }

    .muted {
      color: var(--muted)
    }

    .right {
      text-align: right
    }
  </style>
</head>

<body>

  <div class="container">
    <!-- Filtros -->
    <form class="card filters" method="get" action="pedidos.php">
      <div class="field">
        <label>Início</label>
        <input type="date" name="ini" value="<?= h($ini) ?>">
      </div>
      <div class="field">
        <label>Fim</label>
        <input type="date" name="fim" value="<?= h($fim) ?>">
      </div>
      <div class="field">
        <label>Status</label>
        <select name="status">
          <option value="" <?= $stt === '' ? 'selected' : '' ?>>Todos</option>
          <option value="pago" <?= $stt === 'pago' ? 'selected' : '' ?>>Pago</option>
          <option value="aberto" <?= $stt === 'aberto' ? 'selected' : '' ?>>Aberto</option>
          <option value="cancelado" <?= $stt === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
        </select>
      </div>
      <div class="field">
        <button class="btn" type="submit">Filtrar</button>
      </div>
      <div class="muted">Máx. 300 registros.</div>
    </form>

    <!-- Resumo -->
    <div class="summary">
      <div class="box">
        <h4>Pedidos no período</h4>
        <div class="val"><?= (int)$sum['cnt_total'] ?></div>
      </div>
      <div class="box">
        <h4>Total faturado</h4>
        <div class="val"><?= moeda($sum['sum_total'] ?? 0) ?></div>
      </div>
      <div class="box">
        <h4>Total pago</h4>
        <div class="val"><?= moeda($sum['sum_pago'] ?? 0) ?> <span class="muted">(<?= (int)$sum['cnt_pago'] ?>)</span></div>
      </div>
      <div class="box">
        <h4>Em aberto</h4>
        <div class="val"><?= moeda($sum['sum_aberto'] ?? 0) ?> <span class="muted">(<?= (int)$sum['cnt_aberto'] ?>)</span></div>
      </div>
    </div>

    <!-- Tabela -->
    <div class="card" style="padding:0;overflow:auto">
      <table>
        <thead>
          <tr>
            <th style="width:150px">Data</th>
            <th style="width:90px">ID</th>
            <th style="width:90px">Mesa</th>
            <th class="right" style="width:120px">Valor</th>
            <th style="width:120px">Situação</th>
            <th>Pagamento</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr>
              <td colspan="6" class="muted" style="padding:14px">Nenhum pedido encontrado no período.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= date('d/m/Y H:i', strtotime($r['data_pedido'])) ?></td>
                <td>#<?= (int)$r['id'] ?></td>
                <td><?= $r['mesa_numero'] !== null ? (int)$r['mesa_numero'] : '-' ?></td>
                <td class="right"><?= moeda($r['total']) ?></td>
                <td>
                  <?php if (strtolower($r['status']) === 'pago'): ?>
                    <span class="badge ok">Pago</span>
                  <?php else: ?>
                    <span class="badge no"><?= h(ucfirst($r['status'])) ?></span>
                  <?php endif; ?>
                </td>
                <td class="muted"><?= $r['pagamento_id'] ? h($r['pagamento_id']) : '—' ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</body>

</html>
