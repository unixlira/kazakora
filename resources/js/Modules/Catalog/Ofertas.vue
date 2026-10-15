<script setup>
// Todas as ofertas no ar (pedido 2026-10-15): Oferta Relâmpago e Ofertas do
// Dia, com filtros no layout da Beauty Kora (coluna lateral no computador).
// No celular, a palavra FILTRAR abre uma tela cinza que desce de cima com os
// filtros e o botão laranja Confirmar no fim; só aplica ao confirmar.
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import ProductCard from '@/Shared/Components/ProductCard.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    produtos: { type: Array, default: () => [] },
    totalOfertas: { type: Number, default: 0 },
    departamentos: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    favoriteIds: { type: Array, default: () => [] },
    reviewableProductIds: { type: Array, default: () => [] },
    reviewedProductIds: { type: Array, default: () => [] },
});

const page = usePage();
const isAuthenticated = computed(() => !!page.props.auth?.user);

const DESCONTOS = [
    { valor: null, label: 'Qualquer desconto' },
    { valor: '10', label: '10% ou mais' },
    { valor: '20', label: '20% ou mais' },
    { valor: '30', label: '30% ou mais' },
    { valor: '40', label: '40% ou mais' },
];
const PRECOS = [
    { valor: null, label: 'Qualquer preço' },
    { valor: 'ate-50', label: 'Até R$ 50' },
    { valor: '50-100', label: 'R$ 50 a R$ 100' },
    { valor: '100-200', label: 'R$ 100 a R$ 200' },
    { valor: '200-mais', label: 'Acima de R$ 200' },
];
const ORDENS = [
    { valor: null, label: 'Maior desconto' },
    { valor: 'termina', label: 'Termina primeiro' },
    { valor: 'menor_preco', label: 'Menor preço' },
    { valor: 'maior_preco', label: 'Maior preço' },
];

const atuais = () => ({
    categoria: props.filters.categoria || null,
    desconto: props.filters.desconto || null,
    preco: props.filters.preco || null,
    acabando: props.filters.acabando ? '1' : null,
    ordenar: props.filters.ordenar || null,
});

const href = (filtros) => {
    const params = new URLSearchParams();
    Object.entries(filtros).forEach(([chave, valor]) => { if (valor) params.set(chave, valor); });
    const query = params.toString();
    return query ? `/ofertas?${query}` : '/ofertas';
};
// Computador: cada clique já aplica.
const filtroHref = (mudancas) => href({ ...atuais(), ...mudancas });
const ordenarPor = (valor) => router.get(filtroHref({ ordenar: valor || null }), {}, { preserveScroll: true });

const temFiltro = computed(() => ['categoria', 'desconto', 'preco', 'acabando'].some((chave) => props.filters[chave]));
const qtdFiltros = computed(() => ['categoria', 'desconto', 'preco', 'acabando'].filter((chave) => props.filters[chave]).length);

// Celular: escolhas ficam no rascunho até o Confirmar.
const painelAberto = ref(false);
const rascunho = ref(atuais());
const abrirPainel = () => { rascunho.value = atuais(); painelAberto.value = true; };
const fecharPainel = () => { painelAberto.value = false; };
const escolher = (chave, valor) => { rascunho.value[chave] = valor; };
const limparRascunho = () => { rascunho.value = { categoria: null, desconto: null, preco: null, acabando: null, ordenar: rascunho.value.ordenar }; };
const confirmar = () => {
    painelAberto.value = false;
    router.get(href(rascunho.value), {}, { preserveScroll: false });
};

const aoTeclar = (evento) => { if (evento.key === 'Escape') fecharPainel(); };
watch(painelAberto, (aberto) => {
    document.body.style.overflow = aberto ? 'hidden' : '';
    if (aberto) document.addEventListener('keydown', aoTeclar);
    else document.removeEventListener('keydown', aoTeclar);
});
onBeforeUnmount(() => {
    document.body.style.overflow = '';
    document.removeEventListener('keydown', aoTeclar);
});

const itemLateral = (ativo) => ativo
    ? 'bg-store-accent text-store-accent-contrast font-semibold'
    : 'text-store-fg-muted hover:bg-store-bg hover:text-store-fg';
const pilulaPainel = (ativo) => ativo
    ? 'border-[#f27a2a] bg-[#f27a2a] text-white'
    : 'border-neutral-300 bg-white text-neutral-700';
</script>

<template>
    <Head title="Ofertas — KazaKora" />

    <AppLayout>
        <section class="mx-auto max-w-[1320px] px-4 pb-20 pt-8 md:px-6">
            <div class="mb-6">
                <h1 class="text-2xl font-bold md:text-3xl">Ofertas</h1>
                <p class="mt-1 text-sm text-store-fg-muted">Oferta Relâmpago e Ofertas do Dia: preço baixo por tempo limitado.</p>
            </div>

            <div class="flex flex-col gap-8 lg:flex-row">
                <!-- Computador: coluna de filtros (modelo Beauty Kora) -->
                <aside class="hidden w-72 shrink-0 space-y-4 lg:block">
                    <div class="rounded-2xl border border-store-border bg-store-bg-raised p-5">
                        <h2 class="mb-3 text-base font-bold">Departamentos</h2>
                        <nav class="space-y-1">
                            <Link :href="filtroHref({ categoria: null })" preserve-scroll
                                class="flex items-center justify-between rounded-xl px-3 py-2 text-sm no-underline transition-colors" :class="itemLateral(!filters.categoria)">
                                <span>Todas as ofertas</span>
                            </Link>
                            <Link v-for="d in departamentos" :key="d.slug" :href="filtroHref({ categoria: d.slug })" preserve-scroll
                                class="flex items-center justify-between rounded-xl px-3 py-2 text-sm no-underline transition-colors" :class="itemLateral(filters.categoria === d.slug)">
                                <span class="truncate pr-2">{{ d.name }}</span>
                                <span class="text-xs opacity-80">{{ d.total }}</span>
                            </Link>
                        </nav>
                    </div>

                    <div class="rounded-2xl border border-store-border bg-store-bg-raised p-5">
                        <h2 class="mb-3 text-base font-bold">Desconto</h2>
                        <nav class="space-y-1">
                            <Link v-for="op in DESCONTOS" :key="op.label" :href="filtroHref({ desconto: op.valor })" preserve-scroll
                                class="block rounded-xl px-3 py-2 text-sm no-underline transition-colors" :class="itemLateral((filters.desconto || null) === op.valor)">
                                {{ op.label }}
                            </Link>
                        </nav>
                    </div>

                    <div class="rounded-2xl border border-store-border bg-store-bg-raised p-5">
                        <h2 class="mb-3 text-base font-bold">Preço</h2>
                        <nav class="space-y-1">
                            <Link v-for="op in PRECOS" :key="op.label" :href="filtroHref({ preco: op.valor })" preserve-scroll
                                class="block rounded-xl px-3 py-2 text-sm no-underline transition-colors" :class="itemLateral((filters.preco || null) === op.valor)">
                                {{ op.label }}
                            </Link>
                        </nav>
                    </div>

                    <Link :href="filtroHref({ acabando: filters.acabando ? null : '1' })" preserve-scroll
                        class="flex items-center gap-3 rounded-2xl border border-store-border bg-store-bg-raised p-5 text-sm font-semibold text-store-fg no-underline">
                        <i class="fa-solid text-lg" :class="filters.acabando ? 'fa-square-check text-store-accent' : 'fa-square text-store-fg-faint'" aria-hidden="true"></i>
                        <span><i class="fa-solid fa-stopwatch mr-1 text-[#E02424]" aria-hidden="true"></i>Termina em menos de 2 h</span>
                    </Link>
                </aside>

                <div class="min-w-0 flex-1">
                    <div class="mb-5 flex items-center justify-between gap-4">
                        <p class="text-sm text-store-fg-muted">
                            {{ produtos.length }} oferta{{ produtos.length === 1 ? '' : 's' }}
                            <Link v-if="temFiltro" :href="filtroHref({ categoria: null, desconto: null, preco: null, acabando: null })" class="ml-2 font-semibold text-store-accent hover:underline">Limpar filtros</Link>
                        </p>

                        <!-- Celular: a palavra FILTRAR abre o painel cinza -->
                        <button type="button" class="inline-flex items-center gap-2 text-sm font-bold tracking-wide text-store-fg lg:hidden"
                            :aria-expanded="painelAberto" aria-controls="painel-filtros" @click="abrirPainel">
                            <i class="fa-solid fa-sliders" aria-hidden="true"></i>
                            FILTRAR<span v-if="qtdFiltros" class="rounded-full bg-[#f27a2a] px-1.5 text-xs text-white">{{ qtdFiltros }}</span>
                        </button>

                        <label class="hidden items-center gap-2 text-sm text-store-fg-muted lg:flex">
                            Ordenar por
                            <select :value="filters.ordenar ?? ''" class="rounded-lg border border-store-border-strong bg-store-bg-raised px-3 py-2 text-sm text-store-fg" @change="ordenarPor($event.target.value)">
                                <option v-for="op in ORDENS" :key="op.label" :value="op.valor ?? ''">{{ op.label }}</option>
                            </select>
                        </label>
                    </div>

                    <div v-if="produtos.length === 0" class="py-16 text-center text-store-fg-muted">
                        <i class="fa-solid fa-bolt mb-3 text-3xl text-store-fg-faint"></i>
                        <template v-if="totalOfertas > 0">
                            <p class="text-base font-semibold text-store-fg">Nenhuma oferta com esses filtros.</p>
                            <Link href="/ofertas" class="mt-4 inline-block rounded-lg bg-store-accent px-5 py-2.5 text-sm font-semibold text-store-accent-contrast">Ver todas as ofertas</Link>
                        </template>
                        <template v-else>
                            <p class="text-base font-semibold text-store-fg">Nenhuma oferta no ar agora.</p>
                            <p class="mt-1 text-sm">Volte mais tarde: as Ofertas do Dia trocam à meia-noite.</p>
                            <Link href="/?todos=1#produtos" class="mt-4 inline-block rounded-lg bg-store-accent px-5 py-2.5 text-sm font-semibold text-store-accent-contrast">Ver todos os produtos</Link>
                        </template>
                    </div>

                    <div v-else class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:gap-6 xl:grid-cols-4">
                        <ProductCard v-for="product in produtos" :key="product.id" :product="product"
                            :is-favorite="favoriteIds.includes(product.id)" :is-authenticated="isAuthenticated"
                            :can-review="reviewableProductIds.includes(product.id)" :has-reviewed="reviewedProductIds.includes(product.id)" />
                    </div>
                </div>
            </div>
        </section>

        <!-- Celular: tela cinza que desce com os filtros; aplica no Confirmar -->
        <Teleport to="body">
            <Transition enter-active-class="transition duration-300 ease-out" enter-from-class="-translate-y-full" enter-to-class="translate-y-0"
                leave-active-class="transition duration-200 ease-in" leave-from-class="translate-y-0" leave-to-class="-translate-y-full">
                <div v-if="painelAberto" id="painel-filtros" role="dialog" aria-modal="true" aria-labelledby="painel-filtros-titulo"
                    class="fixed inset-0 z-[70] flex flex-col bg-neutral-200 text-neutral-800 lg:hidden">
                    <div class="flex items-center justify-between border-b border-neutral-300 px-4 py-3">
                        <h2 id="painel-filtros-titulo" class="text-base font-bold tracking-wide">FILTRAR</h2>
                        <button type="button" class="flex h-9 w-9 items-center justify-center rounded-full text-lg" aria-label="Fechar" @click="fecharPainel">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </div>

                    <div class="flex-1 space-y-6 overflow-y-auto px-4 py-5">
                        <fieldset>
                            <legend class="mb-2 text-sm font-bold">Ordenar por</legend>
                            <div class="flex flex-wrap gap-2">
                                <button v-for="op in ORDENS" :key="op.label" type="button" :aria-pressed="rascunho.ordenar === op.valor"
                                    class="rounded-full border px-3 py-1.5 text-sm" :class="pilulaPainel(rascunho.ordenar === op.valor)"
                                    @click="escolher('ordenar', op.valor)">{{ op.label }}</button>
                            </div>
                        </fieldset>

                        <fieldset>
                            <legend class="mb-2 text-sm font-bold">Departamento</legend>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" :aria-pressed="!rascunho.categoria"
                                    class="rounded-full border px-3 py-1.5 text-sm" :class="pilulaPainel(!rascunho.categoria)"
                                    @click="escolher('categoria', null)">Todas</button>
                                <button v-for="d in departamentos" :key="d.slug" type="button" :aria-pressed="rascunho.categoria === d.slug"
                                    class="rounded-full border px-3 py-1.5 text-sm" :class="pilulaPainel(rascunho.categoria === d.slug)"
                                    @click="escolher('categoria', d.slug)">{{ d.name }} ({{ d.total }})</button>
                            </div>
                        </fieldset>

                        <fieldset>
                            <legend class="mb-2 text-sm font-bold">Desconto</legend>
                            <div class="flex flex-wrap gap-2">
                                <button v-for="op in DESCONTOS" :key="op.label" type="button" :aria-pressed="rascunho.desconto === op.valor"
                                    class="rounded-full border px-3 py-1.5 text-sm" :class="pilulaPainel(rascunho.desconto === op.valor)"
                                    @click="escolher('desconto', op.valor)">{{ op.label }}</button>
                            </div>
                        </fieldset>

                        <fieldset>
                            <legend class="mb-2 text-sm font-bold">Preço</legend>
                            <div class="flex flex-wrap gap-2">
                                <button v-for="op in PRECOS" :key="op.label" type="button" :aria-pressed="rascunho.preco === op.valor"
                                    class="rounded-full border px-3 py-1.5 text-sm" :class="pilulaPainel(rascunho.preco === op.valor)"
                                    @click="escolher('preco', op.valor)">{{ op.label }}</button>
                            </div>
                        </fieldset>

                        <label class="flex items-center gap-3 rounded-xl bg-white px-4 py-3 text-sm font-semibold">
                            <input type="checkbox" class="h-5 w-5 accent-[#f27a2a]" :checked="!!rascunho.acabando" @change="escolher('acabando', $event.target.checked ? '1' : null)">
                            <span><i class="fa-solid fa-stopwatch mr-1 text-[#E02424]" aria-hidden="true"></i>Termina em menos de 2 h</span>
                        </label>
                    </div>

                    <div class="flex items-center gap-3 border-t border-neutral-300 px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                        <button type="button" class="px-2 py-3 text-sm font-semibold text-neutral-600 underline" @click="limparRascunho">Limpar</button>
                        <button type="button" class="flex-1 rounded-xl bg-[#f27a2a] py-3 text-base font-bold text-white shadow-md active:bg-[#d9661a]" @click="confirmar">
                            Confirmar
                        </button>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </AppLayout>
</template>
