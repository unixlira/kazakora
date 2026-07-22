<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    kpis: { type: Object, required: true },
});

const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
</script>

<template>
    <Head title="Indicadores" />

    <AdminLayout>
        <h1 class="mb-4 text-2xl font-bold">Indicadores (KPIs)</h1>
        <p class="mb-6 text-sm text-slate-400">Calculados com base no mês corrente.</p>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <CardStats stat-subtitle="TICKET MÉDIO" :stat-title="formatPrice(kpis.averageTicket)" stat-icon-name="fas fa-receipt" stat-icon-color="bg-primary" />
            <CardStats stat-subtitle="TAXA DE CONVERSÃO" :stat-title="`${kpis.conversionRate}%`" stat-icon-name="fas fa-bullseye" stat-icon-color="bg-success" />
            <CardStats stat-subtitle="TAXA DE CANCELAMENTO" :stat-title="`${kpis.cancellationRate}%`" stat-icon-name="fas fa-ban" stat-icon-color="bg-error" />
            <CardStats stat-subtitle="ESTOQUE BAIXO" :stat-title="`${kpis.lowStockRate}%`" stat-icon-name="fas fa-triangle-exclamation" stat-icon-color="bg-warning" />
            <CardStats stat-subtitle="VISITANTES ÚNICOS (MÊS)" :stat-title="String(kpis.uniqueVisitorsMonth)" stat-icon-name="fas fa-eye" stat-icon-color="bg-info" />
            <CardStats stat-subtitle="PEDIDOS NO MÊS" :stat-title="String(kpis.ordersMonth)" stat-icon-name="fas fa-cart-shopping" stat-icon-color="bg-secondary" />
        </div>
    </AdminLayout>
</template>
