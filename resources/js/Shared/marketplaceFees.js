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
        icon: 'fas fa-bag-shopping',
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
        icon: 'fas fa-cart-shopping',
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
        icon: 'fab fa-amazon',
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
        icon: 'fab fa-tiktok',
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
