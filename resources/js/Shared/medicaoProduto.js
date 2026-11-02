// Até onde a pessoa rolou a página do produto (Botrel, 2026-11-02), em 25, 50, 75
// e 100%. Mesmo caminho da medição do checkout (POST /finalizacao/eventos): sem
// terceiros e sem nada digitado. Leitura: php artisan produto:funil.
const URL_EVENTOS = '/finalizacao/eventos';
const MARCOS = [25, 50, 75, 100];

const codigoDaPagina = () => {
    const bytes = new Uint8Array(8);
    window.crypto?.getRandomValues?.(bytes);
    return Array.from(bytes, (b) => (b % 36).toString(36)).join('').padEnd(12, '0').slice(0, 12);
};

export function medirRolagem(produtoId) {
    const pagina = codigoDaPagina();
    const feitos = new Set();
    let espera = null;

    const conferir = () => {
        const altura = document.documentElement.scrollHeight;
        if (altura <= 0 || window.scrollY <= 0) return;
        const percentual = ((window.scrollY + window.innerHeight) / altura) * 100;
        const eventos = MARCOS.filter((marco) => percentual >= marco && !feitos.has(marco)).map((marco) => {
            feitos.add(marco);
            return { evento: 'produto_rolagem', campo: String(produtoId), valor: marco };
        });
        if (!eventos.length) return;
        try {
            const corpo = JSON.stringify({ pagina, eventos });
            fetch(URL_EVENTOS, { method: 'POST', body: corpo, headers: { 'Content-Type': 'text/plain;charset=UTF-8' }, credentials: 'same-origin', keepalive: true }).catch(() => {});
        } catch {
            // medição nunca atrapalha a compra
        }
    };
    const aoRolar = () => {
        clearTimeout(espera);
        espera = setTimeout(conferir, 300);
    };

    window.addEventListener('scroll', aoRolar, { passive: true });
    return () => {
        clearTimeout(espera);
        window.removeEventListener('scroll', aoRolar);
    };
}
