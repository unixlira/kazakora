<script setup>
/**
 * Cabeçalho do painel de um grupo (Lira, 17/11/2026): "de um lado só os
 * marketplaces, do outro meus e-commerces, nunca somar os dois". Mostra o
 * nome do grupo, os canais que entram na conta e as seções do painel
 * (Resultado, Indicadores, Mês a mês). Usado pelo Resultado (CashFlow) e
 * pelos Indicadores.
 */
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { usePermissions } from '@/Shared/usePermissions';

const props = defineProps({
    grupo: { type: String, required: true }, // 'marketplaces' | 'ecommerces'
    secao: { type: String, required: true }, // 'resultado' | 'indicadores'
});

const { can } = usePermissions();

const GRUPOS = {
    marketplaces: { nome: 'Marketplaces', canais: 'Mercado Livre, Shopee, TikTok Shop, Amazon e Shein', icone: 'fas fa-store', abaMes: 'plataformas' },
    ecommerces: { nome: 'E-commerces', canais: 'KazaKora, FerraKora e Beauty Kora', icone: 'fas fa-cart-shopping', abaMes: 'ecommerces' },
};
const g = computed(() => GRUPOS[props.grupo] ?? GRUPOS.marketplaces);
const outro = computed(() => (props.grupo === 'marketplaces' ? 'ecommerces' : 'marketplaces'));

const secoes = computed(() => [
    { chave: 'resultado', rotulo: 'Resultado', href: `/admin/painel/${props.grupo}/resultado`, permissao: 'financeiro.view' },
    { chave: 'indicadores', rotulo: 'Indicadores', href: `/admin/painel/${props.grupo}/indicadores`, permissao: 'relatorios.view' },
    { chave: 'mes', rotulo: 'Mês a mês', href: `/admin/financeiro/plataformas-e-ecommerces?aba=${g.value.abaMes}`, permissao: 'financeiro.view' },
].filter((s) => can(s.permissao)));
</script>

<template>
    <div class="mb-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="min-w-0">
                <h1 class="flex items-center gap-2 text-2xl font-bold">
                    <i :class="g.icone" class="text-lg text-[var(--destaque)]" aria-hidden="true"></i>{{ g.nome }}
                </h1>
                <p class="text-sm text-pretty text-[var(--texto-suave)]">Só {{ g.canais }}. Nenhum número desta tela soma o outro grupo.</p>
            </div>
            <div class="flex items-center gap-2">
                <slot name="acoes" />
                <Link :href="`/admin/painel/${outro}/${secao}`"
                    class="inline-flex items-center gap-2 rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm font-semibold hover:bg-[var(--surface-muted)]">
                    Ver {{ GRUPOS[outro].nome }} <i class="fas fa-arrow-right text-xs" aria-hidden="true"></i>
                </Link>
            </div>
        </div>
        <nav class="mt-3 flex gap-2 overflow-x-auto" :aria-label="`Seções de ${g.nome}`">
            <Link v-for="s in secoes" :key="s.chave" :href="s.href"
                class="shrink-0 rounded-full px-4 py-1.5 text-sm font-semibold whitespace-nowrap transition-colors"
                :class="secao === s.chave ? 'bg-primary text-white' : 'bg-[var(--surface-muted)] text-[var(--texto-suave)] hover:text-[var(--surface-foreground)]'"
                :aria-current="secao === s.chave ? 'page' : undefined">{{ s.rotulo }}</Link>
        </nav>
    </div>
</template>
