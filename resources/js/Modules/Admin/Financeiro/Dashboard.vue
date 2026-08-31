<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import ChartCanvas from '@/Shared/Components/ChartCanvas.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    summary: { type: Object, required: true },
    netProfit: { type: Object, required: true },
    walletBalances: { type: Object, default: () => ({}) },
    adSpendByChannel: { type: Array, default: () => [] },
    adSpendSeries: { type: Array, default: () => [] },
    cashFlowSeries: { type: Array, default: () => [] },
    channelMonthlyBreakdown: { type: Array, default: () => [] },
});

const safeNumber = (value, fallback = 0) => {
    const number = Number(value);
    return Number.isFinite(number) ? number : fallback;
};
const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(safeNumber(value));
const formatShortDate = (date) => new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit' }).format(new Date(`${date}T00:00:00`));

const chartData = computed(() => ({
    labels: props.cashFlowSeries.map((item) => formatShortDate(item.date)),
    datasets: [
        { label: 'Entradas', data: props.cashFlowSeries.map((item) => item.income), backgroundColor: '#13deb9' },
        { label: 'Saídas', data: props.cashFlowSeries.map((item) => item.expense), backgroundColor: '#ef4444' },
    ],
}));

// Mesma paleta usada em Invoices/Index.vue e na calculadora de precificação
// — cor real de cada plataforma, pedido explícito 2026-08-09.
const CHANNEL_STYLES = {
    shopee: { label: 'Shopee', color: '#EE4D2D' },
    mercado_livre: { label: 'Mercado Livre', color: '#2968C8' },
    tiktok_shop: { label: 'TikTok Shop', color: '#000000' },
    amazon: { label: 'Amazon', color: '#FF9900' },
    shein: { label: 'Shein', color: '#3D3D3D' },
    loja: { label: 'Loja própria', color: '#04D7B6' },
};

const monthLabel = (month) => {
    const [year, m] = month.split('-');
    return new Intl.DateTimeFormat('pt-BR', { month: 'short', year: '2-digit' }).format(new Date(Number(year), Number(m) - 1, 1));
};

// Agrupado por mês (mais recente primeiro, já vem ordenado do backend),
// cada mês com a lista de canais que tiveram pedido naquele mês —
// pedido explícito 2026-08-31: "detalhado de cada marketplace por mês".
const breakdownByMonth = computed(() => {
    const months = [];

    for (const row of props.channelMonthlyBreakdown) {
        let bucket = months.find((m) => m.month === row.month);

        if (!bucket) {
            bucket = { month: row.month, rows: [] };
            months.push(bucket);
        }

        bucket.rows.push(row);
    }

    return months;
});

const hexToRgba = (hex, alpha) => {
    const value = hex.replace('#', '');
    const r = parseInt(value.substring(0, 2), 16);
    const g = parseInt(value.substring(2, 4), 16);
    const b = parseInt(value.substring(4, 6), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
};

const adSpendChartData = computed(() => ({
    labels: props.adSpendSeries.map((item) => formatShortDate(item.date)),
    datasets: [
        {
            label: 'Shopee Ads',
            data: props.adSpendSeries.map((item) => item.shopee),
            borderColor: CHANNEL_STYLES.shopee.color,
            backgroundColor: hexToRgba(CHANNEL_STYLES.shopee.color, 0.15),
            tension: 0.3,
        },
        {
            label: 'Mercado Ads',
            data: props.adSpendSeries.map((item) => item.mercado_livre),
            borderColor: CHANNEL_STYLES.mercado_livre.color,
            backgroundColor: hexToRgba(CHANNEL_STYLES.mercado_livre.color, 0.15),
            tension: 0.3,
        },
    ],
}));

const totalAdSpend14Days = computed(() => props.adSpendSeries.reduce((sum, item) => sum + item.shopee + item.mercado_livre, 0));

const hasCostData = computed(() => props.netProfit.productsWithCost > 0);

// "Saldo Atual" = soma dos saldos disponíveis nas duas plataformas —
// pedido explícito 2026-08-10 (deixou de ser fluxo de caixa lançado à
// mão). Se qualquer uma vier indisponível, o total também fica
// indisponível (não dá pra somar um número real com "não sei").
const totalWalletBalance = computed(() => {
    const { shopee, mercado_livre: mercadoLivre } = props.walletBalances;
    return shopee !== null && shopee !== undefined && mercadoLivre !== null && mercadoLivre !== undefined
        ? shopee + mercadoLivre
        : null;
});

// "métrica pra saber se tá dando lucro" (pedido explícito 2026-08-09) —
// cor muda na hora: verde quando positivo, vermelho quando negativo.
const profitVariant = computed(() => (props.summary.profitMonth >= 0 ? 'success' : 'error'));
const netProfitAllTimeVariant = computed(() => (props.summary.netProfitAllTime >= 0 ? 'success' : 'error'));
</script>

<template>
    <Head title="Financeiro" />

    <AdminLayout>
        <h1 class="mb-4 text-2xl font-bold">Financeiro</h1>

        <!-- Reorganizado 2026-08-10 (usuário achou os cards confusos: nomes
             parecidos "Bruto"/"Líquido"/"Líquido do Mês" difíceis de
             distinguir, "Receita de Vendas"/"Lucro Líquido" repetindo os
             mesmos valores lá embaixo, tudo solto sem agrupamento). Regra
             adotada: cada valor aparece em UM lugar só. Esta seção é só a
             visão geral (desde o início + do mês corrente); "Faturamento
             Líquido do Mês" saiu daqui — ele já é um passo intermediário
             do cálculo, mora só na seção "Como o Lucro é calculado" mais
             abaixo, não faz sentido repetir como card solto aqui. -->
        <h2 class="mb-3 text-xl font-bold">Visão Geral</h2>
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <CardStats stat-subtitle="FATURAMENTO BRUTO (desde o início)" :stat-title="formatPrice(summary.grossRevenueAllTime)" stat-icon-name="fas fa-bag-shopping" variant="info" />
            <CardStats stat-subtitle="LUCRO LÍQUIDO (desde o início)" :stat-title="formatPrice(summary.netProfitAllTime)" stat-icon-name="fas fa-chart-line" :variant="netProfitAllTimeVariant" />
            <CardStats stat-subtitle="FATURAMENTO BRUTO DO MÊS" :stat-title="formatPrice(summary.grossRevenueMonth)" stat-icon-name="fas fa-arrow-trend-up" variant="success" />
            <CardStats stat-subtitle="LUCRO LÍQUIDO DO MÊS" :stat-title="formatPrice(summary.profitMonth)" stat-icon-name="fas fa-coins" :variant="profitVariant" />
            <!-- Pedido explícito 2026-08-10: soma de todos os produtos por
                 custo x quantidade em estoque — capital parado em mercadoria. -->
            <CardStats stat-subtitle="VALOR DE ESTOQUE" :stat-title="formatPrice(summary.stockValue)" stat-icon-name="fas fa-boxes-stacked" variant="warning" />
            <!-- Pedido explícito 2026-08-10: "Saldo Atual" saiu da linha de
                 saldo por plataforma e veio pra cá, do lado de Valor de
                 Estoque — e o rótulo perdeu o "(Shopee + Mercado Livre)"
                 (o detalhamento por plataforma já está na seção "Saldo em
                 Conta" logo abaixo, não precisa repetir no nome do card). -->
            <CardStats stat-subtitle="SALDO ATUAL" :stat-title="totalWalletBalance !== null ? formatPrice(totalWalletBalance) : 'Indisponível'" stat-icon-name="fas fa-scale-balanced" variant="primary" />
        </div>

        <!-- Saldo disponível pra saque em cada plataforma — a soma das duas
             (Saldo Atual) mudou pra Visão Geral, pedido explícito
             2026-08-10. -->
        <h2 class="mb-3 text-xl font-bold">Saldo em Conta</h2>
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full" :style="{ color: CHANNEL_STYLES.shopee.color, background: hexToRgba(CHANNEL_STYLES.shopee.color, 0.12) }">
                        <i class="fas fa-wallet"></i>
                    </span>
                    <div class="min-w-0">
                        <p class="text-xs uppercase tracking-wide text-slate-400">Saldo disponível — Shopee</p>
                        <p class="mt-0.5 truncate text-2xl font-bold">{{ walletBalances.shopee !== null ? formatPrice(walletBalances.shopee) : 'Indisponível' }}</p>
                    </div>
                </div>
            </div>
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full" :style="{ color: CHANNEL_STYLES.mercado_livre.color, background: hexToRgba(CHANNEL_STYLES.mercado_livre.color, 0.12) }">
                        <i class="fas fa-wallet"></i>
                    </span>
                    <div class="min-w-0">
                        <p class="text-xs uppercase tracking-wide text-slate-400">Saldo disponível — Mercado Livre</p>
                        <p class="mt-0.5 truncate text-2xl font-bold">{{ walletBalances.mercado_livre !== null ? formatPrice(walletBalances.mercado_livre) : 'Indisponível' }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <div class="border-b border-[var(--surface-border)] px-4 py-4">
                <h3 class="text-base font-semibold">Entradas x Saídas (últimos 14 dias)</h3>
                <p class="text-xs text-slate-400">Lançamentos do Fluxo de Caixa — não inclui o faturamento em vendas.</p>
            </div>
            <div class="p-4">
                <ChartCanvas type="bar" :data="chartData" />
            </div>
        </div>

        <!-- Reorganizado 2026-08-10 — trocado o grid de 4 cards (que
             repetia "Receita de Vendas"/"Lucro Líquido" já mostrados na
             Visão Geral) por um extrato de único painel, em passos, na
             ordem real da conta: Bruto → (–) Ads → Líquido do Mês → (–)
             Custo → Lucro Líquido. Isso também resolve a confusão entre
             "Faturamento Líquido do Mês" e "Lucro Líquido do Mês": aqui
             fica claro que um é passo intermediário do outro, não duas
             coisas soltas parecidas. -->
        <h2 class="mb-3 mt-8 text-xl font-bold">Como o Lucro Líquido do Mês é calculado</h2>

        <div class="mb-3 max-w-xl rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
            <div class="flex items-center justify-between py-1.5 text-sm">
                <span class="text-slate-500 dark:text-slate-400">Faturamento Bruto do Mês</span>
                <span class="font-semibold">{{ formatPrice(netProfit.salesRevenueMonth) }}</span>
            </div>
            <!-- Pedido explícito 2026-08-15: frete não é receita nem custo
                 do vendedor (quem cobra/paga a transportadora é o canal —
                 Shopee Xpress etc.), então fica fora da conta de cima pra
                 baixo — mas visível, pra quem quiser conferir. -->
            <div class="flex items-center justify-between py-1.5 text-sm text-slate-400 dark:text-slate-500">
                <span>Frete do Mês (informativo — não afeta o lucro)</span>
                <span class="font-medium">{{ formatPrice(netProfit.shippingCostMonth) }}</span>
            </div>
            <div class="flex items-center justify-between py-1.5 text-sm">
                <span class="text-slate-500 dark:text-slate-400">(–) Gasto com Anúncio</span>
                <span class="font-semibold text-error">{{ formatPrice(netProfit.adSpendMonth) }}</span>
            </div>
            <div class="flex items-center justify-between border-t border-[var(--surface-border)] py-2 text-sm">
                <span class="font-medium">(=) Faturamento Líquido do Mês</span>
                <span class="font-semibold">{{ formatPrice(summary.netRevenueMonth) }}</span>
            </div>
            <div class="flex items-center justify-between py-1.5 text-sm">
                <span class="text-slate-500 dark:text-slate-400">(–) Custo de Produto</span>
                <span class="font-semibold text-error">{{ formatPrice(netProfit.productCostMonth) }}</span>
            </div>
            <!-- Pedido explícito 2026-08-14: taxa do marketplace (comissão
                 real Shopee/ML) passou a entrar na conta — antes ficava só
                 informativa (nota de rodapé, "não entra nessa conta") por
                 um pedido de 2026-08-10, mas o usuário reconsiderou: é um
                 custo real (~12-20% da receita), não faz sentido de fora.
                 Agora é uma linha normal do extrato, igual as outras. -->
            <div class="flex items-center justify-between py-1.5 text-sm">
                <span class="text-slate-500 dark:text-slate-400">(–) Taxa de Marketplace (Shopee/ML)</span>
                <span class="font-semibold text-error">{{ formatPrice(netProfit.marketplaceFeeMonth) }}</span>
            </div>
            <div class="flex items-center justify-between py-1.5 text-sm">
                <span class="text-slate-500 dark:text-slate-400">(–) Custo Flex (Mercado Livre)</span>
                <span class="font-semibold text-error">{{ formatPrice(netProfit.flexCostMonth) }}</span>
            </div>
            <div class="flex items-center justify-between border-t-2 border-[var(--surface-border)] py-2">
                <span class="font-bold">(=) Lucro Líquido do Mês</span>
                <span class="text-xl font-bold" :class="profitVariant === 'success' ? 'text-success' : 'text-error'">{{ formatPrice(netProfit.netProfitMonth) }}</span>
            </div>

            <Link href="/admin/integracoes/mercado-livre/flex" class="mt-3 block border-t border-dashed border-[var(--surface-border)] pt-3 text-xs text-primary hover:underline">
                Ver detalhes do custo Flex →
            </Link>
        </div>

        <p v-if="!hasCostData" class="mb-6 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-700 dark:border-amber-900 dark:bg-amber-900/20 dark:text-amber-400">
            <i class="fas fa-triangle-exclamation mt-0.5"></i>
            <span>
                Nenhum produto ativo tem preço de custo cadastrado ainda ({{ netProfit.productsWithCost }} de {{ netProfit.productsActive }}) —
                "Custo de produto" e "Lucro líquido" estão contando custo zero, não é o valor real. Preencha o custo em
                cada produto (aba Dados fiscais) pra esses números ficarem precisos sozinhos.
            </span>
        </p>
        <p v-else-if="netProfit.productsWithCost < netProfit.productsActive" class="mb-6 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-700 dark:border-amber-900 dark:bg-amber-900/20 dark:text-amber-400">
            <i class="fas fa-triangle-exclamation mt-0.5"></i>
            <span>
                {{ netProfit.productsActive - netProfit.productsWithCost }} de {{ netProfit.productsActive }} produtos ativos ainda sem custo cadastrado —
                "Custo de produto" está subestimado até completar o cadastro.
            </span>
        </p>
        <!-- Receita/custo/lucro líquido por canal, mês a mês — pedido
             explícito 2026-08-31 ("quanto eu ganhei líquido no tiktok...
             detalhado de cada marketplace por mês"). -->
        <h2 class="mb-3 text-xl font-bold">Por Marketplace, por Mês</h2>

        <div v-if="breakdownByMonth.length" class="mb-6 space-y-5">
            <div v-for="bucket in breakdownByMonth" :key="bucket.month">
                <h3 class="mb-2 text-sm font-bold uppercase tracking-wide text-slate-400">{{ monthLabel(bucket.month) }}</h3>
                <div class="overflow-x-auto rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-[var(--surface-border)] text-left text-xs uppercase tracking-wide text-slate-400">
                                <th class="px-4 py-2.5">Canal</th>
                                <th class="px-4 py-2.5 text-right">Pedidos</th>
                                <th class="px-4 py-2.5 text-right">Receita</th>
                                <th class="px-4 py-2.5 text-right">Custo produto</th>
                                <th class="px-4 py-2.5 text-right">Taxa canal</th>
                                <th class="px-4 py-2.5 text-right">Lucro líquido</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in bucket.rows" :key="row.channel" class="border-b border-[var(--surface-border)] last:border-0">
                                <td class="px-4 py-2.5">
                                    <span class="inline-block rounded-full px-2.5 py-1 text-xs font-bold"
                                        :style="{ color: CHANNEL_STYLES[row.channel]?.color ?? '#64748B', background: hexToRgba(CHANNEL_STYLES[row.channel]?.color ?? '#64748B', 0.12) }">
                                        {{ CHANNEL_STYLES[row.channel]?.label ?? row.channel }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-right text-slate-400">{{ row.orders }}</td>
                                <td class="px-4 py-2.5 text-right font-medium">{{ formatPrice(row.revenue) }}</td>
                                <td class="px-4 py-2.5 text-right text-slate-400">{{ formatPrice(row.productCost) }}</td>
                                <td class="px-4 py-2.5 text-right text-slate-400">
                                    <span v-if="row.feeAvailable">{{ formatPrice(row.marketplaceFee) }}</span>
                                    <span v-else class="italic text-amber-600 dark:text-amber-400" title="Este canal ainda não tem captura de taxa real — lucro líquido está superestimado até essa fonte existir.">
                                        não disponível
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-right font-bold" :class="row.netProfit >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'">
                                    {{ formatPrice(row.netProfit) }}
                                    <i v-if="!row.feeAvailable" class="fas fa-triangle-exclamation ml-1 text-xs text-amber-500" title="Sem taxa do canal descontada — número real é menor que este."></i>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <p v-else class="mb-6 text-sm text-slate-400">Nenhum pedido faturado nos últimos 6 meses ainda.</p>

        <!-- Gasto com anúncio por canal -->
        <h2 class="mb-3 text-xl font-bold">Gasto com Anúncio</h2>

        <div v-if="adSpendByChannel.length" class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div v-for="row in adSpendByChannel" :key="row.channel"
                class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="inline-block rounded-full px-2.5 py-1 text-xs font-bold"
                        :style="{ color: CHANNEL_STYLES[row.channel]?.color ?? '#64748B', background: hexToRgba(CHANNEL_STYLES[row.channel]?.color ?? '#64748B', 0.12) }">
                        {{ CHANNEL_STYLES[row.channel]?.label ?? row.channel }}
                    </span>
                    <span class="text-xs text-slate-400">{{ row.impressions.toLocaleString('pt-BR') }} impressões · {{ row.clicks.toLocaleString('pt-BR') }} cliques</span>
                </div>
                <div class="mt-3 flex items-end justify-between">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-slate-400">Gasto no mês</p>
                        <p class="text-2xl font-bold">{{ formatPrice(row.spend) }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs uppercase tracking-wide text-slate-400">Vendas atribuídas</p>
                        <p class="text-sm font-semibold">{{ formatPrice(row.attributedGmv) }}</p>
                        <p class="text-xs text-slate-400">ROAS {{ row.spend > 0 ? (row.attributedGmv / row.spend).toFixed(2) : '—' }}</p>
                    </div>
                </div>
            </div>
        </div>
        <p v-else class="mb-4 text-sm text-slate-400">
            Nenhum gasto com anúncio sincronizado ainda — roda <code class="rounded bg-[var(--surface-muted)] px-1">php artisan ads:sync-spend</code> ou aguarda a sincronização automática das 6h.
        </p>

        <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <div class="border-b border-[var(--surface-border)] px-4 py-4">
                <h3 class="text-base font-semibold">Gasto com anúncio por dia (últimos 14 dias)</h3>
                <p class="text-xs text-slate-400">Total do período: {{ formatPrice(totalAdSpend14Days) }}</p>
            </div>
            <div class="p-4">
                <ChartCanvas type="line" :data="adSpendChartData" />
            </div>
        </div>
    </AdminLayout>
</template>
