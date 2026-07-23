<?php

require_once __DIR__ . '/config.php';

// Verifica senha
$senha = $_GET['senha'] ?? '';
if ($senha !== ADMIN_PASSWORD) {
    http_response_code(401);
    echo json_encode(['error' => 'Senha inválida']);
    exit;
}

$resultado = getUploads();

if ($resultado['status'] === 200) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($resultado['data']);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao buscar uploads']);
}
