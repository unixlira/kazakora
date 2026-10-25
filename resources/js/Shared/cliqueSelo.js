// Avisa a loja que o visitante clicou num selo de segurança do rodapé
// (pedido 2026-10-25). Não atrapalha o link: sendBeacon sai em segundo plano,
// mesmo com a página indo para outra aba. selo: google | reclame_aqui | norton.
export function cliqueSelo(selo) {
    try {
        const corpo = new Blob([JSON.stringify({ selo })], { type: 'text/plain' });
        if (navigator.sendBeacon && navigator.sendBeacon('/selos/clique', corpo)) return;
        fetch('/selos/clique', { method: 'POST', body: corpo, keepalive: true, credentials: 'same-origin' }).catch(() => {});
    } catch {
        // contagem é secundária: nunca quebra o clique
    }
}
