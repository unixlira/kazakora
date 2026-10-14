<script setup>
/**
 * Indicadores (2026-10-14): as métricas mais usadas no e-commerce por
 * canal, com alerta quando algo piora (queda de ROAS, CPC/CPA alto, CTR
 * baixo, margem, cancelamento, estoque). Últimos N dias completos contra os
 * N anteriores; regras e limites em PainelDeAlertas e
 * docs/indicadores-e-alertas.md. Os 6 cards do mês ficam no fim.
 */
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    painel: { type: Object, required: true },
    kpis: { type: Object, required: true },
});

const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
const um = (v) => v.toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
const data = (d) => new Date(`${d}T00:00:00`).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });

const valor = (m, v) => {
    if (v === null || v === undefined) return '—';
    if (m.formato === 'moeda') return formatPrice(v);
    if (m.formato === 'pct') return `${um(v)}%`;
    if (m.formato === 'vezes') return um(v);
    return new Intl.NumberFormat('pt-BR').format(v);
};

// Seta verde quando a variação vai para o lado bom do indicador.
const variacao = (m) => {
    if (m.variacao === null || m.variacao === undefined) return null;
    const subiu = m.variacao > 0;
    const bom = m.bom === 'neutro' ? null : (m.bom === 'alto') === subiu;
    return {
        texto: `${subiu ? '+' : ''}${um(m.variacao)}%`,
        icone: m.variacao === 0 ? 'fas fa-arrow-right' : (subiu ? 'fas fa-arrow-up' : 'fas fa-arrow-down'),
        cor: bom === null || m.variacao === 0 ? 'text-slate-500 dark:text-slate-400' : (bom ? 'text-success' : 'text-error'),
    };
};

const NIVEL = {
    critico: { rotulo: 'Crítico', cor: 'bg-lighterror text-error', borda: 'border-l-error', icone: 'fas fa-circle-exclamation text-error' },
    atencao: { rotulo: 'Atenção', cor: 'bg-lightwarning text-warning', borda: 'border-l-warning', icone: 'fas fa-triangle-exclamation text-warning' },
};

const GRUPO = { vendas: 'Vendas', lucro: 'Lucro', ads: 'ADS', site: 'Site', operacao: 'Operação', estoque: 'Estoque' };

const resumo = computed(() => props.painel.resumo);
const periodo = computed(() => `${data(props.painel.desde)} a ${data(props.painel.ate)} contra ${data(props.painel.anterior_desde)} a ${data(props.painel.anterior_ate)}`);

const trocar = (dias) => router.get('/admin/indicadores', { dias }, { preserveScroll: true, preserveState: true });
</script>

<template>
    <Head title="Indicadores" />

    <AdminLayout>
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold">Indicadores e alertas</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Últimos {{ painel.dias }} dias completos ({{ periodo }}). Hoje fica de fora para não acusar queda falsa.</p>
            </div>
            <div class="flex gap-1 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-1" role="group" aria-label="Período">
                <button v-for="d in [7, 14, 30]" :key="d" type="button" class="rounded-md px-3 py-1 text-sm font-medium"
                    :class="painel.dias === d ? 'bg-primary text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'"
                    :aria-pressed="painel.dias === d" @click="trocar(d)">{{ d }} dias</button>
            </div>
        </div>

        <!-- Alertas -->
        <section class="mb-8" aria-labelledby="titulo-alertas">
            <h2 id="titulo-alertas" class="mb-3 flex flex-wrap items-center gap-2 text-lg font-semibold">
                O que precisa de atenção
                <span v-if="resumo.critico" class="rounded-full bg-lighterror px-2 py-0.5 text-xs font-bold text-error">{{ resumo.critico }} crítico{{ resumo.critico > 1 ? 's' : '' }}</span>
                <span v-if="resumo.atencao" class="rounded-full bg-lightwarning px-2 py-0.5 text-xs font-bold text-warning">{{ resumo.atencao }} de atenção</span>
            </h2>

            <p v-if="!painel.alertas.length" class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 text-sm">
                <i class="fas fa-circle-check mr-1 text-success"></i> Nenhum indicador fora do limite no período.
            </p>

            <ul v-else class="space-y-2">
                <li v-for="(a, i) in painel.alertas" :key="i"
                    class="flex gap-3 rounded-xl border border-l-4 border-[var(--surface-border)] bg-[var(--surface)] p-3 shadow-sm" :class="NIVEL[a.nivel].borda">
                    <i class="mt-0.5 shrink-0" :class="NIVEL[a.nivel].icone" aria-hidden="true"></i>
                    <div class="min-w-0 flex-1">
                        <div class="mb-1 flex flex-wrap items-center gap-2 text-xs">
                            <span class="rounded px-1.5 py-0.5 font-semibold" :class="NIVEL[a.nivel].cor">{{ NIVEL[a.nivel].rotulo }}</span>
                            <span class="font-semibold text-slate-600 dark:text-slate-300">{{ GRUPO[a.grupo] ?? a.grupo }} · {{ a.indicador }}</span>
                        </div>
                        <p class="text-sm font-medium">{{ a.texto }}</p>
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ a.acao }}</p>
                    </div>
                    <Link v-if="a.link" :href="a.link" class="shrink-0 self-center text-sm font-medium text-primary hover:underline">Abrir</Link>
                </li>
            </ul>
        </section>

        <!-- Indicadores por canal -->
        <section class="mb-8" aria-labelledby="titulo-canais">
            <h2 id="titulo-canais" class="mb-3 text-lg font-semibold">Indicadores por canal</h2>
            <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <div v-for="c in painel.canais" :key="c.canal" class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
                    <h3 class="border-b border-[var(--surface-border)] px-4 py-2 font-semibold">{{ c.nome }}</h3>
                    <table class="w-full text-sm">
                        <thead class="sr-only">
                            <tr><th>Indicador</th><th>Período</th><th>Anterior</th><th>Variação</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="m in c.metricas" :key="m.chave" class="border-b border-[var(--surface-border)] last:border-0">
                                <td class="px-4 py-1.5">
                                    <span class="inline-flex items-center gap-1.5">
                                        <span v-if="m.nivel" class="h-2 w-2 rounded-full" :class="m.nivel === 'critico' ? 'bg-error' : 'bg-warning'" :title="NIVEL[m.nivel].rotulo"></span>
                                        {{ m.nome }}
                                    </span>
                                    <span v-if="m.referencia" class="block text-xs text-slate-500 dark:text-slate-400">{{ m.referencia }}</span>
                                </td>
                                <td class="px-2 py-1.5 text-right font-semibold tabular-nums" :class="m.nivel === 'critico' ? 'text-error' : ''">{{ valor(m, m.valor) }}</td>
                                <td class="px-2 py-1.5 text-right tabular-nums text-slate-500 dark:text-slate-400">{{ valor(m, m.anterior) }}</td>
                                <td class="w-24 px-4 py-1.5 text-right tabular-nums">
                                    <span v-if="variacao(m)" :class="variacao(m).cor"><i :class="variacao(m).icone" class="mr-0.5 text-xs" aria-hidden="true"></i>{{ variacao(m).texto }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                Margem = conta oficial (receita − custo − taxas − frete − ADS + bônus Flex). ROAS = venda atribuída ÷ gasto; ACOS = gasto ÷ venda do ADS; TACOS = gasto ÷ faturamento total do canal; CTR = cliques ÷ impressões; CPC = gasto ÷ cliques; CPA = gasto ÷ pedidos do ADS.
            </p>
        </section>

        <!-- Mês corrente (cards de antes) -->
        <section aria-labelledby="titulo-mes">
            <h2 id="titulo-mes" class="mb-3 text-lg font-semibold">Mês corrente</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <CardStats stat-subtitle="TICKET MÉDIO" :stat-title="formatPrice(kpis.averageTicket)" stat-icon-name="fas fa-receipt" variant="primary" />
                <CardStats stat-subtitle="TAXA DE CONVERSÃO" :stat-title="`${kpis.conversionRate}%`" stat-icon-name="fas fa-bullseye" variant="success" />
                <CardStats stat-subtitle="TAXA DE CANCELAMENTO" :stat-title="`${kpis.cancellationRate}%`" stat-icon-name="fas fa-ban" variant="error" />
                <CardStats stat-subtitle="ESTOQUE BAIXO" :stat-title="`${kpis.lowStockRate}%`" stat-icon-name="fas fa-triangle-exclamation" variant="warning" />
                <CardStats stat-subtitle="VISITANTES ÚNICOS (MÊS)" :stat-title="String(kpis.uniqueVisitorsMonth)" stat-icon-name="fas fa-eye" variant="info" />
                <CardStats stat-subtitle="PEDIDOS NO MÊS" :stat-title="String(kpis.ordersMonth)" stat-icon-name="fas fa-cart-shopping" variant="secondary" />
            </div>
        </section>
    </AdminLayout>
</template>
