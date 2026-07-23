<?php

// Configuração do Supabase
define('SUPABASE_URL', getenv('SUPABASE_URL') ?: 'https://nxfwowbyjxaznuljlcym.supabase.co');
define('SUPABASE_KEY', getenv('SUPABASE_KEY') ?: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Im54Zndvd2J5anhhem51bGpsY3ltIiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODQ4Mjc3OTgsImV4cCI6MjEwMDQwMzc5OH0.kHzPyQPQBdAcPpT1UhyW41WH6Oshb3NE0cMh1mwqIr4');
define('ADMIN_PASSWORD', getenv('ADMIN_PASSWORD') ?: 'cruzeiro2024');

// Headers CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Funções auxiliares para Supabase via REST API
 */

function supabaseRequest($method, $endpoint, $data = null) {
    $url = SUPABASE_URL . '/rest/v1/' . $endpoint;
    
    $headers = [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Content-Type: application/json',
        'Prefer: return=representation'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    switch ($method) {
        case 'GET':
            break;
        case 'POST':
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            break;
        case 'PUT':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            break;
        case 'DELETE':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            break;
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'status' => $httpCode,
        'data' => json_decode($response, true)
    ];
}

/**
 * Retorna todos os cursos
 */
function getCursos($tipo = null) {
    $endpoint = 'cursos?ativo=eq.true&order=nome_curso.asc';
    
    if ($tipo) {
        $endpoint .= '&tipo=eq.' . $tipo;
    }
    
    return supabaseRequest('GET', $endpoint);
}

/**
 * Busca cursos por nome
 */
function buscarCursos($termo) {
    $endpoint = 'cursos?ativo=eq.true&nome_curso=ilike.*' . urlencode($termo) . '*&order=nome_curso.asc';
    return supabaseRequest('GET', $endpoint);
}

/**
 * Insere múltiplos cursos
 */
function insertCursos($cursos) {
    return supabaseRequest('POST', 'cursos', $cursos);
}

/**
 * Desativa todos os cursos de um tipo (antes de novo upload)
 */
function desativarCursos($tipo) {
    return supabaseRequest('POST', 'cursos', [
        'ativo' => false
    ]);
}

/**
 * Registra upload
 */
function registrarUpload($nomeArquivo, $tipo, $registros) {
    return supabaseRequest('POST', 'uploads', [
        'nome_arquivo' => $nomeArquivo,
        'tipo_planilha' => $tipo,
        'registros_inseridos' => $registros
    ]);
}

/**
 * Retorna histórico de uploads
 */
function getUploads() {
    return supabaseRequest('GET', 'uploads?order=data_upload.desc&limit=10');
}

/**
 * Formata valor para brasileiro
 */
function formatarValor($valor) {
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}
