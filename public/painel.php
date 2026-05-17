<?php include __DIR__ . '/../views/partials/header.php'; ?>

<?php

// Sessão
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => false, // true se HTTPS
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Carrega autoload + config da aplicação
require __DIR__ . '/../bootstrap.php';

// Se você já tiver um guard de sessão, mantenha:
require __DIR__ . '/verificar_sessao.php'; // veja arquivo abaixo



// Info do usuário (se quiser exibir)
$usuario_nome  = $_SESSION['usuario_nome']  ?? 'Usuário';
$usuario_nivel = $_SESSION['usuario_nivel'] ?? 'desconhecido';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Painel - Bar Azerutan</title>

  <style>
    *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,Helvetica,sans-serif}
    body{background:#f8f9fa;color:#333}

    /* --------- Estilos do PRÓPRIO PAINEL (não duplicam o header) --------- */
    .container{max-width:1200px;margin:20px auto;padding:0 20px}

    .welcome-card{
      background:#fff;border-radius:10px;box-shadow:0 4px 6px rgba(0,0,0,.08);
      padding:30px;margin-bottom:30px;text-align:center
    }
    .welcome-card h2{color:#00a650;margin-bottom:10px}

    .menu-grid{
      display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));
      gap:20px;margin-bottom:30px
    }
    .menu-card{
      background:#fff;border-radius:10px;box-shadow:0 4px 6px rgba(0,0,0,.08);
      padding:20px;text-align:center;transition:.25s
    }
    .menu-card:hover{transform:translateY(-4px);box-shadow:0 8px 14px rgba(0,0,0,.12)}
    .menu-card h3{color:#00a650;margin-bottom:12px}
    .menu-card p{color:#666;margin-bottom:18px}
    .menu-card a{
      display:inline-block;background:#00a650;color:#fff;padding:10px 18px;border-radius:6px;
      text-decoration:none;transition:.2s
    }
    .menu-card a:hover{background:#008040}

    .debug-info{background:#fff;border:1px solid #ddd;border-radius:8px;padding:15px}
    .debug-info h3{margin-bottom:10px;color:#00a650}
    .debug-info pre{background:#f1f1f1;padding:10px;border-radius:4px;overflow:auto}
  </style>
</head>
<body>


  <div class="container">
    <div class="welcome-card">
      <h2>Bem-vindo ao Sistema de Gestão do Bar Azerutan</h2>
      <p>Selecione uma opção abaixo para começar</p>
    </div>

    <div class="menu-grid">
      <div class="menu-card">
        <h3>Pedidos</h3>
        <p>Cadastrar e gerenciar pedidos</p>
        <a href="pedidos.php">Ir para Pedidos</a>
      </div>

      <div class="menu-card">
        <h3>Produtos</h3>
        <p>Gerenciar produtos e estoque</p>
        <a href="produtos.php">Ir para Produtos</a>
      </div>

      <div class="menu-card">
        <h3>Mesas</h3>
        <p>Gerenciar mesas do estabelecimento</p>
        <a href="mesas.php">Ir para Mesas</a>
      </div>

      <div class="menu-card">
        <h3>Relatórios</h3>
        <p>Visualizar relatórios de vendas</p>
        <a href="relatorios.php">Ir para Relatórios</a>
      </div>
    </div>

    <!-- Informações de depuração (remover em produção) -->
    <div class="debug-info">
      <h3>Informações de Depuração</h3>
      <pre>
ID do Usuário: <?= isset($_SESSION['usuario_id']) ? $_SESSION['usuario_id'] : 'Não definido' ?>

Nome do Usuário: <?= $usuario_nome ?>

Nível do Usuário: <?= $usuario_nivel ?>

Último Acesso: <?= isset($_SESSION['ultimo_acesso']) ? date('d/m/Y H:i:s', $_SESSION['ultimo_acesso']) : 'Não definido' ?>
      </pre>
    </div>
  </div>

</body>
</html>
