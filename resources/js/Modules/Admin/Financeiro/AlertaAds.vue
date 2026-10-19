<script setup>
/**
 * Alerta de ADS acima do limite (6% da receita do canal, no mês ou nos
 * últimos 7 dias), pedido do Lira 2026-10-19. Regra no servidor
 * (App\Modules\Financeiro\Support\RadarDeAds, a mesma do vigia do Homer).
 * Aparece no Fluxo de Caixa e no Resultado e margem só quando algum canal
 * passou do limite.
 */
import { Link } from '@inertiajs/vue3';
import { formatPercent, formatPrice } from '@/Shared/graficos';
import { computed } from 'vue';

const props = defineProps({
    radar: { type: Object, default: null },
    // Mostra a observação da Meta quando o gasto dela não veio.
    mostrarMeta: { type: Boolean, default: false },
});

const acima = computed(() => props.radar?.acima ?? []);
const limite = computed(() => (props.radar?.limite ?? 6).toLocaleString('pt-BR'));
const metaIndisponivel = computed(() => props.radar && props.radar.meta && !props.radar.meta.disponivel);

const periodo = (p) => {
    if (!p || p.ads <= 0) return { pct: 'sem ADS', detalhe: '' };
    if (p.pct === null) return { pct: 'sem venda', detalhe: `ADS ${formatPrice(p.ads)} sem nenhuma venda` };
    return { pct: formatPercent(p.pct), detalhe: `${formatPrice(p.ads)} de ${formatPrice(p.receita)}` };
};
</script>

<template>
    <section v-if="acima.length || (mostrarMeta && metaIndisponivel)" class="mb-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-4 py-3 shadow-sm"
        :style="acima.length ? { borderLeft: '4px solid var(--color-error)' } : {}" aria-label="Alerta de ADS">
        <template v-if="acima.length">
            <h2 class="text-sm font-semibold">
                <i class="fas fa-bullhorn me-1 text-error" aria-hidden="true"></i>
                ADS acima de {{ limite }}% da receita
            </h2>
            <p class="mb-2 text-xs text-[var(--texto-suave)]">Gasto com anúncio dividido pela receita do canal, no mês até hoje e nos últimos 7 dias. Conta oficial do lucro.</p>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[560px] text-sm tabular-nums">
                    <thead>
                        <tr class="border-b border-[var(--surface-border)] text-[11px] text-[var(--texto-suave)]">
                            <th scope="col" class="py-1.5 pr-2 text-left font-semibold">Canal</th>
                            <th scope="col" class="px-2 py-1.5 text-right font-semibold">No mês</th>
                            <th scope="col" class="px-2 py-1.5 text-right font-semibold">Últimos 7 dias</th>
                            <th scope="col" class="py-1.5 pl-2 text-right font-semibold"><span class="sr-only">Ação</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in acima" :key="c.canal" class="border-b border-[var(--surface-muted)] last:border-0">
                            <th scope="row" class="py-1.5 pr-2 text-left font-medium whitespace-nowrap">{{ c.nome }}</th>
                            <td class="px-2 py-1.5 text-right">
                                <span class="font-semibold" :class="c.mes.acima ? 'text-error' : ''">{{ periodo(c.mes).pct }}</span>
                                <span class="block text-[11px] text-[var(--texto-suave)]">{{ periodo(c.mes).detalhe }}</span>
                            </td>
                            <td class="px-2 py-1.5 text-right">
                                <span class="font-semibold" :class="c.semana.acima ? 'text-error' : ''">{{ periodo(c.semana).pct }}</span>
                                <span class="block text-[11px] text-[var(--texto-suave)]">{{ periodo(c.semana).detalhe }}</span>
                            </td>
                            <td class="py-1.5 pl-2 text-right whitespace-nowrap">
                                <Link :href="c.link.href" class="text-sm font-semibold text-[var(--destaque)] hover:underline">{{ c.link.rotulo }}</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
        <p v-if="mostrarMeta && metaIndisponivel" class="text-xs text-[var(--texto-suave)]" :class="acima.length ? 'mt-2' : ''">
            Meta Ads: gasto não disponível. {{ radar.meta.motivo }}
        </p>
    </section>
</template>
