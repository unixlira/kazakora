// Preços e selos dos cartões de opção (OpcoesEmCartoes.vue, pedido do Lira 26/11/2026).
// Nada inventado: cada número sai da própria opção, com a mesma conta do bloco de preço da página.

/** "Por" do cartão = o preço grande da página para esta opção (com o cupom automático, se houver). */
export const porDaOpcao = (opcao) => Number(opcao?.preco_cupom ? opcao.preco_cupom.por : opcao?.final_price ?? 0);

/**
 * "De" (riscado) do cartão: o preço cheio cadastrado (o "de" do card da loja); sem ele, o preço sem o
 * cupom. Só aparece quando é maior que o "por".
 */
export const deDaOpcao = (opcao) => {
    const por = porDaOpcao(opcao);
    let de = null;
    if ((opcao?.oferta_do_dia || opcao?.oferta_relampago) && opcao?.preco_sem_oferta) de = Number(opcao.preco_sem_oferta);
    else if (opcao?.has_discount && opcao?.preco_de) de = Number(opcao.preco_de);
    else if (opcao?.preco_cupom) de = Number(opcao.preco_cupom.de);
    return de && de > por ? de : null;
};

export const economiaDaOpcao = (opcao) => {
    const de = deDaOpcao(opcao);
    return de ? Math.round((de - porDaOpcao(opcao)) * 100) / 100 : 0;
};

/** Do mais barato ao mais caro, como os kits do livro. */
export const ordenarOpcoes = (opcoes) => [...opcoes].sort((a, b) => porDaOpcao(a) - porDaOpcao(b));

/**
 * Selos: o texto do cadastro quando houver; senão o destaque é "Recomendado" (com estrela), o mais
 * barato "Para começar" e, com 3 ou mais, o mais caro "Mais completo" (os mesmos do livro).
 */
export const selosDasOpcoes = (lista, textos) => {
    const selos = {};
    if (lista.length < 2) return selos;
    lista.forEach((opcao, i) => {
        const texto = textos.get(opcao.id) ?? {};
        if (texto.destaque) selos[opcao.id] = { texto: texto.selo || 'Recomendado', tom: 'amarelo', estrela: true };
        else if (texto.selo) selos[opcao.id] = { texto: texto.selo, tom: 'escuro' };
        else if (i === 0) selos[opcao.id] = { texto: 'Para começar', tom: 'escuro' };
        else if (lista.length >= 3 && i === lista.length - 1) selos[opcao.id] = { texto: 'Mais completo', tom: 'verde' };
    });
    return selos;
};
