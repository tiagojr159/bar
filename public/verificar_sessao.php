<?php
// public/verificar_sessao.php
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

/* ➊ Se a página marcou que é pública, não redireciona */
if (defined('PUBLIC_PAGE') && PUBLIC_PAGE === true) {
  return;
}

/* ➋ Considera “visitante” como sessão válida (para páginas públicas com header) */
$estaLogado = !empty($_SESSION['usuario_id'])
           || (!empty($_SESSION['logado']) && $_SESSION['logado'] === true)
           || (!empty($_SESSION['user']['guest']) && $_SESSION['user']['guest'] === true);

if (!$estaLogado) {
  header('Location: login.php');
  exit;
}
