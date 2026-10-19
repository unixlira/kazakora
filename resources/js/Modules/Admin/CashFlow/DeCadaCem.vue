<script setup>
/**
 * Bloco 3 (lado): "De cada R$ 100 vendidos". A cascata virada numa frase
 * ("de cada 100, sobram X"). HTML puro. Frete aqui é o frete pago pela loja
 * menos o bônus do Flex, para as partes somarem a receita.
 */
import { formatPrice } from '@/Shared/graficos';
import { computed } from 'vue';

const props = defineProps({
    conta: { type: Object, required: true },
});

const reais = (v) => (v ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const partes = computed(() => {
    const c = props.conta;
    const r = c.receita;
    if (!r || r <= 0) return [];
    return [
        { rotulo: 'Produtos', valor: c.custo_produtos, cor: '#3c4044', corEscuro: '#8a8f95' },
        { rotulo: 'Taxas', valor: c.taxas, cor: '#6b5f63', corEscuro: '#a99fa2' },
        { rotulo: 'ADS', valor: c.ads, cor: '#9a8f92', corEscuro: '#c9c1c3' },
        { rotulo: 'Imposto', valor: c.imposto, cor: '#b3aaad', corEscuro: '#7a6f73' },
        { rotulo: 'Frete', valor: c.frete - c.bonificacoes, cor: '#d6cfd1', corEscuro: '#5b5458' },
        { rotulo: 'Lucro', valor: c.lucro, cor: '#a3324f', corEscuro: '#fd7b41', destaque: true },
    ].map((p) => ({ ...p, cem: (p.valor / r) * 100 }));
});
const positivas = computed(() => partes.value.filter((p) => p.cem > 0));
const somaPositivas = computed(() => positivas.value.reduce((s, p) => s + p.cem, 0));
const lucro = computed(() => partes.value.find((p) => p.rotulo === 'Lucro'));
</script>

<template>
    <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-labelledby="fc-cem">
        <h2 id="fc-cem" class="font-semibold">De cada R$ 100 vendidos</h2>
        <template v-if="partes.length">
            <p class="text-xs text-[var(--texto-suave)]">
                <template v-if="lucro.cem >= 0">sobram <strong class="tabular-nums text-[var(--surface-foreground)]">R$ {{ reais(lucro.cem) }}</strong> de lucro.</template>
                <template v-else>a loja perde <strong class="tabular-nums text-error">R$ {{ reais(-lucro.cem) }}</strong>.</template>
            </p>
            <div class="my-3 flex h-7 gap-0.5 overflow-hidden rounded-md" aria-hidden="true">
                <span v-for="p in positivas" :key="p.rotulo" class="parte block h-full" :style="{ width: `${(p.cem / somaPositivas) * 100}%`, '--cor': p.cor, '--cor-escuro': p.corEscuro }"></span>
            </div>
            <ul class="grid gap-1.5 text-sm">
                <li v-for="p in partes" :key="p.rotulo" class="grid grid-cols-[12px_1fr_auto_auto] items-center gap-2">
                    <span class="parte h-3 w-3 rounded-sm" :style="{ '--cor': p.cor, '--cor-escuro': p.corEscuro }" aria-hidden="true"></span>
                    <span :class="p.destaque ? 'font-semibold' : ''">{{ p.rotulo }}</span>
                    <span class="min-w-[64px] text-right font-bold tabular-nums" :class="p.cem < 0 ? 'text-error' : ''">R$ {{ reais(p.cem) }}</span>
                    <span class="min-w-[92px] text-right text-xs tabular-nums text-[var(--texto-suave)]">{{ formatPrice(p.valor) }}</span>
                </li>
            </ul>
        </template>
        <p v-else class="mt-4 text-sm text-[var(--texto-suave)]">Nenhuma venda paga neste período.</p>
    </section>
</template>

<style scoped>
.parte { background: var(--cor); }
:global(.dark) .parte { background: var(--cor-escuro); }
</style>
