import { computed, ref, watch } from 'vue';

// Busca e paginação na tela das tabelas do Radar (pedido do Lira
// 2026-10-12): cada fonte manda a lista inteira de uma vez, então filtrar e
// paginar aqui não gasta consulta nem crédito. A posição mostrada na tabela
// continua a do ranking original, mesmo com a busca ativa.
// `textoDe(item)` devolve o texto em que a busca procura.
export const normalizar = (texto) => (texto ?? '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, ' ').trim();

export function useBuscaPaginada(itens, textoDe, porPagina = 20) {
    const busca = ref('');
    const pagina = ref(1);

    const filtrados = computed(() => {
        const palavras = normalizar(busca.value).split(' ').filter(Boolean);
        const todos = itens.value ?? [];
        if (palavras.length === 0) return todos;
        return todos.filter((item) => {
            const texto = normalizar(textoDe(item));
            return palavras.every((palavra) => texto.includes(palavra));
        });
    });
    const totalPaginas = computed(() => Math.max(1, Math.ceil(filtrados.value.length / porPagina)));
    const daPagina = computed(() => filtrados.value.slice((pagina.value - 1) * porPagina, pagina.value * porPagina));
    const faixa = computed(() => {
        const total = filtrados.value.length;
        if (total === 0) return '0';
        const inicio = (pagina.value - 1) * porPagina + 1;
        return `${inicio} a ${Math.min(pagina.value * porPagina, total)} de ${total.toLocaleString('pt-BR')}`;
    });
    const irParaPagina = (n) => { pagina.value = Math.min(Math.max(1, n), totalPaginas.value); };

    // Busca nova volta à primeira página; lista que encolheu (consultar de
    // novo) não deixa a página atual vazia.
    watch(busca, () => { pagina.value = 1; });
    watch(totalPaginas, (total) => { if (pagina.value > total) pagina.value = total; });

    return { busca, pagina, filtrados, totalPaginas, daPagina, faixa, irParaPagina };
}
