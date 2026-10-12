<script setup>
import BuscaTabela from '@/Modules/Admin/Radar/BuscaTabela.vue';
import Paginacao from '@/Modules/Admin/Radar/Paginacao.vue';
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

// Radar da Shopee (pedido do Lira 2026-10-12): ranking "Produtos em alta" da
// Central do Vendedor, lido no Chrome do Cora Box e guardado no banco
// (RadarShopee). Em cima, as subcategorias que mais vendem; embaixo, os
// produtos de terceiros, filtráveis por categoria e por palavra.
// `radar` é null enquanto a prop adiada não chega.
// Filtro e paginação no servidor (2026-10-12): a mineração completa tem
// dezenas de milhares de produtos. Cada mudança recarrega só `radarShopee`
// com os parâmetros rs_l1, rs_cat, rs_busca e rs_pagina na URL.
const props = defineProps({
    radar: { type: Object, default: null },
});

const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
const formatNumber = (value) => (value ?? 0).toLocaleString('pt-BR');
const formatDateTime = (iso) => new Intl.DateTimeFormat('pt-BR', { timeZone: 'America/Sao_Paulo', dateStyle: 'short', timeStyle: 'short' }).format(new Date(iso));

const grupos = computed(() => props.radar?.grupos ?? []);
const filtros = computed(() => props.radar?.filtros ?? { l1: 0, categoria: '', busca: '' });
const pag = computed(() => props.radar?.paginacao ?? { pagina: 1, paginas: 1, total: 0, por_pagina: 20 });
const carregando = ref(false);

const recarregar = (mudancas) => {
    const atual = Object.fromEntries(new URLSearchParams(window.location.search));
    const proximo = { ...atual, rs_l1: filtros.value.l1, rs_cat: filtros.value.categoria, rs_busca: filtros.value.busca, rs_pagina: 1, ...mudancas };
    Object.keys(proximo).forEach((k) => { if (proximo[k] === '' || proximo[k] === null || (k === 'rs_pagina' && proximo[k] === 1)) delete proximo[k]; });
    router.get(window.location.pathname, proximo, {
        only: ['radarShopee'], preserveState: true, preserveScroll: true, replace: true,
        onStart: () => { carregando.value = true; },
        onFinish: () => { carregando.value = false; },
    });
};

// Cartão: 'l1:<id>' abre o nível 1; 'g:<l2>' ou 's:<l3>' filtra (clicar de novo tira).
const escolher = (valor) => {
    if (valor.startsWith('l1:')) return recarregar({ rs_l1: valor.slice(3), rs_cat: '' });
    recarregar({ rs_cat: filtros.value.categoria === valor ? '' : valor });
};
const nivel1 = ref(String(filtros.value.l1));
watch(() => filtros.value.l1, (v) => { nivel1.value = String(v); });

const busca = ref(filtros.value.busca);
let espera = null;
watch(busca, (v) => {
    clearTimeout(espera);
    if (v === filtros.value.busca) return;
    espera = setTimeout(() => recarregar({ rs_busca: v }), 400);
});

const nomeFiltro = computed(() => {
    const c = filtros.value.categoria;
    if (!c) return '';
    for (const g of grupos.value) {
        if (g.id === c) return g.nome;
        const item = g.itens.find((s) => s.id === c);
        if (item) return item.nome;
    }
    return '';
});
const faixa = computed(() => {
    if (!pag.value.total) return '0';
    const inicio = (pag.value.pagina - 1) * pag.value.por_pagina + 1;
    return `${formatNumber(inicio)}–${formatNumber(Math.min(pag.value.total, inicio + pag.value.por_pagina - 1))} de ${formatNumber(pag.value.total)}`;
});
const maiorItem = computed(() => Math.max(1, ...grupos.value.flatMap((g) => g.itens.map((s) => s.unidades_vendidas))));
const faixaPreco = (p) => {
    if (p.preco_min === null) return '—';
    if (p.preco_max !== null && p.preco_max > p.preco_min) return `${formatPrice(p.preco_min)} a ${formatPrice(p.preco_max)}`;
    return formatPrice(p.preco_min);
};
</script>

<template>
    <section class="mb-8" aria-labelledby="radar-shopee-titulo">
        <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
            <div>
                <h2 id="radar-shopee-titulo" class="text-lg font-bold">Radar da Shopee · Produtos em alta</h2>
                <p class="text-sm text-[var(--texto-suave)]">Ranking da Central do Vendedor (últimos 30 dias, sem os produtos da KazaKora): subcategorias e produtos de outros vendedores com mais unidades vendidas.</p>
            </div>
            <span v-if="radar?.coletado_em" class="text-xs text-[var(--texto-suave)]">Coletado em {{ formatDateTime(radar.coletado_em) }}</span>
        </div>

        <div v-if="!radar" class="animate-pulse space-y-2 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4" role="status" aria-live="polite">
            <span class="block h-4 w-48 rounded bg-[var(--surface-muted)]"></span>
            <span v-for="n in 6" :key="n" class="block h-3 rounded bg-[var(--surface-muted)]" :style="{ width: `${90 - n * 8}%` }"></span>
            <span class="sr-only">Carregando…</span>
        </div>

        <p v-else-if="!radar.coletado_em" class="rounded-xl border border-dashed border-[var(--surface-border)] bg-[var(--surface)] px-6 py-8 text-center text-sm text-[var(--texto-suave)]">
            Nenhuma coleta ainda. Os dados vêm da Central do Vendedor da Shopee (Dados › Produtos em alta) e entram com <code>php artisan shopee:radar-importar</code>.
        </p>

        <template v-else>
            <!-- Números do topo -->
            <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-4 py-3">
                    <p class="text-xs text-[var(--texto-suave)]">Categorias</p>
                    <p class="text-xl font-bold tabular-nums">{{ formatNumber(radar.totais.niveis2) }} <span class="text-sm font-normal text-[var(--texto-suave)]">com {{ formatNumber(radar.totais.subcategorias) }} subcategorias</span></p>
                </div>
                <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-4 py-3">
                    <p class="text-xs text-[var(--texto-suave)]">Produtos no ranking</p>
                    <p class="text-xl font-bold tabular-nums">{{ formatNumber(radar.totais.produtos) }}</p>
                </div>
                <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-4 py-3">
                    <p class="text-xs text-[var(--texto-suave)]">A KazaKora já vende</p>
                    <p class="text-xl font-bold tabular-nums">{{ formatNumber(radar.totais.voce_vende) }}</p>
                </div>
            </div>

            <!-- Nível 1: escolhe quais cartões aparecem -->
            <label class="mb-3 flex flex-wrap items-center gap-2 text-sm">
                <span class="text-[var(--texto-suave)]">Categoria principal</span>
                <select v-model="nivel1" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm" :disabled="carregando" @change="recarregar({ rs_l1: nivel1, rs_cat: '' })">
                    <option value="0">Todas ({{ radar.niveis1.length }})</option>
                    <option v-for="n in radar.niveis1" :key="n.id" :value="String(n.id)">{{ n.nome }} · {{ formatNumber(n.unidades_vendidas) }} un.</option>
                </select>
                <i v-if="carregando" class="fas fa-circle-notch animate-spin text-primary" aria-hidden="true"></i>
            </label>

            <!-- Cartões: cada nível 2 com as subcategorias (ou cada nível 1 com os níveis 2) por unidades vendidas -->
            <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                <article v-for="g in grupos" :key="g.id" class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" style="border-top: 3px solid var(--faixa-card)">
                    <button type="button" class="mb-2 w-full text-left" :aria-pressed="filtros.categoria === g.id" @click="escolher(g.id)">
                        <span class="block text-xs text-[var(--texto-suave)]">{{ g.sobre }}</span>
                        <span class="font-semibold hover:text-[var(--destaque)]" :class="{ 'text-[var(--destaque)] underline': filtros.categoria === g.id }">{{ g.nome }}</span>
                        <span class="ml-1 text-xs tabular-nums text-[var(--texto-suave)]">{{ formatNumber(g.unidades_vendidas) }} un.</span>
                    </button>
                    <ol class="space-y-1 text-sm">
                        <li v-for="s in g.itens" :key="s.id">
                            <button type="button" class="w-full text-left" :aria-pressed="filtros.categoria === s.id" @click="escolher(s.id)">
                                <span class="flex justify-between gap-2">
                                    <span class="truncate" :class="filtros.categoria === s.id ? 'font-semibold text-[var(--destaque)]' : 'hover:text-[var(--destaque)]'">{{ s.posicao }}. {{ s.nome }}</span>
                                    <span class="shrink-0 tabular-nums text-[var(--texto-suave)]">{{ formatNumber(s.unidades_vendidas) }}</span>
                                </span>
                                <span class="mt-0.5 block h-1 rounded bg-[var(--surface-muted)]">
                                    <span class="block h-1 rounded bg-primary" :style="{ width: `${(s.unidades_vendidas / maiorItem) * 100}%` }"></span>
                                </span>
                            </button>
                        </li>
                    </ol>
                </article>
            </div>

            <!-- Produtos -->
            <section class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm" style="border-top: 3px solid var(--faixa-card)">
                <div class="flex flex-wrap items-center gap-3 border-b border-[var(--surface-border)] px-4 py-3">
                    <BuscaTabela v-model="busca" rotulo="Buscar produto no Radar da Shopee" placeholder="Buscar produto (ex.: organizador, mop)" />
                    <button v-if="filtros.categoria" type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-1 text-xs hover:bg-[var(--surface-muted)]" @click="escolher(filtros.categoria)">
                        {{ nomeFiltro || 'Categoria' }} <i class="fas fa-xmark ml-1" aria-hidden="true"></i>
                    </button>
                    <span class="ml-auto text-xs text-[var(--texto-suave)]" role="status" aria-live="polite">{{ faixa }} produtos</span>
                </div>
                <p v-if="pag.total === 0" class="px-4 py-6 text-sm text-[var(--texto-suave)]">Nenhum produto com esse filtro.</p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-left text-sm" :class="{ 'opacity-60': carregando }">
                        <thead class="border-b border-[var(--surface-border)] text-xs uppercase text-[var(--texto-suave)]">
                            <tr>
                                <th scope="col" class="px-4 py-2" title="Posição na subcategoria">#</th>
                                <th scope="col" class="py-2 pr-3">Produto</th>
                                <th scope="col" class="py-2 pr-3">Subcategoria</th>
                                <th scope="col" class="py-2 pr-3 text-right">Preço</th>
                                <th scope="col" class="py-2 pr-4 text-right" title="Unidades vendidas informadas pela Shopee">Vendas</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in radar.produtos" :key="p.id" class="border-b border-[var(--surface-border)] last:border-0 hover:bg-[var(--surface-muted)]">
                                <td class="px-4 py-2 tabular-nums text-[var(--texto-suave)]">{{ p.posicao }}º</td>
                                <td class="py-2 pr-3">
                                    <div class="flex items-center gap-3">
                                        <img v-if="p.imagem" :src="p.imagem" alt="" width="48" height="48" loading="lazy" class="h-12 w-12 shrink-0 rounded-lg border border-[var(--surface-border)] bg-white object-contain">
                                        <div class="min-w-0">
                                            <a :href="p.link" target="_blank" rel="noopener noreferrer" class="line-clamp-2 hover:text-[var(--destaque)] hover:underline">{{ p.nome }}</a>
                                            <span v-if="p.voce_vende" class="mt-0.5 inline-block rounded-full bg-[var(--surface-muted)] px-2 py-0.5 text-xs">A KazaKora já vende</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-2 pr-3 text-xs text-[var(--texto-suave)]">{{ p.categoria ?? '—' }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ faixaPreco(p) }}</td>
                                <td class="py-2 pr-4 text-right font-semibold tabular-nums">{{ formatNumber(p.vendas) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-[var(--surface-border)] px-4 py-3">
                    <Paginacao :pagina="pag.pagina" :total="pag.paginas" rotulo="Páginas do Radar da Shopee" @ir="(n) => recarregar({ rs_pagina: n })" />
                </div>
            </section>
        </template>
    </section>
</template>
