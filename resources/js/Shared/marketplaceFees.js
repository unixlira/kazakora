/**
 * Taxas reais de venda por canal, usadas pela calculadora de precificação
 * (`Admin/PricingCalculator/Index.vue`). Cada marketplace tem sua própria
 * estrutura de comissão — algumas são um percentual fixo, outras mudam por
 * faixa de preço do item. Os valores abaixo são os praticados publicamente
 * em 2026. Shopee e Mercado Livre revisados em 16/10/2026 contra o que as
 * plataformas cobraram de verdade nos pedidos da loja (escrow da Shopee,
 * `sale_fee` e custo de envio do ML) e contra a API oficial de tarifas do
 * ML (`sites/MLB/listing_prices`) nas categorias dos anúncios ativos.
 * Em 06/11/2026 (pedido do Lira) conferido contra as três páginas oficiais
 * que ele mandou: comissão da Shopee 2026, custo dos Envios do Mercado Livre
 * (desde 24/08/2026, por peso e preço) e tarifas do TikTok Shop (comissão +
 * Programa de taxa de envio de 6%, até R$ 50 por item).
 * Amazon e Stripe seguem como checados em ago/2026. Comissão por categoria varia MUITO (ex.: Mercado Livre vai de
 * 10% a 19%) — o valor default aqui é uma média realista para o nicho da
 * loja (eletrônicos, casa e cozinha), sempre editável na tela via "Ajustar
 * taxas". Isso não substitui o simulador oficial de cada marketplace na
 * hora de bater o preço final de uma categoria específica.
 */

// Custo dos Envios no Mercado Livre desde 24/08/2026 (reputação verde, sem
// reputação e MercadoLíderes; produto novo, Full, Coleta e Agências).
// Cobrado em TODA venda, mesmo quando o comprador paga o frete.
// Fonte: mercadolivre.com.br/ajuda/custos-envio-reputacao-verde-sem-reputacao_48392
// Colunas = faixas de preço do anúncio; linhas = faixas de peso (kg).
const ML_ENVIO_FAIXAS_PRECO = [19, 49, 79, 100, 120, 150, 200]; // limites inferiores das colunas 2 a 8
const ML_ENVIO_TABELA = [
    // [peso até (kg), R$0-18,99, R$19-48,99, R$49-78,99, R$79-99,99, R$100-119,99, R$120-149,99, R$150-199,99, R$200+]
    [0.3, 5.65, 6.85, 8.15, 12.95, 14.95, 16.95, 19.05, 21.65],
    [0.5, 5.95, 6.95, 8.25, 13.85, 16.15, 18.15, 20.45, 23.25],
    [1, 6.05, 7.15, 8.45, 14.45, 16.85, 19.05, 21.35, 24.45],
    [1.5, 6.15, 7.35, 8.65, 14.75, 17.15, 19.45, 21.75, 25.45],
    [2, 6.25, 7.45, 8.75, 15.05, 17.65, 19.85, 22.25, 25.55],
    [3, 6.35, 8.65, 9.15, 16.45, 19.15, 21.65, 24.35, 27.05],
    [4, 6.45, 8.75, 9.75, 17.85, 20.75, 23.35, 26.35, 29.25],
    [5, 6.55, 8.85, 10.25, 19.75, 22.85, 26.05, 29.25, 32.45],
    [6, 6.65, 8.95, 10.35, 25.95, 29.15, 33.35, 36.45, 40.85],
    [7, 6.75, 9.05, 10.45, 27.55, 31.65, 36.75, 40.85, 45.25],
    [8, 6.85, 9.25, 10.55, 29.45, 34.35, 39.25, 44.15, 49.35],
    [9, 6.95, 9.35, 10.65, 30.25, 35.25, 40.35, 45.35, 50.75],
    [10, 7.05, 9.45, 10.85, 38.25, 45.05, 51.95, 58.75, 65.85],
    [11, 7.05, 9.65, 11.05, 41.65, 48.55, 55.45, 62.35, 69.35],
    [13, 7.15, 10.05, 11.45, 42.55, 49.75, 56.85, 63.85, 70.95],
    [15, 7.25, 10.25, 11.65, 45.55, 52.95, 60.55, 68.15, 75.65],
    [17, 7.35, 10.45, 11.85, 48.95, 56.55, 64.05, 71.35, 79.35],
    [20, 7.45, 10.65, 12.05, 55.15, 64.35, 73.55, 82.75, 91.95],
    [25, 7.65, 11.05, 12.25, 64.55, 75.75, 85.45, 96.25, 106.85],
    [30, 7.75, 11.25, 12.45, 66.45, 76.05, 86.25, 97.15, 107.85],
    [40, 7.85, 11.45, 12.65, 68.35, 79.65, 89.75, 100.05, 107.95],
    [50, 7.95, 11.65, 12.85, 70.95, 81.85, 92.85, 103.45, 111.65],
    [60, 8.05, 11.85, 13.05, 75.55, 87.25, 99.05, 110.25, 119.05],
    [70, 8.15, 12.05, 13.25, 80.95, 93.75, 105.95, 118.05, 127.45],
    [80, 8.25, 12.25, 13.45, 84.65, 97.95, 110.75, 123.35, 133.15],
    [90, 8.35, 12.45, 13.65, 94.05, 108.35, 122.95, 136.95, 147.85],
    [100, 8.45, 12.65, 13.85, 107.45, 124.85, 140.45, 156.45, 168.85],
    [125, 8.55, 12.85, 14.05, 120.15, 138.95, 156.95, 174.85, 188.85],
    [150, 8.65, 12.85, 14.25, 127.45, 147.05, 166.55, 185.55, 200.35],
    [Infinity, 8.75, 12.85, 14.45, 167.05, 193.35, 218.45, 243.45, 262.85],
];

const ML_ENVIO_FAIXAS_PESO = ['até 0,3 kg', '0,3 a 0,5 kg', '0,5 a 1 kg', '1 a 1,5 kg', '1,5 a 2 kg', '2 a 3 kg', '3 a 4 kg', '4 a 5 kg', '5 a 6 kg', '6 a 7 kg', '7 a 8 kg', '8 a 9 kg', '9 a 10 kg', '10 a 11 kg', '11 a 13 kg', '13 a 15 kg', '15 a 17 kg', '17 a 20 kg', '20 a 25 kg', '25 a 30 kg', '30 a 40 kg', '40 a 50 kg', '50 a 60 kg', '60 a 70 kg', '70 a 80 kg', '80 a 90 kg', '90 a 100 kg', '100 a 125 kg', '125 a 150 kg', 'mais de 150 kg'];

/** Custo dos Envios do ML para o preço e o peso; null sem peso informado. */
export function custoEnvioMercadoLivre(price, weightKg) {
    if (!(weightKg > 0)) return null;
    const linha = ML_ENVIO_TABELA.findIndex(([ate]) => weightKg <= ate);
    const coluna = 1 + ML_ENVIO_FAIXAS_PRECO.filter((limite) => price >= limite).length;
    let valor = ML_ENVIO_TABELA[linha][coluna];
    // "Os produtos de menos de R$ 19 pagam no máximo metade do preço do produto."
    if (price < 19) valor = Math.min(valor, price / 2);
    return { valor, faixaPeso: ML_ENVIO_FAIXAS_PESO[linha] };
}

const mlExtras = (price, { weightKg }) => {
    const envio = custoEnvioMercadoLivre(price, weightKg);
    return envio ? [{ label: `Envio do ML (${envio.faixaPeso})`, fixed: envio.valor }] : [];
};

// Programa de taxa de envio do TikTok Shop: todo vendedor do Brasil entra
// automaticamente; 6% por item, no máximo R$ 50 (seller.tiktok.com/br/seller-fees).
const tiktokExtras = (price) => (price * 0.06 <= 50
    ? [{ label: 'Programa de frete (6%)', pct: 6 }]
    : [{ label: 'Programa de frete (teto)', fixed: 50 }]);

// Cartão de crédito nacional via Stripe — já confirmado funcionando de
// verdade no checkout do site (ver histórico do projeto).
const STRIPE_PCT = 3.99;
const STRIPE_FIXED = 0.39;

export const MARKETPLACES = [
    {
        key: 'site',
        label: 'Site próprio',
        sublabel: 'Venda direta, sem comissão de marketplace',
        icon: 'fas fa-store',
        theme: {
            cardBg: 'linear-gradient(135deg, #1B3A5C 0%, #14293f 100%)',
            fg: '#ffffff',
            accent: '#F27A2A',
            chipBg: 'rgba(255,255,255,0.12)',
        },
        estimated: false,
        defaultCommissionPct: STRIPE_PCT,
        defaultFixedFee: STRIPE_FIXED,
        commissionLabel: 'Taxa Stripe (cartão)',
        resolveFee: () => ({ pct: STRIPE_PCT, fixed: STRIPE_FIXED }),
        note: `Sem comissão de marketplace — só a taxa do gateway de pagamento (Stripe, cartão de crédito nacional): ${STRIPE_PCT}% + R$ ${STRIPE_FIXED.toFixed(2)} por venda.`,
        source: 'stripe.com/br/pricing',
    },
    {
        key: 'mercado_livre',
        label: 'Mercado Livre Clássico',
        sublabel: 'Sem parcelamento sem juros',
        // A marca real do Mercado Livre é justamente um aperto de mãos
        // (o "handshake" do logo) — usamos o ícone equivalente do
        // FontAwesome em vez de um SVG de terceiro pra essa marca.
        icon: 'fas fa-handshake',
        theme: {
            cardBg: 'linear-gradient(135deg, #FFE600 0%, #FFC400 100%)',
            fg: '#2d2600',
            accent: '#2968C8',
            chipBg: 'rgba(0,0,0,0.08)',
        },
        estimated: false,
        // Categorias dos anúncios ativos da loja (out/2026): 11,5% a 13%.
        defaultCommissionPct: 12,
        defaultFixedFee: 0,
        commissionLabel: 'Comissão (Clássico)',
        // Sem taxa fixa por venda: o custo dos Envios (por peso e preço,
        // desde 24/08/2026) entra como custo à parte em `extraCosts`.
        resolveFee: (price, commissionPct) => ({ pct: commissionPct, fixed: 0 }),
        extraCosts: mlExtras,
        note: 'Comissão do Clássico de 10% a 14% conforme a categoria (anúncios ativos da loja: 11,5% a 13%). Desde 24/08/2026 toda venda paga o custo dos Envios do ML, pela tabela de peso e preço (ex.: até 0,3 kg, R$ 6,85 de R$ 19 a R$ 48,99; R$ 8,15 de R$ 49 a R$ 78,99; R$ 12,95 de R$ 79 a R$ 99,99). Informe o peso lá em cima: o card soma esse custo sozinho, não repita no frete. Abaixo de R$ 19 paga no máximo metade do preço. Frete grátis e rápido abaixo de R$ 79 é opcional e mais caro (não incluído).',
        source: 'mercadolivre.com.br/ajuda (custos de envio, reputação verde) e API do ML, checado 06/11/2026',
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
        estimated: false,
        // Categorias dos anúncios ativos da loja (out/2026): 16,5% a 18%.
        defaultCommissionPct: 17,
        defaultFixedFee: 0,
        commissionLabel: 'Comissão (Premium)',
        resolveFee: (price, commissionPct) => ({ pct: commissionPct, fixed: 0 }),
        extraCosts: mlExtras,
        note: 'Comissão do Premium de 15% a 19% conforme a categoria (anúncios ativos da loja: 16,5% a 18%). Desde 24/08/2026 toda venda paga o custo dos Envios do ML, pela tabela de peso e preço (ex.: até 0,3 kg, R$ 6,85 de R$ 19 a R$ 48,99; R$ 8,15 de R$ 49 a R$ 78,99; R$ 12,95 de R$ 79 a R$ 99,99). Informe o peso lá em cima: o card soma esse custo sozinho, não repita no frete. Abaixo de R$ 19 paga no máximo metade do preço. Frete grátis e rápido abaixo de R$ 79 é opcional e mais caro (não incluído).',
        source: 'mercadolivre.com.br/ajuda (custos de envio, reputação verde) e API do ML, checado 06/11/2026',
    },
    {
        key: 'shopee',
        label: 'Shopee',
        sublabel: 'Vendedor CNPJ · tabela out/2026',
        // Logo oficial da Shopee (via biblioteca Simple Icons, que espelha
        // a marca original) — não existe ícone de marca da Shopee no
        // FontAwesome, por isso usamos o path SVG real em vez de um ícone
        // genérico de carrinho.
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
        estimated: false,
        defaultCommissionPct: 20,
        defaultFixedFee: 4.5,
        commissionLabel: 'Comissão + transação',
        // Desde mar/2026 a Shopee cobra por faixa de preço do item, sem o
        // antigo teto de R$100 na comissão. Em 01/10/2026 a taxa fixa da
        // primeira faixa subiu de R$4,00 para R$4,50. Faixas conferidas no
        // escrow dos pedidos da loja (set e out/2026).
        resolveFee: (price) => {
            // Abaixo de R$ 9 o adicional por item é metade do preço.
            if (price < 9) return { pct: 20, fixed: price / 2 };
            if (price < 80) return { pct: 20, fixed: 4.5 };
            if (price < 100) return { pct: 14, fixed: 16 };
            if (price < 200) return { pct: 14, fixed: 20 };
            return { pct: 14, fixed: 26 };
        },
        note: 'Comissão por faixa de preço do item, sem teto (já inclui a taxa de transação): 20%+R$4,50 até R$79,99, 14%+R$16 de R$80 a R$99,99, 14%+R$20 de R$100 a R$199,99 e 14%+R$26 a partir de R$200. Abaixo de R$9 o adicional por item é metade do preço. O frete grátis já está incluído. Vendedor pessoa física com mais de 450 pedidos em 90 dias paga +R$3 por item, não incluído aqui.',
        source: 'seller.shopee.com.br/edu (Comissão CNPJ e CPF 2026) e escrow da loja, checado 06/11/2026',
    },
    {
        key: 'amazon',
        label: 'Amazon',
        sublabel: 'Plano Profissional · categoria eletrônicos/casa',
        // Logo oficial da Amazon (Simple Icons) — mais fiel à marca real
        // do que o ícone genérico "fab fa-amazon" do FontAwesome.
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
        estimated: false,
        defaultCommissionPct: 13,
        defaultFixedFee: 0,
        commissionLabel: 'Tarifa de indicação',
        resolveFee: (price, commissionPct) => ({ pct: commissionPct, fixed: 0 }),
        note: 'Tarifa de indicação de 8% a 20% conforme categoria (eletrônicos e casa costumam ficar entre 10% e 15%). Plano Profissional custa R$19/mês (grátis no 1º ano) e não cobra por item — por isso não entra taxa fixa por venda aqui. Plano Individual não tem mensalidade mas cobra R$2,00 por item vendido.',
        source: 'venda.amazon.com.br/precos, checado ago/2026',
    },
    {
        key: 'tiktok_shop',
        label: 'TikTok Shop',
        sublabel: 'Comissão + Programa de taxa de envio',
        // Logo oficial do TikTok (Simple Icons) — mesma marca real usada
        // pela plataforma, em vez do ícone estilizado do FontAwesome.
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
        estimated: false,
        defaultCommissionPct: 8,
        defaultFixedFee: 5,
        commissionLabel: 'Comissão da plataforma',
        resolveFee: (price) => (price < 50 ? { pct: 10, fixed: 4 } : { pct: 6, fixed: 6 }),
        extraCosts: tiktokExtras,
        note: 'Comissão sobre o preço depois do desconto do vendedor: 10%+R$4 abaixo de R$50 e 6%+R$6 a partir de R$50. Mais o Programa de taxa de envio, em que todo vendedor entra automaticamente: 6% por item, no máximo R$50. O frete do pedido é estimado pelas medidas da embalagem e sai do repasse; se a loja pagar, informe em "Outro frete que você paga".',
        source: 'seller.tiktok.com/br/seller-fees, checado 06/11/2026',
    },
];

/**
 * Resolve preço de venda + breakdown completo para um marketplace, dado o
 * custo do produto e a margem de lucro desejada (fração do preço de venda,
 * padrão de precificação de varejo: margem = lucro / preço de venda — não
 * markup sobre o custo).
 *
 * Fórmula: P = (custo + taxa_fixa + custos_fixos_extras + frete) / (1 - comissão% - extras% - imposto% - margem%)
 *
 * Custos extras (`extraCosts`) são cobranças à parte da comissão, mostradas
 * em linha própria: custo dos Envios do ML (por peso e preço) e Programa de
 * taxa de envio do TikTok (6%, até R$ 50).
 *
 * Para marketplaces com taxa por faixa de preço (Mercado Livre, Shopee,
 * TikTok Shop) o resultado é resolvido por ponto fixo: a faixa depende do
 * preço final, que é justamente o que estamos calculando. Perto da virada
 * de uma faixa a conta pode não fechar exato; por isso as taxas do
 * detalhamento são sempre recalculadas no preço final e o lucro mostrado é
 * o real nesse preço (a "margem real" pode ficar um pouco diferente da
 * pedida).
 */
export function computePricing(marketplace, { cost, marginPct, taxPct = 0, freight = 0, weightKg = 0, commissionPctOverride = null }) {
    const margin = marginPct / 100;
    const tax = taxPct / 100;
    const ctx = { weightKg };

    const feesAt = (price) => {
        const resolved = marketplace.resolveFee(price, commissionPctOverride ?? marketplace.defaultCommissionPct);
        const pct = commissionPctOverride ?? resolved.pct;
        const extras = (marketplace.extraCosts?.(price, ctx) ?? []).map((extra) => ({
            label: extra.label,
            pct: extra.pct ?? 0,
            fixed: extra.fixed ?? 0,
            value: price * ((extra.pct ?? 0) / 100) + (extra.fixed ?? 0),
        }));
        return {
            pct,
            fixed: resolved.fixed,
            extras,
            totalPct: pct + extras.reduce((sum, e) => sum + e.pct, 0),
            totalFixed: resolved.fixed + extras.reduce((sum, e) => sum + e.fixed, 0),
        };
    };

    let fees = feesAt(0);
    let price = (cost + fees.totalFixed + freight) / Math.max(0.01, 1 - fees.totalPct / 100 - tax - margin);

    for (let i = 0; i < 8; i++) {
        fees = feesAt(price);
        const denominator = 1 - fees.totalPct / 100 - tax - margin;

        if (denominator <= 0.01) {
            return { invalid: true, pct: fees.pct, fixed: fees.fixed, extras: [] };
        }

        const nextPrice = (cost + fees.totalFixed + freight) / denominator;
        const converged = Math.abs(nextPrice - price) < 0.005;
        price = nextPrice;

        if (converged) break;
    }

    // Detalhamento sempre com as taxas da faixa do preço final.
    fees = feesAt(price);

    const commissionValue = price * (fees.pct / 100);
    const extrasValue = fees.extras.reduce((sum, e) => sum + e.value, 0);
    const taxValue = price * tax;
    const netReceived = price - commissionValue - fees.fixed - extrasValue - taxValue;
    const profit = netReceived - cost - freight;

    return {
        invalid: false,
        price,
        pct: fees.pct,
        fixed: fees.fixed,
        extras: fees.extras,
        commissionValue,
        taxValue,
        netReceived,
        profit,
        realMarginPct: price > 0 ? (profit / price) * 100 : 0,
    };
}
