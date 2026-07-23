// Estado
let tipoSelecionado = 'graduacao';
let senhaAdmin = '';

// Elementos DOM
const uploadArea = document.getElementById('uploadArea');
const fileInput = document.getElementById('fileInput');
const progressBar = document.getElementById('progressBar');
const progressFill = document.getElementById('progressFill');
const message = document.getElementById('message');
const historyContainer = document.getElementById('historyContainer');
const uploadOptions = document.querySelectorAll('.upload-option');

// Inicialização
document.addEventListener('DOMContentLoaded', () => {
    // Verifica senha na URL
    const params = new URLSearchParams(window.location.search);
    senhaAdmin = params.get('senha') || '';
    
    if (!senhaAdmin) {
        mostrarMensagem('Acesse este painel com a senha: /admin?senha=SUA_SENHA', 'error');
        uploadArea.style.opacity = '0.5';
        uploadArea.style.pointerEvents = 'none';
        return;
    }
    
    configurarEventos();
    carregarHistorico();
});

// Configura eventos
function configurarEventos() {
    // Upload Area click
    uploadArea.addEventListener('click', () => {
        fileInput.click();
    });
    
    // File input change
    fileInput.addEventListener('change', (e) => {
        if (e.target.files.length > 0) {
            enviarArquivo(e.target.files[0]);
        }
    });
    
    // Drag and drop
    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.classList.add('dragover');
    });
    
    uploadArea.addEventListener('dragleave', () => {
        uploadArea.classList.remove('dragover');
    });
    
    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
        
        if (e.dataTransfer.files.length > 0) {
            enviarArquivo(e.dataTransfer.files[0]);
        }
    });
    
    // Seleção de tipo
    uploadOptions.forEach(option => {
        option.addEventListener('click', () => {
            uploadOptions.forEach(o => o.classList.remove('selected'));
            option.classList.add('selected');
            tipoSelecionado = option.dataset.tipo;
        });
    });
}

// Envia arquivo
async function enviarArquivo(arquivo) {
    // Valida extensão
    const extensao = arquivo.name.split('.').pop().toLowerCase();
    if (!['xls', 'xlsx', 'csv'].includes(extensao)) {
        mostrarMensagem('Formato não aceito. Use .xls, .xlsx ou .csv', 'error');
        return;
    }
    
    // Prepara FormData
    const formData = new FormData();
    formData.append('arquivo', arquivo);
    formData.append('tipo', tipoSelecionado);
    
    // Mostra progresso
    progressBar.style.display = 'block';
    progressFill.style.width = '0%';
    uploadArea.style.opacity = '0.5';
    
    try {
        const response = await fetch(`/api/upload.php?senha=${senhaAdmin}`, {
            method: 'POST',
            body: formData
        });
        
        const resultado = await response.json();
        
        if (response.ok && resultado.success) {
            mostrarMensagem(`✅ ${resultado.message}`, 'success');
            progressFill.style.width = '100%';
            carregarHistorico();
        } else {
            mostrarMensagem(`❌ ${resultado.error || 'Erro ao enviar arquivo'}`, 'error');
        }
        
    } catch (error) {
        mostrarMensagem('❌ Erro de conexão. Tente novamente.', 'error');
        console.error('Erro:', error);
    } finally {
        uploadArea.style.opacity = '1';
        setTimeout(() => {
            progressBar.style.display = 'none';
            progressFill.style.width = '0%';
        }, 2000);
    }
}

// Carrega histórico
async function carregarHistorico() {
    try {
        const response = await fetch(`/api/uploads.php?senha=${senhaAdmin}`);
        
        if (!response.ok) {
            throw new Error('Erro ao carregar histórico');
        }
        
        const uploads = await response.json();
        renderizarHistorico(uploads);
        
    } catch (error) {
        historyContainer.innerHTML = '<p style="color: #666">Não foi possível carregar o histórico.</p>';
    }
}

// Renderiza histórico
function renderizarHistorico(uploads) {
    if (!uploads || uploads.length === 0) {
        historyContainer.innerHTML = '<p style="color: #666">Nenhum upload realizado ainda.</p>';
        return;
    }
    
    const html = `
        <table class="history-table">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Arquivo</th>
                    <th>Tipo</th>
                    <th>Registros</th>
                </tr>
            </thead>
            <tbody>
                ${uploads.map(upload => `
                    <tr>
                        <td>${formatarData(upload.data_upload)}</td>
                        <td>${escapeHtml(upload.nome_arquivo)}</td>
                        <td>${upload.tipo_planilha === 'graduacao' ? 'Graduação' : 'Pós-Graduação'}</td>
                        <td>${upload.registros_inseridos}</td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    `;
    
    historyContainer.innerHTML = html;
}

// Mostra mensagem
function mostrarMensagem(texto, tipo) {
    message.textContent = texto;
    message.className = `message ${tipo}`;
}

// Formata data
function formatarData(data) {
    if (!data) return '-';
    const d = new Date(data);
    return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
}

// Escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
