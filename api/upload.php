<?php

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$senha = $_GET['senha'] ?? '';
if ($senha !== ADMIN_PASSWORD) {
    http_response_code(401);
    echo json_encode(['error' => 'Senha inválida']);
    exit;
}

if (!isset($_FILES['arquivo'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Nenhum arquivo enviado']);
    exit;
}

$tipo = $_POST['tipo'] ?? '';
if (!in_array($tipo, ['graduacao', 'pos_graduacao'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Tipo inválido']);
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
    $tempDir = sys_get_temp_dir();
    $tempFile = $tempDir . '/' . uniqid('upload_') . '.' . $extensao;
    move_uploaded_file($arquivo['tmp_name'], $tempFile);

    if ($extensao === 'csv') {
        $dados = processarCSV($tempFile);
    } else {
        $dados = processarExcel($tempFile);
    }

    unlink($tempFile);

    if (empty($dados)) {
        throw new Exception('Nenhum dado encontrado na planilha.');
    }

    // Limpa dados antigos do tipo
    supabaseRequest('POST', 'cursos?tipo=eq.' . $tipo, ['ativo' => false], true);

    // Agrupa por curso + modalidade para criar registros únicos
    $cursosAgrupados = agruparCursos($dados);

    // Insere cursos
    $cursosInseridos = 0;
    $canaisInseridos = 0;

    foreach ($cursosAgrupados as $chave => $curso) {
        // Insere curso
        $resultadoCurso = supabaseRequest('POST', 'cursos', [
            'tipo' => $tipo,
            'nome_curso' => $curso['nome'],
            'duracao' => $curso['duracao'],
            'grau' => $curso['grau'],
            'modalidade' => $curso['modalidade'],
            'valor_integral' => $curso['valor_integral'],
            'ativo' => true
        ], true);

        if ($resultadoCurso['status'] === 201 && !empty($resultadoCurso['data'])) {
            $cursoId = $resultadoCurso['data'][0]['id'];
            $cursosInseridos++;

            // Insere canais de desconto
            foreach ($curso['canais'] as $canal) {
                supabaseRequest('POST', 'canais_desconto', [
                    'curso_id' => $cursoId,
                    'canal' => $canal['canal'],
                    'percentual_desconto' => $canal['percentual'],
                    'valor_com_desconto' => $canal['valor_desconto'],
                    'regressao_2sem' => $canal['regressao_2sem'],
                    'regressao_demais' => $canal['regressao_demais']
                ], true);
                $canaisInseridos++;
            }
        }
    }

    registrarUpload($arquivo['name'], $tipo, $cursosInseridos);

    echo json_encode([
        'success' => true,
        'message' => "$cursosInseridos cursos importados com $canaisInseridos canais de desconto",
        'cursos' => $cursosInseridos,
        'canais' => $canaisInseridos
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Agrupa linhas por curso + modalidade
 */
function agruparCursos($dados) {
    $agrupados = [];

    foreach ($dados as $row) {
        $chave = normalizarTexto($row['curso']) . '|' . normalizarTexto($row['modalidade']);

        if (!isset($agrupados[$chave])) {
            $agrupados[$chave] = [
                'nome' => $row['curso'],
                'duracao' => $row['duracao'],
                'grau' => $row['grau'],
                'modalidade' => $row['modalidade'],
                'valor_integral' => $row['preco_siaa'],
                'canais' => []
            ];
        }

        // Adiciona canal se não existir
        $canalExiste = false;
        foreach ($agrupados[$chave]['canais'] as $c) {
            if ($c['canal'] === $row['canal']) {
                $canalExiste = true;
                break;
            }
        }

        if (!$canalExiste && !empty($row['canal'])) {
            $agrupados[$chave]['canais'][] = [
                'canal' => $row['canal'],
                'percentual' => $row['desconto'],
                'valor_desconto' => $row['valor_com_desconto'],
                'regressao_2sem' => $row['regressao_2sem'],
                'regressao_demais' => $row['regressao_demais']
            ];
        }
    }

    return $agrupados;
}

/**
 * Normaliza texto para comparação
 */
function normalizarTexto($texto) {
    $texto = mb_strtolower(trim($texto), 'UTF-8');
    return str_replace(
        ['á','à','â','ã','ä','å','é','è','ê','ë','í','ì','î','ï','ó','ò','ô','õ','ö','ø','ú','ù','û','ü','ý','ñ','ç'],
        ['a','a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','o','u','u','u','u','y','n','c'],
        $texto
    );
}

/**
 * Converte valor monetário para float
 */
function parseValor($valor) {
    if ($valor === null || $valor === '') return 0;
    if (is_numeric($valor)) return (float) $valor;

    $valor = (string) $valor;
    $valor = preg_replace('/[^0-9,.]/', '', $valor);
    if ($valor === '') return 0;

    $temVirgula = strpos($valor, ',') !== false;
    $temPonto = strpos($valor, '.') !== false;

    if ($temVirgula && $temPonto) {
        $ultimaVirgula = strrpos($valor, ',');
        $ultimoPonto = strrpos($valor, '.');
        if ($ultimaVirgula > $ultimoPonto) {
            $valor = str_replace('.', '', $valor);
            $valor = str_replace(',', '.', $valor);
        } else {
            $valor = str_replace(',', '', $valor);
        }
    } elseif ($temVirgula) {
        $partes = explode(',', $valor);
        if (count($partes) === 2 && strlen($partes[1]) <= 2) {
            $valor = str_replace(',', '.', $valor);
        } else {
            $valor = str_replace(',', '', $valor);
        }
    }

    return (float) $valor;
}

/**
 * Processa planilha Excel
 */
function processarExcel($arquivo) {
    require_once __DIR__ . '/../vendor/autoload.php';

    use PhpOffice\PhpSpreadsheet\IOFactory;

    $spreadsheet = IOFactory::load($arquivo);
    $sheet = $spreadsheet->getActiveSheet();
    $allData = $sheet->toArray(null, true, true, true);

    if (empty($allData)) return [];

    // Cabeçalho esperado:
    // CÓDIGO, CURSOS, DURAÇÃO, GRAU, SUBMODALIDADE, CANAL,
    // PREÇO SIAA, DESCONTO 1 SEMESTRE, VALOR COM DESCONTO,
    // REGRESSÃO A PARTIR DO 2 SEMESTRE, REGRESSÃO DEMAIS SEMESTRES, DATA, DATA FINAL CAMPANHA

    $result = [];
    $linhas = $sheet->toArray();

    for ($i = 1; $i < count($linhas); $i++) {
        $row = $linhas[$i];
        if (!$row || empty($row[1])) continue;

        $preco = parseValor($row[6]);
        if ($preco <= 0) continue;

        $percentual = parseValor($row[7]);
        $valorDesconto = parseValor($row[8]);
        $regressao2 = parseValor($row[9]);
        $regressaoDemais = parseValor($row[10]);

        $result[] = [
            'codigo' => trim((string)$row[0]),
            'curso' => trim((string)$row[1]),
            'duracao' => trim((string)$row[2]),
            'grau' => trim((string)$row[3]),
            'modalidade' => trim((string)$row[4]),
            'canal' => trim((string)$row[5]),
            'preco_siaa' => $preco,
            'desconto' => $percentual,
            'valor_com_desconto' => $valorDesconto,
            'regressao_2sem' => $regressao2,
            'regressao_demais' => $regressaoDemais
        ];
    }

    return $result;
}

/**
 * Processa CSV
 */
function processarCSV($arquivo) {
    $handle = fopen($arquivo, 'r');
    $primeiraLinha = fgets($handle, 4096);
    $sep = (substr_count($primeiraLinha, ';') > substr_count($primeiraLinha, ',')) ? ';' : ',';
    rewind($handle);

    $cabecalho = fgetcsv($handle, 0, $sep);
    if (!$cabecalho) { fclose($handle); return []; }

    $result = [];
    while (($row = fgetcsv($handle, 0, $sep)) !== false) {
        if (count($row) < 9 || empty($row[1])) continue;

        $result[] = [
            'codigo' => trim((string)$row[0]),
            'curso' => trim((string)$row[1]),
            'duracao' => trim((string)$row[2]),
            'grau' => trim((string)$row[3]),
            'modalidade' => trim((string)$row[4]),
            'canal' => trim((string)$row[5]),
            'preco_siaa' => parseValor($row[6]),
            'desconto' => parseValor($row[7]),
            'valor_com_desconto' => parseValor($row[8]),
            'regressao_2sem' => parseValor($row[9] ?? ''),
            'regressao_demais' => parseValor($row[10] ?? '')
        ];
    }

    fclose($handle);
    return $result;
}

/**
 * Wrapper para requests Supabase com método DELETE via POST
 */
function supabaseRequest($method, $endpoint, $data = null, $returnRaw = false) {
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

function registrarUpload($nomeArquivo, $tipo, $registros) {
    supabaseRequest('POST', 'uploads', [
        'nome_arquivo' => $nomeArquivo,
        'tipo_planilha' => $tipo,
        'registros_inseridos' => $registros
    ]);
}
