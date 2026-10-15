<script setup>
// Todas as ofertas no ar (pedido 2026-10-15): Oferta Relâmpago e Ofertas do
// Dia, com filtros no layout da Beauty Kora (FiltrosCatalogo: coluna lateral
// no computador, FILTRAR com tela cinza e Confirmar no celular).
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import FiltrosCatalogo from '@/Shared/Components/FiltrosCatalogo.vue';
import { DESCONTOS, PRECOS } from '@/Shared/filtrosCatalogo';
import ProductCard from '@/Shared/Components/ProductCard.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

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

const ORDENS = [
    { valor: null, label: 'Maior desconto' },
    { valor: 'termina', label: 'Termina primeiro' },
    { valor: 'menor_preco', label: 'Menor preço' },
    { valor: 'maior_preco', label: 'Maior preço' },
];

const href = (filtros) => {
    const params = new URLSearchParams();
    Object.entries(filtros).forEach(([chave, valor]) => { if (valor) params.set(chave, valor); });
    const query = params.toString();
    return query ? `/ofertas?${query}` : '/ofertas';
};
const filtroHref = (mudancas) => href({ ...props.filters, ...mudancas });
const ordenarPor = (valor) => router.get(filtroHref({ ordenar: valor || null }), {}, { preserveScroll: true });

const temFiltro = computed(() => ['categoria', 'desconto', 'preco', 'acabando'].some((chave) => props.filters[chave]));

const grupos = computed(() => [
    {
        chave: 'categoria',
        titulo: 'Departamentos',
        opcoes: [
            { valor: null, label: 'Todas as ofertas', labelPainel: 'Todas' },
            ...props.departamentos.map((d) => ({ valor: d.slug, label: d.name, total: d.total })),
        ],
    },
    { chave: 'desconto', titulo: 'Desconto', opcoes: DESCONTOS },
    { chave: 'preco', titulo: 'Preço', opcoes: PRECOS },
]);
const MARCAS = [{ chave: 'acabando', label: 'Termina em menos de 2 h', icone: 'fa-stopwatch', corIcone: 'text-[#E02424]' }];
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
                <FiltrosCatalogo modo="lateral" :grupos="grupos" :marcas="MARCAS" :filters="filters" :href="href" />

                <div class="min-w-0 flex-1">
                    <div class="mb-5 flex items-center justify-between gap-4">
                        <p class="text-sm text-store-fg-muted">
                            {{ produtos.length }} oferta{{ produtos.length === 1 ? '' : 's' }}
                            <Link v-if="temFiltro" :href="filtroHref({ categoria: null, desconto: null, preco: null, acabando: null })" class="ml-2 font-semibold text-store-accent hover:underline">Limpar filtros</Link>
                        </p>

                        <!-- Celular: a palavra FILTRAR abre a tela cinza com Confirmar -->
                        <div class="lg:hidden">
                            <FiltrosCatalogo modo="botao" :grupos="grupos" :marcas="MARCAS" :ordens="ORDENS" :filters="filters" :href="href" />
                        </div>

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

    </AppLayout>
</template>
