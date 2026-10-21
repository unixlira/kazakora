<script setup>
// Espelho do Gerenciador (pedido do Lira 2026-10-18): Campanha > Conjuntos >
// Anúncios lidos ao vivo da Meta, com as métricas de cada nível. Os números
// de campanha e conjunto vêm da própria Meta (alcance não se soma).
import { Link } from '@inertiajs/vue3';
import { computed, nextTick, reactive, ref } from 'vue';
import AnuncioModais from './AnuncioModais.vue';
import GerenciarModais from './GerenciarModais.vue';
import LinkInline from './LinkInline.vue';
import OrcamentoInline from './OrcamentoInline.vue';
import { ctaDoAnuncio, formatarNumero, formatarReais, PRIORIDADES, statusMeta } from './metaAds';

const props = defineProps({
    campanhas: { type: Array, default: () => [] },
    // Abre todos os conjuntos de cara (tela de uma campanha só).
    abrirTudo: { type: Boolean, default: false },
    // Botões Novo conjunto e Novo anúncio (quem pode criar; Lira 2026-10-19).
    acoes: { type: Boolean, default: false },
    // Editar e Excluir em cada linha (anúncio do KazaKora 2026-10-19; campanha,
    // conjunto e anúncio feito no Gerenciador 2026-10-20).
    editarAnuncios: { type: Boolean, default: false },
    produtos: { type: Array, default: () => [] },
    chamadas: { type: Object, default: () => ({}) },
    // Tela da própria campanha: sem o link "Ver no KazaKora".
    semLinkLocal: { type: Boolean, default: false },
    // Status ao vivo (useStatusAoVivo, 2026-10-21): { campanhas, conjuntos, anuncios } id => effective_status.
    aoVivo: { type: Object, default: null },
    // "O que fazer agora" (MetaAdsDiagnostico): as sugestões aparecem na linha de cada item.
    diagnostico: { type: Object, default: null },
});

const emit = defineEmits(['mudou']);
const modais = ref(null);
const alvo = (a) => ({ local: a.local, nome: a.nome, status: a.status, gasto: a.metricas?.gasto ?? 0, ultimoAtivo: a.ultimo_ativo });
const tituloDesabilitado = (a) => a.local?.bloqueio ?? undefined;

// Campanha, conjunto e anúncio do Gerenciador: editar e excluir pelo id da Meta.
const gerenciar = ref(null);
const ativos = (lista) => lista.filter((x) => x.status === 'ACTIVE').length;
const alvoCampanha = (c) => ({
    nivel: 'campanha', id: c.id, nome: c.nome, status: c.status, gasto: c.metricas?.gasto ?? 0,
    orcamento_diario: c.orcamento_nivel === 'campanha' ? c.orcamento_diario : null,
    // Com o filtro de status a linha mostra só parte; a confirmação conta tudo.
    dentro: `${plural(c.conjuntos_total ?? c.conjuntos.length, 'conjunto', 'conjuntos')} e ${plural(c.anuncios_total ?? totalAnuncios(c), 'anúncio', 'anúncios')}`,
});
const alvoConjunto = (c, s) => ({
    nivel: 'conjunto', id: s.id, nome: s.nome, status: s.status, gasto: s.metricas?.gasto ?? 0,
    orcamento_diario: c.orcamento_nivel === 'conjunto' ? s.orcamento_diario : null,
    dentro: (s.anuncios_total ?? s.anuncios.length) ? plural(s.anuncios_total ?? s.anuncios.length, 'anúncio', 'anúncios') : '',
    ultimoAtivo: s.status === 'ACTIVE' && ativos(c.conjuntos) === 1,
});
const alvoAnuncio = (a) => ({ nivel: 'anuncio', id: a.id, nome: a.nome, status: a.status, gasto: a.metricas?.gasto ?? 0, orcamento_diario: null, ultimoAtivo: a.ultimo_ativo });
// Coluna Ações (pedido do Lira 2026-10-21): botões só com ícone; o nome vai no title e no aria-label.
const BOTAO_ICONE = 'inline-flex h-7 w-7 items-center justify-center rounded-md border text-xs focus:outline-none focus-visible:ring-2 disabled:cursor-not-allowed disabled:opacity-50';
const BOTAO_EDITAR = `${BOTAO_ICONE} border-primary/40 text-primary hover:bg-primary/10 focus-visible:ring-primary/40`;
const BOTAO_EXCLUIR = `${BOTAO_ICONE} border-red-300 text-red-700 hover:bg-red-50 focus-visible:ring-red-300 dark:text-red-300 dark:hover:bg-red-500/10`;
const temAcoes = computed(() => props.acoes || props.editarAnuncios);
const totalColunas = computed(() => 12 + (temAcoes.value ? 1 : 0));
// Nome com largura fixa: nome comprido quebra em linhas em vez de esticar a tabela.
const COL_NOME = 'sticky left-0 z-10 w-[320px] min-w-[280px] max-w-[340px] break-words bg-[var(--surface)]';
const COL_ACOES = 'sticky right-0 z-10 whitespace-nowrap border-l border-[var(--surface-border)] bg-[var(--surface)] px-3';

const BASE = '/admin/publicidade/meta-ads/campanhas';

// Status da Meta agora (pergunta a cada 20 s) por cima do lido com as métricas.
const status = (nivel, item) => statusMeta(props.aoVivo?.[nivel]?.[item.id] ?? item.status);

// Coluna "O que fazer" (pedido do Lira 2026-10-21): as sugestões do
// diagnóstico na própria linha do anúncio ou do conjunto; a da campanha
// resume quantas há dentro dela, pela prioridade.
const ORDEM = ['urgente', 'atencao', 'oportunidade', 'aviso'];
const sugestoes = computed(() => {
    const porAlvo = {};
    const porCampanha = {};
    (props.diagnostico?.cartoes ?? []).forEach((cartao) => {
        (porAlvo[cartao.alvo.id] ??= []).push(cartao);
        (porCampanha[cartao.alvo.campanha_id] ??= []).push(cartao);
    });
    return { porAlvo, porCampanha };
});
const daLinha = (id) => sugestoes.value.porAlvo[id] ?? [];
const resumoDaCampanha = (id) => {
    const lista = sugestoes.value.porCampanha[id] ?? [];
    return ORDEM.map((p) => [p, lista.filter((x) => x.prioridade === p).length]).filter(([, n]) => n > 0);
};
const verMais = reactive({});
const visiveis = (id) => (verMais[id] ? daLinha(id) : daLinha(id).slice(0, 2));

// "Pausar criativo" (Lira 2026-10-21): uma vez por anúncio, na primeira
// sugestão de desempenho, só com o anúncio ativo na Meta agora.
const BOTAO_PAUSAR = 'mt-1 inline-flex items-center gap-1 rounded-md border border-amber-400 px-2 py-0.5 text-xs font-medium text-amber-800 hover:bg-amber-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-300 dark:text-amber-300 dark:hover:bg-amber-500/10';
const pausavel = (a) => {
    if (!props.editarAnuncios || (props.aoVivo?.anuncios?.[a.id] ?? a.status) !== 'ACTIVE' || !daLinha(a.id).some((x) => x.pausar)) return null;
    // Sugestão de desempenho escondida no "mais": o botão fica na primeira visível.
    return (visiveis(a.id).find((x) => x.pausar) ?? visiveis(a.id)[0]).id;
};
const pausar = (id) => {
    const achado = acharLinha('anuncio', id);
    if (achado && props.editarAnuncios) gerenciar.value.pausar(alvoAnuncio(achado.a));
};
const ROTULO_RESUMO = { urgente: ['urgente', 'urgentes'], atencao: ['atenção', 'atenção'], oportunidade: ['oportunidade', 'oportunidades'], aviso: ['aviso', 'avisos'] };
const abrirSugestoes = (c) => {
    abertos[c.id] = true;
    c.conjuntos.forEach((s) => { if (daLinha(s.id).length || s.anuncios.some((a) => daLinha(a.id).length)) abertos[s.id] = true; });
};

// Campanha vem fechada (pedido do Lira, 21/10); ao abrir, os conjuntos da
// campanha ativa já aparecem abertos. O clique do Lira vale até sair da tela.
const abertos = reactive({});
const aberto = (c, id) => abertos[id] ?? (props.abrirTudo || (id !== c.id && c.status === 'ACTIVE'));
const alternar = (c, id) => { abertos[id] = !aberto(c, id); };

const plural = (n, um, varios) => `${n} ${n === 1 ? um : varios}`;
const totalAnuncios = (c) => c.conjuntos.reduce((t, s) => t + s.anuncios.length, 0);
const roas = (m) => (m.roas === null ? '-' : `${m.roas.toFixed(2).replace('.', ',')}x`);
const orcamento = (item) => {
    if (item.orcamento_diario) return `${formatarReais(item.orcamento_diario)}/dia`;
    if (item.orcamento_total) return `${formatarReais(item.orcamento_total)} total`;
    return '-';
};

// "Ver na tabela" e "Editar" dos cartões do O que fazer agora (2026-10-21):
// abre a campanha e o conjunto, rola até a linha, destaca 2 s e põe o foco nela.
const destacado = ref(null);
const acharLinha = (tipo, id) => {
    for (const c of props.campanhas) {
        for (const s of c.conjuntos) {
            if (tipo === 'conjunto' && s.id === id) return { c, s, a: null };
            const a = s.anuncios.find((x) => x.id === id);
            if (tipo === 'anuncio' && a) return { c, s, a };
        }
    }
    return null;
};
const mostrar = async (tipo, id) => {
    const achado = acharLinha(tipo, id);
    if (!achado) return;
    abertos[achado.c.id] = true;
    if (achado.a) abertos[achado.s.id] = true;
    await nextTick();
    const linha = document.getElementById(`meta-linha-${id}`);
    if (!linha) return;
    const reduzir = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    linha.scrollIntoView({ behavior: reduzir ? 'auto' : 'smooth', block: 'center' });
    linha.focus({ preventScroll: true });
    destacado.value = id;
    setTimeout(() => { if (destacado.value === id) destacado.value = null; }, 2000);
};
const editar = (tipo, id) => {
    const achado = acharLinha(tipo, id);
    if (!achado || !props.editarAnuncios) return;
    if (achado.a) {
        if (achado.a.local) modais.value.editar(alvo(achado.a));
        else gerenciar.value.editar(alvoAnuncio(achado.a));
    } else {
        gerenciar.value.editar(alvoConjunto(achado.c, achado.s));
    }
};
defineExpose({ mostrar, editar, pausar });
const classeDestaque = (id) => (destacado.value === id ? 'bg-primary/10 outline outline-2 outline-primary/50' : '');
const cta = ctaDoAnuncio;

const COLUNAS = [
    ['gasto', 'Gastos', (m) => formatarReais(m.gasto)],
    ['impressoes', 'Impressões', (m) => formatarNumero(m.impressoes)],
    ['alcance', 'Alcance', (m) => formatarNumero(m.alcance)],
    ['cliques', 'Cliques', (m) => formatarNumero(m.cliques)],
    ['cpc', 'CPC', (m) => (m.cpc === null ? '-' : formatarReais(m.cpc))],
    ['compras', 'Compras', (m) => formatarNumero(m.compras)],
    ['roas', 'ROAS', roas],
];
</script>

<template>
    <div class="overflow-x-auto rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
        <table class="w-full min-w-[1380px] text-sm">
            <thead class="bg-slate-500/5 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th scope="col" :class="COL_NOME" class="px-4 py-3">Nome</th>
                    <th scope="col" class="px-3 py-3">Status</th>
                    <th scope="col" class="px-3 py-3" title="Botão de chamada do anúncio, igual ao do Gerenciador">Botão (CTA)</th>
                    <th scope="col" class="px-3 py-3 text-right">Orçamento</th>
                    <th v-for="[chave, rotulo] in COLUNAS" :key="chave" scope="col" class="px-3 py-3 text-right"
                        :title="chave === 'cpc' ? 'Custo por clique: gasto ÷ cliques (todos)' : (chave === 'roas' ? 'Valor das compras ÷ gasto' : undefined)">{{ rotulo }}</th>
                    <th scope="col" class="min-w-[280px] px-3 py-3" title="Sugestões automáticas a partir dos números do período">O que fazer</th>
                    <th v-if="temAcoes" scope="col" :class="COL_ACOES" class="py-3 text-center">Ações</th>
                </tr>
            </thead>
            <tbody>
                <template v-for="c in campanhas" :key="c.id">
                    <tr class="border-t border-[var(--surface-border)] bg-slate-500/[0.03] font-semibold">
                        <td :class="COL_NOME" class="px-4 py-3">
                            <button type="button" class="flex items-start gap-2 text-left" :aria-expanded="aberto(c, c.id)" @click="alternar(c, c.id)">
                                <i class="fas fa-chevron-right mt-1 text-[10px] text-slate-400 transition-transform" :class="{ 'rotate-90': aberto(c, c.id) }" aria-hidden="true"></i>
                                <span>
                                    <span class="block">{{ c.nome }}</span>
                                    <span class="block text-xs font-normal text-slate-500">
                                        Campanha · {{ c.objetivo }} · {{ plural(c.conjuntos.length, 'conjunto', 'conjuntos') }} · {{ plural(totalAnuncios(c), 'anúncio', 'anúncios') }}
                                    </span>
                                </span>
                            </button>
                            <span v-if="c.local_id && !semLinkLocal" class="ml-5 flex flex-wrap items-center gap-x-3 gap-y-1">
                                <Link :href="`${BASE}/${c.local_id}`" class="text-xs font-medium text-primary hover:underline">Ver no KazaKora</Link>
                            </span>
                            <!-- Link do Gerenciador só na campanha, não nos anúncios (pedido do Lira 2026-10-21). -->
                            <a v-if="c.gerenciador" :href="c.gerenciador" target="_blank" rel="noopener"
                                class="ml-5 mt-1 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
                                <i class="fab fa-meta text-[10px]" aria-hidden="true"></i> Abrir no Gerenciador
                                <span class="sr-only">(abre em outra aba)</span>
                            </a>
                        </td>
                        <td class="px-3 py-3"><span class="whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-bold" :class="status('campanhas', c)[1]">{{ status('campanhas', c)[0] }}</span></td>
                        <td class="px-3 py-3"></td>
                        <td class="whitespace-nowrap px-3 py-3 text-right" :title="c.orcamento_nivel === 'conjunto' ? 'Soma do orçamento dos conjuntos ativos' : 'Orçamento da campanha'">
                            <OrcamentoInline v-if="c.orcamento_nivel === 'campanha' && c.orcamento_diario" nivel="campanha" :id="c.id" :nome="c.nome" :valor="c.orcamento_diario"
                                :pode-editar="editarAnuncios" @mudou="emit('mudou', $event)" />
                            <template v-else>{{ orcamento(c) }}</template>
                            <span v-if="c.orcamento_nivel === 'conjunto'" class="block text-[11px] font-normal text-slate-500">nos conjuntos</span>
                        </td>
                        <td v-for="[chave, , fmt] in COLUNAS" :key="chave" class="whitespace-nowrap px-3 py-3 text-right tabular-nums">{{ fmt(c.metricas) }}</td>
                        <td class="px-3 py-3 font-normal">
                            <button v-if="resumoDaCampanha(c.id).length" type="button" class="flex flex-wrap items-center gap-1.5 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                                title="Abrir os conjuntos e anúncios com sugestão" @click="abrirSugestoes(c)">
                                <span v-for="[p, n] in resumoDaCampanha(c.id)" :key="p" class="whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-bold" :class="PRIORIDADES[p][1]">
                                    {{ n }} {{ ROTULO_RESUMO[p][n === 1 ? 0 : 1] }}
                                </span>
                                <span class="text-xs text-primary hover:underline">ver</span>
                            </button>
                            <span v-else-if="diagnostico && c.status === 'ACTIVE'" class="text-xs text-slate-400">Nada a ajustar agora</span>
                            <span v-else class="text-slate-400">-</span>
                        </td>
                        <td v-if="temAcoes" :class="COL_ACOES" class="py-3 font-normal">
                            <span class="flex items-center justify-end gap-1.5">
                                <Link v-if="acoes && c.novo_conjunto && c.local_id && !semLinkLocal" :href="`${BASE}/${c.local_id}/conjuntos/novo`" title="Novo conjunto"
                                    :aria-label="`Novo conjunto na campanha ${c.nome}`" :class="BOTAO_EDITAR">
                                    <i class="fas fa-plus" aria-hidden="true"></i>
                                </Link>
                                <template v-if="editarAnuncios">
                                    <button type="button" title="Editar" :aria-label="`Editar campanha ${c.nome}`" :class="BOTAO_EDITAR" @click="gerenciar.editar(alvoCampanha(c))">
                                        <i class="fas fa-pen" aria-hidden="true"></i>
                                    </button>
                                    <button type="button" title="Excluir" :aria-label="`Excluir campanha ${c.nome}`" :class="BOTAO_EXCLUIR" @click="gerenciar.excluir(alvoCampanha(c))">
                                        <i class="fas fa-trash" aria-hidden="true"></i>
                                    </button>
                                </template>
                            </span>
                        </td>
                    </tr>
                    <template v-if="aberto(c, c.id)">
                        <template v-for="s in c.conjuntos" :key="s.id">
                            <tr :id="`meta-linha-${s.id}`" tabindex="-1" class="border-t border-[var(--surface-border)] transition-colors focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary/50" :class="classeDestaque(s.id)">
                                <td :class="COL_NOME" class="py-2.5 pl-9 pr-4">
                                    <button type="button" class="flex items-start gap-2 text-left" :aria-expanded="aberto(c, s.id)" @click="alternar(c, s.id)">
                                        <i class="fas fa-chevron-right mt-1 text-[10px] text-slate-400 transition-transform" :class="{ 'rotate-90': aberto(c, s.id) }" aria-hidden="true"></i>
                                        <span>
                                            <span class="block font-medium">{{ s.nome }}</span>
                                            <span class="block text-xs text-slate-500">Conjunto · {{ plural(s.anuncios.length, 'anúncio', 'anúncios') }}</span>
                                        </span>
                                    </button>
                                </td>
                                <td class="px-3 py-2.5"><span class="whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-bold" :class="status('conjuntos', s)[1]">{{ status('conjuntos', s)[0] }}</span></td>
                                <td class="px-3 py-2.5"></td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right">
                                    <OrcamentoInline v-if="c.orcamento_nivel === 'conjunto' && s.orcamento_diario" nivel="conjunto" :id="s.id" :nome="s.nome" :valor="s.orcamento_diario"
                                        :pode-editar="editarAnuncios" @mudou="emit('mudou', $event)" />
                                    <template v-else>{{ c.orcamento_nivel === 'conjunto' ? orcamento(s) : 'da campanha' }}</template>
                                </td>
                                <td v-for="[chave, , fmt] in COLUNAS" :key="chave" class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums">{{ fmt(s.metricas) }}</td>
                                <td class="px-3 py-2.5 align-top">
                                    <ul v-if="daLinha(s.id).length" class="space-y-1.5">
                                        <li v-for="cartao in visiveis(s.id)" :key="cartao.id" class="text-xs leading-snug">
                                            <span class="mr-1 rounded-full px-1.5 py-px text-[10px] font-bold uppercase" :class="PRIORIDADES[cartao.prioridade][1]">{{ PRIORIDADES[cartao.prioridade][0] }}</span>
                                            <strong>{{ cartao.titulo }}</strong>
                                            <span class="block text-slate-600 dark:text-slate-300">{{ cartao.acao }}</span>
                                        </li>
                                        <li v-if="daLinha(s.id).length > 2">
                                            <button type="button" class="text-xs text-primary hover:underline" @click="verMais[s.id] = !verMais[s.id]">{{ verMais[s.id] ? 'menos' : `mais ${daLinha(s.id).length - 2}` }}</button>
                                        </li>
                                    </ul>
                                    <span v-else class="text-slate-400">-</span>
                                </td>
                                <td v-if="temAcoes" :class="COL_ACOES" class="py-2.5">
                                    <span class="flex items-center justify-end gap-1.5">
                                        <Link v-if="acoes && s.novo_anuncio" :href="`${BASE}/${c.local_id}/conjuntos/${s.local_id}/anuncios`" title="Novo anúncio"
                                            :aria-label="`Novo anúncio no conjunto ${s.nome}`" :class="BOTAO_EDITAR">
                                            <i class="fas fa-plus" aria-hidden="true"></i>
                                        </Link>
                                        <template v-if="editarAnuncios">
                                            <button type="button" title="Editar" :aria-label="`Editar conjunto ${s.nome}`" :class="BOTAO_EDITAR" @click="gerenciar.editar(alvoConjunto(c, s))">
                                                <i class="fas fa-pen" aria-hidden="true"></i>
                                            </button>
                                            <button type="button" title="Excluir" :aria-label="`Excluir conjunto ${s.nome}`" :class="BOTAO_EXCLUIR" @click="gerenciar.excluir(alvoConjunto(c, s))">
                                                <i class="fas fa-trash" aria-hidden="true"></i>
                                            </button>
                                        </template>
                                    </span>
                                </td>
                            </tr>
                            <template v-if="aberto(c, s.id)">
                                <tr v-for="a in s.anuncios" :id="`meta-linha-${a.id}`" :key="a.id" tabindex="-1"
                                    class="border-t border-[var(--surface-border)] text-slate-700 transition-colors focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary/50 dark:text-slate-300" :class="classeDestaque(a.id)">
                                    <td :class="COL_NOME" class="py-2 pl-16 pr-4">
                                        <span class="flex items-center gap-2.5">
                                            <img v-if="a.miniatura" :src="a.miniatura" alt="" class="h-9 w-9 shrink-0 rounded object-cover" loading="lazy" referrerpolicy="no-referrer">
                                            <span v-else class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-slate-500/10 text-slate-400"><i class="fas fa-image text-xs" aria-hidden="true"></i></span>
                                            <span class="min-w-0">
                                                <span class="block">{{ a.nome }}</span>
                                                <span class="block text-xs text-slate-500">Anúncio</span>
                                                <LinkInline :id="a.id" :nome="a.nome" :link="a.link ?? null" :pode-editar="editarAnuncios && Boolean(a.link_editavel) && !a.local?.bloqueio"
                                                    @mudou="emit('mudou', $event)" />
                                            </span>
                                        </span>
                                    </td>
                                    <td class="px-3 py-2"><span class="whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-bold" :class="status('anuncios', a)[1]">{{ status('anuncios', a)[0] }}</span></td>
                                    <td class="px-3 py-2">
                                        <span v-if="cta(a.cta).alerta" role="img" :title="cta(a.cta).dica"
                                            :aria-label="`${cta(a.cta).texto}: campanha de Vendas com botão de pouca intenção de compra`"
                                            class="inline-flex items-center gap-1 whitespace-nowrap rounded-full bg-amber-500/15 px-2.5 py-1 text-xs font-semibold text-amber-800 dark:text-amber-300">
                                            <i class="fas fa-triangle-exclamation text-[10px]" aria-hidden="true"></i>
                                            <span :class="{ 'font-mono': cta(a.cta).mono }">{{ cta(a.cta).texto }}</span>
                                        </span>
                                        <span v-else-if="cta(a.cta).texto === '-'" class="text-slate-400" :title="cta(a.cta).dica">-</span>
                                        <span v-else :title="cta(a.cta).dica"
                                            class="inline-block whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold"
                                            :class="[cta(a.cta).vazio ? 'bg-slate-500/10 text-slate-500' : 'bg-slate-500/15 text-slate-700 dark:text-slate-200', { 'font-mono': cta(a.cta).mono }]">{{ cta(a.cta).texto }}</span>
                                    </td>
                                    <td class="px-3 py-2 text-right text-slate-400">-</td>
                                    <td v-for="[chave, , fmt] in COLUNAS" :key="chave" class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ fmt(a.metricas) }}</td>
                                    <td class="px-3 py-2 align-top">
                                        <ul v-if="daLinha(a.id).length" class="space-y-1.5">
                                            <li v-for="cartao in visiveis(a.id)" :key="cartao.id" class="text-xs leading-snug">
                                                <span class="mr-1 rounded-full px-1.5 py-px text-[10px] font-bold uppercase" :class="PRIORIDADES[cartao.prioridade][1]">{{ PRIORIDADES[cartao.prioridade][0] }}</span>
                                                <strong>{{ cartao.titulo }}</strong>
                                                <span class="block text-slate-600 dark:text-slate-300">{{ cartao.acao }}</span>
                                                <button v-if="cartao.id === pausavel(a)" type="button" :aria-label="`Pausar criativo ${a.nome}`" :class="BOTAO_PAUSAR"
                                                    @click="gerenciar.pausar(alvoAnuncio(a))">
                                                    <i class="fas fa-pause text-[10px]" aria-hidden="true"></i> Pausar criativo
                                                </button>
                                            </li>
                                            <li v-if="daLinha(a.id).length > 2">
                                                <button type="button" class="text-xs text-primary hover:underline" @click="verMais[a.id] = !verMais[a.id]">{{ verMais[a.id] ? 'menos' : `mais ${daLinha(a.id).length - 2}` }}</button>
                                            </li>
                                        </ul>
                                        <span v-else class="text-slate-400">-</span>
                                    </td>
                                    <td v-if="temAcoes" :class="COL_ACOES" class="py-2">
                                        <span v-if="editarAnuncios && a.local" class="flex items-center justify-end gap-1.5">
                                            <button type="button" :disabled="Boolean(a.local.bloqueio)" :title="tituloDesabilitado(a) ?? 'Editar'" :aria-label="`Editar anúncio ${a.nome}`"
                                                :class="BOTAO_EDITAR" @click="modais.editar(alvo(a))">
                                                <i class="fas fa-pen" aria-hidden="true"></i>
                                            </button>
                                            <button type="button" :disabled="Boolean(a.local.bloqueio)" :title="tituloDesabilitado(a) ?? 'Excluir'" :aria-label="`Excluir anúncio ${a.nome}`"
                                                :class="BOTAO_EXCLUIR" @click="modais.excluir(alvo(a))">
                                                <i class="fas fa-trash" aria-hidden="true"></i>
                                            </button>
                                        </span>
                                        <span v-else-if="editarAnuncios" class="flex items-center justify-end gap-1.5">
                                            <button type="button" title="Editar nome" :aria-label="`Editar nome do anúncio ${a.nome}`" :class="BOTAO_EDITAR" @click="gerenciar.editar(alvoAnuncio(a))">
                                                <i class="fas fa-pen" aria-hidden="true"></i>
                                            </button>
                                            <button type="button" title="Excluir" :aria-label="`Excluir anúncio ${a.nome}`" :class="BOTAO_EXCLUIR" @click="gerenciar.excluir(alvoAnuncio(a))">
                                                <i class="fas fa-trash" aria-hidden="true"></i>
                                            </button>
                                        </span>
                                    </td>
                                </tr>
                                <tr v-if="!s.anuncios.length" class="border-t border-[var(--surface-border)]">
                                    <td :colspan="totalColunas" class="py-2 pl-16 text-xs text-amber-700">Conjunto sem anúncio.</td>
                                </tr>
                            </template>
                        </template>
                        <tr v-if="!c.conjuntos.length" class="border-t border-[var(--surface-border)]">
                            <td :colspan="totalColunas" class="py-2 pl-9 text-xs text-amber-700">Campanha sem conjunto.</td>
                        </tr>
                    </template>
                </template>
                <tr v-if="!campanhas.length">
                    <td :colspan="totalColunas" class="px-4 py-6 text-center text-slate-500">Nenhuma campanha na conta da Meta.</td>
                </tr>
            </tbody>
        </table>
        <AnuncioModais v-if="editarAnuncios" ref="modais" :produtos="produtos" :chamadas="chamadas" @mudou="emit('mudou')" />
        <GerenciarModais v-if="editarAnuncios" ref="gerenciar" @mudou="emit('mudou', $event)" />
    </div>
</template>
