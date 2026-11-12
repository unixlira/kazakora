<script setup>
/**
 * Campanhas da Meta por loja (pedido do Lira em 2026-11-11): KazaKora,
 * FerraKora ou as duas, com as métricas do período e os anúncios de cada
 * campanha (na FerraKora, os vídeos UGC p1..p10). Lido ao vivo da Meta
 * (CampanhasMetaPorLoja, guardado 5 minutos). Só leitura.
 */
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { formatarNumero, formatarReais, statusMeta } from '@/Modules/Admin/MetaAds/metaAds';

const props = defineProps({
    // { lojas: {chave: nome}, loja, periodo, periodos: {preset: rótulo} }
    config: { type: Object, required: true },
    // Lista por loja: { loja, nome, conta, erro, totais, campanhas, ocultas }
    dados: { type: Array, default: () => [] },
});

const decimal = (v) => (v === null || v === undefined ? '-' : Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
const reais = (v) => (v === null || v === undefined ? '-' : formatarReais(v));
const roas = (v) => (v === null || v === undefined ? '-' : `${decimal(v)}x`);
const pct = (v) => (v === null || v === undefined ? '-' : `${decimal(v)}%`);

const abas = computed(() => {
    const lojas = Object.entries(props.config.lojas ?? {}).map(([chave, nome]) => ({ chave, nome }));
    return lojas.length > 1 ? [...lojas, { chave: 'ambas', nome: 'Ambas' }] : lojas;
});
const ambas = computed(() => props.config.loja === 'ambas');

const carregando = ref(false);
const trocar = (mudanca) => {
    const loja = mudanca.loja ?? props.config.loja;
    const periodo = mudanca.periodo ?? props.config.periodo;
    if (loja === props.config.loja && periodo === props.config.periodo) return;

    const url = new URL(window.location.href);
    url.searchParams.set('loja', loja);
    url.searchParams.set('periodo_lojas', periodo);
    router.get(url.pathname, Object.fromEntries(url.searchParams), {
        only: ['lojasMeta', 'campanhasLojas'],
        preserveScroll: true,
        preserveState: true,
        replace: true,
        onStart: () => { carregando.value = true; },
        onFinish: () => { carregando.value = false; },
    });
};

// Campanhas de todas as lojas escolhidas numa tabela só (com a loja em "Ambas").
// "Mostrar todas" (Lira 2026-11-12): inclui as paradas e sem gasto no período.
const todas = ref(false);
const totalOcultas = computed(() => props.dados.reduce((soma, l) => soma + (l.ocultas || 0), 0));
const linhas = computed(() => props.dados.flatMap((l) => l.campanhas
    .filter((c) => todas.value || !c.oculta)
    .map((c) => ({ ...c, loja: l.nome, chave: `${l.loja}-${c.id}` }))));
const abertas = ref({});
const alternar = (chave) => { abertas.value = { ...abertas.value, [chave]: !abertas.value[chave] }; };

const CARTOES = [
    ['gasto', 'Gasto', reais],
    ['compras', 'Compras', formatarNumero],
    ['vendas', 'Valor de compras', reais],
    ['roas', 'ROAS', roas],
    ['custo_compra', 'Custo por compra', reais],
];
const DETALHES = [
    ['impressoes', 'Impressões', formatarNumero],
    ['alcance', 'Alcance', formatarNumero],
    ['cliques_link', 'Cliques no link', formatarNumero],
    ['ctr_link', 'CTR', pct],
    ['cpc_link', 'CPC', reais],
    ['cpm', 'CPM', reais],
    ['carrinho', 'Carrinho', formatarNumero],
    ['checkout', 'Checkout', formatarNumero],
];
const COLUNAS = [
    ['gasto', 'Gasto', reais],
    ['impressoes', 'Impressões', formatarNumero],
    ['alcance', 'Alcance', formatarNumero],
    ['cliques_link', 'Cliques no link', formatarNumero],
    ['ctr_link', 'CTR', pct],
    ['cpc_link', 'CPC', reais],
    ['cpm', 'CPM', reais],
    ['carrinho', 'Carrinho', formatarNumero],
    ['checkout', 'Checkout', formatarNumero],
    ['compras', 'Compras', formatarNumero],
    ['vendas', 'Valor de compras', reais],
    ['roas', 'ROAS', roas],
    ['custo_compra', 'Custo/compra', reais],
];
</script>

<template>
    <section class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm" aria-labelledby="lojas-meta-titulo">
        <div class="flex flex-wrap items-center gap-3 border-b border-[var(--surface-border)] px-4 py-3">
            <div class="min-w-0 flex-1">
                <h2 id="lojas-meta-titulo" class="text-base font-semibold">Campanhas por loja</h2>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                    Lido na Meta (guardado 5 minutos). Cliques, CTR e CPC são do clique no link. ROAS = valor de compras ÷ gasto, atribuído pela Meta.
                </p>
            </div>
            <div v-if="abas.length > 1" class="inline-flex rounded-lg border border-[var(--surface-border)] p-0.5" role="group" aria-label="Loja">
                <button
                    v-for="a in abas" :key="a.chave" type="button"
                    class="rounded-md px-3 py-1 text-sm font-medium"
                    :class="config.loja === a.chave ? 'bg-primary text-white' : 'text-slate-600 hover:text-primary dark:text-slate-300'"
                    :aria-pressed="config.loja === a.chave"
                    @click="trocar({ loja: a.chave })"
                >{{ a.nome }}</button>
            </div>
            <button
                type="button"
                class="rounded-lg border px-3 py-1 text-sm font-medium"
                :class="todas ? 'border-primary bg-primary text-white' : 'border-[var(--surface-border)] text-slate-600 hover:text-primary dark:text-slate-300'"
                :aria-pressed="todas"
                @click="todas = !todas"
            >{{ todas ? 'Só ativas e com gasto' : `Mostrar todas${totalOcultas ? ` (+${totalOcultas})` : ''}` }}</button>
            <div class="inline-flex flex-wrap rounded-lg border border-[var(--surface-border)] p-0.5" role="group" aria-label="Período">
                <button
                    v-for="(rotulo, chave) in config.periodos" :key="chave" type="button"
                    class="rounded-md px-3 py-1 text-sm font-medium"
                    :class="config.periodo === chave ? 'bg-primary text-white' : 'text-slate-600 hover:text-primary dark:text-slate-300'"
                    :aria-pressed="config.periodo === chave"
                    @click="trocar({ periodo: chave })"
                >{{ rotulo }}</button>
            </div>
        </div>

        <div class="p-4 transition-opacity" :class="carregando ? 'pointer-events-none opacity-50' : ''" :aria-busy="carregando">
            <p v-if="!abas.length" class="text-sm text-slate-500">Nenhuma conta de anúncios configurada.</p>

            <!-- Cartões: totais da conta inteira de cada loja (inclui campanha excluída). -->
            <div class="grid gap-4" :class="dados.length > 1 ? 'lg:grid-cols-2' : ''">
                <div v-for="l in dados" :key="l.loja" class="rounded-lg border border-[var(--surface-border)] p-3">
                    <div class="mb-2 flex items-baseline justify-between gap-2">
                        <h3 class="font-semibold">{{ l.nome }}</h3>
                        <span class="text-xs text-slate-500">{{ l.conta }}</span>
                    </div>
                    <p v-if="l.erro" class="rounded-md bg-amber-500/10 p-2 text-sm text-amber-800 dark:text-amber-300" role="alert">
                        Não deu para ler a Meta desta loja agora: {{ l.erro }}
                    </p>
                    <template v-else-if="l.totais">
                        <dl class="grid grid-cols-2 gap-2 sm:grid-cols-5">
                            <div v-for="[campo, rotulo, fmt] in CARTOES" :key="campo" class="rounded-md bg-slate-500/5 px-2 py-1.5">
                                <dt class="text-[11px] uppercase tracking-wide text-slate-500">{{ rotulo }}</dt>
                                <dd class="font-semibold tabular-nums">{{ fmt(l.totais[campo]) }}</dd>
                            </div>
                        </dl>
                        <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-4">
                            <div v-for="[campo, rotulo, fmt] in DETALHES" :key="campo" class="flex justify-between gap-2">
                                <dt class="text-slate-500">{{ rotulo }}</dt>
                                <dd class="tabular-nums">{{ fmt(l.totais[campo]) }}</dd>
                            </div>
                        </dl>
                    </template>
                </div>
            </div>
        </div>

        <div v-if="linhas.length" class="overflow-x-auto border-t border-[var(--surface-border)]">
            <table class="w-full text-sm" :class="carregando ? 'opacity-50' : ''">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        <th scope="col" class="px-3 py-2 font-semibold">Campanha</th>
                        <th scope="col" class="px-3 py-2 font-semibold">Status</th>
                        <th scope="col" class="whitespace-nowrap px-3 py-2 text-right font-semibold">Orçamento/dia</th>
                        <th v-for="[campo, rotulo] in COLUNAS" :key="campo" scope="col" class="whitespace-nowrap px-3 py-2 text-right font-semibold">{{ rotulo }}</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="c in linhas" :key="c.chave">
                        <tr class="border-t border-[var(--surface-border)] align-top">
                            <td class="min-w-[16rem] px-3 py-2">
                                <button type="button" class="flex items-start gap-2 text-left" :aria-expanded="!!abertas[c.chave]" @click="alternar(c.chave)">
                                    <i class="fas mt-1 text-[10px] text-slate-400" :class="abertas[c.chave] ? 'fa-chevron-down' : 'fa-chevron-right'" aria-hidden="true"></i>
                                    <span>
                                        <span class="font-semibold">{{ c.nome }}</span>
                                        <span class="block text-xs text-slate-500">
                                            <template v-if="ambas">{{ c.loja }} · </template>{{ c.anuncios.length }} anúncio(s)
                                        </span>
                                    </span>
                                </button>
                                <a v-if="c.gerenciador" :href="c.gerenciador" target="_blank" rel="noopener noreferrer" class="ml-5 text-xs text-primary hover:underline">
                                    Abrir no Gerenciador <span class="sr-only">(abre em outra aba)</span>
                                </a>
                            </td>
                            <td class="px-3 py-2">
                                <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium" :class="statusMeta(c.status)[1]">{{ statusMeta(c.status)[0] }}</span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                                {{ reais(c.orcamento_diario) }}
                                <span v-if="c.orcamento_nivel === 'conjunto'" class="block text-[11px] text-slate-500">nos conjuntos</span>
                            </td>
                            <td v-for="[campo, , fmt] in COLUNAS" :key="campo" class="whitespace-nowrap px-3 py-2 text-right tabular-nums" :class="campo === 'gasto' || campo === 'roas' ? 'font-semibold' : ''">{{ fmt(c[campo]) }}</td>
                        </tr>
                        <template v-if="abertas[c.chave]">
                            <tr v-for="a in c.anuncios" :key="a.id" class="border-t border-dashed border-[var(--surface-border)] bg-slate-500/5 text-xs">
                                <td class="py-1.5 pl-8 pr-3">
                                    <span class="font-medium">{{ a.nome }}</span>
                                    <span class="block text-slate-500">{{ a.conjunto }}</span>
                                </td>
                                <td class="px-3 py-1.5">
                                    <span class="whitespace-nowrap rounded-full px-2 py-0.5 font-medium" :class="statusMeta(a.status)[1]">{{ statusMeta(a.status)[0] }}</span>
                                </td>
                                <td class="px-3 py-1.5"></td>
                                <td v-for="[campo, , fmt] in COLUNAS" :key="campo" class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums">{{ fmt(a[campo]) }}</td>
                            </tr>
                            <tr v-if="!c.anuncios.length" class="border-t border-dashed border-[var(--surface-border)] text-xs">
                                <td :colspan="COLUNAS.length + 3" class="py-1.5 pl-8 text-slate-500">Nenhum anúncio listado nesta campanha.</td>
                            </tr>
                        </template>
                    </template>
                </tbody>
            </table>
        </div>
        <p v-else-if="dados.some((l) => !l.erro)" class="border-t border-[var(--surface-border)] px-4 py-3 text-sm text-slate-500">
            Nenhuma campanha ativa ou com gasto no período.
        </p>
        <p v-if="!todas && dados.some((l) => l.ocultas > 0)" class="border-t border-[var(--surface-border)] px-4 py-2 text-xs text-slate-500">
            <template v-for="(l, i) in dados.filter((x) => x.ocultas > 0)" :key="l.loja">{{ i ? ' · ' : '' }}{{ l.nome }}: {{ l.ocultas }} campanha(s) parada(s) e sem gasto no período fora da lista</template>. <button type="button" class="font-medium text-primary hover:underline" @click="todas = true">Mostrar todas</button>
        </p>
    </section>
</template>
