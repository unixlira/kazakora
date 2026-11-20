// Chamadas da fila de PDFs de brinde (20/11/2026), usadas na aba "Brindes (PDF)"
// do produto e em Entregáveis › Fila de PDFs.
const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');

export async function chamar(metodo, url, corpo) {
    const resposta = await fetch(url, {
        method: metodo,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' },
        body: corpo ? JSON.stringify(corpo) : undefined,
    });
    const dados = await resposta.json().catch(() => ({}));
    if (!resposta.ok) {
        const erro = dados.errors ? Object.values(dados.errors).flat()[0] : dados.message;
        throw new Error(erro || 'Não deu certo. Tente de novo.');
    }
    return dados;
}

export const TONS = { fila: 'gray', produzindo: 'blue', revisao: 'yellow', aprovado: 'green', erro: 'red' };
