<?php
session_start();
require(__DIR__ . '/../config/config.php');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: product_entry.php");
    exit;
}

/* ==========================
   FUNÇÕES AUXILIARES
========================== */
function moedaParaFloat($valor): float {
    return (float) str_replace(',', '.', preg_replace('/[^0-9,]/', '', $valor));
}

/* ==========================
   DADOS DO FORMULÁRIO
========================== */
$id_empresa     = (int) ($_POST['id_empresa'] ?? 0);
$id_produto     = (int) ($_POST['id_produto'] ?? 0);
$id_fornecedor  = (int) ($_POST['id_fornecedor'] ?? 0);
$quantidade     = (int) ($_POST['quantidade'] ?? 0);
$valor_unitario = moedaParaFloat($_POST['valor_unitario'] ?? '');
$frete          = moedaParaFloat($_POST['frete'] ?? '0');
$valor_total    = moedaParaFloat($_POST['valor_total'] ?? '');
$data_entrada   = $_POST['data_entrada'] ?? '';
$observacao     = trim($_POST['observacao'] ?? '');

/* ==========================
   VALIDAÇÕES
========================== */
if (
    !$id_empresa || !$id_produto || !$id_fornecedor ||
    $quantidade <= 0 || $valor_unitario <= 0 || empty($data_entrada)
) {
    $_SESSION['error'] = 'Preencha todos os campos obrigatórios corretamente.';
    header("Location: product_entry.php");
    exit;
}

$valor_calculado = ($quantidade * $valor_unitario) + $frete;
if (abs($valor_calculado - $valor_total) > 0.01) {
    $_SESSION['error'] = 'Valor total inconsistente.';
    header("Location: product_entry.php");
    exit;
}

/* ==========================
   UPLOAD NF
========================== */
$caminho_nf = null;
$uploadDir = __DIR__ . '/../uploads';

if (!empty($_FILES['nf']['name'])) {

    $permitidos = ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    $mime = mime_content_type($_FILES['nf']['tmp_name']);

    if (!in_array($mime, $permitidos)) {
        $_SESSION['error'] = 'Arquivo inválido. Apenas PDF ou DOCX.';
        header("Location: product_entry.php");
        exit;
    }

    if ($_FILES['nf']['size'] > (2 * 1024 * 1024)) {
        $_SESSION['error'] = 'Arquivo maior que 2MB.';
        header("Location: product_entry.php");
        exit;
    }

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $ext = pathinfo($_FILES['nf']['name'], PATHINFO_EXTENSION);
    $nomeArquivo = uniqid('nf_', true) . '.' . $ext;
    $caminho_nf = $uploadDir . '/' . $nomeArquivo;

    move_uploaded_file($_FILES['nf']['tmp_name'], $caminho_nf);
}

/* ==========================
   TRANSAÇÃO
========================== */
try {
    $mysqli->begin_transaction();

    /* Entrada Produto */
    $stmt = $mysqli->prepare("
        INSERT INTO entrada_produto
        (id_empresa, id_produto, id_fornecedor, quantidade, valor_unitario, frete, valor_total, data_entrada, nf, observacao)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        'iiiidddsss',
        $id_empresa,
        $id_produto,
        $id_fornecedor,
        $quantidade,
        $valor_unitario,
        $frete,
        $valor_total,
        $data_entrada,
        $caminho_nf,
        $observacao
    );
    $stmt->execute();

    /* Estoque */
    $stmt = $mysqli->prepare("
        SELECT id_estoque, quantidade
        FROM estoque
        WHERE id_empresa = ? AND id_produto = ?
        FOR UPDATE
    ");
    $stmt->bind_param('ii', $id_empresa, $id_produto);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        $nova_qtd = $row['quantidade'] + $quantidade;
        $stmt = $mysqli->prepare("UPDATE estoque SET quantidade = ? WHERE id_estoque = ?");
        $stmt->bind_param('ii', $nova_qtd, $row['id_estoque']);
    } else {
        $stmt = $mysqli->prepare("INSERT INTO estoque (id_empresa, id_produto, quantidade) VALUES (?, ?, ?)");
        $stmt->bind_param('iii', $id_empresa, $id_produto, $quantidade);
    }

    $stmt->execute();
    $mysqli->commit();

    $_SESSION['success'] = 'Entrada registrada e estoque atualizado com sucesso!';
    header("Location: product_entry.php");
    exit;

} catch (Throwable $e) {
    $mysqli->rollback();
    $_SESSION['error'] = 'Erro ao registrar entrada: ' . $e->getMessage();
    header("Location: product_entry.php");
    exit;
}
