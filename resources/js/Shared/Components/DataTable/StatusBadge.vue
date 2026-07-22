<script setup>
import { computed } from 'vue';

const props = defineProps({
    status: { type: String, required: true },
    label: { type: String, default: null },
});

// Palette fixed by design spec: green=success, yellow=pending, blue=info,
// red=danger, purple=processing.
const STYLES = {
    completed: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300',
    active: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300',
    'in stock': 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300',
    paid: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300',
    pending: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300',
    shipped: 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300',
    cancelled: 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300',
    'out of stock': 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300',
    processing: 'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300',
};

const classes = computed(
    () => STYLES[props.status?.toLowerCase()] ?? 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
);
</script>

<template>
    <span class="whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium" :class="classes">
        {{ label ?? status }}
    </span>
</template>
