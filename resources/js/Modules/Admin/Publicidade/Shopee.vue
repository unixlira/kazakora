<script setup>
/**
 * Publicidade › Estratégia Shopee (2026-10-14): campanhas de ADS da Shopee
 * com o resultado em reais e uma situação para decidir de olho. Fase 1, só
 * leitura: os botões só abrem a Shopee, nada muda por aqui. Ver
 * PublicidadeShopeeController e EstrategiaShopeeAds.
 */
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    estrategia: { type: Object, required: true },
    filtro: { type: Object, required: true },
    periodos: { type: Array, default: () => [] },
    saldo: { type: [Number, null], default: null },
});

const dinheiro = (v) => (v === null || v === undefined ? '—' : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v));
const numero = (v) => new Intl.NumberFormat('pt-BR').format(v ?? 0);
const um = (v) => (v === null || v === undefined ? '—' : v.toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }));
const pct = (v) => (v === null || v === undefined ? '—' : `${um(v)}%`);
const data = (d) => new Date(`${d}T00:00:00`).toLocaleDateString('pt-BR');
const dataHora = (d) => (d ? new Date(d.replace(' ', 'T')).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : 'nunca');

const SITUACAO = {
    queimando: { cor: 'bg-lighterror text-error', ponto: 'bg-error' },
    prejuizo: { cor: 'bg-lighterror text-error', ponto: 'bg-error' },
    sem_margem: { cor: 'bg-slate-800 text-white dark:bg-slate-200 dark:text-slate-900', ponto: 'bg-slate-800 dark:bg-slate-200' },
    margem_fina: { cor: 'bg-lightwarning text-warning', ponto: 'bg-warning' },
    saudavel: { cor: 'bg-lightsuccess text-success', ponto: 'bg-success' },
    escalar: { cor: 'bg-lightinfo text-info', ponto: 'bg-info' },
    pouco_dado: { cor: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300', ponto: 'bg-slate-400' },
    sem_custo: { cor: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300', ponto: 'bg-slate-400' },
};
const CAUSA = { capa: 'Capa/título', pagina: 'Página/preço', lance: 'Lance', margem: 'Margem' };
const TIPO = { automatica: 'Automática', meta_roas: 'Meta de ROAS', manual: 'Manual' };
const TENDENCIA = {
    subindo: { icone: 'fas fa-arrow-trend-up text-success', texto: 'ROAS subindo nos últimos 7 dias' },
    caindo: { icone: 'fas fa-arrow-trend-down text-error', texto: 'ROAS caindo nos últimos 7 dias' },
    estavel: { icone: 'fas fa-arrow-right text-slate-400', texto: 'ROAS estável nos últimos 7 dias' },
};

const k = computed(() => props.estrategia.kpis);
const campanhas = computed(() => props.estrategia.campanhas);

// Filtros (na tela; o período vai para o servidor).
const situacoes = ref([]);
const status = ref('ativas');
const tipo = ref('');
const causa = ref('');
const gastoMinimo = ref(10);
const busca = ref('');
const estoqueBaixo = ref(false);
const ordem = ref('resultado');

const filtradas = computed(() => {
    const termo = busca.value.trim().toLowerCase();
    const lista = campanhas.value.filter((c) => {
        if (situacoes.value.length && !situacoes.value.includes(c.situacao)) return false;
        if (status.value === 'ativas' && !c.ativa) return false;
        if (status.value === 'paradas' && c.ativa) return false;
        if (tipo.value && c.tipo !== tipo.value) return false;
        if (causa.value && c.causa !== causa.value) return false;
        if (c.gasto < (Number(gastoMinimo.value) || 0)) return false;
        if (estoqueBaixo.value && !(c.dias_estoque !== null && c.dias_estoque < 15)) return false;
        if (termo && ![c.nome, c.sku, c.item_id].some((v) => String(v ?? '').toLowerCase().includes(termo))) return false;
        return true;
    });
    const chave = {
        resultado: (c) => c.resultado ?? 0,
        gasto: (c) => -c.gasto,
        roas: (c) => (c.roas !== null && c.roas_empate ? c.roas / c.roas_empate : 999),
        conversao: (c) => c.conversao ?? 999,
    }[ordem.value];
    return [...lista].sort((a, b) => chave(a) - chave(b));
});

// Contador de cada situação já com os outros filtros aplicados.
const contagem = computed(() => {
    const base = campanhas.value.filter((c) => (status.value === 'todas' || (status.value === 'ativas') === c.ativa) && c.gasto >= (Number(gastoMinimo.value) || 0));
    return Object.fromEntries(Object.keys(props.estrategia.situacoes).map((s) => [s, base.filter((c) => c.situacao === s).length]));
});

const alternar = (s) => {
    situacoes.value = situacoes.value.includes(s) ? situacoes.value.filter((x) => x !== s) : [...situacoes.value, s];
};
const limpar = () => {
    situacoes.value = [];
    status.value = 'ativas';
    tipo.value = '';
    causa.value = '';
    gastoMinimo.value = 10;
    busca.value = '';
    estoqueBaixo.value = false;
};

const desde = ref(props.filtro.desde);
const ate = ref(props.filtro.ate);
const irPeriodo = (dias) => router.get('/admin/publicidade/shopee', { dias }, { preserveScroll: true });
const irIntervalo = () => desde.value && ate.value && router.get('/admin/publicidade/shopee', { desde: desde.value, ate: ate.value }, { preserveScroll: true });

const corResultado = (v) => (v === null ? 'text-slate-400' : v < 0 ? 'text-error' : 'text-success');
const abaixo = (c) => c.roas !== null && c.roas_empate && c.roas < c.roas_empate;
</script>

<template>
    <Head title="Estratégia Shopee" />

    <AdminLayout>
        <div class="mb-2 flex flex-wrap items-center gap-3">
            <h1 class="flex items-center gap-3 text-2xl font-bold">
                <i class="fas fa-chess-knight" style="color: #EE4D2D" aria-hidden="true"></i>
                Estratégia Shopee
            </h1>
            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600 dark:bg-slate-800 dark:text-slate-300">Só leitura</span>
            <div class="ml-auto flex flex-wrap items-center gap-2" role="group" aria-label="Período">
                <button
                    v-for="d in periodos"
                    :key="d"
                    type="button"
                    class="rounded-lg border px-3 py-1.5 text-sm font-medium focus-visible:outline-2 focus-visible:outline-primary"
                    :class="filtro.dias === d ? 'border-primary bg-primary text-white' : 'border-[var(--surface-border)] hover:border-primary hover:text-primary'"
                    :aria-pressed="filtro.dias === d"
                    @click="irPeriodo(d)"
                >{{ d }} dias</button>
                <label class="sr-only" for="shp-desde">De</label>
                <input id="shp-desde" v-model="desde" type="date" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1 text-sm" />
                <label class="sr-only" for="shp-ate">Até</label>
                <input id="shp-ate" v-model="ate" type="date" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1 text-sm" />
                <button type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm font-medium hover:border-primary hover:text-primary" @click="irIntervalo">Ver</button>
            </div>
        </div>
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
            {{ data(estrategia.desde) }} a {{ data(estrategia.ate) }}. Atualizado em {{ dataHora(estrategia.sincronizado_em) }}.
            Resultado do ADS = venda atribuída × margem antes do ADS − gasto. ROAS de empate e meta (15% de lucro) pela
            mesma conta do <Link href="/admin/dashboard-financeiro" class="font-medium text-primary hover:underline">Resultado e margem</Link>.
        </p>

        <div
            v-if="!estrategia.conferencia.confere"
            class="mb-4 rounded-lg border border-warning/40 bg-lightwarning px-4 py-2 text-sm text-warning"
            role="status"
        >
            A soma das campanhas ({{ dinheiro(estrategia.conferencia.gasto_campanhas) }}) difere
            {{ um(estrategia.conferencia.diferenca_pct) }}% do gasto total da Shopee Ads no período
            ({{ dinheiro(estrategia.conferencia.gasto_loja) }}). Pode haver campanha de loja fora desta lista ou sincronização atrasada.
        </div>

        <div v-if="!campanhas.length" class="mb-6 rounded-xl border border-dashed border-[var(--surface-border)] bg-[var(--surface)] p-6 text-sm text-slate-500 dark:text-slate-400">
            Nenhuma campanha sincronizada ainda. O comando <code>shopee:ads-campanhas</code> roda de hora em hora.
        </div>

        <template v-else>
            <div class="mb-6 grid grid-cols-2 gap-4 md:grid-cols-3">
                <CardStats compact stat-subtitle="GASTO" :stat-title="dinheiro(k.gasto)" stat-icon-name="fas fa-bullhorn" variant="warning" />
                <CardStats compact stat-subtitle="VENDA ATRIBUÍDA" :stat-title="dinheiro(k.venda)" stat-icon-name="fas fa-cart-shopping" variant="primary" />
                <CardStats compact stat-subtitle="ROAS" :stat-title="um(k.roas)" :stat-note="`meta da loja ${um(k.roas_meta_loja)}`" stat-icon-name="fas fa-arrow-trend-up" :variant="(k.roas ?? 0) >= (k.roas_meta_loja ?? 0) ? 'success' : 'error'" />
                <CardStats compact vivid stat-subtitle="PREJUÍZO DO ADS" :stat-title="dinheiro(k.prejuizo)" :stat-note="`${k.em_prejuizo} de ${k.com_gasto} campanhas com gasto`" stat-icon-name="fas fa-triangle-exclamation" variant="error" />
                <CardStats compact stat-subtitle="GASTO SEM VENDA" :stat-title="dinheiro(k.gasto_sem_venda)" stat-icon-name="fas fa-fire" variant="error" />
                <CardStats compact stat-subtitle="SALDO DE ADS" :stat-title="dinheiro(saldo)" :stat-note="`${k.ativas} campanhas ativas`" stat-icon-name="fas fa-wallet" variant="info" />
            </div>

            <section v-if="estrategia.decidir.length" class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-labelledby="decidir-titulo">
                <h2 id="decidir-titulo" class="mb-3 text-base font-semibold">Decida hoje: as que mais tiram dinheiro</h2>
                <ol class="space-y-2">
                    <li v-for="c in estrategia.decidir" :key="c.campaign_id" class="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-lg border border-[var(--surface-border)] px-3 py-2">
                        <span class="inline-flex items-center gap-2 rounded-full px-2 py-0.5 text-xs font-bold" :class="SITUACAO[c.situacao].cor">
                            <span class="h-2 w-2 rounded-full" :class="SITUACAO[c.situacao].ponto" aria-hidden="true"></span>{{ estrategia.situacoes[c.situacao] }}
                        </span>
                        <span class="min-w-0 flex-1 truncate font-semibold" :title="c.nome">{{ c.nome }}</span>
                        <span class="text-sm tabular-nums">ROAS {{ um(c.roas) }} <span class="text-slate-500">/ empate {{ um(c.roas_empate) }}</span></span>
                        <span class="text-sm font-bold tabular-nums text-error">{{ dinheiro(c.resultado) }}</span>
                        <span class="basis-full text-sm text-slate-600 dark:text-slate-300">{{ c.sugestao }}</span>
                    </li>
                </ol>
            </section>

            <section class="mb-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-label="Filtros">
                <div class="mb-3 flex flex-wrap gap-2" role="group" aria-label="Situação">
                    <button
                        v-for="(rotulo, s) in estrategia.situacoes"
                        :key="s"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold focus-visible:outline-2 focus-visible:outline-primary"
                        :class="situacoes.includes(s) ? 'border-primary bg-lightprimary text-primary' : 'border-[var(--surface-border)]'"
                        :aria-pressed="situacoes.includes(s)"
                        @click="alternar(s)"
                    >
                        <span class="h-2 w-2 rounded-full" :class="SITUACAO[s].ponto" aria-hidden="true"></span>
                        {{ rotulo }} <span class="tabular-nums text-slate-500">{{ contagem[s] }}</span>
                    </button>
                </div>
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-8">
                    <label class="text-xs font-semibold text-slate-500 xl:col-span-2">Buscar
                        <input v-model="busca" type="search" placeholder="Nome, SKU ou item" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm font-normal" />
                    </label>
                    <label class="text-xs font-semibold text-slate-500">Status
                        <select v-model="status" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm font-normal">
                            <option value="ativas">Ativas</option>
                            <option value="paradas">Pausadas/encerradas</option>
                            <option value="todas">Todas</option>
                        </select>
                    </label>
                    <label class="text-xs font-semibold text-slate-500">Tipo
                        <select v-model="tipo" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm font-normal">
                            <option value="">Todos</option>
                            <option v-for="(r, t) in TIPO" :key="t" :value="t">{{ r }}</option>
                        </select>
                    </label>
                    <label class="text-xs font-semibold text-slate-500">Causa
                        <select v-model="causa" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm font-normal">
                            <option value="">Todas</option>
                            <option v-for="(r, t) in CAUSA" :key="t" :value="t">{{ r }}</option>
                        </select>
                    </label>
                    <label class="text-xs font-semibold text-slate-500">Gasto mínimo (R$)
                        <input v-model="gastoMinimo" type="number" min="0" step="1" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm font-normal" />
                    </label>
                    <label class="text-xs font-semibold text-slate-500">Ordenar por
                        <select v-model="ordem" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm font-normal">
                            <option value="resultado">Maior prejuízo</option>
                            <option value="gasto">Maior gasto</option>
                            <option value="roas">ROAS ÷ empate</option>
                            <option value="conversao">Menor conversão</option>
                        </select>
                    </label>
                    <div class="flex items-end gap-3">
                        <label class="inline-flex items-center gap-2 pb-2 text-sm"><input v-model="estoqueBaixo" type="checkbox" /> Estoque &lt; 15 dias</label>
                    </div>
                </div>
                <p class="mt-3 text-xs text-slate-500">
                    {{ filtradas.length }} de {{ campanhas.length }} campanhas.
                    <button type="button" class="font-medium text-primary hover:underline" @click="limpar">Limpar filtros</button>
                </p>
            </section>

            <section class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                <th scope="col" class="px-3 py-2 font-semibold">Campanha</th>
                                <th scope="col" class="px-3 py-2 font-semibold">Situação</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">Resultado</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">Gasto</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">Venda</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">ROAS</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">Empate</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">Meta 15%</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">Meta na Shopee</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">CTR</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">Conversão</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">CPC</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">Pedidos</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">Estoque</th>
                                <th scope="col" class="px-3 py-2 text-center font-semibold">7 dias</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in filtradas" :key="c.campaign_id" class="border-t border-[var(--surface-border)] align-top">
                                <td class="max-w-xs px-3 py-2">
                                    <a v-if="c.link" :href="c.link" target="_blank" rel="noopener noreferrer" class="line-clamp-2 font-semibold hover:text-primary hover:underline" :title="c.nome">
                                        {{ c.nome }}<span class="sr-only"> (abre a Shopee em outra aba)</span>
                                    </a>
                                    <span v-else class="line-clamp-2 font-semibold">{{ c.nome }}</span>
                                    <span class="block text-xs text-slate-500">
                                        {{ TIPO[c.tipo] }}<template v-if="c.orcamento"> · {{ dinheiro(c.orcamento) }}/dia</template><template v-if="!c.ativa"> · {{ c.status }}</template><template v-if="c.sku"> · {{ c.sku }}</template>
                                    </span>
                                    <span class="mt-1 block text-xs text-slate-600 dark:text-slate-300">{{ c.sugestao }}</span>
                                </td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-bold" :class="SITUACAO[c.situacao].cor">
                                        <span class="h-2 w-2 rounded-full" :class="SITUACAO[c.situacao].ponto" aria-hidden="true"></span>{{ estrategia.situacoes[c.situacao] }}
                                    </span>
                                    <span v-if="c.causa" class="mt-1 block text-xs text-slate-500">{{ CAUSA[c.causa] }}</span>
                                </td>
                                <td class="px-3 py-2 text-right font-bold tabular-nums" :class="corResultado(c.resultado)">
                                    {{ dinheiro(c.resultado) }}
                                    <span v-if="c.estimado && c.resultado !== null" class="block text-xs font-normal text-slate-500" title="Sem venda no período: margem estimada com a taxa média da Shopee">estimado</span>
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ dinheiro(c.gasto) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ dinheiro(c.venda) }}
                                    <span class="block text-xs text-slate-500" title="Venda só do produto anunciado">direta {{ dinheiro(c.venda_direta) }}</span>
                                </td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums" :class="abaixo(c) ? 'text-error' : ''">{{ um(c.roas) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ um(c.roas_empate) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ um(c.roas_meta) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums" :class="c.meta_configurada && c.roas_meta && c.meta_configurada < c.roas_meta ? 'text-warning font-semibold' : ''">{{ um(c.meta_configurada) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums" :class="c.ctr !== null && c.impressoes >= 500 && c.ctr < 2 ? 'text-error' : ''">{{ pct(c.ctr) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums" :class="c.conversao !== null && c.cliques >= 100 && c.conversao < 1 ? 'text-error' : ''">
                                    {{ pct(c.conversao) }}
                                    <span class="block text-xs text-slate-500">{{ numero(c.cliques) }} cliques</span>
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ dinheiro(c.cpc) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ numero(c.pedidos) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ c.estoque === null ? '—' : numero(c.estoque) }}
                                    <span v-if="c.dias_estoque !== null" class="block text-xs" :class="c.dias_estoque < 15 ? 'text-error' : 'text-slate-500'">{{ c.dias_estoque }} dias</span>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <i v-if="c.tendencia" :class="TENDENCIA[c.tendencia].icone" :title="TENDENCIA[c.tendencia].texto" aria-hidden="true"></i>
                                    <span v-if="c.tendencia" class="sr-only">{{ TENDENCIA[c.tendencia].texto }}</span>
                                    <span v-else class="text-slate-400">—</span>
                                </td>
                            </tr>
                            <tr v-if="!filtradas.length">
                                <td colspan="15" class="px-3 py-6 text-center text-slate-500">Nenhuma campanha com esses filtros.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>
    </AdminLayout>
</template>
