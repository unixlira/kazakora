// Parcela real do cartão (Botrel, 2026-11-02), com a taxa do Mercado Pago que vem
// do servidor (props.parcelamento, ParcelasCartao). Mesma conta do Mercado Pago,
// conferida na API: total arredondado no centavo e parcela = total ÷ parcelas.
// Sem tabela devolve null e a tela não mostra valor de parcela.
export function parcelaMaxima(preco, tabela) {
    const valor = Number(preco);
    const opcao = (tabela ?? []).filter((item) => valor >= item.minimo).at(-1);
    if (!opcao || opcao.parcelas < 2) return null;
    const total = Math.round(valor * (100 + opcao.taxa)) / 100;
    return { parcelas: opcao.parcelas, valor: Math.round((total * 100) / opcao.parcelas + 1e-7) / 100, total };
}
