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

if (!in_array($extensao, ['csv', 'xls', 'xlsx'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Formato não suportado. Use: .csv']);
    exit;
}

if (in_array($extensao, ['xls', 'xlsx'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Arquivos Excel (.xls/.xlsx) ainda não suportados. Por favor, exporte como .csv no Excel (Arquivo > Salvar Como > CSV UTF-8).']);
    exit;
}

try {
    $tempDir = sys_get_temp_dir();
    $tempFile = $tempDir . '/' . uniqid('upload_') . '.' . $extensao;
    move_uploaded_file($arquivo['tmp_name'], $tempFile);

    $dados = processarCSV($tempFile);
    unlink($tempFile);

    if (empty($dados)) {
        throw new Exception('Nenhum dado encontrado na planilha');
    }

    // Busca IDs dos cursos antigos para deletar canais
    $cursosAntigos = supabaseRequest('GET', 'cursos?tipo=eq.' . $tipo . '&select=id');
    if (!empty($cursosAntigos['data'])) {
        $ids = array_column($cursosAntigos['data'], 'id');
        $idsStr = implode(',', $ids);
        supabaseRequest('DELETE', 'canais_desconto?curso_id=in.(' . $idsStr . ')');
    }

    // Deleta cursos antigos do tipo
    supabaseRequest('DELETE', 'cursos?tipo=eq.' . $tipo);

    // Agrupa por curso + modalidade
    $agrupados = [];
    foreach ($dados as $row) {
        $chave = trim($row['curso']) . '|' . trim($row['modalidade']);
        if (!isset($agrupados[$chave])) {
            $agrupados[$chave] = [
                'nome' => trim($row['curso']),
                'duracao' => trim($row['duracao']),
                'grau' => trim($row['grau']),
                'modalidade' => trim($row['modalidade']),
                'valor_integral' => $row['preco'],
                'canais' => []
            ];
        }
        if (!empty($row['canal'])) {
            $agrupados[$chave]['canais'][] = [
                'canal' => trim($row['canal']),
                'percentual' => $row['desconto'],
                'valor_desconto' => $row['valor_desconto'],
                'regressao_2sem' => $row['regressao_2sem'],
                'regressao_demais' => $row['regressao_demais']
            ];
        }
    }

    $cursosInseridos = 0;
    $canaisInseridos = 0;

    foreach ($agrupados as $curso) {
        $resultadoCurso = supabaseRequest('POST', 'cursos', [
            'tipo' => $tipo,
            'nome_curso' => $curso['nome'],
            'duracao' => $curso['duracao'],
            'grau' => $curso['grau'],
            'modalidade' => $curso['modalidade'],
            'valor_integral' => $curso['valor_integral'],
            'ativo' => true
        ]);

        if ($resultadoCurso['status'] === 201 && !empty($resultadoCurso['data'])) {
            $cursoId = $resultadoCurso['data'][0]['id'];
            $cursosInseridos++;

            foreach ($curso['canais'] as $canal) {
                supabaseRequest('POST', 'canais_desconto', [
                    'curso_id' => $cursoId,
                    'canal' => $canal['canal'],
                    'percentual_desconto' => $canal['percentual'],
                    'valor_com_desconto' => $canal['valor_desconto'],
                    'regressao_2sem' => $canal['regressao_2sem'],
                    'regressao_demais' => $canal['regressao_demais']
                ]);
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
 * Processa CSV no formato SESTED
 * Separador: ;
 * Valores: R$ 209,9 ou 209,9
 * Percentuais: 15,00% ou 0.15
 */
function processarCSV($arquivo) {
    $handle = fopen($arquivo, 'r');
    if (!$handle) throw new Exception('Não foi possível abrir o arquivo');

    // Detecta encoding e converte para UTF-8
    $raw = file_get_contents($arquivo);
    $encoding = mb_detect_encoding($raw, ['ISO-8859-1', 'Windows-1252', 'UTF-8', 'ASCII'], true);
    if ($encoding && $encoding !== 'UTF-8') {
        $raw = mb_convert_encoding($raw, 'UTF-8', $encoding);
        file_put_contents($arquivo, $raw);
        fclose($handle);
        $handle = fopen($arquivo, 'r');
    }

    // Detecta separador
    $primeiraLinha = fgets($handle, 8192);
    $sep = (substr_count($primeiraLinha, ';') > substr_count($primeiraLinha, ',')) ? ';' : ',';
    rewind($handle);

    // Lê cabeçalho e normaliza
    $cabecalho = fgetcsv($handle, 0, $sep);
    if (!$cabecalho) { fclose($handle); return []; }

    $cabecalhoNorm = array_map(function($h) {
        return trim(mb_strtolower($h, 'UTF-8'));
    }, $cabecalho);

    // Mapeia índices das colunas
    $mapa = [
        'codigo' => -1, 'curso' => -1, 'duracao' => -1, 'grau' => -1,
        'modalidade' => -1, 'canal' => -1, 'preco' => -1,
        'desconto' => -1, 'valor_desconto' => -1,
        'regressao_2sem' => -1, 'regressao_demais' => -1
    ];

    foreach ($cabecalhoNorm as $idx => $col) {
        if (strpos($col, 'código') !== false || strpos($col, 'codigo') !== false) $mapa['codigo'] = $idx;
        elseif (strpos($col, 'curso') !== false || strpos($col, 'nome') !== false) $mapa['curso'] = $idx;
        elseif (strpos($col, 'duração') !== false || strpos($col, 'duracao') !== false) $mapa['duracao'] = $idx;
        elseif (strpos($col, 'grau') !== false) $mapa['grau'] = $idx;
        elseif (strpos($col, 'submodalidade') !== false || strpos($col, 'modalidade') !== false) $mapa['modalidade'] = $idx;
        elseif (strpos($col, 'canal') !== false) $mapa['canal'] = $idx;
        elseif (strpos($col, 'preço') !== false || strpos($col, 'preco') !== false || strpos($col, 'siaa') !== false) $mapa['preco'] = $idx;
        elseif (strpos($col, 'desconto') !== false && strpos($col, 'valor') === false) $mapa['desconto'] = $idx;
        elseif (strpos($col, 'valor com desconto') !== false || strpos($col, 'valor_com_desconto') !== false) $mapa['valor_desconto'] = $idx;
        elseif (strpos($col, 'regressão a partir') !== false || strpos($col, 'regressao a partir') !== false) $mapa['regressao_2sem'] = $idx;
        elseif (strpos($col, 'regressão demais') !== false || strpos($col, 'regressao demais') !== false) $mapa['regressao_demais'] = $idx;
    }

    if ($mapa['curso'] === -1 || $mapa['preco'] === -1) {
        fclose($handle);
        throw new Exception('Colunas obrigatórias não encontradas. Cabeçalho: ' . implode(' | ', $cabecalho));
    }

    $result = [];
    $linhasLidas = 0;

    while (($row = fgetcsv($handle, 0, $sep)) !== false) {
        $linhasLidas++;
        if (count($row) < 5) continue;

        $curso = $mapa['curso'] >= 0 ? trim($row[$mapa['curso']] ?? '') : '';
        if (empty($curso)) continue;

        // Pula linhas de filtro/resumo no final
        if (strpos($curso, 'Filtros') !== false || strpos($curso, 'filtro') !== false) break;
        if (preg_match('/^(total|subtotal|soma|quantidade|qtd)/i', $curso)) continue;

        $preco = parseValorMonetario($row[$mapa['preco']] ?? '0');
        if ($preco <= 0) continue;

        $desconto = $mapa['desconto'] >= 0 ? parsePercentual($row[$mapa['desconto']] ?? '0') : 0;
        $valorDesconto = $mapa['valor_desconto'] >= 0 ? parseValorMonetario($row[$mapa['valor_desconto']] ?? '0') : 0;
        $reg2 = $mapa['regressao_2sem'] >= 0 ? parsePercentual($row[$mapa['regressao_2sem']] ?? '0') : 0;
        $regDemais = $mapa['regressao_demais'] >= 0 ? parsePercentual($row[$mapa['regressao_demais']] ?? '0') : 0;

        $result[] = [
            'codigo' => $mapa['codigo'] >= 0 ? trim($row[$mapa['codigo']] ?? '') : '',
            'curso' => $curso,
            'duracao' => $mapa['duracao'] >= 0 ? trim($row[$mapa['duracao']] ?? '') : '',
            'grau' => $mapa['grau'] >= 0 ? trim($row[$mapa['grau']] ?? '') : '',
            'modalidade' => $mapa['modalidade'] >= 0 ? trim($row[$mapa['modalidade']] ?? '') : '',
            'canal' => $mapa['canal'] >= 0 ? trim($row[$mapa['canal']] ?? '') : '',
            'preco' => $preco,
            'desconto' => $desconto,
            'valor_desconto' => $valorDesconto,
            'regressao_2sem' => $reg2,
            'regressao_demais' => $regDemais
        ];
    }

    fclose($handle);
    return $result;
}

/**
 * Converte "R$ 209,9" ou "209,90" ou "209.9" para float
 */
function parseValorMonetario($valor) {
    if ($valor === null || $valor === '') return 0;
    if (is_numeric($valor)) return (float) $valor;

    $s = (string) $valor;
    $s = preg_replace('/R\$\s*/i', '', $s);
    $s = preg_replace('/[^0-9,.]/', '', $s);
    $s = trim($s);
    if ($s === '') return 0;

    // Formato brasileiro: 1.200,50 ou 209,9
    $temVirgula = strpos($s, ',') !== false;
    $temPonto = strpos($s, '.') !== false;

    if ($temVirgula && $temPonto) {
        $ultimaVirgula = strrpos($s, ',');
        $ultimoPonto = strrpos($s, '.');
        if ($ultimaVirgula > $ultimoPonto) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }
    } elseif ($temVirgula) {
        $partes = explode(',', $s);
        if (count($partes) === 2 && strlen($partes[1]) <= 2) {
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }
    }

    return (float) $s;
}

/**
 * Converte "15,00%" ou "0.15" para decimal (0.15)
 */
function parsePercentual($valor) {
    if ($valor === null || $valor === '') return 0;
    if (is_numeric($valor)) {
        $v = (float) $valor;
        // Se já é decimal (0.15), retorna direto
        if ($v > 0 && $v < 1) return $v;
        // Se é percentual (15), converte
        if ($v >= 1 && $v <= 100) return $v / 100;
        return 0;
    }

    $s = (string) $valor;
    $s = str_replace('%', '', $s);
    $s = str_replace(',', '.', trim($s));

    if (!is_numeric($s)) return 0;

    $v = (float) $s;
    if ($v > 0 && $v < 1) return $v;
    if ($v >= 1 && $v <= 100) return $v / 100;
    return 0;
}


