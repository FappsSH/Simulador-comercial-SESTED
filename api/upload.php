<?php

require_once __DIR__ . '/config.php';

// Verifica método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

// Verifica senha
$senha = $_GET['senha'] ?? '';
if ($senha !== ADMIN_PASSWORD) {
    http_response_code(401);
    echo json_encode(['error' => 'Senha inválida']);
    exit;
}

// Verifica se arquivo foi enviado
if (!isset($_FILES['arquivo'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Nenhum arquivo enviado']);
    exit;
}

$tipo = $_POST['tipo'] ?? '';
if (!in_array($tipo, ['graduacao', 'pos_graduacao'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Tipo inválido. Use: graduacao ou pos_graduacao']);
    exit;
}

$arquivo = $_FILES['arquivo'];
$extensao = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));

if (!in_array($extensao, ['xls', 'xlsx', 'csv'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Formato não suportado. Use: .xls, .xlsx ou .csv']);
    exit;
}

try {
    // Salva temporariamente
    $tempDir = sys_get_temp_dir();
    $tempFile = $tempDir . '/' . uniqid('upload_') . '.' . $extensao;
    move_uploaded_file($arquivo['tmp_name'], $tempFile);
    
    // Processa conforme tipo
    if ($extensao === 'csv') {
        $dados = processarCSV($tempFile);
    } else {
        $dados = processarExcel($tempFile);
    }
    
    // Remove arquivo temporário
    unlink($tempFile);
    
    if (empty($dados)) {
        throw new Exception('Nenhum dado encontrado na planilha');
    }
    
    // Desativa cursos antigos do mesmo tipo
    desativarCursos($tipo);
    
    // Insere novos cursos
    $cursosParaInserir = array_map(function($item) use ($tipo) {
        return [
            'tipo' => $tipo,
            'nome_curso' => $item['nome'],
            'duracao' => $item['duracao'] ?? '',
            'valor_integral' => $item['valor_integral'],
            'valor_com_desconto' => $item['valor_com_desconto'] ?? null,
            'desconto_aplicado' => $item['desconto'] ?? '',
            'percentual_desconto' => $item['percentual'] ?? null,
            'observacoes' => $item['observacoes'] ?? '',
            'ativo' => true
        ];
    }, $dados);
    
    $resultado = insertCursos($cursosParaInserir);
    
    // Registra upload
    registrarUpload($arquivo['name'], $tipo, count($dados));
    
    echo json_encode([
        'success' => true,
        'message' => count($dados) . ' cursos importados com sucesso',
        'registros' => count($dados)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Processa arquivo CSV
 */
function processarCSV($arquivo) {
    $dados = [];
    $handle = fopen($arquivo, 'r');
    
    // Lê cabeçalho
    $cabecalho = fgetcsv($handle, 0, ';');
    
    // Normaliza cabeçalho
    $cabecalho = array_map(function($h) {
        return mb_strtolower(trim($h), 'UTF-8');
    }, $cabecalho);
    
    while (($row = fgetcsv($handle, 0, ';')) !== false) {
        if (count($row) < 3) continue;
        
        $item = [];
        foreach ($cabecalho as $i => $col) {
            $item[$col] = $row[$i] ?? '';
        }
        
        // Mapeia colunas (ajuste conforme sua planilha)
        $dados[] = [
            'nome' => $item['nome_curso'] ?? $item['curso'] ?? $item['nome'] ?? '',
            'duracao' => $item['duracao'] ?? '',
            'valor_integral' => parseFloat($item['valor_integral'] ?? $item['integral'] ?? $item['valor_integral_mensal'] ?? '0'),
            'valor_com_desconto' => parseFloat($item['valor_com_desconto'] ?? $item['com_desconto'] ?? $item['valor_com_desconto_mensal'] ?? '0') ?: null,
            'desconto' => $item['desconto_aplicado'] ?? $item['desconto'] ?? $item['cota'] ?? '',
            'percentual' => parseFloat($item['percentual_desconto'] ?? $item['percentual'] ?? '0') ?: null,
            'observacoes' => $item['observacoes'] ?? $item['obs'] ?? ''
        ];
    }
    
    fclose($handle);
    return $dados;
}

/**
 * Processa arquivo Excel (.xls / .xlsx)
 */
function processarExcel($arquivo) {
    require_once __DIR__ . '/../vendor/autoload.php';
    
    use PhpOffice\PhpSpreadsheet\IOFactory;
    
    $spreadsheet = IOFactory::load($arquivo);
    $sheet = $spreadsheet->getActiveSheet();
    $dados = $sheet->toArray();
    
    if (empty($dados)) return [];
    
    // Primeira linha é cabeçalho
    $cabecalho = array_map(function($h) {
        return mb_strtolower(trim($h), 'UTF-8');
    }, array_shift($dados));
    
    $result = [];
    
    foreach ($dados as $row) {
        $item = [];
        foreach ($cabecalho as $i => $col) {
            $item[$col] = $row[$i] ?? '';
        }
        
        // Pula linhas vazias
        $nome = $item['nome_curso'] ?? $item['curso'] ?? $item['nome'] ?? '';
        if (empty($nome)) continue;
        
        $result[] = [
            'nome' => $nome,
            'duracao' => $item['duracao'] ?? '',
            'valor_integral' => parseFloat($item['valor_integral'] ?? $item['integral'] ?? $item['valor_integral_mensal'] ?? '0'),
            'valor_com_desconto' => parseFloat($item['valor_com_desconto'] ?? $item['com_desconto'] ?? $item['valor_com_desconto_mensal'] ?? '0') ?: null,
            'desconto' => $item['desconto_aplicado'] ?? $item['desconto'] ?? $item['cota'] ?? '',
            'percentual' => parseFloat($item['percentual_desconto'] ?? $item['percentual'] ?? '0') ?: null,
            'observacoes' => $item['observacoes'] ?? $item['obs'] ?? ''
        ];
    }
    
    return $result;
}

/**
 * Converte valor monetário brasileiro para float
 */
function parseFloat($valor) {
    if (is_numeric($valor)) {
        return (float) $valor;
    }
    
    // Remove "R$" e espaços
    $valor = preg_replace('/[^0-9,.]/', '', $valor);
    
    // Troca vírgula por ponto
    $valor = str_replace(',', '.', $valor);
    
    return (float) $valor;
}
