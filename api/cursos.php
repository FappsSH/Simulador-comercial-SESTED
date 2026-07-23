<?php

require_once __DIR__ . '/config.php';

// Headers de cache
header('Cache-Control: public, max-age=300'); // 5 minutos

$tipo = $_GET['tipo'] ?? null;

$resultado = getCursos($tipo);

if ($resultado['status'] === 200) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($resultado['data']);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao buscar cursos']);
}
