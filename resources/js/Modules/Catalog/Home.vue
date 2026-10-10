<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import BannerCarousel from '@/Shared/Components/BannerCarousel.vue';
import CategoryCarousel from '@/Shared/Components/CategoryCarousel.vue';
import MaisBuscadosSlider from '@/Shared/Components/MaisBuscadosSlider.vue';
import ProductCard from '@/Shared/Components/ProductCard.vue';
import SecaoProdutos from '@/Shared/Components/SecaoProdutos.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    banners: {
        type: Array,
        default: () => [],
    },
    // Home sem filtro (pedido 2026-10-10): seções da vitrine. Com busca ou
    // filtro vem null e a tela mostra a lista com "Carregar mais".
    vitrine: {
        type: Object,
        default: null,
    },
    products: {
        type: Object,
        default: null,
    },
    categories: {
        type: Array,
        default: () => [],
    },
    favoriteIds: {
        type: Array,
        default: () => [],
    },
    reviewableProductIds: {
        type: Array,
        default: () => [],
    },
    reviewedProductIds: {
        type: Array,
        default: () => [],
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
});

const page = usePage();
const isAuthenticated = computed(() => !!page.props.auth?.user);

const isFavorite = (productId) => props.favoriteIds.includes(productId);
const canReview = (productId) => props.reviewableProductIds.includes(productId);
const hasReviewed = (productId) => props.reviewedProductIds.includes(productId);

const TIPO_TABS = [
    { key: null, label: 'Todos' },
    { key: 'destaque', label: 'Destaques' },
    { key: 'lancamento', label: 'Lançamentos' },
];

const listTitle = computed(() => {
    if (props.filters.search) return `Resultados para "${props.filters.search}"`;
    if (props.filters.tipo === 'destaque') return 'Destaques';
    if (props.filters.tipo === 'lancamento') return 'Lançamentos';
    if (props.filters.categoria) return props.categories.find((category) => category.slug === props.filters.categoria)?.name ?? 'Catálogo';
    return 'Catálogo';
});

const tabHref = (tipo) => (tipo ? `/?tipo=${tipo}#produtos` : '/?todos=1#produtos');

const BENEFICIOS = [
    { icone: 'fa-truck-fast', titulo: 'Frete Grátis', texto: 'Entrega em todo Brasil' },
    { icone: 'fa-credit-card', titulo: 'Parcelamento', texto: 'Em 12x nos cartões' },
    { icone: 'fa-lock', titulo: 'Compra Segura', texto: 'Ambiente seguro para pagamentos online' },
    { icone: 'fa-face-smile', titulo: 'Satisfação Garantida', texto: 'Você 100% feliz ou seu reembolso garantido' },
];

// Catálogo 8 por vez (pedido 2026-10-10): "Carregar mais" busca só a próxima
// página dos produtos (sem recarregar a tela nem mudar o endereço) e junta
// embaixo. Trocar de aba/busca recomeça a lista.
const listaProdutos = ref([...(props.products?.data ?? [])]);
const carregandoMais = ref(false);
let somarNaLista = false;

watch(() => props.products, (novos) => {
    if (!novos) return;
    listaProdutos.value = somarNaLista ? [...listaProdutos.value, ...novos.data] : [...novos.data];
    somarNaLista = false;
});

const temMais = computed(() => !!props.products && props.products.current_page < props.products.last_page);

// Ofertas do dia: contagem até a meia-noite (quando as ofertas trocam).
const restanteOfertas = ref('');
let relogioOfertas = null;
const atualizarRelogio = () => {
    const fim = props.vitrine?.ofertasTerminamEm ? new Date(props.vitrine.ofertasTerminamEm).getTime() : 0;
    const segundos = Math.max(0, Math.floor((fim - Date.now()) / 1000));
    const dois = (valor) => String(valor).padStart(2, '0');
    restanteOfertas.value = `${dois(Math.floor(segundos / 3600))}:${dois(Math.floor((segundos % 3600) / 60))}:${dois(segundos % 60)}`;
};
onMounted(() => { atualizarRelogio(); relogioOfertas = setInterval(atualizarRelogio, 1000); });
onBeforeUnmount(() => clearInterval(relogioOfertas));

const carregarMais = () => {
    if (carregandoMais.value || !temMais.value) return;
    carregandoMais.value = true;
    somarNaLista = true;
    router.reload({
        data: { page: props.products.current_page + 1 },
        only: ['products'],
        preserveUrl: true,
        preserveScroll: true,
        onError: () => { somarNaLista = false; },
        onFinish: () => { carregandoMais.value = false; },
    });
};
</script>

<template>
    <Head :title="filters.search ? `Busca: ${filters.search}` : 'KazaKora — eletrônicos, gadgets e cozinha'" />

    <AppLayout>
        <!-- Banner rotativo -->
        <BannerCarousel v-if="banners.length" :banners="banners" reserva-base />

        <!-- Benefícios (pedido 2026-10-10, modelo izeshop): metade em cima do
             banner, metade abaixo da linha que separa o banner do resto. -->
        <section class="relative z-10 mx-auto max-w-[1320px] px-4 md:px-6" :class="banners.length ? '-mt-12 mb-8 md:-mt-11' : 'my-6'">
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-4">
                <div v-for="beneficio in BENEFICIOS" :key="beneficio.titulo"
                    class="flex items-center gap-3 rounded-xl border border-store-border bg-store-bg-raised px-3 py-3 shadow-[0_8px_24px_var(--store-shadow)] md:px-5 md:py-4">
                    <i class="fa-solid shrink-0 text-2xl text-store-accent md:text-3xl" :class="beneficio.icone"></i>
                    <div class="min-w-0">
                        <h3 class="text-sm font-bold leading-tight md:text-base">{{ beneficio.titulo }}</h3>
                        <p class="mt-0.5 text-xs leading-snug text-store-fg-muted md:text-sm">{{ beneficio.texto }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Categories -->
        <section v-if="categories.length" id="categorias" class="mx-auto max-w-[1320px] px-4 pb-6 md:px-6">
            <h2 class="mb-6 text-center font-display text-2xl font-semibold">Departamentos</h2>
            <CategoryCarousel :categories="categories" :ativa="filters.categoria ?? null" />
        </section>

        <!-- Vitrine da home (pedido 2026-10-10): Departamentos, Ofertas do dia,
             Mais buscados, Utilidades para casa, Últimas novidades e Você também pode gostar. -->
        <template v-if="vitrine">
            <SecaoProdutos titulo="Ofertas do dia" :produtos="vitrine.ofertas" cinco
                subtitulo="Desconto extra só hoje, em produtos escolhidos a dedo."
                :favorite-ids="favoriteIds" :reviewable-product-ids="reviewableProductIds" :reviewed-product-ids="reviewedProductIds">
                <template #acao>
                    <span class="inline-flex items-center gap-2 rounded-lg bg-[#E02424] px-3 py-1.5 text-sm font-bold text-white">
                        <i class="fa-solid fa-stopwatch"></i> Termina em <span class="font-mono tabular-nums">{{ restanteOfertas }}</span>
                    </span>
                </template>
            </SecaoProdutos>

            <MaisBuscadosSlider :produtos="vitrine.maisBuscados" />

            <SecaoProdutos titulo="Utilidades para casa" :produtos="vitrine.utilidades"
                :link="vitrine.utilidadesLink ? `/?categoria=${vitrine.utilidadesLink}#produtos` : null"
                :favorite-ids="favoriteIds" :reviewable-product-ids="reviewableProductIds" :reviewed-product-ids="reviewedProductIds" />

            <SecaoProdutos titulo="Últimas novidades" :produtos="vitrine.novidades" link="/?todos=1#produtos"
                :favorite-ids="favoriteIds" :reviewable-product-ids="reviewableProductIds" :reviewed-product-ids="reviewedProductIds" />

            <SecaoProdutos titulo="Você também pode gostar" :produtos="vitrine.gostar"
                :favorite-ids="favoriteIds" :reviewable-product-ids="reviewableProductIds" :reviewed-product-ids="reviewedProductIds" />
        </template>

        <!-- Lista (busca, aba ou departamento) com "Carregar mais" -->
        <section v-else id="produtos" class="mx-auto max-w-[1320px] px-4 pb-20 md:px-6">
            <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="font-display text-3xl font-semibold">{{ listTitle }}</h2>
                    <p class="mt-2 text-store-fg-muted">Eletrônicos, gadgets e utensílios de cozinha selecionados pela curadoria KazaKora.</p>
                </div>

                <div v-if="!filters.search" class="flex flex-wrap gap-2">
                    <Link v-for="tab in TIPO_TABS" :key="tab.label" :href="tabHref(tab.key)" preserve-scroll
                        class="rounded-full px-4 py-1.5 text-sm font-medium no-underline transition-colors"
                        :class="(filters.tipo ?? null) === tab.key
                            ? 'bg-store-accent text-store-accent-contrast'
                            : 'border border-store-border-strong text-store-fg-muted hover:border-store-fg'">
                        {{ tab.label }}
                    </Link>
                </div>
            </div>

            <p v-if="listaProdutos.length === 0" class="py-16 text-center text-store-fg-muted">
                Nenhum produto encontrado.
            </p>

            <div v-else class="grid grid-cols-2 gap-4 md:grid-cols-4 lg:gap-6">
                <ProductCard v-for="product in listaProdutos" :key="product.id" :product="product"
                    :is-favorite="isFavorite(product.id)" :is-authenticated="isAuthenticated"
                    :can-review="canReview(product.id)" :has-reviewed="hasReviewed(product.id)" />
            </div>

            <div v-if="temMais" class="mt-10 flex flex-col items-center gap-2">
                <button type="button" :disabled="carregandoMais"
                    class="inline-flex items-center gap-2 rounded-lg bg-store-accent px-8 py-3 text-sm font-semibold text-store-accent-contrast transition-opacity hover:opacity-90 disabled:opacity-60"
                    @click="carregarMais">
                    <i class="fa-solid" :class="carregandoMais ? 'fa-spinner animate-spin' : 'fa-plus'"></i>
                    {{ carregandoMais ? 'Carregando...' : 'Carregar mais produtos' }}
                </button>
                <span class="text-xs text-store-fg-muted">Mostrando {{ listaProdutos.length }} de {{ products?.total }}</span>
            </div>
        </section>

    </AppLayout>
</template>
