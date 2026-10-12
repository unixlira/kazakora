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
    // Celular em 2 colunas (2026-10-11): abaixo de sm o ícone vai para cima
    // do valor e tudo encolhe um pouco, para caber em ~170px de largura.
    compact: { type: Boolean, default: false },
});

const VARIANTS = {
    primary: { bg: 'bg-lightprimary', text: 'text-primary', color: 'var(--primary-solido, var(--color-primary))' },
    secondary: { bg: 'bg-lightsecondary', text: 'text-secondary', color: 'var(--color-secondary)' },
    success: { bg: 'bg-lightsuccess', text: 'text-success', color: 'var(--color-success)' },
    warning: { bg: 'bg-lightwarning', text: 'text-warning', color: 'var(--color-warning)' },
    error: { bg: 'bg-lighterror', text: 'text-error', color: 'var(--color-error)' },
    info: { bg: 'bg-lightinfo', text: 'text-info', color: 'var(--color-info)' },
};

const style = computed(() => VARIANTS[props.variant] ?? VARIANTS.primary);

const vividStyle = computed(() => (props.vivid
    ? {
        borderTop: '3px solid var(--faixa-card)',
        background: `linear-gradient(135deg, color-mix(in oklab, ${style.value.color} 10%, var(--surface)) 0%, var(--surface) 70%)`,
    }
    : {}));
</script>

<template>
    <div class="relative flex min-w-0 flex-col break-words rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm transition duration-200 hover:shadow-md"
        :class="[vivid ? 'hover:-translate-y-0.5' : '', compact ? 'p-3 sm:p-4' : 'p-4']"
        :style="vividStyle">
        <div class="flex gap-4" :class="compact ? 'flex-col items-start gap-2 sm:flex-row sm:items-center sm:gap-4' : 'items-center'">
            <div class="flex shrink-0 items-center justify-center rounded-full"
                :class="[vivid ? 'text-white shadow-sm' : [style.bg, style.text], compact ? 'h-9 w-9 sm:h-12 sm:w-12' : 'h-12 w-12']"
                :style="vivid ? { background: style.color } : {}">
                <i :class="[statIconName, compact ? 'text-base sm:text-xl' : 'text-xl']"></i>
            </div>
            <div class="min-w-0 max-w-full">
                <p class="truncate font-bold" :class="compact ? 'text-lg sm:text-2xl' : 'text-2xl'">{{ statTitle }}</p>
                <p class="text-slate-500 dark:text-slate-400" :class="[vivid ? 'line-clamp-2 leading-tight' : 'truncate', compact ? 'text-xs sm:text-sm' : 'text-sm']">{{ statSubtitle }}</p>
            </div>
        </div>
    </div>
</template>
