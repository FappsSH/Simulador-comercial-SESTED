<?php

require_once __DIR__ . '/config.php';

$q = $_GET['q'] ?? '';

if (strlen($q) < 2) {
    http_response_code(400);
    echo json_encode(['error' => 'Termo de busca deve ter pelo menos 2 caracteres']);
    exit;
}

// Busca cursos
$endpoint = 'cursos?ativo=eq.true&nome_curso=ilike.*' . urlencode($q) . '*&order=nome_curso.asc';
$resultado = supabaseRequest('GET', $endpoint);

if ($resultado['status'] !== 200) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao buscar cursos']);
    exit;
}

$cursos = $resultado['data'] ?? [];

// Busca canais
$canaisResultado = supabaseRequest('GET', 'canais_desconto');
$canaisData = $canaisResultado['data'] ?? [];

$canaisPorCurso = [];
foreach ($canaisData as $canal) {
    $cursoId = $canal['curso_id'];
    if (!isset($canaisPorCurso[$cursoId])) {
        $canaisPorCurso[$cursoId] = [];
    }
    $canaisPorCurso[$cursoId][] = $canal;
}

$cursosComCanais = array_map(function($curso) use ($canaisPorCurso) {
    $curso['canais'] = $canaisPorCurso[$curso['id']] ?? [];
    return $curso;
}, $cursos);

header('Content-Type: application/json; charset=utf-8');
echo json_encode($cursosComCanais);
