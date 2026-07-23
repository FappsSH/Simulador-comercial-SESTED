<?php

require_once __DIR__ . '/config.php';

$q = $_GET['q'] ?? '';

if (strlen($q) < 2) {
    http_response_code(400);
    echo json_encode(['error' => 'Termo de busca deve ter pelo menos 2 caracteres']);
    exit;
}

$resultado = buscarCursos($q);

if ($resultado['status'] === 200) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($resultado['data']);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao buscar cursos']);
}
