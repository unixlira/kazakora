/**
 * Taxas reais de venda por canal, usadas pela calculadora de precificação
 * (`Admin/PricingCalculator/Index.vue`). Cada marketplace tem sua própria
 * estrutura de comissão — algumas são um percentual fixo, outras mudam por
 * faixa de preço do item. Os valores abaixo são os praticados publicamente
 * em 2026 (checados em ago/2026 contra a documentação oficial de cada
 * plataforma — Mercado Livre, Amazon e Stripe direto; Shopee e TikTok Shop
 * via cobertura de imprensa/blogs especializados corroborando entre si,
 * já que as páginas oficiais de tarifas dessas duas bloqueiam scraping
 * direto). Comissão por categoria varia MUITO (ex.: Mercado Livre vai de
 * 10% a 19%) — o valor default aqui é uma média realista para o nicho da
 * loja (eletrônicos, casa e cozinha), sempre editável na tela via "Ajustar
 * taxas". Isso não substitui o simulador oficial de cada marketplace na
 * hora de bater o preço final de uma categoria específica.
 */

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
        label: 'Mercado Livre',
        sublabel: 'Clássico · categoria eletrônicos/casa',
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
        defaultCommissionPct: 12,
        defaultFixedFee: 6,
        commissionLabel: 'Comissão (anúncio Clássico)',
        // Custo fixo só é cobrado em vendas abaixo de R$79 — acima disso,
        // frete grátis passa a ser obrigatório mas não há mais taxa fixa.
        resolveFee: (price, commissionPct) => {
            let fixed;
            if (price < 20) fixed = 5.5;
            else if (price < 79) fixed = 6;
            else fixed = 0;
            return { pct: commissionPct, fixed };
        },
        note: 'Comissão varia de 10% a 19% conforme categoria e tipo de anúncio (Clássico é mais barato que Premium). Custo fixo de R$5,50 a R$6,00 só em vendas abaixo de R$79 — acima disso o frete grátis passa a ser obrigatório. Confira o percentual exato da sua categoria no simulador oficial do Mercado Livre.',
        source: 'mercadolivre.com.br/ajuda (tarifas de venda), checado ago/2026',
    },
    {
        key: 'shopee',
        label: 'Shopee',
        sublabel: 'Vendedor padrão · tabela mar/2026',
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
        defaultCommissionPct: 14,
        defaultFixedFee: 15,
        commissionLabel: 'Comissão + transação',
        // Desde mar/2026 a Shopee cobra por faixa de preço do item, sem o
        // antigo teto de R$100 na comissão.
        resolveFee: (price) => {
            if (price < 80) return { pct: 20, fixed: 4 };
            if (price < 100) return { pct: 17, fixed: 15 };
            return { pct: 14, fixed: 26 };
        },
        note: 'Desde março/2026 a comissão é por faixa de preço do item (sem teto de R$100): 20%+R$4 até R$79,99, 14%+R$26 acima de R$100. Vendedor pessoa física com mais de 450 pedidos em 90 dias paga +R$3 por item — não incluído aqui, ajuste manualmente se for o seu caso.',
        source: 'seller.shopee.com.br/edu + cobertura especializada, checado ago/2026',
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
        sublabel: 'Ainda sem venda real na loja',
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
        estimated: true,
        defaultCommissionPct: 8,
        defaultFixedFee: 5,
        commissionLabel: 'Comissão da plataforma',
        resolveFee: (price) => (price < 50 ? { pct: 10, fixed: 4 } : { pct: 6, fixed: 6 }),
        note: 'Canal ainda não tem integração real na loja (nenhuma venda passou por aqui até hoje) — taxas de referência da documentação pública: 10%+R$4 abaixo de R$50, 6%+R$6 a partir de R$50 (vigente desde jul/2026). Trate como estimativa até conectarmos o canal de verdade.',
        source: 'seller-br.tiktok.com/university, checado ago/2026',
    },
];

/**
 * Resolve preço de venda + breakdown completo para um marketplace, dado o
 * custo do produto e a margem de lucro desejada (fração do preço de venda,
 * padrão de precificação de varejo: margem = lucro / preço de venda — não
 * markup sobre o custo).
 *
 * Fórmula: P = (custo + taxa_fixa + frete) / (1 - comissão% - imposto% - margem%)
 *
 * Para marketplaces com taxa por faixa de preço (Mercado Livre, Shopee,
 * TikTok Shop) o resultado é resolvido por ponto fixo: a faixa depende do
 * preço final, que é justamente o que estamos calculando — poucas
 * iterações bastam pois a função é monótona e limitada.
 */
export function computePricing(marketplace, { cost, marginPct, taxPct = 0, freight = 0, commissionPctOverride = null }) {
    const margin = marginPct / 100;
    const tax = taxPct / 100;

    let pct = commissionPctOverride ?? marketplace.defaultCommissionPct;
    let fixed = marketplace.defaultFixedFee;
    let price = (cost + fixed + freight) / Math.max(0.01, 1 - pct / 100 - tax - margin);

    for (let i = 0; i < 6; i++) {
        const resolved = marketplace.resolveFee(price, commissionPctOverride ?? marketplace.defaultCommissionPct);
        const nextPct = commissionPctOverride ?? resolved.pct;
        const nextFixed = resolved.fixed;
        const denominator = 1 - nextPct / 100 - tax - margin;

        if (denominator <= 0.01) {
            return { invalid: true, pct: nextPct, fixed: nextFixed };
        }

        const nextPrice = (cost + nextFixed + freight) / denominator;
        const converged = Math.abs(nextPrice - price) < 0.005 && pct === nextPct && fixed === nextFixed;

        price = nextPrice;
        pct = nextPct;
        fixed = nextFixed;

        if (converged) break;
    }

    const commissionValue = price * (pct / 100);
    const taxValue = price * tax;
    const netReceived = price - commissionValue - fixed - taxValue;
    const profit = netReceived - cost - freight;

    return {
        invalid: false,
        price,
        pct,
        fixed,
        commissionValue,
        taxValue,
        netReceived,
        profit,
        realMarginPct: price > 0 ? (profit / price) * 100 : 0,
    };
}
