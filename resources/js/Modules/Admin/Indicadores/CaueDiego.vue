<script setup>
/**
 * Aba "Cauê e Diego" de Indicadores (2026-10-19): o bloco "O que ajustar
 * agora" do antigo Gerenciador, inteiro. Os botões do Cauê usam as mesmas
 * rotas e permissões de antes (/admin/diagnostico/shopee/..., só admin) e
 * recarregam só o diagnóstico.
 */
import { router, usePage } from '@inertiajs/vue3';
import { formatPrice } from '@/Shared/graficos';
import Swal from 'sweetalert2';
import { computed, ref } from 'vue';

const props = defineProps({
    diagnosticos: { type: Object, default: () => ({ shopee: null, mercado_livre: null }) },
});

// ---- Diagnóstico Cauê (Shopee) e Diego (Mercado Livre), pedido 2026-10-11 --
// Vem pronto do marketplace:diagnostico (a cada 3 h). Aqui só agrupa os
// alertas por produto e filtra pela prioridade.
const PRIORIDADES = {
    P0: { rotulo: 'Urgente', cor: '#dc2626', icone: 'fas fa-triangle-exclamation' },
    P1: { rotulo: 'Atenção', cor: '#d97706', icone: 'fas fa-circle-exclamation' },
    P2: { rotulo: 'Melhorar', cor: '#0d6efd', icone: 'fas fa-wand-magic-sparkles' },
    OK: { rotulo: 'Oportunidade', cor: '#16a34a', icone: 'fas fa-rocket' },
};
const ESPECIALISTAS = [
    { canal: 'shopee', nome: 'Cauê', canalNome: 'Shopee', fundo: 'linear-gradient(120deg, #c2410c 0%, #c2410c 50%, #ee4d2d 100%)', texto: '#ffffff', icone: 'fas fa-bag-shopping' },
    { canal: 'mercado_livre', nome: 'Diego', canalNome: 'Mercado Livre', fundo: 'linear-gradient(120deg, #ffe600 0%, #ffd000 100%)', texto: '#2d3277', icone: 'fas fa-handshake' },
];
// Filtro abre na primeira prioridade que tem alerta (Gerenciador,
// 2026-10-14): abrir em "Urgente" vazio parecia que não havia nada a fazer.
const filtroDiag = ref({ shopee: null, mercado_livre: null });
const mostrarTodosDiag = ref({ shopee: false, mercado_livre: false });

const gruposDiag = computed(() => Object.fromEntries(ESPECIALISTAS.map(({ canal }) => {
    const d = props.diagnosticos?.[canal];
    if (!d) return [canal, null];
    const contagem = { P0: 0, P1: 0, P2: 0, OK: 0 };
    const porProduto = new Map();
    for (const a of d.alertas ?? []) {
        contagem[a.prioridade] = (contagem[a.prioridade] ?? 0) + 1;
        const id = a.produto?.item_id;
        if (!porProduto.has(id)) porProduto.set(id, { produto: a.produto, alertas: [] });
        porProduto.get(id).alertas.push(a);
    }
    return [canal, { ...d, contagem, grupos: [...porProduto.values()] }];
})));

const diagPorCanal = computed(() => Object.fromEntries(ESPECIALISTAS.map(({ canal }) => {
    const d = gruposDiag.value[canal];
    if (!d) return [canal, null];
    const filtro = filtroDiag.value[canal] ?? Object.keys(PRIORIDADES).find((p) => d.contagem[p] > 0) ?? 'P0';
    const produtos = d.grupos.filter((g) => g.alertas.some((a) => a.prioridade === filtro));
    const outra = Object.keys(PRIORIDADES).find((p) => p !== filtro && d.contagem[p] > 0) ?? null;
    return [canal, { ...d, produtos, filtro, outra }];
})));
const diagnostico = (canal) => diagPorCanal.value[canal];

// Diagnóstico roda a cada 3 h; passou de 6 h sem atualizar, algo parou.
const HORAS_DIAG_PARADO = 6;
const horasDesde = (iso) => (iso ? (Date.now() - new Date(iso).getTime()) / 3600000 : 0);

// Botão de ação do Cauê (pedido 2026-10-14): manda a correção para a Shopee
// pela API e confere lendo de volta. Só admin; sempre confirma antes.
const ehAdmin = computed(() => usePage().props.auth?.user?.role === 'admin');
const ICONE_ACAO = { pausar_ads: 'fas fa-pause', meta_roas: 'fas fa-bullseye', preco: 'fas fa-tag' };
const aplicando = ref(null);
const esc = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const acaoFeita = (canal, itemId, acao) => props.diagnosticos?.[canal]?.feitas?.[itemId]?.[acao] ?? null;

async function aplicarAcao(produto, a) {
    let valor = a.valor;
    let modelId = null;
    if (a.acao === 'preco') {
        let modelos = [];
        try {
            const r = await fetch(`/admin/diagnostico/shopee/${produto.item_id}/modelos`, { headers: { Accept: 'application/json' } });
            modelos = (await r.json()).modelos ?? [];
        } catch { /* sem variações conhecidas: segue pelo item */ }
        const opcoes = Object.fromEntries(modelos.map((m) => [m.model_id, `${m.nome} (hoje ${formatPrice(m.original)})`]));
        const passo1 = await Swal.fire({
            title: 'Novo preço na Shopee',
            html: `<p class="text-sm">${esc(produto.nome)}</p><p class="mt-2 text-sm">Sugestão do Cauê: <b>${formatPrice(a.valor)}</b> (margem de 15% antes do ADS).</p>`,
            input: 'number',
            inputValue: a.valor,
            inputAttributes: { step: '0.01', min: '1' },
            showCancelButton: true,
            confirmButtonText: modelos.length ? 'Escolher variação' : 'Aplicar preço',
            cancelButtonText: 'Cancelar',
            reverseButtons: true,
            inputValidator: (v) => (!v || Number(v) <= 0 ? 'Informe o preço.' : null),
        });
        if (!passo1.isConfirmed) return;
        valor = Number(passo1.value);
        if (modelos.length) {
            const passo2 = await Swal.fire({
                title: 'Qual variação?',
                text: `Preço ${formatPrice(valor)} só na variação escolhida.`,
                input: 'select',
                inputOptions: opcoes,
                showCancelButton: true,
                confirmButtonText: 'Aplicar preço',
                cancelButtonText: 'Cancelar',
                reverseButtons: true,
            });
            if (!passo2.isConfirmed) return;
            modelId = passo2.value;
        }
    } else {
        const texto = a.acao === 'pausar_ads'
            ? 'Pausa todas as campanhas de ADS em andamento deste produto na Shopee. Dá para religar depois no Seller Center.'
            : `Muda a meta de ROAS das campanhas em andamento deste produto para ${formatNum(a.valor)} (empate + 2).`;
        const ok = await Swal.fire({
            icon: 'question',
            title: a.rotulo,
            html: `<p class="text-sm">${esc(produto.nome)}</p><p class="mt-2 text-sm">${texto}</p>`,
            showCancelButton: true,
            confirmButtonText: 'Aplicar na Shopee',
            cancelButtonText: 'Cancelar',
            reverseButtons: true,
        });
        if (!ok.isConfirmed) return;
    }
    aplicando.value = `${produto.item_id}:${a.acao}`;
    router.post('/admin/diagnostico/shopee/acao', { item_id: produto.item_id, acao: a.acao, valor, model_id: modelId }, {
        preserveScroll: true,
        // Só o diagnóstico (e o aviso) voltam; o resto da tela não muda.
        only: ['diagnosticos', 'flash'],
        onFinish: () => { aplicando.value = null; },
    });
}

const formatNum = (v, casas = 1) => (v === null || v === undefined ? '—' : Number(v).toLocaleString('pt-BR', { minimumFractionDigits: casas, maximumFractionDigits: casas }));
const quandoGerado = (iso) => {
    if (!iso) return '';
    const min = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    return min < 60 ? `há ${Math.max(1, min)} min` : `há ${Math.round(min / 60)} h`;
};
</script>

<template>
    <div>
        <h2 class="mb-1 flex items-center gap-2 text-lg font-bold">
            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary text-sm text-white" aria-hidden="true"><i class="fas fa-list-check" aria-hidden="true"></i></span>
            O que ajustar agora
        </h2>
        <p class="mb-4 text-sm text-[var(--texto-suave)]">Anúncio por anúncio, o que o Cauê (Shopee) e o Diego (Mercado Livre) mandam ajustar para vender mais.</p>
        <!-- Cauê (Shopee) e Diego (Mercado Livre): o que ajustar para vender
             mais, anúncio por anúncio (pedido 2026-10-11). -->
        <section v-for="esp in ESPECIALISTAS" :key="esp.canal" class="mb-4 overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3" :style="{ background: esp.fundo, color: esp.texto }">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-white/25 text-lg ring-1 ring-white/40"><i :class="esp.icone" aria-hidden="true"></i></span>
                    <div>
                        <h3 class="text-lg font-bold leading-tight">{{ esp.nome }} · {{ esp.canalNome }}</h3>
                        <p class="text-xs font-semibold">Anúncios que pedem ação · últimos 30 dias<template v-if="diagnosticos?.[esp.canal]"> · atualizado {{ quandoGerado(diagnosticos[esp.canal].gerado_em) }}</template></p>
                    </div>
                </div>
            </div>

            <div v-if="!diagnostico(esp.canal)" class="p-6 text-sm text-[var(--texto-suave)]">
                O primeiro diagnóstico sai em até 3 horas.
            </div>
            <template v-else>
                <p v-if="horasDesde(diagnostico(esp.canal).gerado_em) > HORAS_DIAG_PARADO"
                    class="mx-4 mt-4 flex items-center gap-2 rounded-lg bg-lightwarning px-3 py-2 text-sm font-semibold text-warning">
                    <i class="fas fa-clock" aria-hidden="true"></i>
                    Diagnóstico sem atualizar há {{ Math.round(horasDesde(diagnostico(esp.canal).gerado_em)) }} h. Os números abaixo podem estar velhos.
                </p>
                <div class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-3 xl:grid-cols-6">
                    <div class="rounded-lg border border-[var(--surface-border)] p-3">
                        <p class="text-xs text-[var(--texto-suave)]">Pedidos</p>
                        <p class="text-xl font-bold">{{ diagnostico(esp.canal).kpis.pedidos }}</p>
                    </div>
                    <div class="rounded-lg border border-[var(--surface-border)] p-3">
                        <p class="text-xs text-[var(--texto-suave)]">Faturamento</p>
                        <p class="text-xl font-bold">{{ formatPrice(diagnostico(esp.canal).kpis.faturamento) }}</p>
                    </div>
                    <div class="rounded-lg border border-[var(--surface-border)] p-3">
                        <p class="text-xs text-[var(--texto-suave)]">Gasto com ADS</p>
                        <p class="text-xl font-bold">{{ formatPrice(diagnostico(esp.canal).kpis.ads_gasto) }}</p>
                    </div>
                    <div class="rounded-lg border border-[var(--surface-border)] p-3">
                        <p class="text-xs text-[var(--texto-suave)]">ROAS do ADS</p>
                        <p class="text-xl font-bold">{{ formatNum(diagnostico(esp.canal).kpis.ads_roas) }}</p>
                    </div>
                    <div class="rounded-lg border border-[var(--surface-border)] p-3" title="ROAS mínimo para sobrar 15% de margem, na média do que a loja vende">
                        <p class="text-xs text-[var(--texto-suave)]">ROAS mínimo para 15% de margem</p>
                        <p class="text-xl font-bold" :class="(diagnostico(esp.canal).kpis.ads_roas ?? 0) >= (diagnostico(esp.canal).kpis.roas_meta_loja ?? 0) ? 'text-success' : 'text-error'">{{ formatNum(diagnostico(esp.canal).kpis.roas_meta_loja) }}</p>
                    </div>
                    <div class="rounded-lg border border-[var(--surface-border)] p-3">
                        <p class="text-xs text-[var(--texto-suave)]">{{ esp.canal === 'mercado_livre' ? 'Qualidade média (ML)' : 'Anúncios em ADS' }}</p>
                        <p class="text-xl font-bold">
                            <template v-if="esp.canal === 'mercado_livre'">{{ diagnostico(esp.canal).kpis.qualidade_media ?? '—' }}<span class="text-sm font-normal text-[var(--texto-suave)]">/100</span></template>
                            <template v-else>{{ diagnostico(esp.canal).kpis.em_ads }}<span class="text-sm font-normal text-[var(--texto-suave)]"> de {{ diagnostico(esp.canal).kpis.anuncios }}</span></template>
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 px-4">
                    <button v-for="(cfg, chave) in PRIORIDADES" :key="chave" type="button"
                        class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm font-semibold transition"
                        :style="diagnostico(esp.canal).filtro === chave ? { background: cfg.cor, borderColor: cfg.cor, color: '#fff' } : { borderColor: cfg.cor, color: cfg.cor }"
                        @click="filtroDiag[esp.canal] = chave; mostrarTodosDiag[esp.canal] = false">
                        <i :class="cfg.icone" class="text-xs"></i> {{ cfg.rotulo }}
                        <span class="rounded-full bg-black/10 px-2 text-xs">{{ diagnostico(esp.canal).contagem[chave] }}</span>
                    </button>
                </div>

                <div class="p-4">
                    <p v-if="!diagnostico(esp.canal).produtos.length" class="text-sm text-[var(--texto-suave)]">
                        Nenhum anúncio em {{ PRIORIDADES[diagnostico(esp.canal).filtro]?.rotulo }} agora.
                        <button v-if="diagnostico(esp.canal).outra" type="button" class="font-semibold text-primary hover:underline"
                            @click="filtroDiag[esp.canal] = diagnostico(esp.canal).outra">
                            Ver {{ PRIORIDADES[diagnostico(esp.canal).outra].rotulo }} ({{ diagnostico(esp.canal).contagem[diagnostico(esp.canal).outra] }})
                        </button>
                    </p>
                    <ul v-else class="divide-y divide-[var(--surface-border)]">
                        <li v-for="g in (mostrarTodosDiag[esp.canal] ? diagnostico(esp.canal).produtos : diagnostico(esp.canal).produtos.slice(0, 6))" :key="g.produto.item_id" class="flex gap-3 py-3">
                            <img v-if="g.produto.imagem" :src="g.produto.imagem" alt="" width="56" height="56" class="h-14 w-14 shrink-0 rounded-lg border border-[var(--surface-border)] object-cover" loading="lazy">
                            <span v-else class="flex h-14 w-14 shrink-0 items-center justify-center rounded-lg bg-[var(--surface-muted)] text-[var(--texto-suave)]"><i class="fas fa-image" aria-hidden="true"></i></span>
                            <div class="min-w-0 flex-1">
                                <a :href="g.produto.link" target="_blank" rel="noopener" class="block truncate font-semibold hover:text-primary hover:underline">{{ g.produto.nome }}</a>
                                <p class="mt-0.5 flex flex-wrap gap-x-3 text-xs text-[var(--texto-suave)]">
                                    <span>{{ formatPrice(g.produto.preco) }}</span>
                                    <span>{{ g.produto.vendas_30d }} {{ g.produto.vendas_30d === 1 ? 'vendido' : 'vendidos' }}</span>
                                    <span>margem {{ formatNum(g.produto.margem) }}%</span>
                                    <span v-if="g.produto.roas_empate">ROAS para empatar {{ formatNum(g.produto.roas_empate) }}</span>
                                    <span v-if="g.produto.roas_meta && g.produto.roas_meta <= 25">meta {{ formatNum(g.produto.roas_meta) }}</span>
                                    <span v-if="g.produto.qualidade">qualidade: {{ g.produto.qualidade }}</span>
                                </p>
                                <ul class="mt-1.5 space-y-1">
                                    <li v-for="(a, i) in g.alertas" :key="i" class="flex items-start gap-2 text-sm">
                                        <span class="mt-0.5 shrink-0 rounded px-1.5 text-[10px] font-bold text-white" :style="{ background: PRIORIDADES[a.prioridade]?.cor }">{{ PRIORIDADES[a.prioridade]?.rotulo }}</span>
                                        <span>{{ a.texto }}</span>
                                    </li>
                                </ul>
                                <div v-if="esp.canal === 'shopee' && ehAdmin && g.produto.acoes?.length" class="mt-2 flex flex-wrap gap-2">
                                    <template v-for="a in g.produto.acoes" :key="a.acao">
                                        <span v-if="acaoFeita(esp.canal, g.produto.item_id, a.acao)" class="inline-flex items-center gap-1.5 rounded-lg bg-success/10 px-2.5 py-1 text-xs font-semibold text-success">
                                            <i class="fas fa-check" aria-hidden="true"></i> {{ a.rotulo }} aplicado
                                        </span>
                                        <button v-else type="button" :disabled="aplicando !== null"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-[#ee4d2d] px-2.5 py-1 text-xs font-semibold text-[#ee4d2d] transition hover:bg-[#ee4d2d] hover:text-white disabled:opacity-50"
                                            @click="aplicarAcao(g.produto, a)">
                                            <i :class="aplicando === `${g.produto.item_id}:${a.acao}` ? 'fas fa-spinner fa-spin' : ICONE_ACAO[a.acao]" aria-hidden="true"></i> {{ a.rotulo }}
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </li>
                    </ul>
                    <button v-if="diagnostico(esp.canal).produtos.length > 6" type="button" class="mt-2 text-sm font-semibold text-primary hover:underline"
                        @click="mostrarTodosDiag[esp.canal] = !mostrarTodosDiag[esp.canal]">
                        {{ mostrarTodosDiag[esp.canal] ? 'Mostrar menos' : `Ver todos (${diagnostico(esp.canal).produtos.length})` }}
                    </button>
                </div>
            </template>
        </section>
    </div>
</template>
