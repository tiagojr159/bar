<?php
require_once 'vendor/autoload.php';

use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\MercadoPagoConfig;

// Configure suas credenciais
MercadoPagoConfig::setAccessToken("APP_USR-6055636657307799-090922-d876c3641af99df0094259a9d355e285-94442799");
MercadoPagoConfig::setRuntimeEnviroment(MercadoPagoConfig::LOCAL);

// Dados do pagamento
$payment_data = [
    "transaction_amount" => 0.50,
    "description" => "Bar Azerutan",
    "payment_method_id" => "pix",
    "payer" => [
        "email" => "tiagojr159@hotmail.com",
        "first_name" => "Tiago",
        "last_name" => "Junior",
        "identification" => [
            "type" => "CPF",
            "number" => "06803396479"
        ],
        "address" => [
            "zip_code" => "53640-120",
            "street_name" => "Av. Marechal Hermes",
            "street_number" => "308",
            "neighborhood" => "Sitio Historico",
            "city" => "Igarassu",
            "federal_unit" => "PE"
        ]
    ]
];

try {
    $client = new PaymentClient();
    $payment = $client->create($payment_data);
    
    // Verifica se o pagamento foi criado com sucesso
    if (isset($payment->id)) {
        $pix_data = $payment->point_of_interaction->transaction_data;
        
        echo "<h1>Pagamento via PIX</h1>";
        echo "<p>Valor: R$ " . number_format($payment->transaction_amount, 2, ',', '.') . "</p>";
        echo "<p>Código copia e cola:</p>";
        echo "<textarea readonly style='width:100%; height:60px'>" . $pix_data->qr_code . "</textarea>";
        echo "<p>QR Code:</p>";
        echo "<img src='" . $pix_data->qr_code_base64 . "' alt='QR Code PIX' />";
        echo "<p>ID da transação: " . $payment->id . "</p>";
        echo "<p>Status: " . $payment->status . "</p>";
        
        // Área para verificação de status (simplificada)
        echo "<hr>";
        echo "<h2>Verificar Status</h2>";
        echo "<form method='post'>";
        echo "<input type='hidden' name='payment_id' value='" . $payment->id . "'>";
        echo "<button type='submit' name='check_status'>Verificar Status</button>";
        echo "</form>";
        
        if (isset($_POST['check_status'])) {
            $payment_check = $client->get($_POST['payment_id']);
            echo "<p>Status atual: " . $payment_check->status . "</p>";
            echo "<p>Status detalhado: " . $payment_check->status_detail . "</p>";
        }
    } else {
        echo "Erro ao criar pagamento: " . $payment->message;
    }
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage();
}
?>