/**
 * Mapa de cliques das páginas de produto (pedido do Lira em 2026-11-19).
 * Carrega /js/kzm.js (o mesmo script dos infoprodutos, < 3 KB, sem cookie,
 * sem dado pessoal, envio em lote por sendBeacon) só depois que a página fica
 * ociosa, para não pesar no LCP, e avisa qual página está aberta.
 * Leitura: admin › Publicidade › Comportamento.
 */
let carregando = null;

const carregar = () => {
    if (window.kzm) return Promise.resolve(window.kzm);
    carregando ??= new Promise((resolve) => {
        const s = document.createElement('script');
        s.src = '/js/kzm.js';
        s.defer = true;
        s.onload = () => resolve(window.kzm ?? null);
        s.onerror = () => resolve(null);
        document.head.appendChild(s);
    });
    return carregando;
};

const quandoOcioso = (fn) => (window.requestIdleCallback ? window.requestIdleCallback(fn, { timeout: 4000 }) : setTimeout(fn, 2500));

/** Começa a medir a página (p = "kz/<slug>" ou "fk/<slug>"); devolve a função que para. */
export function carregarMedicao(p, pg = 'produto', l = 'pt') {
    let ativo = true;
    // Hora da abertura: o tempo na página conta desde aqui, não desde que o script desceu.
    const inicio = performance.now();
    quandoOcioso(() => carregar().then((kzm) => {
        try { if (ativo) kzm?.page(p, pg, l, inicio); } catch { /* medição nunca quebra a página */ }
    }));
    return () => {
        ativo = false;
        try { window.kzm?.page(null); } catch { /* idem */ }
    };
}
