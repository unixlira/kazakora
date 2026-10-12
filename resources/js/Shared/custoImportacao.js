/**
 * Conta da Calculadora de importação (mesma de
 * mentoria.destraveescale.com.br/calculadora-importacao):
 *
 * 1. produto + frete (R$) = (produto + frete em USD) × dólar
 * 2. imposto federal       = (1) × 60%
 * 3. base ICMS             = (1) + (2)
 * 4. ICMS "por dentro"     = (3) ÷ (1 − alíquota) − (3)
 * 5. taxa administrativa   = ((3) + (4)) × taxa
 * 6. custo total           = (3) + (4) + (5)
 * 7. custo por peça        = (6) ÷ quantidade
 *
 * Os valores não são arredondados entre as etapas (igual ao original): só
 * a tela formata em reais.
 */
export const IMPOSTO_FEDERAL_PCT = 60;

const numero = (valor) => {
    if (valor === '' || valor === null || valor === undefined) return null;
    const n = Number(String(valor).replace(',', '.'));
    return Number.isFinite(n) ? n : null;
};

/** Mensagem do primeiro campo errado, ou null se dá para calcular. */
export function validarImportacao({ produtosUsd, freteUsd, dolar, icmsPct, taxaAdmPct, quantidade }) {
    const produtos = numero(produtosUsd);
    if (produtos === null || produtos <= 0) return 'Informe o valor dos produtos em dólar.';

    const frete = numero(freteUsd);
    if (frete !== null && frete < 0) return 'O frete + seguro não pode ser negativo.';

    const cotacao = numero(dolar);
    if (cotacao === null || cotacao <= 0) return 'Informe a cotação do dólar.';

    const icms = numero(icmsPct);
    if (icms === null || icms < 0 || icms >= 100) return 'Informe um ICMS entre 0 e 99%.';

    const taxa = numero(taxaAdmPct);
    if (taxa === null || taxa < 0) return 'Informe a taxa administrativa (pode ser 0).';

    const qtd = numero(quantidade);
    if (qtd !== null && (qtd < 1 || !Number.isInteger(qtd))) return 'A quantidade de peças precisa ser um número inteiro a partir de 1.';

    return null;
}

export function calcularImportacao({ produtosUsd, freteUsd, dolar, icmsPct, taxaAdmPct, quantidade }) {
    const totalUsd = numero(produtosUsd) + (numero(freteUsd) ?? 0);
    const cotacao = numero(dolar);
    const icms = numero(icmsPct);
    const taxa = numero(taxaAdmPct);
    const qtd = numero(quantidade);

    const produtoFrete = totalUsd * cotacao;
    const impostoFederal = produtoFrete * (IMPOSTO_FEDERAL_PCT / 100);
    const baseIcms = produtoFrete + impostoFederal;
    const divisorIcms = 1 - icms / 100;
    const icmsValor = baseIcms / divisorIcms - baseIcms;
    const baseAdministrativa = baseIcms + icmsValor;
    const taxaAdministrativa = baseAdministrativa * (taxa / 100);
    const custoTotal = baseAdministrativa + taxaAdministrativa;

    return {
        totalUsd,
        cotacao,
        icmsPct: icms,
        taxaAdmPct: taxa,
        quantidade: qtd,
        produtoFrete,
        impostoFederal,
        baseIcms,
        divisorIcms,
        icmsValor,
        // Para mostrar por que não é só multiplicar pela alíquota.
        icmsSimples: baseIcms * (icms / 100),
        baseAdministrativa,
        taxaAdministrativa,
        custoTotal,
        custoPorPeca: qtd ? custoTotal / qtd : null,
    };
}

/** Simulação de lucro por peça; mês = 30 dias. Null se faltar o preço. */
export function simularLucro(custoPorPeca, precoVenda, vendasPorDia) {
    const preco = numero(precoVenda);
    if (custoPorPeca === null || preco === null || preco <= 0) return null;

    const vendas = Math.max(numero(vendasPorDia) ?? 0, 0);
    const lucroPeca = preco - custoPorPeca;

    return {
        lucroPeca,
        margemPct: (lucroPeca / preco) * 100,
        faturamentoDia: preco * vendas,
        lucroDia: lucroPeca * vendas,
        faturamentoMes: preco * vendas * 30,
        lucroMes: lucroPeca * vendas * 30,
        prejuizo: lucroPeca < 0,
    };
}
