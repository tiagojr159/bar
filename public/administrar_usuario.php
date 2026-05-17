<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Verificar se o usuário está logado e tem nível 1
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_nivel'] != 1) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/../src/Support/DB.php';
use App\Support\DB;

// Incluir arquivo de conexão com o banco de dados
    try {
        $conexao = DB::conn(); // usa a conexão centralizada
    } catch (Throwable $e) {
        die("Erro de conexão: " . $e->getMessage());
    }

// Variáveis para mensagens
$msg = '';
$msgClass = '';

// Processar ações do formulário
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Adicionar novo usuário
    if (isset($_POST['add_user'])) {
        $nome = $_POST['nome'];
        $email = $_POST['email'];
        $senha = $_POST['senha']; // No seu sistema, a senha não está sendo hasheada
        $nivel = $_POST['nivel'];

        // Verificar se o nome de usuário já existe
        $checkUser = "SELECT * FROM usuarios WHERE nome = ?";
        $stmt = $conexao->prepare($checkUser);
        $stmt->bind_param("s", $nome);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $msg = "Este nome de usuário já está em uso!";
            $msgClass = "alert-danger";
        } else {
            $sql = "INSERT INTO usuarios (nome, email, senha, nivel) VALUES (?, ?, ?, ?)";
            $stmt = $conexao->prepare($sql);
            $stmt->bind_param("sssi", $nome, $email, $senha, $nivel);

            if ($stmt->execute()) {
                $msg = "Usuário adicionado com sucesso!";
                $msgClass = "alert-success";
            } else {
                $msg = "Erro ao adicionar usuário: " . $conexao->error;
                $msgClass = "alert-danger";
            }
        }
    }

    // Editar usuário
    if (isset($_POST['edit_user'])) {
        $id = $_POST['id'];
        $nome = $_POST['nome'];
        $email = $_POST['email'];
        $nivel = $_POST['nivel'];

        // Se a senha foi preenchida, atualiza também
        if (!empty($_POST['senha'])) {
            $senha = $_POST['senha']; // No seu sistema, a senha não está sendo hasheada
            $sql = "UPDATE usuarios SET nome = ?, email = ?, senha = ?, nivel = ? WHERE id = ?";
            $stmt = $conexao->prepare($sql);
            $stmt->bind_param("sssii", $nome, $email, $senha, $nivel, $id);
        } else {
            $sql = "UPDATE usuarios SET nome = ?, email = ?, nivel = ? WHERE id = ?";
            $stmt = $conexao->prepare($sql);
            $stmt->bind_param("ssii", $nome, $email, $nivel, $id);
        }

        if ($stmt->execute()) {
            $msg = "Usuário atualizado com sucesso!";
            $msgClass = "alert-success";
        } else {
            $msg = "Erro ao atualizar usuário: " . $conexao->error;
            $msgClass = "alert-danger";
        }
    }

    // Excluir usuário
    if (isset($_POST['delete_user'])) {
        $id = $_POST['id'];

        // Não permitir excluir o próprio usuário
        if ($id == $_SESSION['usuario_id']) {
            $msg = "Você não pode excluir seu próprio usuário!";
            $msgClass = "alert-danger";
        } else {
            $sql = "DELETE FROM usuarios WHERE id = ?";
            $stmt = $conexao->prepare($sql);
            $stmt->bind_param("i", $id);

            if ($stmt->execute()) {
                $msg = "Usuário excluído com sucesso!";
                $msgClass = "alert-success";
            } else {
                $msg = "Erro ao excluir usuário: " . $conexao->error;
                $msgClass = "alert-danger";
            }
        }
    }
}

// Buscar todos os usuários
$sql = "SELECT * FROM usuarios ORDER BY nome";
$result = $conexao->query($sql);
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrar Usuários - Bar Azerutan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        :root {
            --primary: #2e7d32;
            --primary-dark: #1b5e20;
            --primary-light: #81c784;
            --accent: #4caf50;
            --bg-light: #e8f5e9;
            --bg-hover: #c8e6c9;
            --border: #a5d6a7;
            --bg: #f1f8e9;
        }

        body {
            background-color: var(--bg);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .page-header {
            background-color: var(--primary-dark);
            color: white;
            padding: 20px 0;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }

        .card-header {
            background-color: var(--primary);
            color: white;
            border-radius: 10px 10px 0 0 !important;
            font-weight: 600;
        }

        .btn-primary {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
            border-color: var(--primary-dark);
        }

        .btn-success {
            background-color: var(--accent);
            border-color: var(--accent);
        }

        .btn-success:hover {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .table {
            background-color: white;
            border-radius: 10px;
            overflow: hidden;
        }

        .table thead {
            background-color: var(--bg-light);
        }

        .badge {
            font-size: 0.85em;
        }

        .modal-header {
            background-color: var(--primary);
            color: white;
        }

        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.25rem rgba(46, 125, 50, 0.25);
        }
    </style>
</head>

<body>
    <?php include __DIR__ . '/../views/partials/header.php'; ?>


    <div class="page-header">
        <div class="container">
            <h1><i class="bi bi-people-fill me-2"></i> Administrar Usuários</h1>
            <p class="mb-0">Gerencie todos os usuários do sistema</p>
        </div>
    </div>

    <div class="container">
        <?php if ($msg): ?>
            <div class="alert <?= $msgClass ?> alert-dismissible fade show" role="alert">
                <?= $msg ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Lista de Usuários</span>
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="bi bi-person-plus me-1"></i> Adicionar Usuário
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nome</th>
                                <th>Email</th>
                                <th>Nível</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= $row['id'] ?></td>
                                        <td><?= $row['nome'] ?></td>
                                        <td><?= $row['email'] ?></td>
                                        <td>
                                            <span class="badge bg-<?= $row['nivel'] == 1 ? 'danger' : 'primary' ?>">
                                                <?= $row['nivel'] == 1 ? 'Administrador' : 'Usuário' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editUserModal<?= $row['id'] ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteUserModal<?= $row['id'] ?>">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>

                                    <!-- Modal Editar Usuário -->
                                    <div class="modal fade" id="editUserModal<?= $row['id'] ?>" tabindex="-1" aria-labelledby="editUserModalLabel<?= $row['id'] ?>" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title" id="editUserModalLabel<?= $row['id'] ?>">Editar Usuário</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <form method="post">
                                                    <div class="modal-body">
                                                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                                        <div class="mb-3">
                                                            <label for="nome<?= $row['id'] ?>" class="form-label">Nome</label>
                                                            <input type="text" class="form-control" id="nome<?= $row['id'] ?>" name="nome" value="<?= $row['nome'] ?>" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="email<?= $row['id'] ?>" class="form-label">Email</label>
                                                            <input type="email" class="form-control" id="email<?= $row['id'] ?>" name="email" value="<?= $row['email'] ?>" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="senha<?= $row['id'] ?>" class="form-label">Senha (deixe em branco para manter a atual)</label>
                                                            <input type="password" class="form-control" id="senha<?= $row['id'] ?>" name="senha">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="nivel<?= $row['id'] ?>" class="form-label">Nível</label>
                                                            <select class="form-select" id="nivel<?= $row['id'] ?>" name="nivel" required>
                                                                <option value="1" <?= $row['nivel'] == 1 ? 'selected' : '' ?>>Administrador (Nível 1)</option>
                                                                <option value="2" <?= $row['nivel'] == 2 ? 'selected' : '' ?>>Usuário (Nível 2)</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                        <button type="submit" name="edit_user" class="btn btn-primary">Salvar Alterações</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Modal Excluir Usuário -->
                                    <div class="modal fade" id="deleteUserModal<?= $row['id'] ?>" tabindex="-1" aria-labelledby="deleteUserModalLabel<?= $row['id'] ?>" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title" id="deleteUserModalLabel<?= $row['id'] ?>">Confirmar Exclusão</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <p>Tem certeza de que deseja excluir o usuário <strong><?= $row['nome'] ?></strong>?</p>
                                                    <p class="text-danger">Esta ação não pode ser desfeita!</p>
                                                </div>
                                                <form method="post">
                                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                        <button type="submit" name="delete_user" class="btn btn-danger">Excluir Usuário</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-3">Nenhum usuário encontrado</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Adicionar Usuário -->
    <div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addUserModalLabel">Adicionar Novo Usuário</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="nome" class="form-label">Nome</label>
                            <input type="text" class="form-control" id="nome" name="nome" required>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label for="senha" class="form-label">Senha</label>
                            <input type="password" class="form-control" id="senha" name="senha" required>
                        </div>
                        <div class="mb-3">
                            <label for="nivel" class="form-label">Nível</label>
                            <select class="form-select" id="nivel" name="nivel" required>
                                <option value="1">Administrador (Nível 1)</option>
                                <option value="2">Usuário (Nível 2)</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="add_user" class="btn btn-primary">Adicionar Usuário</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>