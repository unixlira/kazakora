<script setup>
/**
 * Criativos (pedido do Lira em 2026-11-19): anúncio a anúncio das contas
 * Meta da KazaKora, FerraKora e GreenKora (infoprodutos), com gancho,
 * retenção, tempo assistido, funil, CPA, ROAS e um selo de leitura.
 * Só leitura (CriativosMeta, guardado 5 min).
 */
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Deferred, Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { formatarNumero, statusMeta } from '@/Modules/Admin/MetaAds/metaAds';

const props = defineProps({
    contas: { type: Object, default: () => ({}) },
    conta: { type: String, required: true },
    periodo: { type: String, required: true },
    periodos: { type: Object, default: () => ({}) },
    campanha: { type: String, default: null },
    selos: { type: Object, default: () => ({}) },
    dados: { type: Object, default: null },
});

const moeda = computed(() => props.dados?.moeda || 'BRL');
const dinheiro = (v) => (v === null || v === undefined ? '-' : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: moeda.value }).format(v));
const pct = (v, casas = 1) => (v === null || v === undefined ? '-' : `${Number(v).toLocaleString('pt-BR', { maximumFractionDigits: casas })}%`);
const roas = (v) => (v === null || v === undefined ? '-' : `${Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 2 })}x`);
const tempo = (l) => {
    const t = l.video?.tempo_medio;
    if (t === null || t === undefined) return '-';
    return l.duracao ? `${Math.round(t)} s de ${Math.round(l.duracao)} s (${pct(l.tempo_pct, 0)})` : `${Math.round(t)} s`;
};

const ir = (mudanca) => router.get('/admin/publicidade/criativos', {
    conta: props.conta, periodo: props.periodo, campanha: props.campanha || undefined, ...mudanca,
}, { preserveScroll: true });

const COR_SELO = {
    pouco_dado: 'bg-slate-500/15 text-slate-600 dark:text-slate-300',
    vencedor: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    clica_nao_compra: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    gancho_fraco: 'bg-rose-500/15 text-rose-700 dark:text-rose-300',
    segura_pouco: 'bg-orange-500/15 text-orange-700 dark:text-orange-300',
};

const escolhidos = ref([]);
const alternar = (id) => {
    escolhidos.value = escolhidos.value.includes(id)
        ? escolhidos.value.filter((x) => x !== id)
        : [...escolhidos.value, id].slice(-2);
};
const comparados = computed(() => (props.dados?.linhas ?? []).filter((l) => escolhidos.value.includes(l.id)));

const COMPARAR = [
    ['Status', (l) => statusMeta(l.status)[0]],
    ['Gasto', (l) => dinheiro(l.gasto)],
    ['Impressões', (l) => formatarNumero(l.impressoes)],
    ['CTR do link', (l) => pct(l.ctr_link, 2)],
    ['CPC', (l) => dinheiro(l.cpc_link)],
    ['CPV (custo por visita)', (l) => dinheiro(l.cpv)],
    ['Gancho (3 s ÷ impressões)', (l) => pct(l.gancho)],
    ['Retenção 25%', (l) => pct(l.retencao?.p25)],
    ['Retenção 50%', (l) => pct(l.retencao?.p50)],
    ['Retenção 75%', (l) => pct(l.retencao?.p75)],
    ['Retenção 100%', (l) => pct(l.retencao?.p100)],
    ['ThruPlay', (l) => formatarNumero(l.video?.thruplay)],
    ['Tempo médio assistido', tempo],
    ['Carrinho', (l) => formatarNumero(l.carrinho)],
    ['Checkout', (l) => formatarNumero(l.checkout)],
    ['Compras', (l) => formatarNumero(l.compras)],
    ['CPA', (l) => dinheiro(l.cpa)],
    ['ROAS', (l) => roas(l.roas)],
    ['Leitura', (l) => l.selo_rotulo ?? '-'],
];
</script>

<template>
    <Head title="Criativos" />

    <AdminLayout>
        <h1 class="mb-1 text-2xl font-bold">Criativos</h1>
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
            Anúncio a anúncio, lido da Meta. Gancho = views de 3 s ÷ impressões; retenção = quem viu até o ponto ÷ quem deu play;
            CPV = gasto ÷ visitas à página. O selo é uma leitura automática: confira antes de pausar qualquer coisa.
        </p>

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <button v-for="(nome, chave) in contas" :key="chave" type="button" @click="ir({ conta: chave, campanha: undefined })"
                class="rounded-full border px-3 py-1 text-sm" :class="chave === conta ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-300 dark:border-slate-600'">{{ nome }}</button>
            <select class="ml-auto rounded border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-800" :value="periodo" @change="ir({ periodo: $event.target.value })">
                <option v-for="(rotulo, chave) in periodos" :key="chave" :value="chave">{{ rotulo }}</option>
            </select>
            <select v-if="dados?.campanhas" class="max-w-xs rounded border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-800" :value="campanha ?? ''" @change="ir({ campanha: $event.target.value || undefined })">
                <option value="">Todas as campanhas</option>
                <option v-for="(nome, id) in dados.campanhas" :key="id" :value="id">{{ nome }}</option>
            </select>
        </div>

        <p v-if="!Object.keys(contas).length" class="rounded bg-amber-50 p-3 text-sm text-amber-800">Nenhuma conta da Meta configurada.</p>

        <Deferred data="dados">
            <template #fallback><p class="py-10 text-center text-sm text-slate-500">Lendo os anúncios da Meta…</p></template>

            <p v-if="dados?.erro" class="mb-3 rounded bg-rose-50 p-3 text-sm text-rose-700">{{ dados.erro }}</p>

            <div v-if="comparados.length === 2" class="mb-4 overflow-x-auto rounded-lg border border-indigo-200 bg-white p-3 dark:border-indigo-900 dark:bg-slate-900">
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="font-semibold">Lado a lado</h2>
                    <button type="button" class="text-sm text-indigo-600" @click="escolhidos = []">limpar</button>
                </div>
                <table class="w-full text-sm">
                    <thead><tr><th></th><th v-for="l in comparados" :key="l.id" class="p-2 text-left">
                        <img v-if="l.miniatura" :src="l.miniatura" loading="lazy" alt="" class="mb-1 h-16 w-16 rounded object-cover">{{ l.nome }}
                    </th></tr></thead>
                    <tbody>
                        <tr v-for="[rotulo, f] in COMPARAR" :key="rotulo" class="border-t border-slate-100 dark:border-slate-800">
                            <td class="p-2 text-slate-500">{{ rotulo }}</td>
                            <td v-for="l in comparados" :key="l.id" class="p-2 font-medium">{{ f(l) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="mb-2 text-xs text-slate-500">Marque 2 anúncios para comparar lado a lado.</p>

            <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
                <table class="min-w-full whitespace-nowrap text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500 dark:bg-slate-800">
                        <tr>
                            <th class="p-2"></th><th class="p-2">Anúncio</th><th class="p-2">Leitura</th><th class="p-2">Gasto</th><th class="p-2">Impr.</th>
                            <th class="p-2">CTR link</th><th class="p-2">CPC</th><th class="p-2">CPV</th><th class="p-2">Gancho</th>
                            <th class="p-2">25%</th><th class="p-2">50%</th><th class="p-2">75%</th><th class="p-2">100%</th><th class="p-2">ThruPlay</th>
                            <th class="p-2">Tempo médio</th><th class="p-2">Carrinho</th><th class="p-2">Checkout</th><th class="p-2">Compras</th><th class="p-2">CPA</th><th class="p-2">ROAS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="l in dados?.linhas ?? []" :key="l.id" class="border-t border-slate-100 dark:border-slate-800">
                            <td class="p-2"><input type="checkbox" :checked="escolhidos.includes(l.id)" @change="alternar(l.id)" :aria-label="`Comparar ${l.nome}`"></td>
                            <td class="p-2">
                                <div class="flex items-center gap-2">
                                    <a :href="l.previa || l.gerenciador" target="_blank" rel="noopener" class="shrink-0">
                                        <img v-if="l.miniatura" :src="l.miniatura" loading="lazy" alt="" class="h-12 w-12 rounded object-cover">
                                        <span v-else class="flex h-12 w-12 items-center justify-center rounded bg-slate-100 text-xs dark:bg-slate-800">sem</span>
                                    </a>
                                    <div class="max-w-[16rem]">
                                        <a :href="l.previa || l.gerenciador" target="_blank" rel="noopener" class="block truncate font-medium hover:underline" :title="l.nome">{{ l.nome }}</a>
                                        <span class="rounded px-1.5 text-xs" :class="statusMeta(l.status)[1]">{{ statusMeta(l.status)[0] }}</span>
                                        <span class="ml-1 truncate text-xs text-slate-500" :title="l.campanha">{{ l.campanha }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="p-2"><span v-if="l.selo" class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="COR_SELO[l.selo]">{{ l.selo_rotulo }}</span></td>
                            <td class="p-2">{{ dinheiro(l.gasto) }}</td>
                            <td class="p-2">{{ formatarNumero(l.impressoes) }}</td>
                            <td class="p-2">{{ pct(l.ctr_link, 2) }}</td>
                            <td class="p-2">{{ dinheiro(l.cpc_link) }}</td>
                            <td class="p-2">{{ dinheiro(l.cpv) }}</td>
                            <td class="p-2">{{ l.e_video ? pct(l.gancho) : '-' }}</td>
                            <td class="p-2">{{ pct(l.retencao.p25) }}</td>
                            <td class="p-2">{{ pct(l.retencao.p50) }}</td>
                            <td class="p-2">{{ pct(l.retencao.p75) }}</td>
                            <td class="p-2">{{ pct(l.retencao.p100) }}</td>
                            <td class="p-2">{{ l.e_video ? formatarNumero(l.video.thruplay) : '-' }}</td>
                            <td class="p-2">{{ tempo(l) }}</td>
                            <td class="p-2">{{ formatarNumero(l.carrinho) }}</td>
                            <td class="p-2">{{ formatarNumero(l.checkout) }}</td>
                            <td class="p-2">{{ formatarNumero(l.compras) }}</td>
                            <td class="p-2">{{ dinheiro(l.cpa) }}</td>
                            <td class="p-2">{{ roas(l.roas) }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="dados?.total" class="border-t-2 border-slate-300 bg-slate-50 font-semibold dark:border-slate-600 dark:bg-slate-800">
                        <tr>
                            <td class="p-2"></td><td class="p-2">Total ({{ dados.linhas.length }})</td><td class="p-2"></td>
                            <td class="p-2">{{ dinheiro(dados.total.gasto) }}</td>
                            <td class="p-2">{{ formatarNumero(dados.total.impressoes) }}</td>
                            <td class="p-2">{{ pct(dados.total.ctr_link, 2) }}</td>
                            <td class="p-2">{{ dinheiro(dados.total.cpc_link) }}</td>
                            <td class="p-2">{{ dinheiro(dados.total.cpv) }}</td>
                            <td class="p-2">{{ pct(dados.total.gancho) }}</td>
                            <td class="p-2">{{ pct(dados.total.retencao.p25) }}</td>
                            <td class="p-2">{{ pct(dados.total.retencao.p50) }}</td>
                            <td class="p-2">{{ pct(dados.total.retencao.p75) }}</td>
                            <td class="p-2">{{ pct(dados.total.retencao.p100) }}</td>
                            <td class="p-2">{{ formatarNumero(dados.total.video.thruplay) }}</td>
                            <td class="p-2">-</td>
                            <td class="p-2">{{ formatarNumero(dados.total.carrinho) }}</td>
                            <td class="p-2">{{ formatarNumero(dados.total.checkout) }}</td>
                            <td class="p-2">{{ formatarNumero(dados.total.compras) }}</td>
                            <td class="p-2">{{ dinheiro(dados.total.cpa) }}</td>
                            <td class="p-2">{{ roas(dados.total.roas) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="mt-2 text-xs text-slate-500">
                Selos: pouco dado = menos de R$ 30 gastos; gancho fraco = CTR do link abaixo de 0,8% ou gancho abaixo de 70% da mediana dos outros vídeos;
                segura pouco = retenção de 50% ou tempo assistido abaixo de 70% da mediana; clica e não compra = checkout sem compra; vencedor = menor CPA com dado suficiente.
            </p>
        </Deferred>
    </AdminLayout>
</template>
