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
        throw new Exception('Nenhum dado encontrado na planilha. Verifique se a primeira linha contém os cabeçalhos das colunas.');
    }

    desativarCursos($tipo);

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
 * Normaliza nome da coluna para lowercase sem acentos
 */
function normalizarColuna($texto) {
    $texto = mb_strtolower(trim($texto), 'UTF-8');
    $texto = str_replace(
        ['á','à','â','ã','ä','å','é','è','ê','ë','í','ì','î','ï','ó','ò','ô','õ','ö','ø','ú','ù','û','ü','ý','ñ','ç','ª','º'],
        ['a','a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','o','u','u','u','u','y','n','c','',''],
        $texto
    );
    return $texto;
}

/**
 * Busca valor em item usando múltiplos nomes possíveis de coluna
 */
function buscarColuna($item, $opcoes) {
    foreach ($opcoes as $opcao) {
        $chave = normalizarColuna($opcao);
        if (isset($item[$chave]) && $item[$chave] !== '' && $item[$chave] !== null) {
            return $item[$chave];
        }
    }
    return null;
}

/**
 * Converte valor monetário brasileiro para float
 */
function parseValorMonetario($valor) {
    if ($valor === null || $valor === '') return 0;
    if (is_numeric($valor)) return (float) $valor;

    $valor = (string) $valor;
    $valor = preg_replace('/[^0-9,.]/', '', $valor);

    if ($valor === '') return 0;

    // Detecta formato: "1.200,50" (brasileiro) vs "1,200.50" (inglês)
    $temVirgula = strpos($valor, ',') !== false;
    $temPonto = strpos($valor, '.') !== false;

    if ($temVirgula && $temPonto) {
        // Ambos presentes: qual vem por último?
        $ultimaVirgula = strrpos($valor, ',');
        $ultimoPonto = strrpos($valor, '.');
        if ($ultimaVirgula > $ultimoPonto) {
            // Formato brasileiro: 1.200,50
            $valor = str_replace('.', '', $valor);
            $valor = str_replace(',', '.', $valor);
        } else {
            // Formato inglês: 1,200.50
            $valor = str_replace(',', '', $valor);
        }
    } elseif ($temVirgula) {
        // Só vírgula: pode ser "1200,50" ou "1200"
        $partes = explode(',', $valor);
        if (count($partes) === 2 && strlen($partes[1]) <= 2) {
            // provavelmente decimal: 1200,50
            $valor = str_replace(',', '.', $valor);
        } else {
            // milhares: 1,200 ou só remove
            $valor = str_replace(',', '', $valor);
        }
    }

    return (float) $valor;
}

/**
 * Detecta automaticamente qual coluna mapeia para cada campo
 */
function detectarMapeamento($cabecalho) {
    $mapa = [
        'nome' => null,
        'duracao' => null,
        'valor_integral' => null,
        'valor_com_desconto' => null,
        'desconto' => null,
        'percentual' => null,
        'observacoes' => null,
    ];

    foreach ($cabecalho as $idx => $col) {
        $c = normalizarColuna($col);

        // Nome do curso
        if ($mapa['nome'] === null && preg_match('/(nome|curso|disciplina|graduacao|habilitacao|iplina|programa)/', $c)) {
            $mapa['nome'] = $idx;
        }

        // Duração
        if ($mapa['duracao'] === null && preg_match('/(duracao|duracao|periodo|semest|ano|tempo)/', $c)) {
            $mapa['duracao'] = $idx;
        }

        // Valor integral
        if ($mapa['valor_integral'] === null && preg_match('/(integral|cheio|cheia|normal|total|bruto|sem.*(desc|bolsa|cota)|valor.*(mensal|original|base))/i', $c)) {
            $mapa['valor_integral'] = $idx;
        }

        // Valor com desconto
        if ($mapa['valor_com_desconto'] === null && preg_match('/(com.*(desc|bolsa|cota)|descont|bolsa|cota|promocion|oferta|liquido|final|real|pago)/i', $c)) {
            $mapa['valor_com_desconto'] = $idx;
        }

        // Se só tem uma coluna de "valor", usa como integral
        if ($mapa['valor_integral'] === null && preg_match('/(valor|mensalidade|preco|price|fee| mensal)/i', $c)) {
            $mapa['valor_integral'] = $idx;
        }

        // Desconto aplicado
        if ($mapa['desconto'] === null && preg_match('/(desconto|bolsa|cota|beneficio|incentivo|tipo.*(desc|bolsa|cota))/i', $c)) {
            $mapa['desconto'] = $idx;
        }

        // Percentual
        if ($mapa['percentual'] === null && preg_match('/(percent|%)|(pct)/i', $c)) {
            $mapa['percentual'] = $idx;
        }

        // Observações
        if ($mapa['observacoes'] === null && preg_match('/(obs|observ|nota|comment|detalhe|info|complement|regra|condicao)/i', $c)) {
            $mapa['observacoes'] = $idx;
        }
    }

    return $mapa;
}

/**
 * Processa arquivo Excel (.xls / .xlsx)
 */
function processarExcel($arquivo) {
    require_once __DIR__ . '/../vendor/autoload.php';

    use PhpOffice\PhpSpreadsheet\IOFactory;

    $spreadsheet = IOFactory::load($arquivo);
    $sheet = $spreadsheet->getActiveSheet();
    $allData = $sheet->toArray(null, true, true, true);

    if (empty($allData)) return [];

    // Encontra a primeira linha que parece ser cabeçalho
    $cabecalhoIdx = null;
    $cabecalho = null;

    foreach ($allData as $rowIdx => $row) {
        $valores = array_filter($row, function($v) { return $v !== null && $v !== ''; });
        if (count($valores) >= 2) {
            // Verifica se parece cabeçalho (tem palavras, não só números)
            $temTexto = false;
            foreach ($valores as $v) {
                if (!is_numeric($v) && !preg_match('/^R\$/', (string)$v)) {
                    $temTexto = true;
                    break;
                }
            }
            if ($temTexto) {
                $cabecalhoIdx = $rowIdx;
                $cabecalho = array_values($row);
                break;
            }
        }
    }

    if ($cabecalho === null) {
        throw new Exception('Não foi possível encontrar cabeçalhos na planilha. A primeira linha deve conter nomes das colunas.');
    }

    // Normaliza cabeçalho
    $cabecalhoNormalizado = array_map(function($h) {
        return normalizarColuna((string)$h);
    }, $cabecalho);

    // Detecta mapeamento automático
    $mapa = detectarMapeamento($cabecalho);

    if ($mapa['nome'] === null) {
        throw new Exception('Não foi possível identificar a coluna com o nome do curso. Colunas encontradas: ' . implode(', ', $cabecalho));
    }

    if ($mapa['valor_integral'] === null) {
        throw new Exception('Não foi possível identificar a coluna com o valor. Colunas encontradas: ' . implode(', ', $cabecalho));
    }

    $result = [];
    $linhas = $sheet->toArray();

    // Pula linhas até o cabeçalho
    $inicio = $cabecalhoIdx !== null ? $cabecalhoIdx + 1 : 1;

    for ($i = $inicio; $i < count($linhas); $i++) {
        $row = $linhas[$i];
        if (!$row) continue;

        $nome = isset($row[$mapa['nome']]) ? trim((string)$row[$mapa['nome']]) : '';
        if ($nome === '' || $nome === null) continue;

        // Pula linhas que parecem ser de filtro/resumo
        if (preg_match('/(filtro|total|subtotal|soma|quantidade|qtd|nº|numero|cód)/i', $nome)) continue;

        $valorIntegral = isset($row[$mapa['valor_integral']]) ? parseValorMonetario($row[$mapa['valor_integral']]) : 0;
        if ($valorIntegral <= 0) continue;

        $valorDesconto = null;
        if ($mapa['valor_com_desconto'] !== null && isset($row[$mapa['valor_com_desconto']])) {
            $vd = parseValorMonetario($row[$mapa['valor_com_desconto']]);
            $valorDesconto = $vd > 0 ? $vd : null;
        }

        $desconto = '';
        if ($mapa['desconto'] !== null && isset($row[$mapa['desconto']])) {
            $desconto = trim((string)$row[$mapa['desconto']]);
        }

        $percentual = null;
        if ($mapa['percentual'] !== null && isset($row[$mapa['percentual']])) {
            $p = parseValorMonetario($row[$mapa['percentual']]);
            $percentual = $p > 0 ? $p : null;
        }

        $duracao = '';
        if ($mapa['duracao'] !== null && isset($row[$mapa['duracao']])) {
            $duracao = trim((string)$row[$mapa['duracao']]);
        }

        $obs = '';
        if ($mapa['observacoes'] !== null && isset($row[$mapa['observacoes']])) {
            $obs = trim((string)$row[$mapa['observacoes']]);
        }

        $result[] = [
            'nome' => $nome,
            'duracao' => $duracao,
            'valor_integral' => $valorIntegral,
            'valor_com_desconto' => $valorDesconto,
            'desconto' => $desconto,
            'percentual' => $percentual,
            'observacoes' => $obs,
        ];
    }

    return $result;
}

/**
 * Processa arquivo CSV
 */
function processarCSV($arquivo) {
    $dados = [];
    $handle = fopen($arquivo, 'r');

    // Detecta separador
    $primeiraLinha = fgets($handle, 4096);
    $sep = (substr_count($primeiraLinha, ';') > substr_count($primeiraLinha, ',')) ? ';' : ',';
    rewind($handle);

    $cabecalho = fgetcsv($handle, 0, $sep);
    if (!$cabecalho) {
        fclose($handle);
        return [];
    }

    $mapa = detectarMapeamento($cabecalho);

    if ($mapa['nome'] === null || $mapa['valor_integral'] === null) {
        fclose($handle);
        return [];
    }

    while (($row = fgetcsv($handle, 0, $sep)) !== false) {
        if (count($row) < 2) continue;

        $nome = isset($row[$mapa['nome']]) ? trim((string)$row[$mapa['nome']]) : '';
        if ($nome === '' || preg_match('/(filtro|total|subtotal|soma)/i', $nome)) continue;

        $valorIntegral = isset($row[$mapa['valor_integral']]) ? parseValorMonetario($row[$mapa['valor_integral']]) : 0;
        if ($valorIntegral <= 0) continue;

        $valorDesconto = null;
        if ($mapa['valor_com_desconto'] !== null && isset($row[$mapa['valor_com_desconto']])) {
            $vd = parseValorMonetario($row[$mapa['valor_com_desconto']]);
            $valorDesconto = $vd > 0 ? $vd : null;
        }

        $desconto = '';
        if ($mapa['desconto'] !== null && isset($row[$mapa['desconto']])) {
            $desconto = trim((string)$row[$mapa['desconto']]);
        }

        $percentual = null;
        if ($mapa['percentual'] !== null && isset($row[$mapa['percentual']])) {
            $p = parseValorMonetario($row[$mapa['percentual']]);
            $percentual = $p > 0 ? $p : null;
        }

        $duracao = '';
        if ($mapa['duracao'] !== null && isset($row[$mapa['duracao']])) {
            $duracao = trim((string)$row[$mapa['duracao']]);
        }

        $obs = '';
        if ($mapa['observacoes'] !== null && isset($row[$mapa['observacoes']])) {
            $obs = trim((string)$row[$mapa['observacoes']]);
        }

        $dados[] = [
            'nome' => $nome,
            'duracao' => $duracao,
            'valor_integral' => $valorIntegral,
            'valor_com_desconto' => $valorDesconto,
            'desconto' => $desconto,
            'percentual' => $percentual,
            'observacoes' => $obs,
        ];
    }

    fclose($handle);
    return $dados;
}
