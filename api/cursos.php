<?php

require_once __DIR__ . '/config.php';

header('Cache-Control: public, max-age=300');

$tipo = $_GET['tipo'] ?? null;

// Busca cursos
$endpoint = 'cursos?ativo=eq.true&order=nome_curso.asc';
if ($tipo) {
    $endpoint .= '&tipo=eq.' . $tipo;
}

$resultado = supabaseRequest('GET', $endpoint);

if ($resultado['status'] !== 200) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao buscar cursos']);
    exit;
}

$cursos = $resultado['data'] ?? [];

// Busca canais de desconto para todos os cursos ativos
$canaisResultado = supabaseRequest('GET', 'canais_desconto?order=canal.asc');
$canaisData = $canaisResultado['data'] ?? [];

// Indexa canais por curso_id
$canaisPorCurso = [];
foreach ($canaisData as $canal) {
    $cursoId = $canal['curso_id'];
    if (!isset($canaisPorCurso[$cursoId])) {
        $canaisPorCurso[$cursoId] = [];
    }
    $canaisPorCurso[$cursoId][] = $canal;
}

// Monta resposta com canais
$cursosComCanais = array_map(function($curso) use ($canaisPorCurso) {
    $curso['canais'] = $canaisPorCurso[$curso['id']] ?? [];
    return $curso;
}, $cursos);

header('Content-Type: application/json; charset=utf-8');
echo json_encode($cursosComCanais);
