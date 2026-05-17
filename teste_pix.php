<?php
// URL da API do Mercado Pago
$api_url = "https://api.mercadopago.com/v1/payments";

// Configure suas credenciais
$access_token = "APP_USR-6055636657307799-090922-d876c3641af99df0094259a9d355e285-94442799";

// Dados do pagamento de teste
$payment_data = [
    "transaction_amount" => 0.50, // Será substituído pelo valor do formulário
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

// Função para criar pagamento
function criarPagamento($dados, $token)
{
    global $api_url;

    $payload = json_encode($dados);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . $token,
        "X-Idempotency-Key: " . uniqid() // Adicionando chave de idempotência
    ]);

    // Habilitar verbose para depuração
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    $result = json_decode($response, true);

    if ($http_code == 201 && isset($result['id'])) {
        return [
            'success' => true,
            'id' => $result['id'],
            'qr_code' => $result['point_of_interaction']['transaction_data']['qr_code'],
            'qr_code_base64' => $result['point_of_interaction']['transaction_data']['qr_code_base64'],
            'ticket_url' => $result['point_of_interaction']['transaction_data']['ticket_url'],
            'status' => $result['status'],
            'status_detail' => $result['status_detail']
        ];
    } else {
        return [
            'success' => false,
            'error' => $result['message'] ?? 'Erro desconhecido',
            'response' => $result,
            'http_code' => $http_code
        ];
    }
}

// Função para verificar status
function verificarStatus($payment_id, $token)
{
    $url = "https://api.mercadopago.com/v1/payments/" . $payment_id;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $token
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);

    if ($http_code == 200) {
        return [
            'success' => true,
            'status' => $result['status'],
            'status_detail' => $result['status_detail'],
            'date_approved' => $result['date_approved'] ?? null
        ];
    } else {
        return [
            'success' => false,
            'error' => $result['message'] ?? 'Erro desconhecido',
            'response' => $result
        ];
    }
}

// Processar ações
$resultado = null;
$valor_informado = 0.50; // Valor padrão

// Verificar se é uma requisição AJAX para verificar status
if (isset($_GET['action']) && $_GET['action'] === 'check_status' && isset($_GET['payment_id'])) {
    header('Content-Type: application/json');
    $status_result = verificarStatus($_GET['payment_id'], $access_token);
    echo json_encode($status_result);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['valor']) && !empty($_POST['valor'])) {
        // Validar e formatar o valor
        $valor_informado = str_replace(',', '.', $_POST['valor']);
        $valor_informado = floatval($valor_informado);

        if ($valor_informado <= 0) {
            $resultado = [
                'success' => false,
                'error' => 'O valor deve ser maior que zero'
            ];
        }
    }

    if (isset($_POST['criar_pagamento']) && $valor_informado > 0) {
        // Atualizar o valor no array de pagamento
        $payment_data['transaction_amount'] = $valor_informado;
        $resultado = criarPagamento($payment_data, $access_token);

        echo "<script>document.getElementById('form_pagamento').style.display = 'none';</script>";
    } elseif (isset($_POST['verificar_status']) && isset($_POST['payment_id'])) {
        $resultado = verificarStatus($_POST['payment_id'], $access_token);
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Pagamento</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }

        .container {
            border: 1px solid #ddd;
            padding: 20px;
            border-radius: 5px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        input[type="text"] {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }

        button {
            background-color: #00a650;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin: 5px;
        }

        button:hover {
            background-color: #008040;
        }

        .success {
            color: green;
        }

        .error {
            color: red;
        }

        .qr-code {
            max-width: 200px;
            margin: 10px 0;
            border: 1px solid #ddd;
        }

        textarea {
            width: 100%;
            height: 60px;
            margin: 10px 0;
        }

        .status {
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
        }

        .status.pending {
            background-color: #fff3cd;
            border: 1px solid #ffeeba;
        }

        .status.approved {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
        }

        .status.rejected {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
        }

        .qr-container {
            text-align: center;
            margin: 20px 0;
        }

        .qr-fallback {
            display: none;
            background-color: #f8f9fa;
            padding: 10px;
            border: 1px dashed #ddd;
            border-radius: 5px;
        }

        .valor-display {
            font-size: 24px;
            font-weight: bold;
            color: #00a650;
            margin: 10px 0;
        }

        .checking-status {
            font-style: italic;
            color: #666;
            margin: 10px 0;
        }

        .payment-approved {
            animation: pulse 1s infinite;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.05);
            }

            100% {
                transform: scale(1);
            }
        }

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px;
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            border-radius: 5px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            display: none;
        }

        .test-sound {
            margin-top: 10px;
            background-color: #007bff;
        }

        .test-sound:hover {
            background-color: #0069d9;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>Tiago S. A. Junior</h1>

        <div style="display: block;" id="form_pagamento">
            <form method="post">
                <div class="form-group">
                    <label for="valor">Valor a pagar (R$):</label>
                    <input type="text" id="valor" name="valor" value="<?= number_format($valor_informado, 2, ',', '.') ?>" placeholder="0,00" required>
                </div>
                <button type="submit" name="criar_pagamento">Criar Pagamento PIX</button>
            </form>
        </div>


        <?php if ($resultado): ?>
            <script>
                document.getElementById('form_pagamento').style.display = 'none';
            </script>
            <?php if ($resultado['success']): ?>
                <?php if (isset($resultado['qr_code'])): ?>
                    <div style="display: none;">
                        <h2>Pagamento Criado!</h2>
                        <p><strong>ID do Pagamento:</strong> <?= $resultado['id'] ?></p>
                    </div>
                    <div class="valor-display">
                        Valor: R$ <?= number_format($payment_data['transaction_amount'], 2, ',', '.') ?>
                    </div>

                    <div id="status-container" class="status <?= $resultado['status'] ?>">

                        <div style="display: none;">
                            <p><strong>Status:</strong> <span id="status-text"><?= $resultado['status'] ?></span></p>
                            <p><strong>Detalhes:</strong> <span id="status-detail"><?= $resultado['status_detail'] ?></span></p>
                        </div>



                        <div class="qr-container">
                            <?php
                            $qr_base64 = $resultado['qr_code_base64'];
                            if (strpos($qr_base64, 'data:image') === false) {
                                $qr_base64 = 'data:image/png;base64,' . $qr_base64;
                            }
                            ?>
                            <img src="<?= $qr_base64 ?>" alt="QR Code PIX" class="qr-code"
                                onerror="this.style.display='none'; document.getElementById('qr-fallback').style.display='block';" />

                            <div id="qr-fallback" class="qr-fallback">
                                <p>Não foi possível carregar o QR Code.</p>
                                <p><a href="<?= $resultado['ticket_url'] ?>" target="_blank">Clique aqui para pagar</a></p>
                            </div>
                        </div>
                    </div>

                    <form method="post">
                        <input type="hidden" name="payment_id" value="<?= $resultado['id'] ?>">
                        <button type="submit" name="verificar_status">Verificar Status Manualmente</button>
                    </form>

                    <div id="checking-status" class="checking-status" style="display: none;">
                        <img src="http://www.policiacivilrj.net.br/imagens/animated_loader.gif" width="100" alt="Carregando...">
                    </div>


                <?php elseif (isset($resultado['status'])): ?>
                    <h2>Status do Pagamento</h2>
                    <div class="status <?= $resultado['status'] ?>">
                        <p><strong>Status:</strong> <?= $resultado['status'] ?></p>
                        <p><strong>Detalhes:</strong> <?= $resultado['status_detail'] ?></p>
                        <?php if ($resultado['date_approved']): ?>
                            <p><strong>Aprovado em:</strong> <?= date('d/m/Y H:i:s', strtotime($resultado['date_approved'])) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <p class="error">Erro: <?= $resultado['error'] ?></p>
                <?php if (isset($resultado['http_code'])): ?>
                    <p><strong>Código HTTP:</strong> <?= $resultado['http_code'] ?></p>
                <?php endif; ?>
                <?php if (isset($resultado['response'])): ?>
                    <pre><?= print_r($resultado['response'], true) ?></pre>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>


    </div>

    <!-- Notificação de pagamento aprovado -->
    <div id="notification" class="notification">
        <h3>Pagamento Aprovado!</h3>
        <p>O pagamento foi confirmado com sucesso.</p>
    </div>

    <!-- Elemento de áudio para notificação -->
    <audio id="notification-sound" preload="auto">
        <source src="data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmwhBSuBzvLZiTYIG2m98OScTgwOUarm7blmFgU7k9n1unEiBC13yO/eizEIHWq+8+OWT" type="audio/wav">
    </audio>

    <!-- Elemento de áudio alternativo -->
    <audio id="notification-sound-alt" preload="auto">
        <source src="https://assets.mixkit.co/sfx/preview/mixkit-winning-chimes-2015.mp3" type="audio/mpeg">
    </audio>

    <script>
        // Formatar o valor enquanto o usuário digita
        document.getElementById('valor').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            value = (value / 100).toFixed(2) + '';
            value = value.replace('.', ',');
            value = value.replace(/(\d)(?=(\d{3})+,)/g, '$1.');
            e.target.value = value;
        });

        // Verificar status automaticamente
        let checkInterval;
        let paymentId = null;
        let userInteracted = false;

        // Detectar interação do usuário para permitir autoplay
        document.addEventListener('click', function() {
            userInteracted = true;
        }, {
            once: true
        });

        // Testar som
        document.getElementById('test-sound-btn')?.addEventListener('click', function() {
            playNotificationSound();
        });

        // Iniciar verificação automática quando um pagamento é criado
        <?php if (isset($resultado['success']) && $resultado['success'] && isset($resultado['qr_code'])): ?>
            document.addEventListener('DOMContentLoaded', function() {
                paymentId = '<?= $resultado['id'] ?>';
                startStatusCheck();
            });
        <?php endif; ?>

        function startStatusCheck() {
            // Exibir mensagem de verificação
            document.getElementById('checking-status').style.display = 'block';

            // Verificar o status a cada 2 segundos
            checkInterval = setInterval(function() {
                checkPaymentStatus(paymentId);
            }, 2000);
        }

        function checkPaymentStatus(paymentId) {
            // Fazer uma requisição AJAX para verificar o status
            const xhr = new XMLHttpRequest();
            xhr.open('GET', '?action=check_status&payment_id=' + encodeURIComponent(paymentId), true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);

                        if (response.success) {
                            // Atualizar o status na página
                            document.getElementById('status-text').textContent = response.status;
                            document.getElementById('status-detail').textContent = response.status_detail;

                            // Atualizar a classe do container de status
                            document.getElementById('status-container').className = 'status ' + response.status;

                            // Se o pagamento foi aprovado
                            if (response.status === 'approved') {
                                // Parar a verificação
                                clearInterval(checkInterval);

                                // Ocultar mensagem de verificação
                                document.getElementById('checking-status').style.display = 'none';

                                // Adicionar classe de animação
                                document.getElementById('status-container').classList.add('payment-approved');

                                // Tocar o som de notificação
                                playNotificationSound();

                                // Exibir notificação
                                const notification = document.getElementById('notification');
                                notification.style.display = 'block';

                                // Ocultar notificação após 5 segundos
                                setTimeout(function() {
                                    notification.style.display = 'none';
                                }, 5000);

                                // Exibir mensagem de sucesso
                                const successMessage = document.createElement('div');
                                successMessage.className = 'success';
                                successMessage.style.fontSize = '18px';
                                successMessage.style.marginTop = '10px';
                                successMessage.textContent = '✓ Pagamento aprovado com sucesso!';
                                document.getElementById('status-container').appendChild(successMessage);
                                try {
                                    const apito = new Audio('assets/media/audio.mp3');
                                    apito.currentTime = 0;
                                    apito.play().catch(err => {
                                        console.error('Falha ao tocar audio.mp3:', err);
                                    });
                                } catch (e) {
                                    console.error('Erro ao inicializar áudio de teste:', e);
                                }
                            }
                        }
                    } catch (e) {
                        console.error('Erro ao analisar resposta JSON:', e);
                    }
                }
            };
            xhr.onerror = function() {
                console.error('Erro na requisição AJAX');
            };
            xhr.send();
        }

        function playNotificationSound() {
            // Tentar reproduzir o som principal
            const notificationSound = document.getElementById('notification-sound');
            const notificationSoundAlt = document.getElementById('notification-sound-alt');

            // Função para tentar reproduzir o áudio
            const tryPlaySound = (audioElement) => {
                const playPromise = audioElement.play();

                if (playPromise !== undefined) {
                    playPromise.then(_ => {
                        // Reprodução bem-sucedida
                        console.log('Som reproduzido com sucesso');
                    }).catch(error => {
                        // Reprodução falhou
                        console.error('Erro ao reproduzir som:', error);

                        // Tentar o áudio alternativo
                        if (audioElement !== notificationSoundAlt) {
                            tryPlaySound(notificationSoundAlt);
                        } else {
                            // Se ambos falharem, usar o fallback
                            playFallbackSound();
                        }
                    });
                }
            };

            // Tentar reproduzir o som principal primeiro
            tryPlaySound(notificationSound);
        }

        function playFallbackSound() {
            // Criar um contexto de áudio para gerar um som simples
            try {
                const audioContext = new(window.AudioContext || window.webkitAudioContext)();
                const oscillator = audioContext.createOscillator();
                const gainNode = audioContext.createGain();

                oscillator.connect(gainNode);
                gainNode.connect(audioContext.destination);

                oscillator.type = 'sine';
                oscillator.frequency.value = 880; // Frequência do som (A5)
                gainNode.gain.value = 0.3; // Volume

                oscillator.start();

                // Diminuir o volume gradualmente
                gainNode.gain.exponentialRampToValueAtTime(0.001, audioContext.currentTime + 1);

                // Parar o som após 1 segundo
                setTimeout(() => {
                    oscillator.stop();
                }, 1000);

                console.log('Som de fallback reproduzido');
            } catch (e) {
                console.error('Erro ao reproduzir som de fallback:', e);
            }
        }
    </script>


</body>

</html>