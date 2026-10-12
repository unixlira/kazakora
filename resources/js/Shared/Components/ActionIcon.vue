<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    icon: {
        type: String,
        required: true,
    },
    label: {
        type: String,
        required: true,
    },
    color: {
        type: String,
        default: 'slate',
    },
    href: {
        type: String,
        default: null,
    },
});

defineEmits(['click']);

// Same alert palette as StatusBadge.vue (bg-{color}-100/text-{color}-700,
// dark variants included) so action icons and status pills read as one
// consistent color system across the admin.
// Tema grafite (2026-10-11): o "blue" (ver, editar) deixou de ser azul e
// virou a ação principal: grafite no claro, laranja no escuro. Selos de
// status continuam com as cores de estado. Ação destrutiva (red) só com
// contorno, nunca botão cheio, para não se confundir com o laranja.
const ACAO_PRINCIPAL = 'acao-principal bg-[#2b2326]/10 text-[#2b2326] hover:bg-[#2b2326] hover:text-white';
const COLOR_CLASSES = {
    slate: 'bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300',
    blue: ACAO_PRINCIPAL,
    primary: ACAO_PRINCIPAL,
    red: 'acao-destrutiva border border-red-300 bg-transparent text-red-700 hover:bg-red-50',
    amber: 'bg-yellow-100 text-yellow-700 hover:bg-yellow-200 dark:bg-yellow-900/50 dark:text-yellow-300',
    violet: 'bg-purple-100 text-purple-700 hover:bg-purple-200 dark:bg-purple-900/50 dark:text-purple-300',
};
</script>

<template>
    <Link v-if="href" :href="href" :title="label" :aria-label="label"
        class="inline-flex h-8 w-8 items-center justify-center rounded-full transition-colors"
        :class="COLOR_CLASSES[color] ?? COLOR_CLASSES.slate">
        <i class="fas text-sm" :class="icon"></i>
    </Link>
    <button v-else type="button" :title="label" :aria-label="label"
        class="inline-flex h-8 w-8 items-center justify-center rounded-full transition-colors"
        :class="COLOR_CLASSES[color] ?? COLOR_CLASSES.slate"
        @click="$emit('click')">
        <i class="fas text-sm" :class="icon"></i>
    </button>
</template>
