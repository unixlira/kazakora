<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import ChartCanvas from '@/Shared/Components/ChartCanvas.vue';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    summary: { type: Object, required: true },
    cashFlowSeries: { type: Array, default: () => [] },
});

const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
const formatShortDate = (date) => new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit' }).format(new Date(`${date}T00:00:00`));

const chartData = computed(() => ({
    labels: props.cashFlowSeries.map((item) => formatShortDate(item.date)),
    datasets: [
        { label: 'Entradas', data: props.cashFlowSeries.map((item) => item.income), backgroundColor: '#13deb9' },
        { label: 'Saídas', data: props.cashFlowSeries.map((item) => item.expense), backgroundColor: '#fa896b' },
    ],
}));
</script>

<template>
    <Head title="Dashboard Financeiro" />

    <AdminLayout>
        <h1 class="mb-4 text-2xl font-bold">Dashboard Financeiro</h1>

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <CardStats stat-subtitle="SALDO ATUAL" :stat-title="formatPrice(summary.balance)" stat-icon-name="fas fa-scale-balanced" stat-icon-color="bg-primary" />
            <CardStats stat-subtitle="ENTRADAS NO MÊS" :stat-title="formatPrice(summary.incomeMonth)" stat-icon-name="fas fa-arrow-trend-up" stat-icon-color="bg-success" />
            <CardStats stat-subtitle="SAÍDAS NO MÊS" :stat-title="formatPrice(summary.expenseMonth)" stat-icon-name="fas fa-arrow-trend-down" stat-icon-color="bg-error" />
            <CardStats stat-subtitle="LUCRO NO MÊS" :stat-title="formatPrice(summary.profitMonth)" stat-icon-name="fas fa-coins" stat-icon-color="bg-warning" />
            <CardStats stat-subtitle="FATURAMENTO EM VENDAS" :stat-title="formatPrice(summary.salesRevenue)" stat-icon-name="fas fa-bag-shopping" stat-icon-color="bg-info" />
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
    </AdminLayout>
</template>
