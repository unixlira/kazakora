/**
 * Mapa de cliques em TODA página da loja (pedido do Lira em 2026-11-19; todas
 * as páginas desde 2026-11-26). O script /js/kzm.js (o mesmo dos infoprodutos,
 * ~3 KB, sem cookie, sem dado pessoal, envio por sendBeacon) já vem no HTML
 * com defer (resources/views/app.blade.php) e mede a primeira página sozinho.
 * Aqui só a troca de página do Inertia: fecha a anterior (saída) e abre a nova
 * com a chave que o servidor manda em props.medicao (MedicaoDaLoja: a mesma
 * das visitas). medicao = null (checkout) para de medir; sem a prop (recarga
 * parcial) não mexe. Leitura: admin › Publicidade › Comportamento.
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

let atual = null;

/** Página aberta no Inertia (evento navigate, inclusive a primeira). */
export function medirPagina(page) {
    try {
        const m = page?.props?.medicao;
        if (m === undefined) return;
        const chave = m?.p ? `${m.p}|${m.pg}` : null;
        if (chave === atual) return;
        atual = chave;
        if (!chave) {
            window.kzm?.page(null);
            return;
        }
        const inicio = performance.now();
        if (window.kzm) {
            window.kzm.page(m.p, m.pg, 'pt');
            return;
        }
        carregar().then((kzm) => {
            // O kzm.js do HTML já abre a primeira página sozinho (e ignora a mesma chave).
            if (atual === chave) kzm?.page(m.p, m.pg, 'pt', inicio);
        });
    } catch { /* medição nunca quebra a página */ }
}
