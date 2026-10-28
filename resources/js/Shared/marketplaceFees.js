/**
 * Regra de preço do Lira (28/10/2026, método do divisor) e taxas reais de
 * venda por canal, usadas pela calculadora de precificação
 * (`Admin/PricingCalculator/Index.vue`). É a mesma conta da ferramenta do
 * Homer (`~/projects/homer/ferramentas/precificacao/preco.py`):
 *
 *   custos_fixos = custo do produto + frete pago pela loja + insumos + tarifa fixa
 *   divisor      = 1 − (imposto + comissão + ADS + lucro)
 *   preço        = custos_fixos ÷ divisor   (preço de cadastro: próximo X,90 para cima)
 *
 * Lucro (margem de contribuição) é % do PREÇO DE VENDA: 35% nos
 * marketplaces e 45% nas lojas próprias (KazaKora, FerraKora, BeautyKora).
 *
 * Taxas conferidas em 28/10/2026:
 * - Shopee: tabela CNPJ de 01/10/2026 (faixas abaixo), confirmada no escrow
 *   dos pedidos da loja e na Central do Vendedor; já inclui o frete grátis.
 * - Mercado Livre: comissão da categoria (anúncios da loja: Clássico 11,5% a
 *   13%, Premium 16,5% a 18%, API listing_prices em 16/10/2026), sem tarifa
 *   fixa desde mar/2026; o frete que o ML cobra da loja muda com o peso e
 *   entra à parte.
 * - Amazon: 16,5%, média real da conta (SP-API Finances, 23/10/2026); a loja
 *   envia pelos Correios.
 * - TikTok Shop: calibrado no extrato real (`TikTokFeeEstimator`): 6% + R$ 4
 *   por item (R$ 6 de R$ 50 para cima) + 0,15% de comissão + 4,45% de
 *   afiliado esperado + R$ 7,17 de frete médio por pedido.
 */

export const REGRA = {
    impostoPct: 4.5, // Simples, sobre o preço de venda
    adsPct: 2, // "até eu aprender" (Lira, 28/10/2026)
    insumos: 2, // R$ por pedido: embalagem, etiqueta, fita
    lucroMarketplacePct: 35,
    lucroLojaPropriaPct: 45,
};

// Shopee CNPJ desde 01/10/2026: a faixa vale pelo preço do item.
const SHOPEE_FAIXAS = [
    { ate: 80, pct: 20, fixa: 4.5 },
    { ate: 100, pct: 14, fixa: 16 },
    { ate: 200, pct: 14, fixa: 20 },
    { ate: Infinity, pct: 14, fixa: 26 },
];

const TIKTOK_PCT = 6 + 0.15 + 4.45;
const TIKTOK_FRETE = 7.17;

export const MARKETPLACES = [
    {
        key: 'shopee',
        label: 'Shopee',
        sublabel: 'Vendedor CNPJ · tabela de 01/10/2026',
        logoSvg: {
            viewBox: '0 0 24 24',
            path: 'M15.9414 17.9633c.229-1.879-.981-3.077-4.1758-4.0969-1.548-.528-2.277-1.22-2.26-2.1719.065-1.056 1.048-1.825 2.352-1.85a5.2898 5.2898 0 0 1 2.8838.89c.116.072.197.06.263-.039.09-.145.315-.494.39-.62.051-.081.061-.187-.068-.281-.185-.1369-.704-.4149-.983-.5319a6.4697 6.4697 0 0 0-2.5118-.514c-1.909.008-3.4129 1.215-3.5389 2.826-.082 1.1629.494 2.1078 1.73 2.8278.262.152 1.6799.716 2.2438.892 1.774.552 2.695 1.5419 2.478 2.6969-.197 1.047-1.299 1.7239-2.818 1.7439-1.2039-.046-2.2878-.537-3.1278-1.19l-.141-.11c-.104-.08-.218-.075-.287.03-.05.077-.376.547-.458.67-.077.108-.035.168.045.234.35.293.817.613 1.134.775a6.7097 6.7097 0 0 0 2.8289.727 4.9048 4.9048 0 0 0 2.0759-.354c1.095-.465 1.8029-1.394 1.9449-2.554zM11.9986 1.4009c-2.068 0-3.7539 1.95-3.8329 4.3899h7.6657c-.08-2.44-1.765-4.3899-3.8328-4.3899zm7.8516 22.5981-.08.001-15.7843-.002c-1.074-.04-1.863-.91-1.971-1.991l-.01-.195L1.298 6.2858a.459.459 0 0 1 .45-.494h4.9748C6.8448 2.568 9.1607 0 11.9996 0c2.8388 0 5.1537 2.5689 5.2757 5.7898h4.9678a.459.459 0 0 1 .458.483l-.773 15.5883-.007.131c-.094 1.094-.979 1.9769-2.0709 2.0059z',
        },
        theme: {
            cardBg: 'linear-gradient(135deg, #EE4D2D 0%, #d63c1f 100%)',
            fg: '#ffffff',
            accent: '#FFD400',
            chipBg: 'rgba(255,255,255,0.14)',
        },
        frete: 'incluido',
        lucroPct: REGRA.lucroMarketplacePct,
        defaultCommissionPct: null,
        faixas: () => SHOPEE_FAIXAS,
        note: 'Comissão por faixa de preço do item, já com a taxa de transação e o frete grátis: 20% + R$ 4,50 até R$ 79,99, 14% + R$ 16 de R$ 80 a R$ 99,99, 14% + R$ 20 de R$ 100 a R$ 199,99 e 14% + R$ 26 a partir de R$ 200.',
        source: 'Escrow dos pedidos da loja e Central do Vendedor, conferido 28/10/2026',
    },
    {
        key: 'mercado_livre',
        label: 'Mercado Livre Clássico',
        sublabel: 'Sem parcelamento sem juros',
        icon: 'fas fa-handshake',
        theme: {
            cardBg: 'linear-gradient(135deg, #FFE600 0%, #FFC400 100%)',
            fg: '#2d2600',
            accent: '#2968C8',
            chipBg: 'rgba(0,0,0,0.08)',
        },
        frete: 'mercado_livre',
        lucroPct: REGRA.lucroMarketplacePct,
        defaultCommissionPct: 12,
        faixas: (pct) => [{ ate: Infinity, pct, fixa: 0 }],
        note: 'Comissão do Clássico de 10% a 14% conforme a categoria (anúncios da loja: 11,5% a 13%). Sem tarifa fixa desde mar/2026: o ML cobra da loja um frete que muda com o peso (abaixo de R$ 79, R$ 7 a R$ 14 nas vendas da loja em out/2026). Informe esse frete no campo do Mercado Livre.',
        source: 'API oficial do ML (listing_prices) e vendas reais da loja, conferido 28/10/2026',
    },
    {
        key: 'mercado_livre_premium',
        label: 'Mercado Livre Premium',
        sublabel: 'Parcelamento sem juros · maioria dos anúncios da loja',
        icon: 'fas fa-handshake',
        theme: {
            cardBg: 'linear-gradient(135deg, #FFE600 0%, #FFC400 100%)',
            fg: '#2d2600',
            accent: '#2968C8',
            chipBg: 'rgba(0,0,0,0.08)',
        },
        frete: 'mercado_livre',
        lucroPct: REGRA.lucroMarketplacePct,
        defaultCommissionPct: 17,
        faixas: (pct) => [{ ate: Infinity, pct, fixa: 0 }],
        note: 'Comissão do Premium de 15% a 19% conforme a categoria (anúncios da loja: 16,5% a 18%). Sem tarifa fixa desde mar/2026: o ML cobra da loja um frete que muda com o peso. Informe esse frete no campo do Mercado Livre.',
        source: 'API oficial do ML (listing_prices) e vendas reais da loja, conferido 28/10/2026',
    },
    {
        key: 'amazon',
        label: 'Amazon',
        sublabel: 'Envio pela loja (Correios)',
        logoSvg: {
            viewBox: '0 0 24 24',
            path: 'M.045 18.02c.072-.116.187-.124.348-.022 3.636 2.11 7.594 3.166 11.87 3.166 2.852 0 5.668-.533 8.447-1.595l.315-.14c.138-.06.234-.1.293-.13.226-.088.39-.046.525.13.12.174.09.336-.12.48-.256.19-.6.41-1.006.654-1.244.743-2.64 1.316-4.185 1.726a17.617 17.617 0 01-10.951-.577 17.88 17.88 0 01-5.43-3.35c-.1-.074-.151-.15-.151-.22 0-.047.021-.09.051-.13zm6.565-6.218c0-1.005.247-1.863.743-2.577.495-.71 1.17-1.25 2.04-1.615.796-.335 1.756-.575 2.912-.72.39-.046 1.033-.103 1.92-.174v-.37c0-.93-.105-1.558-.3-1.875-.302-.43-.78-.65-1.44-.65h-.182c-.48.046-.896.196-1.246.46-.35.27-.575.63-.675 1.096-.06.3-.206.465-.435.51l-2.52-.315c-.248-.06-.372-.18-.372-.39 0-.046.007-.09.022-.15.247-1.29.855-2.25 1.82-2.88.976-.616 2.1-.975 3.39-1.05h.54c1.65 0 2.957.434 3.888 1.29.135.15.27.3.405.48.12.165.224.314.283.45.075.134.15.33.195.57.06.254.105.42.135.51.03.104.062.3.076.615.01.313.02.493.02.553v5.28c0 .376.06.72.165 1.036.105.313.21.54.315.674l.51.674c.09.136.136.256.136.36 0 .12-.06.226-.18.314-1.2 1.05-1.86 1.62-1.963 1.71-.165.135-.375.15-.63.045a6.062 6.062 0 01-.526-.496l-.31-.347a9.391 9.391 0 01-.317-.42l-.3-.435c-.81.886-1.603 1.44-2.4 1.665-.494.15-1.093.227-1.83.227-1.11 0-2.04-.343-2.76-1.034-.72-.69-1.08-1.665-1.08-2.94l-.05-.076zm3.753-.438c0 .566.14 1.02.425 1.364.285.34.675.512 1.155.512.045 0 .106-.007.195-.02.09-.016.134-.023.166-.023.614-.16 1.08-.553 1.424-1.178.165-.28.285-.58.36-.91.09-.32.12-.59.135-.8.015-.195.015-.54.015-1.005v-.54c-.84 0-1.484.06-1.92.18-1.275.36-1.92 1.17-1.92 2.43l-.035-.02zm9.162 7.027c.03-.06.075-.11.132-.17.362-.243.714-.41 1.05-.5a8.094 8.094 0 011.612-.24c.14-.012.28 0 .41.03.65.06 1.05.168 1.172.33.063.09.099.228.099.39v.15c0 .51-.149 1.11-.424 1.8-.278.69-.664 1.248-1.156 1.68-.073.06-.14.09-.197.09-.03 0-.06 0-.09-.012-.09-.044-.107-.12-.064-.24.54-1.26.806-2.143.806-2.64 0-.15-.03-.27-.087-.344-.145-.166-.55-.257-1.224-.257-.243 0-.533.016-.87.046-.363.045-.7.09-1 .135-.09 0-.148-.014-.18-.044-.03-.03-.036-.047-.02-.077 0-.017.006-.03.02-.063v-.06z',
        },
        theme: {
            cardBg: 'linear-gradient(135deg, #232F3E 0%, #131A22 100%)',
            fg: '#ffffff',
            accent: '#FF9900',
            chipBg: 'rgba(255,255,255,0.12)',
        },
        frete: 'correios',
        lucroPct: REGRA.lucroMarketplacePct,
        defaultCommissionPct: 16.5,
        faixas: (pct) => [{ ate: Infinity, pct, fixa: 0 }],
        note: 'Tarifa média real da conta: 16,5% (SP-API Finances, 23/10/2026). A loja envia pelos Correios, então entra o frete Correios do tamanho escolhido.',
        source: 'SP-API Finances da conta, conferido 23/10/2026',
    },
    {
        key: 'tiktok_shop',
        label: 'TikTok Shop',
        sublabel: 'Calibrado no extrato real',
        logoSvg: {
            viewBox: '0 0 24 24',
            path: 'M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z',
        },
        theme: {
            cardBg: 'linear-gradient(135deg, #000000 0%, #1a1a1a 100%)',
            fg: '#ffffff',
            accent: '#25F4EE',
            accent2: '#FE2C55',
            chipBg: 'rgba(255,255,255,0.12)',
        },
        frete: 'incluido',
        lucroPct: REGRA.lucroMarketplacePct,
        defaultCommissionPct: null,
        faixas: () => [
            { ate: 50, pct: TIKTOK_PCT, fixa: 4 + TIKTOK_FRETE },
            { ate: Infinity, pct: TIKTOK_PCT, fixa: 6 + TIKTOK_FRETE },
        ],
        note: 'Como o TikTok cobrou nos extratos da loja: 6% + R$ 4 por item (R$ 6 a partir de R$ 50) + 0,15% de comissão + 4,45% de afiliado (média esperada) + R$ 7,17 de frete médio por pedido.',
        source: 'Extrato real do TikTok (TikTokFeeEstimator), conferido 28/10/2026',
    },
    {
        key: 'loja_propria',
        label: 'Lojas próprias',
        sublabel: 'KazaKora · FerraKora · BeautyKora',
        icon: 'fas fa-store',
        theme: {
            cardBg: 'linear-gradient(135deg, #1B3A5C 0%, #14293f 100%)',
            fg: '#ffffff',
            accent: '#F27A2A',
            chipBg: 'rgba(255,255,255,0.12)',
        },
        frete: 'correios',
        lucroPct: REGRA.lucroLojaPropriaPct,
        defaultCommissionPct: null,
        faixas: () => [{ ate: Infinity, pct: 0, fixa: 0 }],
        note: 'Sem comissão de plataforma; entra o frete Correios do tamanho escolhido. A taxa do Mercado Pago/cartão não está na regra e sai do lucro.',
        source: 'Regra do Lira, 28/10/2026',
    },
];

/** Próximo preço terminado em ,90 igual ou acima de `p`. */
const noventa = (p) => Math.ceil(p - 0.9 - 1e-9) + 0.9;

/**
 * Preço de cadastro de um canal pela regra do divisor. A comissão e a tarifa
 * fixa dependem da faixa do preço final; para cada faixa o menor preço que
 * fecha é max(custos_fixos ÷ divisor, início da faixa), e vale o menor entre
 * as faixas. O preço de cadastro é o primeiro X,90 a partir dele que também
 * fecha (pular de faixa pode exigir subir mais).
 */
export function computePricing(marketplace, { cost, frete, commissionPctOverride = null }) {
    const pctPadrao = commissionPctOverride ?? marketplace.defaultCommissionPct;
    const faixas = marketplace.faixas(pctPadrao);
    const percentuais = (f) => REGRA.impostoPct + f.pct + REGRA.adsPct + marketplace.lucroPct;
    const faixaDe = (p) => faixas.find((f) => p < f.ate) ?? faixas[faixas.length - 1];
    const fixosDe = (f) => cost + frete + REGRA.insumos + f.fixa;
    const fecha = (p) => {
        const f = faixaDe(p);
        const divisor = 1 - percentuais(f) / 100;
        return divisor > 0 && p * divisor + 1e-9 >= fixosDe(f);
    };

    let minimo = null;
    let inicio = 0;
    for (const f of faixas) {
        const divisor = 1 - percentuais(f) / 100;
        if (divisor > 0.05) {
            const p = Math.max(fixosDe(f) / divisor, inicio);
            if (p < f.ate && (minimo === null || p < minimo)) minimo = p;
        }
        inicio = f.ate;
    }
    if (minimo === null) return { invalid: true };

    let price = noventa(minimo);
    for (let i = 0; i < 500 && !fecha(price); i++) price += 1;

    const f = faixaDe(price);
    const commissionValue = price * (f.pct / 100);
    const taxValue = price * (REGRA.impostoPct / 100);
    const adsValue = price * (REGRA.adsPct / 100);
    const profit = price - commissionValue - f.fixa - taxValue - adsValue - frete - REGRA.insumos - cost;

    return {
        invalid: false,
        price,
        minimo,
        pct: f.pct,
        fixed: f.fixa,
        frete,
        insumos: REGRA.insumos,
        custosFixos: fixosDe(f),
        divisor: 1 - percentuais(f) / 100,
        lucroPct: marketplace.lucroPct,
        commissionValue,
        taxValue,
        adsValue,
        profit,
        realMarginPct: (profit / price) * 100,
    };
}
