<script setup>
/**
 * Publicidade › Infoprodutos (pedido do Lira em 2026-11-19): funil dos
 * infoprodutos (visita › rolou 50% › vídeo › comprar › checkout › compra ›
 * bump › upsell/downsell), por anúncio com o gasto da conta GreenKora, por
 * país, por dia e hora, botão que mais converte e vídeo; e o resumo da
 * KazaKora e da FerraKora (o mesmo dos apps de vendas, lucro do
 * ContributionMargin). Medição sem cookie e sem IP.
 */
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Deferred, Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    periodo: { type: String, required: true },
    periodos: { type: Object, default: () => ({}) },
    produto: { type: String, required: true },
    produtos: { type: Object, default: () => ({}) },
    metaConfigurada: { type: Boolean, default: false },
    funil: { type: Object, default: null },
    lojas: { type: Array, default: null },
});

const n = (v) => (v === null || v === undefined ? '-' : new Intl.NumberFormat('pt-BR').format(v));
const pct = (v) => (v === null || v === undefined ? '-' : `${Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%`);
const moedaMeta = computed(() => props.funil?.moeda_meta || 'USD');
const din = (v, m = moedaMeta.value) => (v === null || v === undefined ? '-' : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: m }).format(v));
const reais = (v) => din(v, 'BRL');
const roas = (v) => (v === null || v === undefined ? '-' : `${Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 2 })}x`);
const variacao = (v) => (v === null || v === undefined ? '' : `${v > 0 ? '+' : ''}${v.toLocaleString('pt-BR')}%`);

const ir = (mudanca) => router.get('/admin/publicidade/infoprodutos', { produto: props.produto, periodo: props.periodo, ...mudanca }, { preserveScroll: true });

// Funil visual: barras afuniladas, largura proporcional às visitas.
const principais = computed(() => {
    const passos = (props.funil?.passos ?? []).filter((p) => !['order_bump', 'upsell', 'downsell'].includes(p.chave));
    const cliques = props.funil?.cliques_anuncio;
    if (!cliques) return passos;
    const visitas = passos[0]?.n ?? 0;
    return [{ chave: 'anuncio', rotulo: 'Clicou no anúncio (Meta)', n: cliques, taxa_passo: null, saiu: null },
        { ...passos[0], taxa_passo: cliques > 0 ? (visitas / cliques) * 100 : null, saiu: Math.max(cliques - visitas, 0) }, ...passos.slice(1)];
});
const extras = computed(() => (props.funil?.passos ?? []).filter((p) => ['order_bump', 'upsell', 'downsell'].includes(p.chave)));
const largura = (p) => {
    const max = principais.value[0]?.n || 0;
    return max > 0 ? Math.max(4, (p.n / max) * 100) : 4;
};

const DIAS = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
const grade = computed(() => {
    const g = {};
    for (const c of props.funil?.semana_hora ?? []) g[`${c.dia}-${c.hora}`] = c;
    return g;
});
const maxGrade = computed(() => Math.max(1, ...(props.funil?.semana_hora ?? []).map((c) => c.visitas)));
const corGrade = (c) => {
    if (!c) return 'transparent';
    const a = 0.12 + (c.visitas / maxGrade.value) * 0.8;
    return c.compras > 0 ? `rgba(16,185,129,${a})` : `rgba(79,70,229,${a})`;
};
const receita = (obj) => Object.entries(obj ?? {}).filter(([, v]) => v > 0).map(([m, v]) => (m === '?' ? n(v) : din(v, m))).join(' + ') || '-';
</script>

<template>
    <Head title="Infoprodutos" />

    <AdminLayout>
        <div class="mb-1 flex flex-wrap items-center gap-2">
            <h1 class="text-2xl font-bold">Infoprodutos</h1>
            <Link href="/admin/publicidade/criativos?conta=greenkora" class="ml-auto text-sm text-indigo-600 hover:underline">Criativos da GreenKora →</Link>
            <Link href="/admin/publicidade/comportamento?loja=infoprodutos" class="text-sm text-indigo-600 hover:underline">Mapa de cliques →</Link>
        </div>
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
            Medição anônima das páginas (sem cookie, sem IP) e vendas avisadas pela Kiwify. Uma visita = uma aba aberta na página.
        </p>

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <select class="rounded border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-800" :value="produto" @change="ir({ produto: $event.target.value })">
                <option v-for="(nome, chave) in produtos" :key="chave" :value="chave">{{ nome }}</option>
            </select>
            <button v-for="(rotulo, chave) in periodos" :key="chave" type="button" @click="ir({ periodo: chave })"
                class="rounded-full border px-3 py-1 text-sm" :class="chave === periodo ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-300 dark:border-slate-600'">{{ rotulo }}</button>
        </div>

        <Deferred data="funil">
            <template #fallback><p class="py-10 text-center text-sm text-slate-500">Montando o funil…</p></template>

            <div v-if="funil" class="grid gap-4 lg:grid-cols-3">
                <section class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900 lg:col-span-2">
                    <h2 class="mb-3 font-semibold">Funil</h2>
                    <div class="space-y-1.5">
                        <div v-for="p in principais" :key="p.chave" class="flex items-center gap-2 text-sm">
                            <div class="w-40 shrink-0 text-right text-slate-600 dark:text-slate-300">{{ p.rotulo }}</div>
                            <div class="relative h-8 flex-1">
                                <div class="mx-auto flex h-8 items-center justify-center rounded bg-indigo-600 text-xs font-semibold text-white transition-all"
                                    :style="{ width: `${largura(p)}%` }">{{ n(p.n) }}</div>
                            </div>
                            <div class="w-28 shrink-0 text-xs text-slate-500">
                                <div>{{ p.taxa_passo !== null ? `${pct(p.taxa_passo)} do passo` : '' }}</div>
                                <div v-if="p.saiu" class="text-rose-500">−{{ n(p.saiu) }} saíram</div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 grid grid-cols-3 gap-2 text-center text-sm">
                        <div v-for="p in extras" :key="p.chave" class="rounded bg-emerald-50 p-2 dark:bg-emerald-900/20">
                            <div class="text-xs text-slate-500">{{ p.rotulo }}</div>
                            <div class="text-lg font-bold">{{ n(p.n) }}</div>
                            <div class="text-xs text-slate-500">{{ pct(p.taxa_passo) }} das compras</div>
                        </div>
                    </div>
                </section>

                <section class="space-y-3 rounded-lg border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900">
                    <div><div class="text-xs text-slate-500">Receita aprovada</div><div class="text-xl font-bold">{{ receita(funil.receita.por_moeda) }}</div></div>
                    <div><div class="text-xs text-slate-500">Líquido (comissão)</div><div class="font-semibold">{{ receita(funil.receita.liquido_por_moeda) }}</div></div>
                    <div><div class="text-xs text-slate-500">Gasto Meta GreenKora</div><div class="font-semibold">{{ metaConfigurada ? din(funil.gasto_total) : 'token não configurado' }}</div>
                        <div v-if="funil.erro_meta" class="text-xs text-rose-600">{{ funil.erro_meta }}</div></div>
                    <div><div class="text-xs text-slate-500">Reembolsos / chargebacks</div><div class="font-semibold">{{ n(funil.reembolsos) }}</div></div>
                    <div><div class="text-xs text-slate-500">Tempo médio na página</div><div class="font-semibold">{{ funil.tempo_medio !== null ? `${funil.tempo_medio} s` : '-' }}</div></div>
                    <div>
                        <div class="text-xs text-slate-500">Vídeo: {{ n(funil.video.plays) }} plays</div>
                        <div class="mt-1 flex gap-1">
                            <div v-for="m in funil.video.marcos" :key="m.marco" class="flex-1 rounded bg-slate-100 p-1 text-center dark:bg-slate-800">
                                <div class="text-[10px] text-slate-500">{{ m.marco }}%</div><div class="text-xs font-semibold">{{ pct(m.pct) }}</div>
                            </div>
                        </div>
                        <div class="mt-1 text-xs">Assistem até o fim: <b>{{ pct(funil.video.ate_o_fim) }}</b></div>
                    </div>
                </section>

                <section class="overflow-x-auto rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900 lg:col-span-3">
                    <h2 class="mb-2 font-semibold">Por anúncio (utm_content)</h2>
                    <table class="min-w-full whitespace-nowrap text-sm">
                        <thead class="text-left text-xs uppercase text-slate-500"><tr>
                            <th class="p-1.5">Anúncio</th><th class="p-1.5">Campanha</th><th class="p-1.5">Gasto</th><th class="p-1.5">Visitas</th><th class="p-1.5">Rolou 50%</th>
                            <th class="p-1.5">Clicou comprar</th><th class="p-1.5">Compras</th><th class="p-1.5">Conversão</th><th class="p-1.5">Custo/visita</th><th class="p-1.5">Custo/compra</th><th class="p-1.5">ROAS</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="a in funil.anuncios" :key="a.utm_content" class="border-t border-slate-100 dark:border-slate-800">
                                <td class="max-w-[18rem] truncate p-1.5" :title="a.utm_content">{{ a.anuncio || a.utm_content || '(sem utm: orgânico/direto)' }}</td>
                                <td class="max-w-[12rem] truncate p-1.5 text-slate-500">{{ a.utm_campaign }}</td>
                                <td class="p-1.5">{{ din(a.gasto) }}</td><td class="p-1.5">{{ n(a.visitas) }}</td><td class="p-1.5">{{ n(a.rolou_50) }}</td>
                                <td class="p-1.5">{{ n(a.comprar) }}</td><td class="p-1.5">{{ n(a.compras) }}</td><td class="p-1.5">{{ pct(a.conversao) }}</td>
                                <td class="p-1.5">{{ din(a.custo_visita) }}</td><td class="p-1.5">{{ din(a.custo_compra) }}</td><td class="p-1.5">{{ roas(a.roas) }}</td>
                            </tr>
                            <tr v-if="!funil.anuncios.length"><td colspan="11" class="p-3 text-center text-slate-500">Sem visitas no período.</td></tr>
                        </tbody>
                    </table>
                </section>

                <section class="rounded-lg border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900">
                    <h2 class="mb-2 font-semibold">Botão de compra</h2>
                    <table class="w-full"><thead class="text-left text-xs text-slate-500"><tr><th>Posição</th><th>Cliques</th><th>Compras</th><th>Conv.</th></tr></thead>
                        <tbody><tr v-for="b in funil.botoes" :key="b.posicao" class="border-t border-slate-100 dark:border-slate-800">
                            <td class="py-1">{{ b.posicao }}</td><td>{{ n(b.cliques) }}</td><td>{{ n(b.compras) }}</td><td>{{ pct(b.conversao) }}</td></tr></tbody></table>
                </section>

                <section class="rounded-lg border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900">
                    <h2 class="mb-2 font-semibold">Por país</h2>
                    <table class="w-full"><thead class="text-left text-xs text-slate-500"><tr><th>País</th><th>Visitas</th><th>Compras</th><th>Conv.</th></tr></thead>
                        <tbody><tr v-for="p in funil.paises.slice(0, 15)" :key="p.pais" class="border-t border-slate-100 dark:border-slate-800">
                            <td class="py-1">{{ p.pais }}</td><td>{{ n(p.visitas) }}</td><td>{{ n(p.compras) }}</td><td>{{ pct(p.conversao) }}</td></tr></tbody></table>
                </section>

                <section class="overflow-x-auto rounded-lg border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900">
                    <h2 class="mb-2 font-semibold">Dia da semana × hora</h2>
                    <table class="text-[10px]">
                        <tbody><tr v-for="(d, i) in DIAS" :key="d"><td class="pr-1 text-slate-500">{{ d }}</td>
                            <td v-for="h in 24" :key="h" class="h-4 w-3" :style="{ background: corGrade(grade[`${i}-${h - 1}`]) }"
                                :title="`${d} ${h - 1}h: ${grade[`${i}-${h - 1}`]?.visitas ?? 0} visitas, ${grade[`${i}-${h - 1}`]?.compras ?? 0} compras`"></td></tr></tbody>
                    </table>
                    <p class="mt-1 text-xs text-slate-500">Mais escuro = mais visitas; verde = teve compra.</p>
                </section>
            </div>
        </Deferred>

        <h2 class="mb-2 mt-8 text-lg font-semibold">KazaKora e FerraKora no mesmo período</h2>
        <Deferred data="lojas">
            <template #fallback><p class="py-6 text-center text-sm text-slate-500">Lendo as lojas…</p></template>
            <div class="grid gap-4 md:grid-cols-2">
                <section v-for="l in lojas ?? []" :key="l.app" class="rounded-lg border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900">
                    <h3 class="mb-2 font-semibold">{{ l.loja }}</h3>
                    <p v-if="l.erro" class="text-rose-600">{{ l.erro }}</p>
                    <dl v-else class="grid grid-cols-2 gap-2">
                        <div><dt class="text-xs text-slate-500">Visitas</dt><dd class="font-semibold">{{ n(l.visitas.valor) }} <span class="text-xs text-slate-400">{{ variacao(l.visitas.variacao) }}</span></dd></div>
                        <div><dt class="text-xs text-slate-500">Pedidos pagos</dt><dd class="font-semibold">{{ n(l.pedidos.valor) }} <span class="text-xs text-slate-400">{{ variacao(l.pedidos.variacao) }}</span></dd></div>
                        <div><dt class="text-xs text-slate-500">Conversão</dt><dd class="font-semibold">{{ pct(l.conversao.valor) }}</dd></div>
                        <div><dt class="text-xs text-slate-500">Faturamento</dt><dd class="font-semibold">{{ reais(l.faturamento.valor) }}</dd></div>
                        <div><dt class="text-xs text-slate-500">Lucro (conta oficial)</dt><dd class="font-semibold">{{ l.lucro.valor !== null ? reais(l.lucro.valor) : (l.lucro.motivo || '-') }}</dd></div>
                        <div><dt class="text-xs text-slate-500">Meta: gasto · ROAS</dt><dd class="font-semibold">{{ l.meta?.erro ? l.meta.erro : `${reais(l.meta?.gasto?.valor)} · ${roas(l.meta?.roas?.valor)}` }}</dd></div>
                    </dl>
                    <div class="mt-2 flex gap-3 text-xs">
                        <Link :href="`/admin/publicidade/criativos?conta=${l.app}`" class="text-indigo-600 hover:underline">Criativos</Link>
                        <Link :href="`/admin/publicidade/comportamento?loja=${l.app}`" class="text-indigo-600 hover:underline">Mapa de cliques</Link>
                        <Link href="/admin/publicidade/meta-ads/campanhas" class="text-indigo-600 hover:underline">Campanhas Meta</Link>
                    </div>
                </section>
            </div>
        </Deferred>
    </AdminLayout>
</template>
