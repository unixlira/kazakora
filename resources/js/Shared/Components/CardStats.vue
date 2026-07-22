<script setup>
import { computed } from 'vue';

const props = defineProps({
    statSubtitle: { type: String, default: '' },
    statTitle: { type: String, default: '' },
    statIconName: { type: String, default: 'fas fa-chart-bar' },
    // primary | secondary | success | warning | error | info
    variant: { type: String, default: 'primary' },
});

const VARIANTS = {
    primary: { bg: 'bg-lightprimary', text: 'text-primary' },
    secondary: { bg: 'bg-lightsecondary', text: 'text-secondary' },
    success: { bg: 'bg-lightsuccess', text: 'text-success' },
    warning: { bg: 'bg-lightwarning', text: 'text-warning' },
    error: { bg: 'bg-lighterror', text: 'text-error' },
    info: { bg: 'bg-lightinfo', text: 'text-info' },
};

const style = computed(() => VARIANTS[props.variant] ?? VARIANTS.primary);
</script>

<template>
    <div class="relative flex min-w-0 flex-col break-words rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm transition-shadow hover:shadow-md">
        <div class="flex items-center gap-4">
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full" :class="[style.bg, style.text]">
                <i :class="statIconName" class="text-xl"></i>
            </div>
            <div class="min-w-0">
                <p class="truncate text-2xl font-bold">{{ statTitle }}</p>
                <p class="truncate text-sm text-slate-500 dark:text-slate-400">{{ statSubtitle }}</p>
            </div>
        </div>
    </div>
</template>
