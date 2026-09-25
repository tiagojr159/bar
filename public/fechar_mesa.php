<?php
// public/fechar_mesa.php
declare(strict_types=1);

use App\Support\DB;

header('Content-Type: application/json; charset=utf-8');

try {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();

    // === Boot / Autoload ===
    // Garante que App\Support\DB exista mesmo quando chamado direto de /public
    require_once __DIR__ . '/../bootstrap.php';
    if (!class_exists('\App\Support\DB')) {
        require_once __DIR__ . '/../src/Support/DB.php';
    }

    // === Conexão ===
    $con = DB::conn();
    if (!($con instanceof mysqli)) {
        throw new RuntimeException('Conexão MySQLi não inicializada.');
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $con->set_charset('utf8mb4');

    // === Helpers ===
    $toCents = function ($v) : ?int {
        if ($v === null) return null;
        $s = trim((string)$v);
        if ($s === '') return null;
        // aceita "1.234,56" ou "1234.56"
        $s = str_replace(' ', '', $s);
        $s = str_replace('.', '', $s);   // remove separadores de milhar
        $s = str_replace(',', '.', $s);  // vírgula -> ponto
        $f = (float)$s;
        return (int)round($f * 100);
    };

    $json_fail = function (string $msg, int $code = 400) {
        http_response_code($code);
        echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    };

    $json_ok = function (array $extra = []) {
        echo json_encode(array_merge(['success' => true], $extra), JSON_UNESCAPED_UNICODE);
        exit;
    };

    // === Entrada ===
    $pedido_id = isset($_POST['pedido_id']) ? (int)$_POST['pedido_id'] : 0;
    $forma     = strtolower(trim((string)($_POST['forma_pagamento'] ?? '')));

    // do front (sempre envie)
    $valor_cobrado_cents  = $toCents($_POST['valor_cobrado']  ?? null);
    // somente para dinheiro
    $valor_recebido_cents = $toCents($_POST['valor_recebido'] ?? null);

    if ($pedido_id <= 0)        $json_fail('Parâmetros inválidos: pedido_id');
    if ($forma === '')          $json_fail('Parâmetros inválidos: forma_pagamento');

    // === Busca o pedido (deve estar aberto) ===
    $st = $con->prepare("SELECT id, status, total FROM pedidos WHERE id = ? AND excluido_em IS NULL LIMIT 1");
    $st->bind_param('i', $pedido_id);
    $st->execute();
    $p = $st->get_result()->fetch_assoc();
    if (!$p)                    $json_fail('Pedido não encontrado', 404);

    $statusAtual = strtolower((string)($p['status'] ?? ''));
    if ($statusAtual !== 'aberto') {
        $json_fail('Pedido já fechado', 409);
    }

    // total do pedido (fallback) em centavos
    $total_pedido_cents = (int)round(((float)$p['total']) * 100);

    // se front não mandou, usa total do pedido
    if ($valor_cobrado_cents === null) {
        $valor_cobrado_cents = $total_pedido_cents;
    }

    // === Regras de fechamento ===

    // 1) Cortesia / Isento: quando forma=cortesia ou valor a cobrar <= 0
    if ($forma === 'cortesia' || $valor_cobrado_cents <= 0) {
        $statusNovo = 'pago'; // ou 'fechado' se o seu fluxo preferir
        $formaFinal = 'cortesia';

        $st2 = $con->prepare("
            UPDATE pedidos
               SET status = ?,
                   data_pagamento = NOW(),
                   forma_pagamento = ?
             WHERE id = ? AND excluido_em IS NULL
        ");
        $st2->bind_param('ssi', $statusNovo, $formaFinal, $pedido_id);
        $st2->execute();

        $json_ok(['status' => $statusNovo, 'forma' => $formaFinal]);
    }

    // 2) Dinheiro: precisa validar recebido >= cobrado (em centavos)
    if ($forma === 'dinheiro') {
        if ($valor_recebido_cents === null) {
            $json_fail('Valor recebido não informado para pagamento em dinheiro.');
        }
       /* if ($valor_recebido_cents < $valor_cobrado_cents) {
            $json_fail('Valor recebido insuficiente!');
        }*/

        $statusNovo = 'pago';
        $st2 = $con->prepare("
            UPDATE pedidos
               SET status = ?,
                   data_pagamento = NOW(),
                   forma_pagamento = ?
             WHERE id = ? AND excluido_em IS NULL
        ");
        $st2->bind_param('ssi', $statusNovo, $forma, $pedido_id);
        $st2->execute();

        // troco (se quiser exibir no front)
        $troco_cents = max(0, $valor_recebido_cents - $valor_cobrado_cents);
        $json_ok([
            'status' => $statusNovo,
            'forma'  => $forma,
            'troco'  => number_format($troco_cents / 100, 2, ',', '.'),
        ]);
    }

    // 3) PIX / Cartão / outros meios offline-confirmados:
    // não exigimos valor_recebido aqui (o PIX já é verificado em outro endpoint)
    if (in_array($forma, ['pix', 'cartao', 'cartão', 'debito', 'crédito', 'credito'], true)) {
        // normaliza forma "cartão" -> "cartao"
        if ($forma === 'cartão') $forma = 'cartao';
        if ($forma === 'crédito') $forma = 'credito';

        $statusNovo = 'pago';
        $st2 = $con->prepare("
            UPDATE pedidos
               SET status = ?,
                   data_pagamento = NOW(),
                   forma_pagamento = ?
             WHERE id = ? AND excluido_em IS NULL
        ");
        $st2->bind_param('ssi', $statusNovo, $forma, $pedido_id);
        $st2->execute();

        $json_ok(['status' => $statusNovo, 'forma' => $forma]);
    }

    // 4) Forma desconhecida
    $json_fail('Forma de pagamento não suportada: ' . $forma);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
