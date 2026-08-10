<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import ChartCanvas from '@/Shared/Components/ChartCanvas.vue';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    summary: { type: Object, required: true },
    netProfit: { type: Object, required: true },
    walletBalances: { type: Object, default: () => ({}) },
    adSpendByChannel: { type: Array, default: () => [] },
    adSpendSeries: { type: Array, default: () => [] },
    cashFlowSeries: { type: Array, default: () => [] },
});

const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
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
};

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
        </div>

        <!-- Saldo disponível pra saque nas plataformas + Saldo Atual (a soma
             das duas) — pedido explícito 2026-08-10: "saldo atual" não é
             mais fluxo de caixa lançado à mão, é literalmente a soma do que
             está disponível em cada plataforma, direto no card, sem um
             card de "soma" separado. -->
        <h2 class="mb-3 text-xl font-bold">Saldo em Conta</h2>
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
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
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-lightprimary text-primary">
                        <i class="fas fa-scale-balanced"></i>
                    </span>
                    <div class="min-w-0">
                        <p class="text-xs uppercase tracking-wide text-slate-400">Saldo Atual (Shopee + Mercado Livre)</p>
                        <p class="mt-0.5 truncate text-2xl font-bold">{{ totalWalletBalance !== null ? formatPrice(totalWalletBalance) : 'Indisponível' }}</p>
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
            <div class="flex items-center justify-between border-t-2 border-[var(--surface-border)] py-2">
                <span class="font-bold">(=) Lucro Líquido do Mês</span>
                <span class="text-xl font-bold" :class="profitVariant === 'success' ? 'text-success' : 'text-error'">{{ formatPrice(netProfit.netProfitMonth) }}</span>
            </div>

            <p class="mt-3 border-t border-dashed border-[var(--surface-border)] pt-3 text-xs text-slate-400">
                Taxa de marketplace do mês (informativo — não entra nessa conta): {{ formatPrice(netProfit.marketplaceFeeMonth) }}
            </p>
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
