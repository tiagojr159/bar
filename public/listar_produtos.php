<?php
//listar_produtos.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../bootstrap.php';

use App\Support\DB;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  $con = DB::conn();


  $sql = "
  SELECT id, nome, preco, imagem, estoque
  FROM produtos
  WHERE ativo = 1
";
  $sql .= " AND estoque > 0";
  $sql .= " ORDER BY categoria, nome";

  $st = $con->prepare($sql);
  $st->execute();
  $rs = $st->get_result();

  $produtos = [];
  while ($r = $rs->fetch_assoc()) {
    $produtos[] = [
      'id'       => (int)$r['id'],
      'nome'     => (string)$r['nome'],
      'preco'    => (float)$r['preco'],
      'imagem'   => $r['imagem'],
      'estoque'  => isset($r['estoque']) ? (int)$r['estoque'] : 0, // << quantidade existente
    ];
  }

  echo json_encode([
    'sucesso'         => true,
    'total_produtos'  => count($produtos),  // << total existente no resultado
    'produtos'        => $produtos
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['sucesso' => false, 'mensagem' => 'Erro ao listar produtos']);
}
