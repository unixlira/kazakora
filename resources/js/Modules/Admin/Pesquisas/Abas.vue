<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { usePermissions } from '@/Shared/usePermissions';

// Abas do menu Pesquisas: as três plataformas e a Curva ABC por canal.
const props = defineProps({
    ativa: { type: String, required: true },
    contagem: { type: Object, default: () => ({}) },
});

const { can } = usePermissions();

const abas = computed(() => [
    { valor: 'mercado_livre', rotulo: 'Mercado Livre', href: '/admin/pesquisas?aba=mercado_livre', icone: 'fas fa-handshake' },
    { valor: 'shopee', rotulo: 'Shopee', href: '/admin/pesquisas?aba=shopee', icone: 'fas fa-bag-shopping' },
    { valor: 'outras', rotulo: 'Outras plataformas', href: '/admin/pesquisas?aba=outras', icone: 'fas fa-layer-group' },
    ...(can('financeiro.view')
        ? [{ valor: 'curva-abc', rotulo: 'Curva ABC', href: '/admin/pesquisas/curva-abc', icone: 'fas fa-ranking-star' }]
        : []),
]);
</script>

<template>
    <nav class="mb-5 flex gap-1 overflow-x-auto border-b border-[var(--surface-border)]" aria-label="Seções de pesquisas">
        <Link v-for="aba in abas" :key="aba.valor" :href="aba.href"
            class="-mb-px inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            :class="props.ativa === aba.valor
                ? 'border-primary text-primary'
                : 'border-transparent text-slate-500 hover:border-[var(--surface-border)] hover:text-slate-700'"
            :aria-current="props.ativa === aba.valor ? 'page' : undefined">
            <i :class="aba.icone" class="text-xs" aria-hidden="true"></i>
            {{ aba.rotulo }}
            <span v-if="aba.valor !== 'curva-abc' && props.contagem[aba.valor] !== undefined"
                class="rounded-full bg-slate-100 px-2 py-0.5 text-xs tabular-nums text-slate-600">
                {{ props.contagem[aba.valor] }}
            </span>
        </Link>
    </nav>
</template>
