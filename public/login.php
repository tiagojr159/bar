<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Se já logado, redireciona
if (isset($_SESSION['usuario_id'])) {
    header('Location: painel.php');
    exit;
}

// Importa classe DB
require_once __DIR__ . '/../src/Support/DB.php';

use App\Support\DB;

// Processa login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conexao = DB::conn(); // usa a conexão centralizada
    } catch (Throwable $e) {
        die("Erro de conexão: " . $e->getMessage());
    }

    $nome  = $_POST['nome']  ?? '';
    $senha = $_POST['senha'] ?? '';

    $sql = "SELECT * FROM usuarios WHERE nome = ? AND ativo = TRUE";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("s", $nome);
    $stmt->execute();
    $resultado = $stmt->get_result();

    if ($resultado->num_rows === 1) {
        $usuario = $resultado->fetch_assoc();

        if ($senha === $usuario['senha']) {
            $_SESSION['usuario_id']    = $usuario['id'];
            $_SESSION['usuario_nome']  = $usuario['nome'];
            $_SESSION['usuario_nivel'] = $usuario['nivel'];

            $sql_update = "UPDATE usuarios SET ultimo_acesso = NOW() WHERE id = ?";
            $stmt_update = $conexao->prepare($sql_update);
            $stmt_update->bind_param("i", $usuario['id']);
            $stmt_update->execute();

            header('Location: painel.php');
            exit;
        }
    }

    $erro = "E-mail ou senha incorretos!";
}
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Bar Azerutan</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Arial', sans-serif;
        }

        body {
            background: linear-gradient(135deg, #1e3c72, #2a5298);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-container {
            background-color: rgba(255, 255, 255, 0.9);
            border-radius: 10px;
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            width: 400px;
            max-width: 90%;
        }

        .login-header {
            background-color: #00a650;
            color: white;
            padding: 20px;
            text-align: center;
        }

        .login-header h1 {
            font-size: 24px;
            margin-bottom: 5px;
        }

        .login-header p {
            font-size: 14px;
            opacity: 0.8;
        }

        .login-body {
            padding: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            transition: border-color 0.3s;
        }

        .form-group input:focus {
            border-color: #00a650;
            outline: none;
        }

        .login-button {
            width: 100%;
            padding: 12px;
            background-color: #00a650;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .login-button:hover {
            background-color: #008040;
        }

        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
        }

        .login-footer {
            text-align: center;
            padding: 20px;
            background-color: #f8f9fa;
            color: #6c757d;
            font-size: 14px;
        }
    </style>
</head>

<body>
    <div class="col-1 text-center" style="position: fixed; top: 20px; right: 20px; z-index: 1000;">
        <a href="clientes.php" class="login-button btn-info">Cardápio</a>
    </div>
    <div class="login-container">
        <div class="login-header">
            <h1>Bar Azerutan</h1>
            <p>Sistema de Gestão</p>
        </div>

        <div class="login-body">
            <?php if (isset($erro)): ?>
                <div class="error-message"><?php echo $erro; ?></div>
            <?php endif; ?>

            <form method="post" action="login.php">
                <div class="form-group">
                    <label for="nome">Nome:</label>
                    <input type="nome" id="nome" name="nome">
                </div>

                <div class="form-group">
                    <label for="senha">Senha:</label>
                    <input type="password" id="senha" name="senha" required>
                </div>

                <button type="submit" class="login-button">Entrar</button>
            </form>
        </div>

        <div class="login-footer">
            <p>© 2023 Bar Azerutan. Todos os direitos reservados.</p>
        </div>
    </div>
</body>

</html>