# Sistema de Consulta de Mensalidades - Cruzeiro

Sistema para consulta de valores de mensalidades de graduação e pós-graduação.

## Funcionalidades

- 📊 Visualização de cursos em cards
- 🔍 Busca por nome do curso
- 📁 Upload de planilhas Excel/CSV
- 💰 Valores com e sem desconto
- 📱 Layout responsivo

## Estrutura do Projeto

```
sistema-mensalidades/
├── api/                    # Backend PHP
│   ├── config.php         # Configuração Supabase
│   ├── upload.php         # Upload de planilhas
│   ├── cursos.php         # API de cursos
│   ├── buscar.php         # Busca de cursos
│   └── uploads.php        # Histórico de uploads
├── public/                 # Frontend
│   ├── index.html         # Página principal
│   ├── admin.html         # Painel administrativo
│   ├── css/style.css      # Estilos
│   └── js/
│       ├── app.js         # Lógica principal
│       └── admin.js       # Lógica admin
├── vercel.json            # Configuração Vercel
└── composer.json          # Dependências PHP
```

## Configuração do Supabase

Execute este SQL no painel do Supabase para criar as tabelas:

```sql
-- Tabela de cursos
CREATE TABLE cursos (
  id SERIAL PRIMARY KEY,
  tipo VARCHAR(20) NOT NULL,
  nome_curso VARCHAR(255) NOT NULL,
  duracao VARCHAR(50),
  valor_integral DECIMAL(10,2) NOT NULL,
  valor_com_desconto DECIMAL(10,2),
  desconto_aplicado VARCHAR(100),
  percentual_desconto DECIMAL(5,2),
  observacoes TEXT,
  data_upload TIMESTAMP DEFAULT NOW(),
  ativo BOOLEAN DEFAULT TRUE
);

-- Tabela de uploads
CREATE TABLE uploads (
  id SERIAL PRIMARY KEY,
  nome_arquivo VARCHAR(255),
  tipo_planilha VARCHAR(20),
  registros_inseridos INTEGER,
  data_upload TIMESTAMP DEFAULT NOW()
);

-- Habilitar RLS (Row Level Security)
ALTER TABLE cursos ENABLE ROW LEVEL SECURITY;
ALTER TABLE uploads ENABLE ROW LEVEL SECURITY;

-- Política para leitura pública
CREATE POLICY "Leitura pública de cursos" ON cursos
  FOR SELECT USING (ativo = true);

-- Política para inserção (apenas com service key)
CREATE POLICY "Inserção autenticada" ON cursos
  FOR INSERT WITH CHECK (true);

CREATE POLICY "Atualização autenticada" ON cursos
  FOR UPDATE USING (true);

CREATE POLICY "Leitura de uploads" ON uploads
  FOR SELECT USING (true);

CREATE POLICY "Inserção de uploads" ON uploads
  FOR INSERT WITH CHECK (true);
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

## Formato das Planilhas

A planilha deve conter estas colunas (cabeçalho na primeira linha):

| Coluna | Descrição |
|--------|-----------|
| nome_curso ou curso | Nome do curso |
| duracao | Duração do curso |
| valor_integral | Valor mensal integral |
| valor_com_desconto | Valor com desconto |
| desconto_aplicado ou desconto | Nome do desconto/cota |
| percentual_desconto | Percentual de desconto |
| observacoes | Observações adicionais |

**Separador CSV:** ponto e vírgula (;)
