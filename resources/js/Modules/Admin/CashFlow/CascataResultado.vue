<script setup>
/**
 * Bloco 3: da receita ao lucro, em cascata (barras flutuantes). Totais no
 * acento (grafite no claro, laranja no escuro), descontos em cinza de
 * contexto, valor escrito em cima de cada barra. Tudo de periodo().
 */
import ChartCanvas from '@/Shared/Components/ChartCanvas.vue';
import { formatCompact, formatPrice, useTemaEscuro, valorNasBarras } from '@/Shared/graficos';
import { computed, ref } from 'vue';

const props = defineProps({
    conta: { type: Object, required: true },
});

const { isDark, chrome, tooltip, axisStyle } = useTemaEscuro();
const verTabela = ref(false);

const passos = computed(() => {
    const c = props.conta;
    const lista = [];
    let nivel = 0;
    const total = (rotulo, valor) => { lista.push({ rotulo, valor, de: 0, ate: valor, tipo: 'total' }); nivel = valor; };
    const menos = (rotulo, valor) => { lista.push({ rotulo, valor: -valor, de: nivel, ate: nivel - valor, tipo: 'desconto' }); nivel -= valor; };
    const mais = (rotulo, valor) => { lista.push({ rotulo, valor, de: nivel, ate: nivel + valor, tipo: 'acrescimo' }); nivel += valor; };
    total('Receita', c.receita);
    menos('Produtos', c.custo_produtos);
    menos('Taxas', c.taxas);
    menos('Frete', c.frete);
    menos('ADS', c.ads);
    mais('Bônus Flex', c.bonificacoes);
    total('Margem', c.margem);
    menos('Imposto', c.imposto);
    total('Lucro', c.lucro);
    return lista;
});

const corPasso = (p) => {
    if (p.tipo === 'total') return p.valor < 0 ? (isDark.value ? '#f87171' : '#b91c1c') : chrome.value.accent;
    if (p.tipo === 'acrescimo') return isDark.value ? '#34d399' : '#047857';
    return chrome.value.context;
};

const dados = computed(() => ({
    labels: passos.value.map((p) => p.rotulo),
    datasets: [{
        label: 'Valor',
        data: passos.value.map((p) => [p.de, p.ate]),
        backgroundColor: passos.value.map(corPasso),
        borderRadius: 4,
        borderSkipped: false,
        maxBarThickness: 56,
    }],
}));

const opcoes = computed(() => ({
    animation: { duration: 450 },
    layout: { padding: { top: 22 } },
    scales: {
        x: { ...axisStyle.value, grid: { display: false } },
        y: { ...axisStyle.value, beginAtZero: true, ticks: { ...axisStyle.value.ticks, callback: (v) => formatCompact(v) } },
    },
    plugins: {
        legend: { display: false },
        tooltip: { ...tooltip.value, callbacks: { label: (ctx) => ` ${formatPrice(passos.value[ctx.dataIndex].valor)}` } },
    },
}));

const plugins = computed(() => [valorNasBarras((_, i) => formatCompact(passos.value[i].valor), () => chrome.value.text, passos.value.map((p) => p.valor))]);
const descricao = computed(() => passos.value.map((p) => `${p.rotulo} ${formatPrice(p.valor)}`).join(', '));
</script>

<template>
    <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-labelledby="fc-cascata">
        <div class="mb-2 flex flex-wrap items-start justify-between gap-2">
            <div>
                <h2 id="fc-cascata" class="font-semibold">Para onde foi o dinheiro</h2>
                <p class="text-xs text-[var(--texto-suave)]">Da receita ao lucro, no período</p>
            </div>
            <button type="button" class="text-xs font-semibold text-[var(--destaque)] hover:underline" :aria-expanded="verTabela" @click="verTabela = !verTabela">
                {{ verTabela ? 'Ver gráfico' : 'Ver em tabela' }}
            </button>
        </div>
        <table v-if="verTabela" class="w-full text-sm tabular-nums">
            <tbody>
                <tr v-for="p in passos" :key="p.rotulo" class="border-b border-[var(--surface-border)]" :class="p.tipo === 'total' ? 'font-semibold' : ''">
                    <th scope="row" class="py-1.5 text-left font-medium">{{ p.rotulo }}</th>
                    <td class="py-1.5 text-right">{{ formatPrice(p.valor) }}</td>
                </tr>
            </tbody>
        </table>
        <ChartCanvas v-else type="bar" :data="dados" :options="opcoes" :plugins="plugins" height-class="h-72" :label="`Cascata do resultado: ${descricao}`" />
    </section>
</template>
