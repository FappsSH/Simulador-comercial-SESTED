# Sistema de Consulta de Mensalidades - Cruzeiro

Sistema para consulta de valores de mensalidades de graduação e pós-graduação.

## Funcionalidades

- Visualização de cursos em cards com valores integrais
- Múltiplos canais de desconto por curso
- Busca por nome do curso
- Upload de planilhas Excel/CSV
- Layout responsivo

## Estrutura do Projeto

```
sistema-mensalidades/
├── api/                    # Backend PHP
│   ├── config.php         # Configuração Supabase
│   ├── upload.php         # Upload de planilhas
│   ├── cursos.php         # API de cursos
│   ├── buscar.php         # Busca de cursos
│   └── uploads.php        # Histórico de uploads
├── index.html             # Página principal
├── admin.html             # Painel administrativo
├── css/style.css          # Estilos
├── js/app.js              # Lógica principal
├── js/admin.js            # Lógica admin
├── vercel.json            # Configuração Vercel
└── composer.json          # Dependências PHP
```

## Configuração do Supabase

Execute este SQL no painel do Supabase (SQL Editor):

```sql
-- Tabela de cursos
CREATE TABLE cursos (
  id SERIAL PRIMARY KEY,
  tipo VARCHAR(20) NOT NULL,
  nome_curso VARCHAR(255) NOT NULL,
  duracao VARCHAR(50),
  grau VARCHAR(100),
  modalidade VARCHAR(100),
  valor_integral DECIMAL(10,2) NOT NULL,
  data_upload TIMESTAMP DEFAULT NOW(),
  ativo BOOLEAN DEFAULT TRUE
);

-- Tabela de canais de desconto
CREATE TABLE canais_desconto (
  id SERIAL PRIMARY KEY,
  curso_id INTEGER REFERENCES cursos(id) ON DELETE CASCADE,
  canal VARCHAR(255) NOT NULL,
  percentual_desconto DECIMAL(5,4),
  valor_com_desconto DECIMAL(10,2),
  regressao_2sem DECIMAL(5,4),
  regressao_demais DECIMAL(5,4)
);

-- Tabela de uploads
CREATE TABLE uploads (
  id SERIAL PRIMARY KEY,
  nome_arquivo VARCHAR(255),
  tipo_planilha VARCHAR(20),
  registros_inseridos INTEGER,
  data_upload TIMESTAMP DEFAULT NOW()
);

-- Habilitar RLS
ALTER TABLE cursos ENABLE ROW LEVEL SECURITY;
ALTER TABLE canais_desconto ENABLE ROW LEVEL SECURITY;
ALTER TABLE uploads ENABLE ROW LEVEL SECURITY;

-- Políticas de leitura pública
CREATE POLICY "Leitura pública cursos" ON cursos FOR SELECT USING (true);
CREATE POLICY "Leitura pública canais" ON canais_desconto FOR SELECT USING (true);
CREATE POLICY "Leitura uploads" ON uploads FOR SELECT USING (true);

-- Políticas de inserção/atualização
CREATE POLICY "Inserção cursos" ON cursos FOR INSERT WITH CHECK (true);
CREATE POLICY "Atualização cursos" ON cursos FOR UPDATE USING (true);
CREATE POLICY "Inserção canais" ON canais_desconto FOR INSERT WITH CHECK (true);
CREATE POLICY "Delete canais" ON canais_desconto FOR DELETE USING (true);
CREATE POLICY "Inserção uploads" ON uploads FOR INSERT WITH CHECK (true);

-- Índices para performance
CREATE INDEX idx_cursos_tipo ON cursos(tipo);
CREATE INDEX idx_cursos_ativo ON cursos(ativo);
CREATE INDEX idx_cursos_nome ON cursos(nome_curso);
CREATE INDEX idx_canais_curso_id ON canais_desconto(curso_id);
```

## Deploy na Vercel

1. Instale o CLI da Vercel:
   ```bash
   npm i -g vercel
   ```

2. Faça login:
   ```bash
   vercel login
   ```

3. Deploy:
   ```bash
   vercel
   ```

4. Configure as variáveis de ambiente no painel da Vercel:
   - `SUPABASE_URL`: URL do seu projeto Supabase
   - `SUPABASE_KEY`: Chave anônima do Supabase
   - `ADMIN_PASSWORD`: Senha para o painel admin

## Uso

### Página Principal
Acesse o link principal para ver todos os cursos.

### Painel Administrativo
Acesse `/admin?senha=SUA_SENHA` para fazer upload de planilhas.

## Formato da Planilha

Colunas esperadas (cabeçalho na primeira linha):

| Coluna | Descrição |
|--------|-----------|
| CÓDIGO | Código do curso |
| CURSOS | Nome do curso |
| DURAÇÃO | Duração em meses |
| GRAU | Bacharelado, Licenciatura, etc. |
| SUBMODALIDADE | Ao Vivo, Digital, Semipresencial |
| CANAL | Canal de venda/desconto |
| PREÇO SIAA | Valor mensal integral |
| DESCONTO 1 SEMESTRE | Percentual decimal (0.15 = 15%) |
| VALOR COM DESCONTO | Valor mensal com desconto |
| REGRESSÃO A PARTIR DO 2 SEMESTRE | Percentual 2º semestre |
| REGRESSÃO DEMAIS SEMESTRES | Percentual demais semestres |

**Separador CSV:** ponto e vírgula (;) ou vírgula (,)
