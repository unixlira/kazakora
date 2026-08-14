<script setup>
import { computed } from 'vue';

const props = defineProps({
    status: { type: String, required: true },
    label: { type: String, default: null },
});

// Palette fixed by design spec: green=success, yellow=pending, blue=info,
// red=danger, purple=processing.
const STYLES = {
    completed: 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300',
    active: 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300',
    'in stock': 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300',
    paid: 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300',
    received: 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300',
    pending: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/50 dark:text-yellow-300',
    open: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/50 dark:text-yellow-300',
    draft: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/50 dark:text-yellow-300',
    shipped: 'bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-300',
    sent: 'bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-300',
    cancelled: 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300',
    'out of stock': 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300',
    processing: 'bg-purple-100 text-purple-700 dark:bg-purple-900/50 dark:text-purple-300',
    in_progress: 'bg-purple-100 text-purple-700 dark:bg-purple-900/50 dark:text-purple-300',
};

// Além das cores fixas do design spec acima, aceita uma cor de marca em hex
// (ex: '#146EB4') pra badge de canal/plataforma que precisa da cor real dela
// em vez de reaproveitar a paleta genérica de status — mesmo padrão de tinta
// já usado em Admin/Invoices/Index.vue, só que embutido aqui pra qualquer
// tela poder usar (pedido explícito 2026-08-14: badges da Amazon).
const isHexColor = computed(() => /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(props.status ?? ''));

const hexToRgba = (hex, alpha) => {
    let value = hex.replace('#', '');
    if (value.length === 3) {
        value = value.split('').map((c) => c + c).join('');
    }
    const r = parseInt(value.substring(0, 2), 16);
    const g = parseInt(value.substring(2, 4), 16);
    const b = parseInt(value.substring(4, 6), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
};

const classes = computed(() => (isHexColor.value
    ? ''
    : STYLES[props.status?.toLowerCase()] ?? 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'));

const hexStyle = computed(() => (isHexColor.value
    ? { color: props.status, backgroundColor: hexToRgba(props.status, 0.15) }
    : null));
</script>

<template>
    <span class="whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium" :class="classes" :style="hexStyle">
        {{ label ?? status }}
    </span>
</template>
