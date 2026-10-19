<script setup>
/**
 * Bloco 4: qual canal dá lucro. Barras horizontais do lucro (cor fixa do
 * canal, valor escrito ao lado; prejuízo vai para a esquerda do zero) e a
 * tabela de porCanal() embaixo, com linha de total.
 */
import ChartCanvas from '@/Shared/Components/ChartCanvas.vue';
import { formatCompact, formatPercent, formatPrice, useTemaEscuro, valorNasBarras } from '@/Shared/graficos';
import { computed } from 'vue';

const props = defineProps({
    canais: { type: Array, default: () => [] },
});

const { chrome, channelColor, tooltip, axisStyle } = useTemaEscuro();

const comVenda = computed(() => props.canais.filter((c) => c.receita !== 0 || c.lucro !== 0));
const dados = computed(() => ({
    labels: comVenda.value.map((c) => c.nome),
    datasets: [{
        label: 'Lucro',
        data: comVenda.value.map((c) => c.lucro),
        backgroundColor: comVenda.value.map((c) => channelColor(c.canal)),
        borderRadius: 4,
        borderSkipped: false,
        maxBarThickness: 26,
    }],
}));
const opcoes = computed(() => ({
    animation: { duration: 450 },
    indexAxis: 'y',
    layout: { padding: { right: 84, left: 8 } },
    scales: {
        x: { ...axisStyle.value, ticks: { ...axisStyle.value.ticks, callback: (v) => formatCompact(v), maxTicksLimit: 5 } },
        y: { ...axisStyle.value, grid: { display: false } },
    },
    plugins: {
        legend: { display: false },
        tooltip: { ...tooltip.value, callbacks: { label: (ctx) => ` Lucro ${formatPrice(ctx.parsed.x)}` } },
    },
}));
const plugins = computed(() => [valorNasBarras((v) => formatPrice(v), () => chrome.value.text)]);

const total = computed(() => props.canais.reduce((s, c) => {
    ['pedidos', 'receita', 'custo_produtos', 'taxas', 'frete', 'ads', 'imposto', 'lucro', 'bonificacoes'].forEach((k) => { s[k] = (s[k] ?? 0) + (c[k] ?? 0); });
    return s;
}, {}));
const pctReceita = (c) => (c.receita > 0 ? formatPercent((c.lucro / c.receita) * 100) : '—');
const descricao = computed(() => comVenda.value.map((c) => `${c.nome} ${formatPrice(c.lucro)}`).join(', '));
</script>

<template>
    <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-labelledby="fc-canal">
        <h2 id="fc-canal" class="font-semibold">Qual canal dá lucro</h2>
        <p class="mb-2 text-xs text-[var(--texto-suave)]">Lucro líquido de cada canal no período, do maior para o menor.</p>
        <ChartCanvas v-if="comVenda.length" type="bar" :data="dados" :options="opcoes" :plugins="plugins"
            :height-class="comVenda.length > 4 ? 'h-60' : 'h-48'" :label="`Lucro por canal: ${descricao}`" />
        <p v-else class="py-8 text-center text-sm text-[var(--texto-suave)]">Nenhuma venda paga neste período.</p>

        <div class="tabela-rolagem mt-3 overflow-x-auto">
            <table class="w-full min-w-[640px] text-sm tabular-nums">
                <caption class="sr-only">Resultado por canal</caption>
                <thead>
                    <tr class="border-b border-[var(--surface-border)] text-[11px] text-[var(--texto-suave)]">
                        <th scope="col" class="coluna-fixa py-1.5 pr-2 text-left font-semibold">Canal</th>
                        <th scope="col" class="px-2 py-1.5 text-right font-semibold">Pedidos</th>
                        <th scope="col" class="px-2 py-1.5 text-right font-semibold">Receita</th>
                        <th scope="col" class="px-2 py-1.5 text-right font-semibold">Produtos</th>
                        <th scope="col" class="px-2 py-1.5 text-right font-semibold">Taxas</th>
                        <th scope="col" class="hidden px-2 py-1.5 text-right font-semibold sm:table-cell">Frete</th>
                        <th scope="col" class="px-2 py-1.5 text-right font-semibold">ADS</th>
                        <th scope="col" class="hidden px-2 py-1.5 text-right font-semibold sm:table-cell">Imposto</th>
                        <th scope="col" class="px-2 py-1.5 text-right font-semibold">Lucro</th>
                        <th scope="col" class="px-2 py-1.5 text-right font-semibold">% da receita</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in canais" :key="c.canal" class="border-b border-[var(--surface-muted)]">
                        <th scope="row" class="coluna-fixa py-1.5 pr-2 text-left font-medium whitespace-nowrap">
                            <span class="inline-flex items-center gap-1.5"><i class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: channelColor(c.canal) }" aria-hidden="true"></i>{{ c.nome }}</span>
                        </th>
                        <template v-if="c.receita === 0 && c.pedidos === 0 && c.ads === 0">
                            <td colspan="9" class="px-2 py-1.5 text-left text-[var(--texto-suave)]">sem vendas</td>
                        </template>
                        <template v-else>
                            <td class="px-2 py-1.5 text-right">{{ c.pedidos }}</td>
                            <td class="px-2 py-1.5 text-right">{{ formatPrice(c.receita) }}</td>
                            <td class="px-2 py-1.5 text-right">{{ formatPrice(c.custo_produtos) }}</td>
                            <td class="px-2 py-1.5 text-right whitespace-nowrap">
                                {{ formatPrice(c.taxas) }}
                                <span v-if="c.taxa_estimada > 0" class="estimada block text-[10px] font-semibold" :title="`${formatPrice(c.taxa_estimada)} ainda estimados`">estimada</span>
                            </td>
                            <td class="hidden px-2 py-1.5 text-right sm:table-cell">{{ formatPrice(c.frete - c.bonificacoes) }}</td>
                            <td class="px-2 py-1.5 text-right">{{ formatPrice(c.ads) }}</td>
                            <td class="hidden px-2 py-1.5 text-right sm:table-cell">{{ formatPrice(c.imposto) }}</td>
                            <td class="px-2 py-1.5 text-right font-semibold" :class="c.lucro < 0 ? 'text-error' : ''">{{ formatPrice(c.lucro) }}</td>
                            <td class="px-2 py-1.5 text-right">{{ pctReceita(c) }}</td>
                        </template>
                    </tr>
                </tbody>
                <tfoot v-if="canais.length > 1">
                    <tr class="border-t border-[var(--surface-border)] font-bold">
                        <th scope="row" class="coluna-fixa py-1.5 pr-2 text-left">Total</th>
                        <td class="px-2 py-1.5 text-right">{{ total.pedidos }}</td>
                        <td class="px-2 py-1.5 text-right">{{ formatPrice(total.receita) }}</td>
                        <td class="px-2 py-1.5 text-right">{{ formatPrice(total.custo_produtos) }}</td>
                        <td class="px-2 py-1.5 text-right">{{ formatPrice(total.taxas) }}</td>
                        <td class="hidden px-2 py-1.5 text-right sm:table-cell">{{ formatPrice((total.frete ?? 0) - (total.bonificacoes ?? 0)) }}</td>
                        <td class="px-2 py-1.5 text-right">{{ formatPrice(total.ads) }}</td>
                        <td class="hidden px-2 py-1.5 text-right sm:table-cell">{{ formatPrice(total.imposto) }}</td>
                        <td class="px-2 py-1.5 text-right" :class="total.lucro < 0 ? 'text-error' : ''">{{ formatPrice(total.lucro) }}</td>
                        <td class="px-2 py-1.5 text-right">{{ pctReceita(total) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p class="mt-2 text-[11px] text-[var(--texto-suave)]">Frete = frete pago pela loja menos o bônus do Flex.</p>
    </section>
</template>

<style scoped>
.estimada { color: #b45309; }
:global(.dark) .estimada { color: #fbbf24; }
.coluna-fixa { position: sticky; left: 0; background: var(--surface); z-index: 1; }
</style>
