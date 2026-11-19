<script setup>
/**
 * Comportamento (pedido do Lira em 2026-11-19): mapa de cliques sobre o print
 * da página (celular 390 px numa moldura de iPhone, ou computador 1280 px num
 * monitor), onde clicam, cliques mortos, curva de rolagem por seção,
 * sequências mais comuns e a linha do tempo de cada visita (anônima).
 * Print e mapa carregam sob demanda (o print é WebP, gerado pelo
 * comportamento:prints com o Chrome headless).
 */
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Deferred, Head, router } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    periodo: { type: String, required: true },
    periodos: { type: Object, default: () => ({}) },
    aparelho: { type: String, required: true },
    loja: { type: String, default: 'kazakora' },
    paginas: { type: Array, default: () => [] },
    pagina: { type: Object, default: null },
    print: { type: Object, default: null },
    dados: { type: Object, default: null },
});

const LOJAS = { kazakora: 'KazaKora', ferrakora: 'FerraKora', infoprodutos: 'Infoprodutos' };
const n = (v) => (v === null || v === undefined ? '-' : new Intl.NumberFormat('pt-BR').format(v));
const pct = (v) => (v === null || v === undefined ? '-' : `${Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%`);

const paginasDaLoja = computed(() => props.paginas.filter((p) => p.loja === props.loja));
const ir = (mudanca) => router.get('/admin/publicidade/comportamento', {
    loja: props.loja, produto: props.pagina?.produto, pagina: props.pagina?.pagina, aparelho: props.aparelho, periodo: props.periodo, ...mudanca,
}, { preserveScroll: true });
const escolherPagina = (valor) => {
    const [produto, pagina] = valor.split('|');
    ir({ produto, pagina });
};

// Mapa de calor ------------------------------------------------------------
const mostrarMapa = ref(true);
const opacidade = ref(0.7);
const canvas = ref(null);
const imagemPronta = ref(false);
const larguraTela = computed(() => (props.aparelho === 'celular' ? 390 : 1280));
// O computador aparece reduzido dentro do monitor.
const escala = computed(() => (props.aparelho === 'celular' ? 1 : 0.5));
const alturaPrint = computed(() => props.print?.altura || 3000);

// Paleta azul → verde → amarelo → vermelho (pouco → muito clicado).
let paleta = null;
const montarPaleta = () => {
    const c = document.createElement('canvas');
    c.width = 256; c.height = 1;
    const ctx = c.getContext('2d');
    const g = ctx.createLinearGradient(0, 0, 256, 0);
    g.addColorStop(0.0, 'rgba(0,0,255,0)');
    g.addColorStop(0.2, 'rgb(0,90,255)');
    g.addColorStop(0.45, 'rgb(0,200,80)');
    g.addColorStop(0.7, 'rgb(255,230,0)');
    g.addColorStop(1.0, 'rgb(255,0,0)');
    ctx.fillStyle = g; ctx.fillRect(0, 0, 256, 1);
    return ctx.getImageData(0, 0, 256, 1).data;
};

const desenhar = () => {
    const el = canvas.value;
    const pontos = props.dados?.pontos ?? [];
    if (!el) return;
    const w = larguraTela.value;
    const h = Math.min(alturaPrint.value, 16000);
    el.width = w; el.height = h;
    const ctx = el.getContext('2d');
    ctx.clearRect(0, 0, w, h);
    if (!pontos.length) return;
    paleta ??= montarPaleta();
    const max = Math.max(...pontos.map((p) => p.n));
    const raio = props.aparelho === 'celular' ? 22 : 30;
    for (const p of pontos) {
        const x = (p.x / 100) * w;
        const g = ctx.createRadialGradient(x, p.y, 0, x, p.y, raio);
        const a = Math.min(1, 0.15 + (p.n / max) * 0.85);
        g.addColorStop(0, `rgba(0,0,0,${a})`);
        g.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = g;
        ctx.fillRect(x - raio, p.y - raio, raio * 2, raio * 2);
    }
    const img = ctx.getImageData(0, 0, w, h);
    const d = img.data;
    for (let i = 0; i < d.length; i += 4) {
        const alfa = d[i + 3];
        if (!alfa) continue;
        const j = alfa * 4;
        d[i] = paleta[j]; d[i + 1] = paleta[j + 1]; d[i + 2] = paleta[j + 2]; d[i + 3] = Math.min(255, alfa + 40);
    }
    ctx.putImageData(img, 0, 0);
};
watch(() => [props.dados, imagemPronta.value, mostrarMapa.value], () => nextTick(desenhar));

// Sob demanda: a imagem (pesada) só entra quando a área aparece na tela.
const area = ref(null);
const carregar = ref(false);
let observador = null;
watch(area, (el) => {
    if (!el || carregar.value) return;
    observador = new IntersectionObserver((e) => { if (e.some((x) => x.isIntersecting)) { carregar.value = true; observador.disconnect(); } }, { rootMargin: '200px' });
    observador.observe(el);
});
onBeforeUnmount(() => observador?.disconnect());

// Rolagem por seção: % das visitas que chegam ao topo de cada seção do print.
const secoes = computed(() => {
    const curva = props.dados?.rolagem ?? [];
    const altura = alturaPrint.value;
    return (props.print?.secoes ?? []).map((s) => {
        const p = Math.min(100, Math.floor((s.topo / altura) * 100 / 5) * 5);
        return { id: s.id, topo: s.topo, pct: curva.find((c) => c.pct_pagina === p)?.pct_visitas ?? null };
    });
});

// Linha do tempo de uma visita -------------------------------------------------
const visita = ref(null);
const passos = ref([]);
const abrirVisita = async (v) => {
    visita.value = v;
    passos.value = [];
    const url = `/admin/publicidade/comportamento/visita?sessao=${encodeURIComponent(v.sessao)}&produto=${encodeURIComponent(props.pagina.produto)}`;
    const r = await fetch(url, { headers: { Accept: 'application/json' } });
    passos.value = r.ok ? (await r.json()).passos : [];
};
const hora = (iso) => new Date(iso).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
</script>

<template>
    <Head title="Comportamento" />

    <AdminLayout>
        <h1 class="mb-1 text-2xl font-bold">Comportamento</h1>
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
            Onde as pessoas clicam, até onde rolam e como navegam. Anônimo: sem cookie, sem IP, só um código aleatório por aba.
        </p>

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <button v-for="(nome, chave) in LOJAS" :key="chave" type="button" @click="ir({ loja: chave, produto: undefined, pagina: undefined })"
                class="rounded-full border px-3 py-1 text-sm" :class="chave === loja ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-300 dark:border-slate-600'">{{ nome }}</button>
            <select class="max-w-xs rounded border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-800" :value="pagina ? `${pagina.produto}|${pagina.pagina}` : ''" @change="escolherPagina($event.target.value)">
                <option v-if="!paginasDaLoja.length" value="">Sem visitas no período</option>
                <option v-for="p in paginasDaLoja" :key="`${p.produto}|${p.pagina}`" :value="`${p.produto}|${p.pagina}`">{{ p.rotulo }} ({{ n(p.visitas) }})</option>
            </select>
            <div class="flex overflow-hidden rounded border border-slate-300 text-sm dark:border-slate-600">
                <button type="button" class="px-3 py-1" :class="aparelho === 'celular' ? 'bg-indigo-600 text-white' : ''" @click="ir({ aparelho: 'celular' })"><i class="fas fa-mobile-screen"></i> Celular</button>
                <button type="button" class="px-3 py-1" :class="aparelho === 'computador' ? 'bg-indigo-600 text-white' : ''" @click="ir({ aparelho: 'computador' })"><i class="fas fa-desktop"></i> Computador</button>
            </div>
            <select class="rounded border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-800" :value="periodo" @change="ir({ periodo: $event.target.value })">
                <option v-for="(rotulo, chave) in periodos" :key="chave" :value="chave">{{ rotulo }}</option>
            </select>
        </div>

        <p v-if="!pagina" class="rounded bg-slate-50 p-4 text-sm text-slate-600 dark:bg-slate-800">Nenhuma visita medida nesta loja no período.</p>

        <div v-else class="grid gap-6 lg:grid-cols-[auto,1fr]">
            <!-- Aparelho com o print e o mapa -->
            <div>
                <div class="mb-2 flex items-center gap-3 text-sm">
                    <label class="flex items-center gap-1"><input v-model="mostrarMapa" type="checkbox"> mapa de calor</label>
                    <label class="flex items-center gap-1">opacidade <input v-model.number="opacidade" type="range" min="0.1" max="1" step="0.05" class="w-24"></label>
                </div>
                <div ref="area" :class="aparelho === 'celular' ? 'mx-auto w-[414px] rounded-[48px] border-[12px] border-slate-900 bg-slate-900 p-0 shadow-xl' : 'mx-auto w-[664px] rounded-xl border-[12px] border-slate-800 bg-slate-800 shadow-xl'">
                    <div v-if="aparelho === 'celular'" class="mx-auto mb-1 h-5 w-32 rounded-b-2xl bg-slate-900"></div>
                    <div class="relative overflow-y-auto overflow-x-hidden bg-white" :class="aparelho === 'celular' ? 'h-[760px] rounded-[30px]' : 'h-[420px]'"
                        :style="{ width: `${larguraTela * escala}px` }">
                        <div class="relative origin-top-left" :style="{ width: `${larguraTela}px`, height: `${alturaPrint}px`, transform: `scale(${escala})` }">
                            <img v-if="print && carregar" :src="print.url" :width="print.largura" :height="print.altura" alt="Print da página" decoding="async"
                                class="absolute left-0 top-0 max-w-none" @load="imagemPronta = true">
                            <div v-else-if="!print" class="p-6 text-sm text-slate-500">Print desta página ainda não foi gerado (sai na próxima hora, pelo comportamento:prints).</div>
                            <canvas v-show="mostrarMapa" ref="canvas" class="pointer-events-none absolute left-0 top-0" :style="{ opacity: opacidade }"></canvas>
                        </div>
                    </div>
                </div>
                <div v-if="aparelho === 'computador'" class="mx-auto h-6 w-40 bg-slate-700"></div>
                <div class="mx-auto mt-2 flex w-64 items-center gap-2 text-xs text-slate-500">
                    pouco <span class="h-2 flex-1 rounded" style="background: linear-gradient(90deg, #005aff, #00c850, #ffe600, #ff0000)"></span> muito
                </div>
                <p v-if="print" class="mt-1 text-center text-xs text-slate-400">Print de {{ print.em ? hora(print.em) : '-' }}</p>
            </div>

            <!-- Leituras -->
            <Deferred data="dados">
                <template #fallback><p class="py-10 text-center text-sm text-slate-500">Lendo os cliques…</p></template>
                <div v-if="dados" class="space-y-4 text-sm">
                    <div class="flex gap-4">
                        <div><div class="text-xs text-slate-500">Visitas</div><div class="text-xl font-bold">{{ n(dados.visitas) }}</div></div>
                        <div><div class="text-xs text-slate-500">Tempo médio</div><div class="text-xl font-bold">{{ dados.tempo_medio !== null ? `${dados.tempo_medio} s` : '-' }}</div></div>
                    </div>

                    <section class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                        <h2 class="mb-2 font-semibold">Onde clicam</h2>
                        <ol class="space-y-1">
                            <li v-for="c in dados.onde_clicam" :key="c.elemento" class="flex items-center gap-2">
                                <span class="flex-1 truncate" :title="c.elemento">{{ c.elemento }}</span>
                                <span class="h-2 w-24 overflow-hidden rounded bg-slate-100 dark:bg-slate-800"><span class="block h-2 bg-indigo-500" :style="{ width: `${Math.min(100, c.pct ?? 0)}%` }"></span></span>
                                <span class="w-14 text-right font-semibold">{{ pct(c.pct) }}</span>
                            </li>
                            <li v-if="!dados.onde_clicam.length" class="text-slate-500">Nenhum clique ainda.</li>
                        </ol>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                        <h2 class="mb-1 font-semibold">Cliques mortos</h2>
                        <p class="mb-2 text-xs text-slate-500">Onde a pessoa achou que dava para clicar e não dava.</p>
                        <ul class="space-y-1">
                            <li v-for="c in dados.cliques_mortos" :key="`${c.secao}|${c.elemento}`" class="flex justify-between gap-2">
                                <span class="truncate">{{ c.elemento }}</span><span class="shrink-0 text-slate-500">{{ n(c.cliques) }} cliques · {{ pct(c.pct) }}</span>
                            </li>
                            <li v-if="!dados.cliques_mortos.length" class="text-slate-500">Nenhum.</li>
                        </ul>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                        <h2 class="mb-2 font-semibold">Até onde rolam</h2>
                        <div class="flex h-24 items-end gap-0.5">
                            <div v-for="c in dados.rolagem" :key="c.pct_pagina" class="flex-1 rounded-t bg-indigo-400" :style="{ height: `${c.pct_visitas ?? 0}%` }"
                                :title="`${c.pct_pagina}% da página: ${pct(c.pct_visitas)} das visitas`"></div>
                        </div>
                        <div class="flex justify-between text-[10px] text-slate-400"><span>topo</span><span>fim da página</span></div>
                        <ul v-if="secoes.length" class="mt-2 space-y-0.5">
                            <li v-for="s in secoes" :key="s.id" class="flex justify-between"><span>#{{ s.id }}</span><span class="font-semibold">{{ pct(s.pct) }} chegam</span></li>
                        </ul>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                        <h2 class="mb-2 font-semibold">Caminhos mais comuns</h2>
                        <ol class="space-y-1">
                            <li v-for="s in dados.sequencias" :key="s.sequencia" class="flex justify-between gap-2"><span>{{ s.sequencia }}</span><span class="shrink-0 text-slate-500">{{ n(s.visitas) }}</span></li>
                        </ol>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                        <h2 class="mb-2 font-semibold">Visitas (anônimas)</h2>
                        <div class="max-h-64 overflow-y-auto">
                            <button v-for="v in dados.visitas_recentes" :key="v.sessao" type="button" @click="abrirVisita(v)"
                                class="flex w-full justify-between gap-2 border-t border-slate-100 py-1 text-left hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800"
                                :class="visita?.sessao === v.sessao ? 'bg-indigo-50 dark:bg-indigo-900/30' : ''">
                                <span>{{ hora(v.inicio) }} · {{ v.pais || '??' }} · <span class="text-slate-500">{{ v.origem }}</span></span>
                                <span class="shrink-0 text-slate-500">{{ v.acoes }} ações · {{ v.segundos }} s</span>
                            </button>
                        </div>
                        <ol v-if="visita" class="mt-3 border-l-2 border-indigo-300 pl-3">
                            <li v-for="(p, i) in passos" :key="i" class="relative py-0.5">
                                <span class="absolute -left-[17px] top-2 h-2 w-2 rounded-full bg-indigo-500"></span>
                                <span class="text-xs text-slate-400">{{ p.segundo }} s<span v-if="p.desde_anterior !== null"> (+{{ p.desde_anterior }} s)</span></span>
                                {{ p.texto }}
                            </li>
                            <li v-if="!passos.length" class="text-slate-500">Carregando…</li>
                        </ol>
                    </section>
                </div>
            </Deferred>
        </div>
    </AdminLayout>
</template>
