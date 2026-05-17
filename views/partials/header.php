<?php



if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}


/*

$estaLogado = !empty($_SESSION['usuario_id']) || (!empty($_SESSION['logado']) && $_SESSION['logado'] === true);
if (!$estaLogado) {
  header('Location: login.php');
  exit;
}
*/



// header.php — Cabeçalho com menu sanduíche

?>
<!-- Sidebar (menu sanduíche) -->
<div class="nav-overlay" id="navOverlay"></div>
<aside class="nav-drawer" id="navDrawer" aria-hidden="true">
  <div class="nav-head">
    <div class="nav-title">Bar Azerutan</div>
    <button class="nav-close" id="navClose" aria-label="Fechar menu">&times;</button>
  </div>
  <nav>
    <ul class="nav-list">
      <li><a href="painel.php" class="<?= basename($_SERVER['PHP_SELF']) === 'painel.php' ? 'active' : '' ?>"><span class="nav-icon">🏠</span> Painel</a></li>
      <li><a href="mesas.php" class="<?= basename($_SERVER['PHP_SELF']) === 'mesas.php' ? 'active' : '' ?>"><span class="nav-icon">🍽️</span> Mesas</a></li>
      <li><a href="pedidos.php" class="<?= basename($_SERVER['PHP_SELF']) === 'pedidos.php' ? 'active' : '' ?>"><span class="nav-icon">🧾</span> Pedidos</a></li>
      <li><a href="produtos.php" class="<?= basename($_SERVER['PHP_SELF']) === 'produtos.php' ? 'active' : '' ?>"><span class="nav-icon">🛒</span> Produtos</a></li>
      <li><a href="relatorios.php" class="<?= basename($_SERVER['PHP_SELF']) === 'relatorios.php' ? 'active' : '' ?>"><span class="nav-icon">📊</span> Relatórios</a></li>
      <li><a href="abertura_caixa.php" class="<?= basename($_SERVER['PHP_SELF']) === 'abertura_caixa.php' ? 'active' : '' ?>"><span class="nav-icon">📊</span>Abertura de Caixa</a></li>
      <li><a href="clientes.php" class="<?= basename($_SERVER['PHP_SELF']) === 'clientes.php' ? 'active' : '' ?>"><span class="nav-icon">📊</span>Clientes</a></li>
      <li><a href="arquivos.php" class="<?= basename($_SERVER['PHP_SELF']) === 'arquivos.php' ? 'active' : '' ?>"><span class="nav-icon">🗂️</span> Arquivos</a></li>

      <!-- Opção de Administrar Usuários (apenas para nível 1) -->
      <?php if (isset($_SESSION['usuario_nivel']) && $_SESSION['usuario_nivel'] == 1): ?>
        <li><a href="administrar_usuario.php" class="<?= basename($_SERVER['PHP_SELF']) === 'administrar_usuario.php' ? 'active' : '' ?>"><span class="nav-icon">👥</span> Administrar Usuários</a></li>
      <?php endif; ?>

      <!-- Opções de Login/Sair -->
      <?php if (isset($_SESSION['usuario_id'])): ?>
        <li><a href="logout.php" class="nav-logout"><span class="nav-icon">🚪</span> Sair</a></li>
      <?php else: ?>
        <li><a href="login.php" class="<?= basename($_SERVER['PHP_SELF']) === 'login.php' ? 'active' : '' ?>"><span class="nav-icon">🔑</span> Login</a></li>
      <?php endif; ?>
    </ul>
  </nav>
</aside>
<!-- Cabeçalho fixo -->
<div class="header">
  <div style="display:flex;align-items:center;gap:8px">
    <button class="hambtn" id="navOpen" aria-label="Abrir menu">☰</button>
    <!-- Botão para ir para mesas.php -->
    <a href="mesas.php" class="mesas-btn" title="Ir para Mesas">
      <span class="mesas-icon">🍽️</span>
    </a>
    <div><strong>Bar Azerutan</strong></div>
  </div>

  <!-- Opção de Login/Sair no cabeçalho -->
  <div class="header-actions">
    <?php if (isset($_SESSION['usuario_id'])): ?>
      <a href="logout.php" class="btn-logout"><span class="btn-icon">🚪</span> Sair</a>
    <?php else: ?>
      <a href="login.php" class="btn-login"><span class="btn-icon">🔑</span> Login</a>
    <?php endif; ?>
  </div>
</div>
<!-- Estilos do cabeçalho/side menu -->
<style>
  :root {
    --primary: #2e7d32;
    /* Verde principal */
    --primary-dark: #1b5e20;
    /* Verde escuro */
    --primary-light: #81c784;
    /* Verde claro */
    --accent: #4caf50;
    /* Verde de destaque */
    --bg-light: #e8f5e9;
    /* Fundo verde muito claro */
    --bg-hover: #c8e6c9;
    /* Fundo para hover */
    --border: #a5d6a7;
    /* Cor da borda */
    --bg: #f1f8e9;
    /* Cor de fundo geral */
  }

  .header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 18px;
    background: var(--primary-dark);
    color: #fff;
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
  }

  .hambtn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    border-radius: 8px;
    background: var(--primary);
    color: #fff;
    border: 0;
    cursor: pointer;
    font-size: 20px;
    transition: background-color 0.3s ease;
  }

  .hambtn:hover {
    background: var(--accent);
  }

  .mesas-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    border-radius: 8px;
    background: var(--primary);
    color: #fff;
    text-decoration: none;
    font-size: 18px;
    transition: background-color 0.3s ease;
  }

  .mesas-btn:hover {
    background: var(--accent);
  }

  .mesas-icon {
    font-size: 18px;
  }

  .header-actions {
    display: flex;
    align-items: center;
  }

  .btn-login,
  .btn-logout {
    padding: 8px 16px;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }

  .btn-icon {
    font-size: 16px;
  }

  .btn-login {
    background-color: var(--accent);
    color: white;
    border: 1px solid var(--accent);
  }

  .btn-login:hover {
    background-color: var(--primary-dark);
    border-color: var(--primary-dark);
  }

  .btn-logout {
    background-color: transparent;
    color: white;
    border: 1px solid rgba(255, 255, 255, 0.5);
  }

  .btn-logout:hover {
    background-color: rgba(255, 255, 255, 0.1);
    border-color: white;
  }

  .nav-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, .35);
    display: none;
    z-index: 998;
  }

  .nav-drawer {
    position: fixed;
    inset: 0 auto 0 0;
    width: 260px;
    background: #fff;
    border-right: 1px solid var(--border);
    transform: translateX(-100%);
    transition: transform .22s ease;
    z-index: 999;
    display: flex;
    flex-direction: column;
    box-shadow: 2px 0 5px rgba(0, 0, 0, 0.1);
  }

  .nav-drawer.open {
    transform: translateX(0);
  }

  .nav-head {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--bg-light);
  }

  .nav-title {
    font-weight: 700;
    color: var(--primary-dark);
  }

  .nav-close {
    background: none;
    border: 0;
    font-size: 22px;
    cursor: pointer;
    color: var(--primary);
    padding: 0;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: background-color 0.2s;
  }

  .nav-close:hover {
    background-color: var(--bg-hover);
  }

  .nav-list {
    list-style: none;
    margin: 0;
    padding: 10px;
  }

  .nav-list a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 10px;
    color: #333;
    text-decoration: none;
    transition: all 0.2s ease;
  }

  .nav-list a:hover {
    background: var(--bg-hover);
    color: var(--primary-dark);
  }

  .nav-list a.active {
    background: var(--bg-light);
    color: var(--primary-dark);
    font-weight: 600;
    border-left: 3px solid var(--primary);
  }

  .nav-icon {
    width: 18px;
    text-align: center;
    color: var(--primary);
  }

  .nav-logout {
    color: #d32f2f !important;
    border-top: 1px solid var(--border);
    margin-top: 10px;
    padding-top: 15px;
  }

  .nav-logout:hover {
    background-color: #ffebee !important;
    color: #d32f2f !important;
  }

  .nav-logout .nav-icon {
    color: #d32f2f;
  }
</style>


<!-- Script do menu -->
<script src="jquery-3.7.1.min.js"></script>

<script>
  (function() {
    const drawer = document.getElementById('navDrawer');
    const overlay = document.getElementById('navOverlay');
    const openBtn = document.getElementById('navOpen');
    const closeBtn = document.getElementById('navClose');

    // Verificar se os elementos existem antes de adicionar os event listeners
    if (drawer && overlay && openBtn && closeBtn) {
      function openNav() {
        drawer.classList.add('open');
        overlay.style.display = 'block';
        drawer.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
      }

      function closeNav() {
        drawer.classList.remove('open');
        overlay.style.display = 'none';
        drawer.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
      }

      openBtn.addEventListener('click', openNav);
      closeBtn.addEventListener('click', closeNav);
      overlay.addEventListener('click', closeNav);
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeNav();
      });
    }

    // Garantir que os links funcionem corretamente
    document.addEventListener('DOMContentLoaded', function() {
      const links = document.querySelectorAll('a[href]');
      links.forEach(link => {
        link.addEventListener('click', function(e) {
          // Se for um link para a mesma página, permitir o comportamento padrão
          if (this.getAttribute('href').startsWith('#')) {
            return;
          }

          // Para outros links, garantir que naveguem corretamente
          const href = this.getAttribute('href');
          if (href && !href.includes('javascript:')) {
            // Permitir que o link funcione normalmente
            return;
          }
        });
      });
    });
  })();
</script>