php
<?php
// Exige usuário autenticado — mesma checagem usada no dashboard.
require_once(__DIR__ . '/../protect/protect.php');
include(__DIR__ . '/../config/config.php');

if (!isset($_GET['id']) || !ctype_digit((string) $_GET['id'])) {
    die('ID de usuário inválido.');
}

$id = (int) $_GET['id'];
$novaSenha = password_hash('1q2w3e4r5t', PASSWORD_DEFAULT);  // Senha padrão

// Consulta parametrizada — evita injeção de SQL via "id".
$sql = "UPDATE usuarios SET senha = ? WHERE id = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('si', $novaSenha, $id);

if ($stmt->execute()) {
    echo "<script>alert('Senha resetada com sucesso!'); window.location.href = 'user_menu.php';</script>";
} else {
    echo "Erro ao resetar senha: " . $mysqli->error;
}

$stmt->close();
$mysqli->close();
?>
