<script setup>
/**
 * Publicidade › Shopee › Estratégia (tarefas 568/569, 2026-10-14). Lista os
 * anúncios de ADS da Shopee que dão prejuízo, com a decisão do Cauê (ver
 * EstrategiaShopee). Só leitura: o botão "Ação" que aplica a decisão vem na
 * fase 2.
 */
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    dados: { type: [Object, null], default: null },
    erro: { type: [String, null], default: null },
    dias: { type: Number, default: 30 },
    periodos: { type: Array, default: () => [7, 15, 30] },
});

const ABAS = [
    { chave: 'prejuizo', titulo: 'Com prejuízo' },
    { chave: 'conversao_baixa', titulo: 'Lucro, mas conversão baixa' },
    { chave: 'ok', titulo: 'Dando lucro' },
];
const DECISOES = {
    PAUSAR: { texto: 'Pausar', classe: 'bg-lighterror text-error' },
    META: { texto: 'Meta de ROAS', classe: 'bg-lightwarning text-warning' },
    PRECO: { texto: 'Preço', classe: 'bg-lightprimary text-primary' },
    CAPA: { texto: 'Capa', classe: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200' },
};

const aba = ref('prejuizo');
const soAtivos = ref(false);
const atualizando = ref(false);

const dinheiro = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v ?? 0);
const decimal = (v, casas = 1) => (v === null || v === undefined ? '—' : Number(v).toLocaleString('pt-BR', { minimumFractionDigits: casas, maximumFractionDigits: casas }));
const pct = (v) => (v === null || v === undefined ? '—' : `${decimal(v, 2)}%`);
const data = (d) => new Date(`${d}T00:00:00`).toLocaleDateString('pt-BR');
const hora = (d) => new Date(d).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });

const linhas = computed(() => (props.dados?.linhas ?? [])
    .filter((l) => l.grupo === aba.value)
    .filter((l) => !soAtivos.value || l.ativo));
const contagem = (chave) => (props.dados?.linhas ?? []).filter((l) => l.grupo === chave).length;

const trocarPeriodo = (dias) => router.get('/admin/publicidade/shopee/estrategia', { dias }, { preserveState: false });
const atualizar = () => router.post('/admin/publicidade/shopee/estrategia/atualizar', { dias: props.dias }, {
    onStart: () => { atualizando.value = true; },
    onFinish: () => { atualizando.value = false; },
});
</script>

<template>
    <Head title="Estratégia Shopee" />

    <AdminLayout>
        <div class="mb-2 flex flex-wrap items-center gap-3">
            <h1 class="flex items-center gap-3 text-2xl font-bold">
                <i class="fas fa-chess text-[#ee4d2d]" aria-hidden="true"></i>
                Estratégia Shopee
            </h1>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <div class="inline-flex overflow-hidden rounded-lg border border-[var(--surface-border)]" role="group" aria-label="Período">
                    <button
                        v-for="p in periodos"
                        :key="p"
                        type="button"
                        class="px-3 py-1.5 text-sm font-medium"
                        :class="p === dias ? 'bg-primary text-white' : 'hover:text-primary'"
                        :aria-pressed="p === dias"
                        @click="trocarPeriodo(p)"
                    >{{ p }} dias</button>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm font-medium hover:border-primary hover:text-primary disabled:opacity-60"
                    :disabled="atualizando"
                    @click="atualizar"
                >
                    <i class="fas fa-rotate text-xs" :class="{ 'fa-spin': atualizando }" aria-hidden="true"></i>
                    {{ atualizando ? 'Consultando a Shopee…' : 'Atualizar agora' }}
                </button>
            </div>
        </div>
        <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">
            Campanhas de ADS por produto. Meta de ROAS sugerida = empate + 2; conversão do anúncio (pedidos ÷ cliques) mínima de 1,65%.
            O empate sai da margem real do KazaKora (custo, taxa e imposto). Nada é alterado na Shopee por esta tela.
            <template v-if="dados">
                Período {{ data(dados.desde) }} a {{ data(dados.ate) }}, consultado em {{ hora(dados.gerado_em) }}.
            </template>
        </p>

        <div v-if="erro" class="mb-6 rounded-xl border border-error bg-lighterror p-4 text-sm text-error" role="alert">{{ erro }}</div>

        <template v-if="dados">
            <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <CardStats stat-subtitle="GASTO EM ADS" :stat-title="dinheiro(dados.kpis.gasto)" stat-icon-name="fas fa-bullhorn" variant="warning" />
                <CardStats stat-subtitle="ROAS PAGO" :stat-title="decimal(dados.kpis.roas, 2)" stat-icon-name="fas fa-arrow-trend-up" variant="primary" />
                <CardStats stat-subtitle="CONVERSÃO DOS ANÚNCIOS (META 1,65%)" :stat-title="pct(dados.kpis.conversao)" stat-icon-name="fas fa-percent" :variant="dados.kpis.conversao !== null && dados.kpis.conversao < 1.65 ? 'error' : 'success'" />
                <CardStats :stat-subtitle="`${dados.kpis.com_prejuizo} DE ${dados.kpis.campanhas} COM PREJUÍZO`" :stat-title="dinheiro(dados.kpis.prejuizo)" stat-icon-name="fas fa-triangle-exclamation" variant="error" />
            </div>

            <div class="mb-3 flex flex-wrap items-center gap-2">
                <div class="flex flex-wrap gap-2" role="tablist">
                    <button
                        v-for="a in ABAS"
                        :key="a.chave"
                        type="button"
                        role="tab"
                        :aria-selected="aba === a.chave"
                        class="rounded-full px-3 py-1 text-sm font-medium"
                        :class="aba === a.chave ? 'bg-primary text-white' : 'bg-slate-100 text-slate-700 hover:text-primary dark:bg-slate-800 dark:text-slate-200'"
                        @click="aba = a.chave"
                    >{{ a.titulo }} ({{ contagem(a.chave) }})</button>
                </div>
                <label class="ml-auto inline-flex items-center gap-2 text-sm">
                    <input v-model="soAtivos" type="checkbox" class="rounded" />
                    Só campanhas ativas
                </label>
            </div>

            <section class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                <th scope="col" class="px-4 py-2 font-semibold">Produto</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">Gasto</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">Vendas ADS</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">ROAS real</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">Empate</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">Meta sugerida</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">Meta hoje</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">Conversão</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">CTR</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">Resultado*</th>
                                <th scope="col" class="px-4 py-2 font-semibold">Decisão do Cauê</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!linhas.length">
                                <td colspan="11" class="px-4 py-6 text-center text-slate-500 dark:text-slate-400">Nenhuma campanha nesta lista.</td>
                            </tr>
                            <tr v-for="l in linhas" :key="l.item_id" class="border-t border-[var(--surface-border)] align-middle">
                                <td class="px-4 py-2">
                                    <div class="flex min-w-[16rem] items-center gap-3">
                                        <img v-if="l.imagem" :src="l.imagem" :alt="l.nome" class="h-14 w-14 shrink-0 rounded-lg border border-[var(--surface-border)] object-cover" loading="lazy" />
                                        <div v-else class="flex h-14 w-14 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-400 dark:bg-slate-800" aria-hidden="true"><i class="fas fa-image"></i></div>
                                        <div class="min-w-0">
                                            <a :href="l.link" target="_blank" rel="noopener noreferrer" class="line-clamp-2 font-semibold hover:text-primary">{{ l.nome }}</a>
                                            <span class="block text-xs text-slate-500 dark:text-slate-400">
                                                <Link v-if="l.produto_id" :href="`/admin/produtos/${l.produto_id}/editar`" class="hover:text-primary">#{{ l.produto_id }}</Link>
                                                · item {{ l.item_id }} · {{ l.ativo ? 'ativa' : (l.status || 'parada') }}
                                            </span>
                                            <span v-if="l.sem_custo" class="block text-xs font-semibold text-warning">Sem custo cadastrado</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ dinheiro(l.gasto) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ dinheiro(l.vendas) }}
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ l.pedidos }} ped.</span>
                                </td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums" :class="l.roas_empate === null || l.roas < l.roas_empate ? 'text-error' : 'text-success'">{{ decimal(l.roas, 2) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ l.roas_empate === null ? 'sem margem' : decimal(l.roas_empate) }}</td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ decimal(l.meta_sugerida) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums" :class="{ 'text-warning font-semibold': l.meta_atual !== null && l.meta_sugerida !== null && l.meta_atual < l.meta_sugerida }">{{ l.meta_atual === null ? 'manual' : decimal(l.meta_atual) }}</td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums" :class="l.conversao === null || l.conversao < 1.65 ? 'text-error' : 'text-success'">{{ pct(l.conversao) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums" :class="{ 'text-error': l.impressoes >= 500 && l.ctr < 2 }">{{ pct(l.ctr) }}</td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums" :class="l.resultado < 0 ? 'text-error' : 'text-success'">{{ dinheiro(l.resultado) }}</td>
                                <td class="px-4 py-2">
                                    <div class="flex flex-wrap gap-1">
                                        <span v-for="d in l.decisoes" :key="d" class="rounded-full px-2 py-0.5 text-xs font-bold" :class="DECISOES[d].classe">{{ DECISOES[d].texto }}</span>
                                        <span v-if="!l.decisoes.length" class="text-xs text-slate-500 dark:text-slate-400">Manter</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <p class="text-xs text-slate-500 dark:text-slate-400">
                * Resultado estimado = vendas do ADS × margem antes do ADS − gasto. "Meta hoje: manual" = campanha sem meta de ROAS (lance manual).
                Empate do KazaKora pode diferir do Seller Bump quando o custo cadastrado for diferente.
            </p>
        </template>
    </AdminLayout>
</template>
