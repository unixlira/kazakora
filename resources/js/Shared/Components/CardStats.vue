<script setup>
import { computed } from 'vue';

const props = defineProps({
    statSubtitle: { type: String, default: '' },
    statTitle: { type: String, default: '' },
    statIconName: { type: String, default: 'fas fa-chart-bar' },
    // primary | secondary | success | warning | error | info
    variant: { type: String, default: 'primary' },
    // Versão com cor (faixa no topo, fundo levemente tingido, ícone cheio e
    // leve elevação no hover) — pedido do usuário 2026-10-06 pra dashboard
    // não ficar "tudo preto no branco". Fora dela o card continua neutro.
    vivid: { type: Boolean, default: false },
});

const VARIANTS = {
    primary: { bg: 'bg-lightprimary', text: 'text-primary', color: 'var(--color-primary)' },
    secondary: { bg: 'bg-lightsecondary', text: 'text-secondary', color: 'var(--color-secondary)' },
    success: { bg: 'bg-lightsuccess', text: 'text-success', color: 'var(--color-success)' },
    warning: { bg: 'bg-lightwarning', text: 'text-warning', color: 'var(--color-warning)' },
    error: { bg: 'bg-lighterror', text: 'text-error', color: 'var(--color-error)' },
    info: { bg: 'bg-lightinfo', text: 'text-info', color: 'var(--color-info)' },
};

const style = computed(() => VARIANTS[props.variant] ?? VARIANTS.primary);

const vividStyle = computed(() => (props.vivid
    ? {
        borderTop: `3px solid ${style.value.color}`,
        background: `linear-gradient(135deg, color-mix(in oklab, ${style.value.color} 10%, var(--surface)) 0%, var(--surface) 70%)`,
    }
    : {}));
</script>

<template>
    <div class="relative flex min-w-0 flex-col break-words rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm transition duration-200 hover:shadow-md"
        :class="vivid ? 'hover:-translate-y-0.5' : ''"
        :style="vividStyle">
        <div class="flex items-center gap-4">
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full"
                :class="vivid ? 'text-white shadow-sm' : [style.bg, style.text]"
                :style="vivid ? { background: style.color } : {}">
                <i :class="statIconName" class="text-xl"></i>
            </div>
            <div class="min-w-0">
                <p class="truncate text-2xl font-bold">{{ statTitle }}</p>
                <p class="text-sm text-slate-500 dark:text-slate-400" :class="vivid ? 'line-clamp-2 leading-tight' : 'truncate'">{{ statSubtitle }}</p>
            </div>
        </div>
    </div>
</template>
