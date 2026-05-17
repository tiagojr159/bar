<?php
use App\Support\DB;

/**
 * gerar_pagamento_pix.php
 * - aceita centavos (ex.: 0,03)
 * - usa payer completo
 * - idempotência por uniqid()
 * - lê token do config/config.php
 */
header('Content-Type: application/json; charset=utf-8');

try {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();

    // === CONFIG ===
    $config = require dirname(__DIR__) . '/config/config.php';
    $access_token = $config['mercadopago']['access_token'] ?? '';
    if ($access_token === '') {
        echo json_encode(['success'=>false,'error'=>'MP_ACCESS_TOKEN não configurado.']);
        exit;
    }

    // === DB via helper (sem conexao.php) ===
    require_once __DIR__ . '/../bootstrap.php';

    $con = DB::conn();
    if (!($con instanceof mysqli)) { throw new Exception('Conexão MySQLi não inicializada.'); }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $con->set_charset('utf8mb4');

    // Parâmetros
    $pedido_id = isset($_POST['pedido_id']) ? (int)$_POST['pedido_id'] : 0;
    $valor_str = isset($_POST['valor']) ? str_replace(',', '.', trim((string)$_POST['valor'])) : '';
    $valor = ($valor_str === '' ? 0.0 : (float)$valor_str);
    if ($pedido_id <= 0) { echo json_encode(['success'=>false,'error'=>'pedido_id inválido']); exit; }

    // Pedido
    $st = $con->prepare("SELECT id, status, total, pagamento_id FROM pedidos WHERE id=? LIMIT 1");
    $st->bind_param('i', $pedido_id);
    $st->execute();
    $st->bind_result($id_db, $status_db, $total_db, $pagamento_id_db);
    $found = $st->fetch();
    $st->close();

    if (!$found) { echo json_encode(['success'=>false,'error'=>'Pedido não encontrado']); exit; }
    if ($status_db === 'pago') { echo json_encode(['success'=>false,'error'=>'Pedido já está pago']); exit; }

    if ($valor <= 0) $valor = (float)$total_db;
    if ($valor < 0.01) $valor = 0.01;

    // Payload MP
    $tx_amount = (float) number_format((float)$valor, 2, '.', '');
    $payload = [
        "transaction_amount" => $tx_amount,
        "description"        => "Bar Azerutan - Pedido #{$pedido_id}",
        "payment_method_id"  => "pix",
        "payer" => [
            "email"      => "tiagojr159@hotmail.com",
            "first_name" => "Tiago",
            "last_name"  => "Junior",
            "identification" => ["type"=>"CPF","number"=>"06803396479"],
            "address" => [
                "zip_code"=>"53640-120","street_name"=>"Av. Marechal Hermes","street_number"=>"308",
                "neighborhood"=>"Sitio Historico","city"=>"Igarassu","federal_unit"=>"PE"
            ]
        ],
        "external_reference" => "pedido_{$pedido_id}"
    ];

    // Chamada MP
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.mercadopago.com/v1/payments',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $access_token,
            'X-Idempotency-Key: ' . uniqid('pix_', true)
        ],
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) { $err = curl_error($ch); curl_close($ch); echo json_encode(['success'=>false,'error'=>'Falha cURL MP: '.$err]); exit; }
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($resp, true);
    if ($http < 200 || $http >= 300) {
        $mp_error = $data['message'] ?? $data['error'] ?? 'Erro ao criar pagamento PIX';
        echo json_encode(['success'=>false,'error'=>"MP HTTP {$http}: {$mp_error}", 'raw'=>$data]); exit;
    }

    $mpId = (string)($data['id'] ?? '');
    $poi  = $data['point_of_interaction']['transaction_data'] ?? [];
    $qr   = $poi['qr_code']        ?? '';
    $qr64 = $poi['qr_code_base64'] ?? '';
    $turl = $poi['ticket_url']     ?? ($data['transaction_details']['external_resource_url'] ?? '');
    if ($mpId === '' || $qr === '' || $qr64 === '') {
        echo json_encode(['success'=>false,'error'=>'Retorno PIX incompleto','raw'=>$data]); exit;
    }

    // Vincula no pedido
    $stUp = $con->prepare("UPDATE pedidos SET pagamento_id=?, total=? WHERE id=?");
    $total_to_set = (float) number_format($tx_amount, 2, '.', '');
    $stUp->bind_param('sdi', $mpId, $total_to_set, $pedido_id);
    $stUp->execute();
    $stUp->close();

    echo json_encode([
        'success'        => true,
        'pagamento_id'   => $mpId,
        'status'         => (string)($data['status'] ?? 'pending'),
        'qr_code'        => $qr,
        'qr_code_base64' => $qr64,
        'ticket_url'     => $turl,
        'pedido_id'      => $pedido_id,
        'valor'          => $total_to_set
    ]);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
