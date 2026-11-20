<script setup>
/**
 * Comportamento (pedido do Lira em 2026-11-19; celular menor, print na hora e Reproduzir no mesmo dia): mapa de cliques sobre o print
 * da página, onde clicam, cliques mortos, rolagem, caminhos e a linha do
 * tempo de cada visita (anônima).
 *
 * Layout em 3 colunas no computador (filtros | celular | dados) e empilhado
 * no celular. O aparelho é pequeno (tela de 270 px no celular, 320 px no
 * computador): o print e o mapa de calor são desenhados na MESMA escala
 * (largura da tela ÷ largura do print), então o x (% da largura) e o y (px da
 * página) de cada clique caem no lugar certo do print reduzido.
 *
 * O print chega com a página quando já existe; senão a tela pede um
 * (GerarPrintDaPagina, Chrome headless da VPS) e mostra "gerando a tela…".
 *
 * "▶ Reproduzir" anima os toques dentro do celular: a tela rola até o ponto,
 * aparece a onda do toque e o nome da ação. Fonte: a visita escolhida (na
 * ordem e no ritmo dela, 1x/2x/4x) ou os toques mais comuns do período.
 * Sem biblioteca: setTimeout, scrollTo e uma animação CSS.
 *
 * Lista de páginas (2026-11-20): TODA página acessada das lojas e dos
 * infoprodutos (PaginasDoSite), com nome, visitas, carrinho e checkout, numa
 * lista com busca na coluna da esquerda; embaixo, o funil da loja no período
 * (visitas → carrinho → checkout → pedido).
 */
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Deferred, Head, router } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';

const props = defineProps({
    periodo: { type: String, required: true },
    periodos: { type: Object, default: () => ({}) },
    aparelho: { type: String, required: true },
    loja: { type: String, default: 'kazakora' },
    paginas: { type: Array, default: () => [] },
    totais: { type: Object, default: () => ({}) },
    pagina: { type: Object, default: null },
    print: { type: Object, default: null },
    dados: { type: Object, default: null },
});

const LOJAS = { kazakora: 'KazaKora', ferrakora: 'FerraKora', infoprodutos: 'Infoprodutos' };
const n = (v) => (v === null || v === undefined ? '-' : new Intl.NumberFormat('pt-BR').format(v));
const pct = (v) => (v === null || v === undefined ? '-' : `${Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%`);
const hora = (iso) => new Date(iso).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });

const paginasDaLoja = computed(() => props.paginas.filter((p) => p.loja === props.loja));
const buscaPagina = ref('');
const semAcento = (t) => String(t || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
const paginasFiltradas = computed(() => {
    const q = semAcento(buscaPagina.value.trim());
    return q ? paginasDaLoja.value.filter((p) => semAcento(p.rotulo).includes(q) || p.produto.includes(q)) : paginasDaLoja.value;
});
const ehEscolhida = (p) => props.pagina && p.produto === props.pagina.produto && p.pagina === props.pagina.pagina;

// Funil da loja no período: cada degrau com a % do degrau anterior.
const funil = computed(() => {
    const t = props.totais?.[props.loja];
    if (!t) return [];
    const degraus = [
        { nome: 'Visitas', valor: t.visitas, cor: 'bg-indigo-500' },
        { nome: props.loja === 'ferrakora' ? 'Clicou em comprar' : 'Carrinho', valor: t.carrinho, cor: 'bg-sky-500' },
        { nome: props.loja === 'infoprodutos' ? 'Foi ao checkout (Kiwify)' : 'Checkout', valor: t.checkout, cor: 'bg-amber-500' },
        { nome: props.loja === 'infoprodutos' ? 'Vendas' : 'Pedidos', valor: t.pedidos, cor: 'bg-emerald-500' },
    ].filter((d) => d.valor !== null && d.valor !== undefined);
    const topo = Math.max(1, degraus[0]?.valor || 0);
    return degraus.map((d, i) => ({ ...d, largura: Math.max(2, Math.round((d.valor / topo) * 100)), taxa: i && degraus[i - 1].valor ? (d.valor / degraus[i - 1].valor) * 100 : null }));
});
const ir = (mudanca) => router.get('/admin/publicidade/comportamento', {
    loja: props.loja, produto: props.pagina?.produto, pagina: props.pagina?.pagina, aparelho: props.aparelho, periodo: props.periodo, ...mudanca,
}, { preserveScroll: true });
const escolherPagina = (valor) => {
    const [produto, pagina] = valor.split('|');
    ir({ produto, pagina });
};

// Aparelho e escala -------------------------------------------------------
const celular = computed(() => props.aparelho !== 'computador');
const TELA_LARGURA = computed(() => (celular.value ? 270 : 320));
const TELA_ALTURA = computed(() => (celular.value ? 560 : 230));
const larguraOriginal = computed(() => printAtual.value?.largura || (celular.value ? 390 : 1024));
const escala = computed(() => TELA_LARGURA.value / larguraOriginal.value);
const alturaPrint = computed(() => Math.min(printAtual.value?.altura || 3000, 16000));
const alturaConteudo = computed(() => Math.round(alturaPrint.value * escala.value));

// Print da página (na hora, ou gerado sob demanda) ------------------------
const printAtual = ref(props.print);
const situacao = ref(props.print ? 'pronto' : 'carregando'); // pronto | carregando | gerando | erro | sem_endereco
const mensagem = ref('');
const imagemPronta = ref(false);
const tela = ref(null);
let consulta = null;
let tentativas = 0;

const buscarPrint = async (deNovo = false) => {
    clearTimeout(consulta);
    if (!props.pagina) return;
    const alvo = `${props.pagina.produto}|${props.pagina.pagina}|${props.aparelho}`;
    const q = new URLSearchParams({ produto: props.pagina.produto, pagina: props.pagina.pagina, aparelho: props.aparelho });
    if (deNovo) q.set('de_novo', '1');
    let r = null;
    try {
        const resp = await fetch(`/admin/publicidade/comportamento/print/info?${q}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        r = resp.ok ? await resp.json() : null;
    } catch { r = null; }
    // A página mudou enquanto esperava: ignora a resposta velha.
    if (alvo !== `${props.pagina?.produto}|${props.pagina?.pagina}|${props.aparelho}`) return;
    if (!r) { situacao.value = 'erro'; mensagem.value = 'Não consegui consultar o print agora.'; return; }
    if (r.situacao === 'pronto') { printAtual.value = r.print; situacao.value = 'pronto'; return; }
    situacao.value = r.situacao;
    mensagem.value = r.mensagem || '';
    if (r.situacao === 'gerando' && ++tentativas < 60) consulta = setTimeout(() => buscarPrint(), 3000);
};


// Mapa de calor (desenhado já na escala da tela) --------------------------
const mostrarMapa = ref(true);
const opacidade = ref(0.7);
const canvas = ref(null);

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
    if (!el) return;
    const w = TELA_LARGURA.value;
    const h = Math.max(1, alturaConteudo.value);
    const e = escala.value;
    el.width = w; el.height = h;
    const ctx = el.getContext('2d');
    ctx.clearRect(0, 0, w, h);
    const pontos = props.dados?.pontos ?? [];
    if (!pontos.length) return;
    paleta ??= montarPaleta();
    const max = Math.max(...pontos.map((p) => p.n));
    const raio = Math.max(8, Math.round((celular.value ? 22 : 30) * e * (celular.value ? 1 : 2)));
    for (const p of pontos) {
        const x = (p.x / 100) * w;
        const y = p.y * e;
        const g = ctx.createRadialGradient(x, y, 0, x, y, raio);
        g.addColorStop(0, `rgba(0,0,0,${Math.min(1, 0.15 + (p.n / max) * 0.85)})`);
        g.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = g;
        ctx.fillRect(x - raio, y - raio, raio * 2, raio * 2);
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
watch(() => [props.dados, printAtual.value, mostrarMapa.value, escala.value], () => nextTick(desenhar), { immediate: true });

// Rolagem por seção: % das visitas que chegam ao topo de cada seção do print.
const secoes = computed(() => {
    const curva = props.dados?.rolagem ?? [];
    const altura = alturaPrint.value;
    return (printAtual.value?.secoes ?? []).map((s) => {
        const p = Math.min(100, Math.floor((s.topo / altura) * 100 / 5) * 5);
        return { id: s.id, topo: s.topo, pct: curva.find((c) => c.pct_pagina === p)?.pct_visitas ?? null };
    });
});

// Linha do tempo de uma visita ---------------------------------------------
const visita = ref(null);
const passos = ref([]);
const carregandoVisita = ref(false);
const abrirVisita = async (v) => {
    parar();
    visita.value = v;
    passos.value = [];
    carregandoVisita.value = true;
    const url = `/admin/publicidade/comportamento/visita?sessao=${encodeURIComponent(v.sessao)}&produto=${encodeURIComponent(props.pagina.produto)}`;
    try {
        const r = await fetch(url, { headers: { Accept: 'application/json' } });
        passos.value = r.ok ? (await r.json()).passos : [];
    } catch { passos.value = []; }
    carregandoVisita.value = false;
    if (visita.value?.sessao === v.sessao && passos.value.length) reproduzir('visita');
};

// Reproduzir -----------------------------------------------------------------
const player = reactive({ fonte: 'comuns', estado: 'parado', i: 0, vel: 1, fila: [] });
const legenda = ref('');
const ondas = ref([]);
let relogio = null;
let idOnda = 0;

const filaDaVisita = () => passos.value.map((p) => {
    const daPagina = p.pagina === props.pagina?.pagina;
    let x = null; let y = null;
    if (daPagina && p.x !== null && p.x !== undefined && p.y !== null && p.y !== undefined) { x = p.x; y = p.y; }
    else if (daPagina && p.tipo === 'scroll' && p.valor !== null && p.valor !== undefined) y = (alturaPrint.value * p.valor) / 100;
    else if (daPagina && p.tipo === 'pageview') y = 0;
    return {
        texto: daPagina || !p.pagina ? p.texto : `${p.texto} (${p.pagina})`,
        x, y, toque: x !== null,
        espera: p.desde_anterior === null || p.desde_anterior === undefined ? 0 : p.desde_anterior * 1000,
        rotuloTempo: p.desde_anterior ? `+${p.desde_anterior} s` : `${p.segundo ?? 0} s`,
    };
});
const filaComum = () => (props.dados?.toques ?? []).map((t, i) => ({
    texto: `${t.texto} · ${pct(t.pct)} das visitas`, x: t.x, y: t.y, toque: true, espera: i ? 1800 : 0, rotuloTempo: `${i + 1}º`,
}));

const rolarAte = (y) => {
    const el = tela.value;
    if (!el || y === null) return;
    el.scrollTo({ top: Math.max(0, y * escala.value - TELA_ALTURA.value / 2), behavior: player.vel > 2 ? 'auto' : 'smooth' });
};
const mostrarPasso = (p) => {
    legenda.value = p.texto;
    rolarAte(p.y);
    if (!p.toque) return;
    const id = ++idOnda;
    const atraso = Math.round(450 / player.vel);
    setTimeout(() => {
        ondas.value = [...ondas.value, { id, x: (p.x / 100) * TELA_LARGURA.value, y: p.y * escala.value }];
        setTimeout(() => { ondas.value = ondas.value.filter((o) => o.id !== id); }, 1250);
    }, atraso);
};
const proximo = () => {
    if (player.estado !== 'tocando') return;
    if (player.i >= player.fila.length) {
        player.estado = 'fim';
        legenda.value = player.fonte === 'visita' ? 'Fim da visita' : 'Fim dos toques mais comuns';
        return;
    }
    mostrarPasso(player.fila[player.i]);
    player.i += 1;
    const prox = player.fila[player.i];
    const espera = prox ? Math.min(4000, Math.max(800, prox.espera)) : 1500;
    relogio = setTimeout(proximo, espera / player.vel);
};
const reproduzir = (fonte = player.fonte) => {
    clearTimeout(relogio);
    player.fonte = fonte;
    player.fila = fonte === 'visita' ? filaDaVisita() : filaComum();
    player.i = 0;
    ondas.value = [];
    if (!player.fila.length) { player.estado = 'parado'; legenda.value = fonte === 'visita' ? 'Escolha uma visita na lista' : 'Nenhum toque no período'; return; }
    player.estado = 'tocando';
    proximo();
};
const pausar = () => { clearTimeout(relogio); player.estado = 'pausado'; };
const continuar = () => { player.estado = 'tocando'; proximo(); };
function parar() {
    clearTimeout(relogio);
    player.estado = 'parado';
    player.i = 0;
    ondas.value = [];
    legenda.value = '';
}
// Trocou de página/aparelho: para a reprodução e mostra o print novo (ou pede um).
watch(() => `${props.pagina?.produto}|${props.pagina?.pagina}|${props.aparelho}|${props.print?.url ?? ''}`, () => {
    parar();
    clearTimeout(consulta);
    tentativas = 0;
    printAtual.value = props.print;
    if (tela.value) tela.value.scrollTop = 0;
    if (props.print) situacao.value = 'pronto';
    else if (props.pagina) { situacao.value = 'carregando'; buscarPrint(); }
}, { immediate: true });
// Fatias do print (1.400 px cada): a 1ª entra na hora, as outras em sequência
// (e a que estiver na tela pula a fila). Print antigo sem fatias = 1 pedaço só.
// Por cima de uma miniatura borrada (≤ 3 KB, vem junto da página).
const fatias = computed(() => {
    const p = printAtual.value;
    if (!p) return [];
    return p.fatias?.length ? p.fatias : [{ url: p.url, topo: 0, altura: p.altura }];
});
const carregadas = ref(1);
const srcs = ref({});
const tentouPorFetch = new Set();
const fatiaPronta = (i) => {
    if (i === 0) imagemPronta.value = true;
    carregadas.value = Math.max(carregadas.value, Math.min(fatias.value.length, i + 2));
};
// Se uma fatia não abrir (extensão do navegador barrando, rede), tenta uma vez
// por fetch e mostra o arquivo baixado; só então avisa o erro.
const erroDaFatia = async (i) => {
    const url = fatias.value[i]?.url;
    if (!url || tentouPorFetch.has(url)) { if (i === 0) { situacao.value = 'erro'; mensagem.value = 'O print não abriu.'; } return; }
    tentouPorFetch.add(url);
    try {
        const r = await fetch(url, { credentials: 'same-origin' });
        if (!r.ok) throw new Error(String(r.status));
        const blob = URL.createObjectURL(await r.blob());
        if (fatias.value[i]?.url === url) srcs.value = { ...srcs.value, [i]: blob };
    } catch {
        if (i === 0) { situacao.value = 'erro'; mensagem.value = 'O print não abriu.'; }
    }
};
const aoRolar = () => {
    const el = tela.value;
    if (!el || !fatias.value.length) return;
    const y = (el.scrollTop + TELA_ALTURA.value) / escala.value;
    const na = fatias.value.findIndex((f) => f.topo + f.altura >= y);
    carregadas.value = Math.max(carregadas.value, (na < 0 ? fatias.value.length : na + 1));
};
watch(() => printAtual.value?.url, () => {
    imagemPronta.value = false;
    carregadas.value = 1;
    Object.values(srcs.value).forEach((u) => URL.revokeObjectURL(u));
    srcs.value = {};
    tentouPorFetch.clear();
}, { immediate: true });
onBeforeUnmount(() => { clearTimeout(relogio); clearTimeout(consulta); });
</script>

<template>
    <Head title="Comportamento" />

    <AdminLayout>
        <h1 class="mb-1 text-2xl font-bold">Comportamento</h1>
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
            Onde as pessoas clicam, até onde rolam e como navegam. Anônimo: sem cookie, sem IP, só um código aleatório por aba.
        </p>

        <div class="grid min-w-0 gap-5 lg:grid-cols-3">
            <!-- Coluna 1: filtros -->
            <div class="min-w-0 space-y-3 text-sm">
                <div class="flex flex-wrap gap-1.5">
                    <button v-for="(nome, chave) in LOJAS" :key="chave" type="button" @click="ir({ loja: chave, produto: undefined, pagina: undefined })"
                        class="rounded-full border px-3 py-1" :class="chave === loja ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-300 dark:border-slate-600'">{{ nome }}</button>
                </div>
                <div>
                    <div class="mb-1 flex items-baseline justify-between text-xs text-slate-500">
                        <span>Páginas ({{ n(paginasDaLoja.length) }})</span>
                        <span>visitas · carrinho · checkout</span>
                    </div>
                    <input v-model="buscaPagina" type="search" placeholder="Buscar página ou produto" class="mb-1 w-full rounded border-slate-300 px-2 py-1 text-sm dark:border-slate-600 dark:bg-slate-800">
                    <div class="max-h-64 overflow-y-auto rounded border border-slate-200 dark:border-slate-700">
                        <button v-for="p in paginasFiltradas" :key="`${p.produto}|${p.pagina}`" type="button" :title="p.rotulo"
                            class="flex w-full items-center gap-2 border-b border-slate-100 px-2 py-1 text-left text-xs last:border-0 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800"
                            :class="ehEscolhida(p) ? 'bg-indigo-50 font-semibold dark:bg-indigo-900/30' : ''" @click="escolherPagina(`${p.produto}|${p.pagina}`)">
                            <span class="min-w-0 flex-1 truncate">{{ p.rotulo }}</span>
                            <span class="shrink-0 tabular-nums">{{ n(p.visitas) }}</span>
                            <span class="w-14 shrink-0 text-right tabular-nums text-slate-500">{{ n(p.carrinho) }} · {{ n(p.checkout) }}</span>
                        </button>
                        <p v-if="!paginasFiltradas.length" class="px-2 py-2 text-xs text-slate-500">{{ paginasDaLoja.length ? 'Nenhuma página com esse nome.' : 'Sem visitas no período.' }}</p>
                    </div>
                </div>
                <div class="flex overflow-hidden rounded border border-slate-300 dark:border-slate-600">
                    <button type="button" class="flex-1 px-3 py-1" :class="celular ? 'bg-indigo-600 text-white' : ''" @click="ir({ aparelho: 'celular' })"><i class="fas fa-mobile-screen"></i> Celular</button>
                    <button type="button" class="flex-1 px-3 py-1" :class="!celular ? 'bg-indigo-600 text-white' : ''" @click="ir({ aparelho: 'computador' })"><i class="fas fa-desktop"></i> Computador</button>
                </div>
                <label class="block">
                    <span class="mb-1 block text-xs text-slate-500">Período</span>
                    <select class="w-full rounded border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-800" :value="periodo" @change="ir({ periodo: $event.target.value })">
                        <option v-for="(rotulo, chave) in periodos" :key="chave" :value="chave">{{ rotulo }}</option>
                    </select>
                </label>
                <div class="space-y-2 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                    <label class="flex items-center gap-2"><input v-model="mostrarMapa" type="checkbox"> Mapa de calor</label>
                    <label class="flex items-center gap-2">Opacidade <input v-model.number="opacidade" type="range" min="0.1" max="1" step="0.05" class="min-w-0 flex-1"></label>
                    <div class="flex items-center gap-2 text-xs text-slate-500">
                        pouco <span class="h-2 flex-1 rounded" style="background: linear-gradient(90deg, #005aff, #00c850, #ffe600, #ff0000)"></span> muito
                    </div>
                </div>
                <p v-if="printAtual?.em" class="text-xs text-slate-400">
                    Print de {{ hora(printAtual.em) }}<template v-if="printAtual.pagina_url"> · <a :href="printAtual.pagina_url" target="_blank" rel="noopener" class="underline">abrir a página</a></template>
                </p>

                <!-- Funil da loja no período (todas as páginas) -->
                <section v-if="funil.length" class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                    <h2 class="text-sm font-semibold">Funil · {{ LOJAS[loja] }} · {{ periodos[periodo] || periodo }}</h2>
                    <p class="mb-2 text-xs text-slate-500">Pessoas diferentes, sem robôs e sem a equipe. {{ n(totais[loja]?.paginas) }} páginas acessadas.</p>
                    <ol class="space-y-1.5">
                        <li v-for="d in funil" :key="d.nome">
                            <div class="flex items-baseline justify-between text-xs">
                                <span>{{ d.nome }}</span>
                                <span><b class="text-sm tabular-nums">{{ n(d.valor) }}</b><span v-if="d.taxa !== null" class="ml-1 text-slate-500">({{ pct(d.taxa) }})</span></span>
                            </div>
                            <div class="h-2 rounded bg-slate-100 dark:bg-slate-800"><div class="h-2 rounded" :class="d.cor" :style="{ width: `${d.largura}%` }"></div></div>
                        </li>
                    </ol>
                    <p class="mt-2 text-[11px] text-slate-400">% = do degrau anterior.<template v-if="loja === 'ferrakora'"> Comprar: cliques medidos na página do produto.</template><template v-if="loja === 'infoprodutos'"> Checkout: clicou em comprar; vendas aprovadas.</template></p>
                </section>
            </div>

            <!-- Coluna 2: o aparelho com o print, o mapa e o Reproduzir -->
            <div class="flex min-w-0 flex-col items-center">
                <p v-if="!pagina" class="w-full rounded bg-slate-50 p-4 text-sm text-slate-600 dark:bg-slate-800">Nenhuma visita medida nesta loja no período.</p>
                <template v-else>
                    <div class="relative max-w-full shadow-xl" :class="celular ? 'rounded-[34px] border-[8px] border-slate-900 bg-slate-900' : 'rounded-lg border-[8px] border-slate-800 bg-slate-800'">
                        <div v-if="celular" class="absolute left-1/2 top-0 z-20 h-3 w-16 -translate-x-1/2 rounded-b-xl bg-slate-900"></div>
                        <div ref="tela" class="relative overflow-y-auto overflow-x-hidden bg-white" @scroll.passive="aoRolar" :class="celular ? 'rounded-[26px]' : ''"
                            :style="{ width: `${TELA_LARGURA}px`, height: `${TELA_ALTURA}px`, maxWidth: '100%' }">
                            <div class="relative" :style="{ width: `${TELA_LARGURA}px`, height: `${printAtual ? alturaConteudo : TELA_ALTURA}px` }">
                                <div v-if="printAtual?.miniatura" class="absolute inset-0 overflow-hidden" aria-hidden="true">
                                    <div class="absolute -inset-2 bg-no-repeat" :style="{ backgroundImage: `url(${printAtual.miniatura})`, backgroundSize: '100% 100%', filter: 'blur(6px)' }"></div>
                                </div>
                                <template v-if="printAtual">
                                    <img v-for="(f, i) in fatias" v-show="i < carregadas" :key="f.url" :src="i < carregadas ? (srcs[i] || f.url) : undefined"
                                        :width="TELA_LARGURA" :height="Math.round(f.altura * escala)" :alt="i === 0 ? 'Print da página' : ''"
                                        :fetchpriority="i === 0 ? 'high' : 'auto'" class="absolute left-0 block max-w-none select-none" draggable="false"
                                        :style="{ top: `${Math.round(f.topo * escala)}px`, width: `${TELA_LARGURA}px`, height: `${Math.round(f.altura * escala)}px` }"
                                        @load="fatiaPronta(i)" @error="erroDaFatia(i)">
                                </template>
                                <canvas v-show="mostrarMapa" ref="canvas" class="pointer-events-none absolute left-0 top-0" :style="{ opacity: opacidade, width: `${TELA_LARGURA}px`, height: `${alturaConteudo}px` }"></canvas>
                                <span v-for="o in ondas" :key="o.id" class="toque" :style="{ left: `${o.x}px`, top: `${o.y}px` }"></span>
                            </div>
                        </div>
                        <!-- Aviso por cima da tela enquanto o print não aparece -->
                        <div v-if="situacao !== 'pronto' || (printAtual && !imagemPronta && !printAtual.miniatura)" class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center p-6 text-center text-xs text-slate-500">
                            <span v-if="situacao === 'gerando' || situacao === 'carregando'" class="rounded bg-white/90 px-3 py-2 shadow"><i class="fas fa-spinner fa-spin"></i> gerando a tela…</span>
                            <span v-else-if="situacao === 'pronto'" class="rounded bg-white/90 px-3 py-2 shadow"><i class="fas fa-spinner fa-spin"></i> carregando a tela…</span>
                            <span v-else class="pointer-events-auto rounded bg-white/95 px-3 py-2 shadow">{{ mensagem }}
                                <button v-if="situacao === 'erro'" type="button" class="mt-1 block w-full text-indigo-600 underline" @click="situacao = 'gerando'; buscarPrint(true)">tentar de novo</button>
                            </span>
                        </div>
                        <!-- Nome da ação que está sendo reproduzida -->
                        <div v-if="legenda" class="pointer-events-none absolute inset-x-2 bottom-3 z-20 rounded-lg bg-slate-900/85 px-2.5 py-1.5 text-center text-xs font-medium text-white shadow">
                            <span v-if="player.fila[player.i - 1]" class="mr-1 text-indigo-300">{{ player.fila[player.i - 1].rotuloTempo }}</span>{{ legenda }}
                        </div>
                    </div>
                    <div v-if="!celular" class="h-4 w-24 bg-slate-700"></div>

                    <div class="mt-3 w-full max-w-[300px] space-y-2 text-xs">
                        <div class="flex overflow-hidden rounded border border-slate-300 dark:border-slate-600">
                            <button type="button" class="flex-1 px-2 py-1" :class="player.fonte === 'visita' ? 'bg-slate-800 text-white dark:bg-slate-200 dark:text-slate-900' : ''"
                                :disabled="!visita" :title="visita ? '' : 'Clique numa visita na lista'" @click="player.fonte = 'visita'; parar()">Visita escolhida</button>
                            <button type="button" class="flex-1 px-2 py-1" :class="player.fonte === 'comuns' ? 'bg-slate-800 text-white dark:bg-slate-200 dark:text-slate-900' : ''" @click="player.fonte = 'comuns'; parar()">Toques mais comuns</button>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button v-if="player.estado === 'tocando'" type="button" class="flex-1 rounded bg-indigo-600 px-3 py-1.5 font-semibold text-white" @click="pausar">❚❚ Pausar</button>
                            <button v-else-if="player.estado === 'pausado'" type="button" class="flex-1 rounded bg-indigo-600 px-3 py-1.5 font-semibold text-white" @click="continuar">▶ Continuar</button>
                            <button v-else type="button" class="flex-1 rounded bg-indigo-600 px-3 py-1.5 font-semibold text-white disabled:opacity-50" :disabled="situacao !== 'pronto'" @click="reproduzir()">▶ Reproduzir</button>
                            <button type="button" class="rounded border border-slate-300 px-2.5 py-1.5 dark:border-slate-600" :disabled="player.estado === 'parado'" title="Parar" @click="parar">■</button>
                            <button v-for="v in [1, 2, 4]" :key="v" type="button" class="rounded border px-2 py-1.5" :class="player.vel === v ? 'border-indigo-600 text-indigo-600' : 'border-slate-300 dark:border-slate-600'" @click="player.vel = v">{{ v }}x</button>
                        </div>
                        <p v-if="player.estado !== 'parado'" class="text-center text-slate-400">{{ Math.min(player.i, player.fila.length) }} de {{ player.fila.length }}</p>
                    </div>
                </template>
            </div>

            <!-- Coluna 3: dados compactos -->
            <div class="min-w-0">
                <Deferred data="dados">
                    <template #fallback><p class="py-10 text-center text-sm text-slate-500">Lendo os cliques…</p></template>
                    <div v-if="dados" class="space-y-2.5 text-xs">
                        <div v-if="pagina" class="grid grid-cols-4 gap-2">
                            <div><div class="text-slate-500">Visitas</div><div class="text-lg font-bold tabular-nums">{{ n(pagina.visitas) }}</div></div>
                            <div><div class="text-slate-500">{{ loja === 'ferrakora' ? 'Comprar' : 'Carrinho' }}</div><div class="text-lg font-bold tabular-nums">{{ n(pagina.carrinho) }}</div></div>
                            <div><div class="text-slate-500">Checkout</div><div class="text-lg font-bold tabular-nums">{{ n(pagina.checkout) }}</div></div>
                            <div><div class="text-slate-500">{{ loja === 'infoprodutos' ? 'Vendas' : 'Pedidos' }}</div><div class="text-lg font-bold tabular-nums">{{ n(pagina.pedidos) }}</div></div>
                        </div>
                        <div class="flex gap-4">
                            <div><div class="text-slate-500">Medidas no mapa</div><div class="text-base font-bold tabular-nums">{{ n(dados.visitas) }}</div></div>
                            <div><div class="text-slate-500">Tempo médio</div><div class="text-base font-bold">{{ dados.tempo_medio !== null ? `${dados.tempo_medio} s` : '-' }}</div></div>
                        </div>
                        <p v-if="!dados.visitas" class="text-slate-500">Esta página ainda não tem medição de cliques (o mapa só aparece onde a tag de medição roda). As visitas vêm do registro de acessos.</p>

                        <section class="rounded-lg border border-slate-200 bg-white p-2.5 dark:border-slate-700 dark:bg-slate-900">
                            <h2 class="mb-1.5 text-sm font-semibold">Onde clicam</h2>
                            <ol class="space-y-0.5">
                                <li v-for="c in dados.onde_clicam.slice(0, 12)" :key="c.elemento" class="flex items-center gap-2">
                                    <span class="min-w-0 flex-1 truncate" :title="c.elemento">{{ c.elemento }}</span>
                                    <span class="h-1.5 w-14 shrink-0 overflow-hidden rounded bg-slate-100 dark:bg-slate-800"><span class="block h-1.5 bg-indigo-500" :style="{ width: `${Math.min(100, c.pct ?? 0)}%` }"></span></span>
                                    <span class="w-11 shrink-0 text-right font-semibold">{{ pct(c.pct) }}</span>
                                </li>
                                <li v-if="!dados.onde_clicam.length" class="text-slate-500">Nenhum clique ainda.</li>
                            </ol>
                        </section>

                        <section class="rounded-lg border border-slate-200 bg-white p-2.5 dark:border-slate-700 dark:bg-slate-900">
                            <h2 class="text-sm font-semibold">Cliques mortos</h2>
                            <p class="mb-1 text-slate-500">Onde acharam que dava para clicar e não dava.</p>
                            <ul class="space-y-0.5">
                                <li v-for="c in dados.cliques_mortos.slice(0, 8)" :key="`${c.secao}|${c.elemento}`" class="flex justify-between gap-2">
                                    <span class="min-w-0 truncate" :title="c.elemento">{{ c.elemento }}</span><span class="shrink-0 text-slate-500">{{ n(c.cliques) }} · {{ pct(c.pct) }}</span>
                                </li>
                                <li v-if="!dados.cliques_mortos.length" class="text-slate-500">Nenhum.</li>
                            </ul>
                        </section>

                        <section class="rounded-lg border border-slate-200 bg-white p-2.5 dark:border-slate-700 dark:bg-slate-900">
                            <h2 class="mb-1.5 text-sm font-semibold">Rolagem</h2>
                            <div class="flex h-14 items-end gap-0.5">
                                <div v-for="c in dados.rolagem" :key="c.pct_pagina" class="flex-1 rounded-t bg-indigo-400" :style="{ height: `${c.pct_visitas ?? 0}%` }"
                                    :title="`${c.pct_pagina}% da página: ${pct(c.pct_visitas)} das visitas`"></div>
                            </div>
                            <div class="flex justify-between text-[10px] text-slate-400"><span>topo</span><span>fim da página</span></div>
                            <ul v-if="secoes.length" class="mt-1 space-y-0.5">
                                <li v-for="s in secoes" :key="s.id" class="flex justify-between"><button type="button" class="hover:underline" @click="rolarAte(s.topo + TELA_ALTURA / 2 / escala)">#{{ s.id }}</button><span class="font-semibold">{{ pct(s.pct) }} chegam</span></li>
                            </ul>
                        </section>

                        <section class="rounded-lg border border-slate-200 bg-white p-2.5 dark:border-slate-700 dark:bg-slate-900">
                            <h2 class="mb-1.5 text-sm font-semibold">Caminhos mais comuns</h2>
                            <ol class="space-y-0.5">
                                <li v-for="s in dados.sequencias" :key="s.sequencia" class="flex justify-between gap-2"><span class="min-w-0">{{ s.sequencia }}</span><span class="shrink-0 text-slate-500">· {{ n(s.visitas) }}</span></li>
                                <li v-if="!dados.sequencias.length" class="text-slate-500">Nenhum ainda.</li>
                            </ol>
                        </section>

                        <section class="rounded-lg border border-slate-200 bg-white p-2.5 dark:border-slate-700 dark:bg-slate-900">
                            <h2 class="text-sm font-semibold">Visitas (anônimas)</h2>
                            <p class="mb-1 text-slate-500">Clique numa visita para ver ela no celular.</p>
                            <div class="max-h-56 overflow-y-auto">
                                <button v-for="v in dados.visitas_recentes" :key="v.sessao" type="button" @click="abrirVisita(v)"
                                    class="flex w-full justify-between gap-2 border-t border-slate-100 px-1 py-1 text-left hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800"
                                    :class="visita?.sessao === v.sessao ? 'bg-indigo-50 dark:bg-indigo-900/30' : ''">
                                    <span class="min-w-0 truncate">▶ {{ hora(v.inicio) }} · {{ v.pais || '??' }} · <span class="text-slate-500">{{ v.origem }}</span></span>
                                    <span class="shrink-0 text-slate-500">{{ v.acoes }} ações · {{ v.segundos }} s</span>
                                </button>
                                <p v-if="!dados.visitas_recentes.length" class="text-slate-500">Nenhuma visita no período.</p>
                            </div>
                            <ol v-if="visita" class="mt-2 border-l-2 border-indigo-300 pl-3">
                                <li v-for="(p, i) in passos" :key="i" class="relative py-0.5" :class="player.fonte === 'visita' && player.estado !== 'parado' && player.i - 1 === i ? 'font-semibold text-indigo-600' : ''">
                                    <span class="absolute -left-[17px] top-1.5 h-2 w-2 rounded-full bg-indigo-500"></span>
                                    <span class="text-slate-400">{{ p.segundo }} s<span v-if="p.desde_anterior !== null"> (+{{ p.desde_anterior }} s)</span></span>
                                    {{ p.texto }}
                                </li>
                                <li v-if="carregandoVisita" class="text-slate-500">Carregando…</li>
                            </ol>
                        </section>
                    </div>
                </Deferred>
            </div>
        </div>
    </AdminLayout>
</template>

<style scoped>
/* Onda do toque: anel que cresce e some (só CSS, sem biblioteca). */
.toque {
    position: absolute;
    width: 44px;
    height: 44px;
    margin: -22px 0 0 -22px;
    border-radius: 9999px;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.95) 0 5px, rgba(236, 72, 153, 0.45) 6px);
    border: 3px solid rgba(236, 72, 153, 0.95);
    box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.8);
    pointer-events: none;
    z-index: 5;
    animation: toque 1.2s ease-out forwards;
}
@keyframes toque {
    0% { transform: scale(0.5); opacity: 1; }
    60% { transform: scale(1.4); opacity: 0.8; }
    100% { transform: scale(2); opacity: 0; }
}
@media (prefers-reduced-motion: reduce) {
    .toque { animation-duration: 0.01s; }
}
</style>
