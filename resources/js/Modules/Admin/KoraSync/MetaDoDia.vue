<script setup>
import { computed } from 'vue';

/**
 * Coluna "META DO DIA" do app desktop KoraSync (MainWindow.xaml) — barra de
 * progresso vendas hoje/ontem + Separados/Enviados/Sem estoque/Pendentes de
 * separação/Cancelamentos/Devoluções do mês. Mesmos números, mesma fonte
 * (metrics() + queue()), só re-renderizados em Tailwind.
 */
const props = defineProps({
    metrics: { type: Object, required: true },
    outOfStockCount: { type: Number, required: true },
    pendingSeparationCount: { type: Number, required: true },
});

const goalPct = computed(() => {
    const today = props.metrics.sales_today ?? 0;
    const yesterday = props.metrics.sales_yesterday ?? 0;

    if (yesterday <= 0) return today > 0 ? 100 : 0;

    return Math.min(100, Math.round((today / yesterday) * 1000) / 10);
});

const cancelledOnly = computed(() => Math.max(0, (props.metrics.cancellations_and_returns_month ?? 0) - (props.metrics.returns_month ?? 0)));

const rows = computed(() => [
    { icon: 'fa-clipboard-check', label: 'Separados', value: props.metrics.packed_today ?? 0, color: 'var(--ks-brand)' },
    { icon: 'fa-truck-fast', label: 'Enviados', value: props.metrics.shipped_today ?? 0, color: 'var(--ks-processing)' },
    { icon: 'fa-triangle-exclamation', label: 'Sem estoque', value: props.outOfStockCount, color: 'var(--ks-error)' },
    { icon: 'fa-clock', label: 'Pendentes de separação', value: props.pendingSeparationCount, color: 'var(--ks-warning)' },
    { icon: 'fa-circle-xmark', label: 'Cancelamentos', value: cancelledOnly.value, color: 'var(--ks-error)' },
    { icon: 'fa-rotate-left', label: 'Devoluções do mês', value: props.metrics.returns_month ?? 0, color: 'var(--ks-error)' },
]);

const now = new Date();
const weekday = now.toLocaleDateString('pt-BR', { weekday: 'long' });
const dateTime = now.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
</script>

<template>
    <aside class="rounded-xl border p-4" style="background: var(--ks-card); border-color: var(--ks-border)">
        <div class="flex items-center justify-between">
            <span class="text-xs font-semibold" style="color: var(--ks-text-secondary)">Vendas de hoje / ontem</span>
            <span
                class="rounded-md border px-2 py-0.5 text-xs font-bold"
                style="background: color-mix(in srgb, var(--ks-brand) 15%, transparent); border-color: var(--ks-brand); color: var(--ks-brand)"
            >{{ goalPct.toFixed(1) }}%</span>
        </div>

        <p class="mt-2 text-2xl font-bold" style="color: var(--ks-text)">{{ metrics.sales_today ?? 0 }} / {{ metrics.sales_yesterday ?? 0 }} vendas</p>

        <div class="mt-2.5 h-2 overflow-hidden rounded-full" style="background: var(--ks-bg)">
            <div class="h-full rounded-full transition-all" :style="{ width: `${goalPct}%`, background: 'var(--ks-brand)' }"></div>
        </div>

        <hr class="my-4" style="border-color: var(--ks-border)">

        <div class="space-y-3.5">
            <div v-for="row in rows" :key="row.label" class="flex items-center gap-3">
                <div
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg"
                    :style="{ background: `color-mix(in srgb, ${row.color} 15%, transparent)` }"
                >
                    <i class="fas text-base" :class="row.icon" :style="{ color: row.color }"></i>
                </div>
                <span class="flex-1 text-sm font-bold" style="color: var(--ks-text)">{{ row.label }}</span>
                <span class="text-2xl font-bold" :style="{ color: row.color }">{{ row.value }}</span>
            </div>
        </div>

        <hr class="my-4" style="border-color: var(--ks-border)">

        <p class="text-center text-sm font-bold capitalize" style="color: var(--ks-brand)">{{ weekday }}</p>
        <p class="mt-0.5 text-center text-sm" style="color: var(--ks-brand)">{{ dateTime }}</p>
    </aside>
</template>
