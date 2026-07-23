// Estado da aplicação
let todosCursos = [];
let cursosFiltrados = [];
let tipoAtual = 'todos';

// Elementos DOM
const searchInput = document.getElementById('searchInput');
const cursosContainer = document.getElementById('cursosContainer');
const tabs = document.querySelectorAll('.tab');
const totalCursos = document.getElementById('totalCursos');
const totalGrad = document.getElementById('totalGrad');
const totalPos = document.getElementById('totalPos');

// Inicialização
document.addEventListener('DOMContentLoaded', () => {
    carregarCursos();
    configurarEventos();
});

// Carrega cursos da API
async function carregarCursos() {
    mostrarLoading();
    
    try {
        const response = await fetch('/api/cursos.php');
        
        if (!response.ok) {
            throw new Error('Erro ao carregar cursos');
        }
        
        todosCursos = await response.json();
        cursosFiltrados = [...todosCursos];
        
        atualizarEstatisticas();
        renderizarCursos();
        
    } catch (error) {
        mostrarErro('Não foi possível carregar os cursos. Tente novamente mais tarde.');
        console.error('Erro:', error);
    }
}

// Configura eventos
function configurarEventos() {
    // Busca
    searchInput.addEventListener('input', debounce((e) => {
        filtrarCursos(e.target.value);
    }, 300));
    
    // Abas
    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            tabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            tipoAtual = tab.dataset.tipo;
            aplicarFiltros();
        });
    });
}

// Filtra cursos por termo de busca
function filtrarCursos(termo) {
    termo = termo.toLowerCase().trim();
    
    if (!termo) {
        cursosFiltrados = [...todosCursos];
    } else {
        cursosFiltrados = todosCursos.filter(curso => 
            curso.nome_curso.toLowerCase().includes(termo) ||
            (curso.observacoes && curso.observacoes.toLowerCase().includes(termo)) ||
            (curso.desconto_aplicado && curso.desconto_aplicado.toLowerCase().includes(termo))
        );
    }
    
    aplicarFiltros();
}

// Aplica filtros (tipo + busca)
function aplicarFiltros() {
    let filtrados = [...cursosFiltrados];
    
    if (tipoAtual !== 'todos') {
        filtrados = filtrados.filter(c => c.tipo === tipoAtual);
    }
    
    renderizarCursos(filtrados);
}

// Renderiza cards dos cursos
function renderizarCursos(cursos = cursosFiltrados) {
    if (cursos.length === 0) {
        mostrarVazio();
        return;
    }
    
    const html = `
        <div class="cards-grid">
            ${cursos.map(curso => criarCard(curso)).join('')}
        </div>
    `;
    
    cursosContainer.innerHTML = html;
}

// Cria card individual
function criarCard(curso) {
    const tipoLabel = curso.tipo === 'graduacao' ? 'Graduação' : 'Pós-Graduação';
    const valorIntegral = formatarValor(curso.valor_integral);
    const valorDesconto = curso.valor_com_desconto ? formatarValor(curso.valor_com_desconto) : null;
    const percentual = curso.percentual_desconto ? Math.round(curso.percentual_desconto) : null;
    
    return `
        <div class="card">
            <div class="card-header">
                <h3>${escapeHtml(curso.nome_curso)}</h3>
                <span class="tipo-badge">${tipoLabel}</span>
                ${curso.duracao ? `<span class="tipo-badge" style="margin-left: 5px">${escapeHtml(curso.duracao)}</span>` : ''}
            </div>
            <div class="card-body">
                <div class="price-row">
                    <span class="price-label">Valor Integral</span>
                    <span class="price-value price-integral">${valorIntegral}</span>
                </div>
                ${valorDesconto ? `
                    <div class="price-row">
                        <span class="price-label">Valor com Desconto</span>
                        <span class="price-value price-desconto">${valorDesconto}</span>
                    </div>
                ` : ''}
                ${curso.desconto_aplicado ? `
                    <div class="discount-badge">
                        ${percentual ? percentual + '% de desconto - ' : ''}${escapeHtml(curso.desconto_aplicado)}
                    </div>
                ` : ''}
            </div>
            ${curso.observacoes ? `
                <div class="card-footer">
                    📝 ${escapeHtml(curso.observacoes)}
                </div>
            ` : ''}
        </div>
    `;
}

// Atualiza estatísticas
function atualizarEstatisticas() {
    const total = todosCursos.length;
    const grad = todosCursos.filter(c => c.tipo === 'graduacao').length;
    const pos = todosCursos.filter(c => c.tipo === 'pos_graduacao').length;
    
    animarNumero(totalCursos, total);
    animarNumero(totalGrad, grad);
    animarNumero(totalPos, pos);
}

// Anima números
function animarNumero(elemento, alvo) {
    let atual = 0;
    const passo = Math.ceil(alvo / 30);
    const intervalo = setInterval(() => {
        atual += passo;
        if (atual >= alvo) {
            atual = alvo;
            clearInterval(intervalo);
        }
        elemento.textContent = atual;
    }, 30);
}

// Mostra loading
function mostrarLoading() {
    cursosContainer.innerHTML = `
        <div class="loading">
            <div class="loading-spinner"></div>
            <p>Carregando cursos...</p>
        </div>
    `;
}

// Mostra erro
function mostrarErro(mensagem) {
    cursosContainer.innerHTML = `
        <div class="error">
            <p>${escapeHtml(mensagem)}</p>
        </div>
    `;
}

// Mostra estado vazio
function mostrarVazio() {
    cursosContainer.innerHTML = `
        <div class="empty-state">
            <h3>Nenhum curso encontrado</h3>
            <p>Tente ajustar sua busca ou selecione outro filtro.</p>
        </div>
    `;
}

// Formata valor
function formatarValor(valor) {
    if (!valor || isNaN(valor)) return 'R$ 0,00';
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL'
    }).format(valor);
}

// Escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Debounce
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}
