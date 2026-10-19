<script setup>
// Espelho do Gerenciador (pedido do Lira 2026-10-18): Campanha > Conjuntos >
// Anúncios lidos ao vivo da Meta, com as métricas de cada nível. Os números
// de campanha e conjunto vêm da própria Meta (alcance não se soma).
import { Link } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import AnuncioModais from './AnuncioModais.vue';
import GerenciarModais from './GerenciarModais.vue';
import { formatarNumero, formatarReais, statusMeta } from './metaAds';

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
    dentro: `${plural(c.conjuntos.length, 'conjunto', 'conjuntos')} e ${plural(totalAnuncios(c), 'anúncio', 'anúncios')}`,
});
const alvoConjunto = (c, s) => ({
    nivel: 'conjunto', id: s.id, nome: s.nome, status: s.status, gasto: s.metricas?.gasto ?? 0,
    orcamento_diario: c.orcamento_nivel === 'conjunto' ? s.orcamento_diario : null,
    dentro: s.anuncios.length ? plural(s.anuncios.length, 'anúncio', 'anúncios') : '',
    ultimoAtivo: s.status === 'ACTIVE' && ativos(c.conjuntos) === 1,
});
const alvoAnuncio = (a) => ({ nivel: 'anuncio', id: a.id, nome: a.nome, status: a.status, gasto: a.metricas?.gasto ?? 0, orcamento_diario: null, ultimoAtivo: a.ultimo_ativo });
const BOTAO_EDITAR = 'inline-flex items-center gap-1 rounded-md border border-primary/40 px-2 py-0.5 text-xs font-medium text-primary hover:bg-primary/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 disabled:cursor-not-allowed disabled:opacity-50';
const BOTAO_EXCLUIR = 'inline-flex items-center gap-1 rounded-md border border-red-300 px-2 py-0.5 text-xs font-medium text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-300 disabled:cursor-not-allowed disabled:opacity-50 dark:text-red-300 dark:hover:bg-red-500/10';

const BASE = '/admin/publicidade/meta-ads/campanhas';

const status = statusMeta;

// Campanha ativa já vem aberta; o clique do Lira vale até sair da tela.
const abertos = reactive({});
const aberto = (c, id) => abertos[id] ?? (props.abrirTudo || c.status === 'ACTIVE');
const alternar = (c, id) => { abertos[id] = !aberto(c, id); };

const plural = (n, um, varios) => `${n} ${n === 1 ? um : varios}`;
const totalAnuncios = (c) => c.conjuntos.reduce((t, s) => t + s.anuncios.length, 0);
const roas = (m) => (m.roas === null ? '-' : `${m.roas.toFixed(2).replace('.', ',')}x`);
const orcamento = (item) => {
    if (item.orcamento_diario) return `${formatarReais(item.orcamento_diario)}/dia`;
    if (item.orcamento_total) return `${formatarReais(item.orcamento_total)} total`;
    return '-';
};

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
        <table class="w-full min-w-[980px] text-sm">
            <thead class="bg-slate-500/5 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th scope="col" class="sticky left-0 z-10 bg-[var(--surface)] px-4 py-3">Nome</th>
                    <th scope="col" class="px-3 py-3">Status</th>
                    <th scope="col" class="px-3 py-3 text-right">Orçamento</th>
                    <th v-for="[chave, rotulo] in COLUNAS" :key="chave" scope="col" class="px-3 py-3 text-right"
                        :title="chave === 'cpc' ? 'Custo por clique: gasto ÷ cliques (todos)' : (chave === 'roas' ? 'Valor das compras ÷ gasto' : undefined)">{{ rotulo }}</th>
                </tr>
            </thead>
            <tbody>
                <template v-for="c in campanhas" :key="c.id">
                    <tr class="border-t border-[var(--surface-border)] bg-slate-500/[0.03] font-semibold">
                        <td class="sticky left-0 z-10 bg-[var(--surface)] px-4 py-3">
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
                                <Link v-if="acoes && c.novo_conjunto" :href="`${BASE}/${c.local_id}/conjuntos/novo`"
                                    class="inline-flex items-center gap-1 rounded-md border border-primary/40 px-2 py-0.5 text-xs font-medium text-primary hover:bg-primary/10">
                                    <i class="fas fa-plus text-[10px]" aria-hidden="true"></i> Novo conjunto
                                </Link>
                            </span>
                            <span v-if="editarAnuncios" class="ml-5 mt-1 flex flex-wrap gap-1.5">
                                <button type="button" :aria-label="`Editar campanha ${c.nome}`" :class="BOTAO_EDITAR" @click="gerenciar.editar(alvoCampanha(c))">
                                    <i class="fas fa-pen text-[10px]" aria-hidden="true"></i> Editar
                                </button>
                                <button type="button" :aria-label="`Excluir campanha ${c.nome}`" :class="BOTAO_EXCLUIR" @click="gerenciar.excluir(alvoCampanha(c))">
                                    <i class="fas fa-trash text-[10px]" aria-hidden="true"></i> Excluir
                                </button>
                            </span>
                        </td>
                        <td class="px-3 py-3"><span class="whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-bold" :class="status(c.status)[1]">{{ status(c.status)[0] }}</span></td>
                        <td class="whitespace-nowrap px-3 py-3 text-right" :title="c.orcamento_nivel === 'conjunto' ? 'Soma do orçamento dos conjuntos ativos' : 'Orçamento da campanha'">
                            {{ orcamento(c) }}
                            <span v-if="c.orcamento_nivel === 'conjunto'" class="block text-[11px] font-normal text-slate-500">nos conjuntos</span>
                        </td>
                        <td v-for="[chave, , fmt] in COLUNAS" :key="chave" class="whitespace-nowrap px-3 py-3 text-right tabular-nums">{{ fmt(c.metricas) }}</td>
                    </tr>
                    <template v-if="aberto(c, c.id)">
                        <template v-for="s in c.conjuntos" :key="s.id">
                            <tr class="border-t border-[var(--surface-border)]">
                                <td class="sticky left-0 z-10 bg-[var(--surface)] py-2.5 pl-9 pr-4">
                                    <button type="button" class="flex items-start gap-2 text-left" :aria-expanded="aberto(c, s.id)" @click="alternar(c, s.id)">
                                        <i class="fas fa-chevron-right mt-1 text-[10px] text-slate-400 transition-transform" :class="{ 'rotate-90': aberto(c, s.id) }" aria-hidden="true"></i>
                                        <span>
                                            <span class="block font-medium">{{ s.nome }}</span>
                                            <span class="block text-xs text-slate-500">Conjunto · {{ plural(s.anuncios.length, 'anúncio', 'anúncios') }}</span>
                                        </span>
                                    </button>
                                    <Link v-if="acoes && s.novo_anuncio" :href="`${BASE}/${c.local_id}/conjuntos/${s.local_id}/anuncios`"
                                        class="ml-5 mt-1 inline-flex items-center gap-1 rounded-md border border-primary/40 px-2 py-0.5 text-xs font-medium text-primary hover:bg-primary/10">
                                        <i class="fas fa-plus text-[10px]" aria-hidden="true"></i> Novo anúncio
                                    </Link>
                                    <span v-if="editarAnuncios" class="ml-5 mt-1 flex flex-wrap gap-1.5">
                                        <button type="button" :aria-label="`Editar conjunto ${s.nome}`" :class="BOTAO_EDITAR" @click="gerenciar.editar(alvoConjunto(c, s))">
                                            <i class="fas fa-pen text-[10px]" aria-hidden="true"></i> Editar
                                        </button>
                                        <button type="button" :aria-label="`Excluir conjunto ${s.nome}`" :class="BOTAO_EXCLUIR" @click="gerenciar.excluir(alvoConjunto(c, s))">
                                            <i class="fas fa-trash text-[10px]" aria-hidden="true"></i> Excluir
                                        </button>
                                    </span>
                                </td>
                                <td class="px-3 py-2.5"><span class="whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-bold" :class="status(s.status)[1]">{{ status(s.status)[0] }}</span></td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right">{{ c.orcamento_nivel === 'conjunto' ? orcamento(s) : 'da campanha' }}</td>
                                <td v-for="[chave, , fmt] in COLUNAS" :key="chave" class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums">{{ fmt(s.metricas) }}</td>
                            </tr>
                            <template v-if="aberto(c, s.id)">
                                <tr v-for="a in s.anuncios" :key="a.id" class="border-t border-[var(--surface-border)] text-slate-700 dark:text-slate-300">
                                    <td class="sticky left-0 z-10 bg-[var(--surface)] py-2 pl-16 pr-4">
                                        <span class="flex items-center gap-2.5">
                                            <img v-if="a.miniatura" :src="a.miniatura" alt="" class="h-9 w-9 shrink-0 rounded object-cover" loading="lazy" referrerpolicy="no-referrer">
                                            <span v-else class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-slate-500/10 text-slate-400"><i class="fas fa-image text-xs" aria-hidden="true"></i></span>
                                            <span>
                                                <span class="block">{{ a.nome }}</span>
                                                <span class="block text-xs text-slate-500">Anúncio</span>
                                                <span v-if="editarAnuncios && a.local" class="mt-1 flex flex-wrap gap-1.5">
                                                    <button type="button" :disabled="Boolean(a.local.bloqueio)" :title="tituloDesabilitado(a)" :aria-label="`Editar anúncio ${a.nome}`"
                                                        class="inline-flex items-center gap-1 rounded-md border border-primary/40 px-2 py-0.5 text-xs font-medium text-primary hover:bg-primary/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 disabled:cursor-not-allowed disabled:opacity-50"
                                                        @click="modais.editar(alvo(a))">
                                                        <i class="fas fa-pen text-[10px]" aria-hidden="true"></i> Editar
                                                    </button>
                                                    <button type="button" :disabled="Boolean(a.local.bloqueio)" :title="tituloDesabilitado(a)" :aria-label="`Excluir anúncio ${a.nome}`"
                                                        class="inline-flex items-center gap-1 rounded-md border border-red-300 px-2 py-0.5 text-xs font-medium text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-300 disabled:cursor-not-allowed disabled:opacity-50 dark:text-red-300 dark:hover:bg-red-500/10"
                                                        @click="modais.excluir(alvo(a))">
                                                        <i class="fas fa-trash text-[10px]" aria-hidden="true"></i> Excluir
                                                    </button>
                                                </span>
                                                <span v-else-if="editarAnuncios" class="mt-1 flex flex-wrap items-center gap-1.5">
                                                    <button type="button" :aria-label="`Editar anúncio ${a.nome}`" :class="BOTAO_EDITAR" @click="gerenciar.editar(alvoAnuncio(a))">
                                                        <i class="fas fa-pen text-[10px]" aria-hidden="true"></i> Editar nome
                                                    </button>
                                                    <button type="button" :aria-label="`Excluir anúncio ${a.nome}`" :class="BOTAO_EXCLUIR" @click="gerenciar.excluir(alvoAnuncio(a))">
                                                        <i class="fas fa-trash text-[10px]" aria-hidden="true"></i> Excluir
                                                    </button>
                                                    <a v-if="a.gerenciador" :href="a.gerenciador" target="_blank" rel="noopener"
                                                        class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
                                                        <i class="fab fa-meta text-[10px]" aria-hidden="true"></i> Abrir no Gerenciador
                                                    </a>
                                                </span>
                                            </span>
                                        </span>
                                    </td>
                                    <td class="px-3 py-2"><span class="whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-bold" :class="status(a.status)[1]">{{ status(a.status)[0] }}</span></td>
                                    <td class="px-3 py-2 text-right text-slate-400">-</td>
                                    <td v-for="[chave, , fmt] in COLUNAS" :key="chave" class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ fmt(a.metricas) }}</td>
                                </tr>
                                <tr v-if="!s.anuncios.length" class="border-t border-[var(--surface-border)]">
                                    <td colspan="10" class="py-2 pl-16 text-xs text-amber-700">Conjunto sem anúncio.</td>
                                </tr>
                            </template>
                        </template>
                        <tr v-if="!c.conjuntos.length" class="border-t border-[var(--surface-border)]">
                            <td colspan="10" class="py-2 pl-9 text-xs text-amber-700">Campanha sem conjunto.</td>
                        </tr>
                    </template>
                </template>
                <tr v-if="!campanhas.length">
                    <td colspan="10" class="px-4 py-6 text-center text-slate-500">Nenhuma campanha na conta da Meta.</td>
                </tr>
            </tbody>
        </table>
        <AnuncioModais v-if="editarAnuncios" ref="modais" :produtos="produtos" :chamadas="chamadas" @mudou="emit('mudou')" />
        <GerenciarModais v-if="editarAnuncios" ref="gerenciar" @mudou="emit('mudou', $event)" />
    </div>
</template>
