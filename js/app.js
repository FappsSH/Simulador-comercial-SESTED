let todosCursos = [];
let cursosFiltrados = [];
let tipoAtual = 'todos';

const searchInput = document.getElementById('searchInput');
const cursosContainer = document.getElementById('cursosContainer');
const tabs = document.querySelectorAll('.tab');
const totalCursos = document.getElementById('totalCursos');
const totalGrad = document.getElementById('totalGrad');
const totalPos = document.getElementById('totalPos');

document.addEventListener('DOMContentLoaded', () => {
    carregarCursos();
    configurarEventos();
});

async function carregarCursos() {
    mostrarLoading();
    try {
        const response = await fetch('/api/cursos.php');
        if (!response.ok) throw new Error('Erro ao carregar');
        todosCursos = await response.json();
        cursosFiltrados = [...todosCursos];
        atualizarEstatisticas();
        renderizarCursos();
    } catch (error) {
        mostrarErro('Não foi possível carregar os cursos.');
    }
}

function configurarEventos() {
    searchInput.addEventListener('input', debounce((e) => {
        filtrarCursos(e.target.value);
    }, 300));

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            tabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            tipoAtual = tab.dataset.tipo;
            aplicarFiltros();
        });
    });
}

function filtrarCursos(termo) {
    termo = termo.toLowerCase().trim();
    if (!termo) {
        cursosFiltrados = [...todosCursos];
    } else {
        cursosFiltrados = todosCursos.filter(curso =>
            curso.nome_curso.toLowerCase().includes(termo) ||
            (curso.modalidade && curso.modalidade.toLowerCase().includes(termo)) ||
            (curso.grau && curso.grau.toLowerCase().includes(termo))
        );
    }
    aplicarFiltros();
}

function aplicarFiltros() {
    let filtrados = [...cursosFiltrados];
    if (tipoAtual !== 'todos') {
        filtrados = filtrados.filter(c => c.tipo === tipoAtual);
    }
    renderizarCursos(filtrados);
}

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

    // Adiciona eventos de expandir
    document.querySelectorAll('.card-expand-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const card = btn.closest('.card');
            const canais = card.querySelector('.card-canais');
            const isOpen = canais.classList.contains('open');

            // Fecha todos os outros
            document.querySelectorAll('.card-canais.open').forEach(c => {
                c.classList.remove('open');
                c.closest('.card').querySelector('.card-expand-btn').textContent = 'Ver canais de desconto';
            });

            if (!isOpen) {
                canais.classList.add('open');
                btn.textContent = 'Fechar canais';
            }
        });
    });
}

function criarCard(curso) {
    const tipoLabel = curso.tipo === 'graduacao' ? 'Graduação' : 'Pós-Graduação';
    const modalidade = curso.modalidade || '';
    const grau = curso.grau || '';
    const duracao = curso.duracao || '';
    const valorIntegral = formatarValor(curso.valor_integral);

    const canais = (curso.canais || [])
        .sort((a, b) => (b.percentual_desconto || 0) - (a.percentual_desconto || 0));

    const melhorDesconto = canais.length > 0 ? canais[0] : null;
    const percentualMax = melhorDesconto ? Math.round((melhorDesconto.percentual_desconto || 0) * 100) : 0;

    return `
        <div class="card">
            <div class="card-header">
                <div class="card-header-top">
                    <span class="tipo-badge">${tipoLabel}</span>
                    ${modalidade ? `<span class="modalidade-badge">${modalidade}</span>` : ''}
                </div>
                <h3>${escapeHtml(curso.nome_curso)}</h3>
                <div class="card-subinfo">
                    ${grau ? `<span>${grau}</span>` : ''}
                    ${duracao ? `<span>${duracao} meses</span>` : ''}
                </div>
            </div>
            <div class="card-body">
                <div class="price-main">
                    <span class="price-label">Valor Mensal Integral</span>
                    <span class="price-value price-integral">${valorIntegral}</span>
                </div>
                ${melhorDesconto ? `
                    <div class="price-main best-discount">
                        <span class="price-label">Melhor desconto: ${percentualMax}%</span>
                        <span class="price-value price-desconto">${formatarValor(melhorDesconto.valor_com_desconto)}</span>
                    </div>
                ` : ''}
                ${canais.length > 0 ? `
                    <button class="card-expand-btn">Ver canais de desconto</button>
                    <div class="card-canais">
                        <table class="canais-table">
                            <thead>
                                <tr>
                                    <th>Canal</th>
                                    <th>Desconto</th>
                                    <th>Valor Mensal</th>
                                    <th>2º Semestre</th>
                                    <th>Demais</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${canais.map(canal => `
                                    <tr>
                                        <td class="canal-nome">${escapeHtml(canal.canal)}</td>
                                        <td>${canal.percentual_desconto ? Math.round(canal.percentual_desconto * 100) + '%' : '-'}</td>
                                        <td class="canal-valor">${canal.valor_com_desconto ? formatarValor(canal.valor_com_desconto) : '-'}</td>
                                        <td>${canal.regressao_2sem ? Math.round(canal.regressao_2sem * 100) + '%' : '-'}</td>
                                        <td>${canal.regressao_demais ? Math.round(canal.regressao_demais * 100) + '%' : '-'}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                ` : ''}
            </div>
        </div>
    `;
}

function atualizarEstatisticas() {
    animarNumero(totalCursos, todosCursos.length);
    animarNumero(totalGrad, todosCursos.filter(c => c.tipo === 'graduacao').length);
    animarNumero(totalPos, todosCursos.filter(c => c.tipo === 'pos_graduacao').length);
}

function animarNumero(elemento, alvo) {
    let atual = 0;
    const passo = Math.max(1, Math.ceil(alvo / 30));
    const intervalo = setInterval(() => {
        atual += passo;
        if (atual >= alvo) { atual = alvo; clearInterval(intervalo); }
        elemento.textContent = atual;
    }, 30);
}

function mostrarLoading() {
    cursosContainer.innerHTML = `<div class="loading"><div class="loading-spinner"></div><p>Carregando cursos...</p></div>`;
}

function mostrarErro(mensagem) {
    cursosContainer.innerHTML = `<div class="error"><p>${escapeHtml(mensagem)}</p></div>`;
}

function mostrarVazio() {
    cursosContainer.innerHTML = `<div class="empty-state"><h3>Nenhum curso encontrado</h3><p>Tente ajustar sua busca.</p></div>`;
}

function formatarValor(valor) {
    if (!valor || isNaN(valor)) return 'R$ 0,00';
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(valor);
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function debounce(func, wait) {
    let timeout;
    return function(...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func(...args), wait);
    };
}
