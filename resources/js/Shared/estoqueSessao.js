// Estado efêmero por produto canônico e por aba. Sem dados pessoais.
const memoriaEstoque = new Map();
const chaveEstoque = (produto) => `pdp:estoque:v1:${String(produto)}`;
const estoqueValido = (s) => s && Number.isInteger(s.numero) && s.numero >= 6 && s.numero <= 36
    && Number.isInteger(s.barra) && s.barra >= s.numero && s.barra <= 50
    && typeof s.vermelho === 'boolean';
export function salvarEstoqueSessao(produto, estado) {
    const chave = chaveEstoque(produto);
    const anterior = memoriaEstoque.get(chave);
    if (!estoqueValido(estado)) return anterior;
    // Uma escrita atrasada não pode elevar o valor já observado.
    const salvo = anterior ? {
        numero: Math.min(anterior.numero, estado.numero),
        barra: Math.min(anterior.barra, estado.barra),
        vermelho: anterior.vermelho || estado.vermelho,
    } : { ...estado };
    memoriaEstoque.set(chave, salvo);
    try { window.sessionStorage.setItem(chave, JSON.stringify(salvo)); } catch { /* memória mantém remounts */ }
    return salvo;
}
export function abrirEstoqueSessao(produto, sortear) {
    const chave = chaveEstoque(produto);
    let salvo = memoriaEstoque.get(chave);
    try {
        const persistido = JSON.parse(window.sessionStorage.getItem(chave));
        if (estoqueValido(persistido)) {
            salvo = salvo ? {
                numero: Math.min(salvo.numero, persistido.numero),
                barra: Math.min(salvo.barra, persistido.barra),
                vermelho: salvo.vermelho || persistido.vermelho,
            } : persistido;
        }
    } catch { /* storage bloqueado ou conteúdo inválido */ }
    if (salvo) {
        // Remount/recarga nunca volta ao topo nem agenda uma nova queda.
        salvo = salvarEstoqueSessao(produto, { ...salvo, barra: salvo.numero });
        return { ...salvo, novo: false };
    }
    const numero = sortear();
    return { ...salvarEstoqueSessao(produto, { numero, barra: 50, vermelho: false }), novo: true };
}
