<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import BannerCarousel from '@/Shared/Components/BannerCarousel.vue';
import CarregandoProdutos from '@/Shared/Components/CarregandoProdutos.vue';
import CategoryCarousel from '@/Shared/Components/CategoryCarousel.vue';
import FiltrosCatalogo from '@/Shared/Components/FiltrosCatalogo.vue';
import MaisBuscadosSlider from '@/Shared/Components/MaisBuscadosSlider.vue';
import ProductCard from '@/Shared/Components/ProductCard.vue';
import SecaoProdutos from '@/Shared/Components/SecaoProdutos.vue';
import { DESCONTOS, PRECOS } from '@/Shared/filtrosCatalogo';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    banners: {
        type: Array,
        default: () => [],
    },
    // Home sem filtro (pedido 2026-10-10): seções da vitrine. Com busca ou
    // filtro vem null e a tela mostra a lista com rolagem infinita.
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
    // Departamentos da coluna de filtros, com quantos produtos cada um tem na
    // busca/filtro atual (só na lista).
    departamentosFiltro: {
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
    return 'Todos os produtos';
});


// Filtros da lista (pedido 2026-10-15, o mesmo de /ofertas): barra de
// filtros única (modelo do FerraKora nas cores da KazaKora). A busca
// continua junto; sem busca, aba nem filtro, ?todos=1 mantém a lista.
const href = (filtros) => {
    const params = new URLSearchParams();
    const tudo = { search: props.filters.search, ...filtros };
    ['search', 'tipo', 'categoria', 'desconto', 'preco', 'ordenar'].forEach((chave) => { if (tudo[chave]) params.set(chave, tudo[chave]); });
    if (![...params.keys()].some((chave) => ['search', 'tipo', 'categoria', 'desconto', 'preco'].includes(chave))) params.set('todos', '1');
    return `/?${params.toString()}#produtos`;
};
const filtroHref = (mudancas) => href({ ...props.filters, ...mudancas });

const contagem = computed(() => `${props.products?.total ?? 0} produto${(props.products?.total ?? 0) === 1 ? '' : 's'}`);
// A busca vira etiqueta com X junto dos filtros ligados.
const etiquetasExtras = computed(() => (props.filters.search ? [{ label: `"${props.filters.search}"`, href: filtroHref({ search: null }) }] : []));

const ORDENS = computed(() => [
    { valor: null, label: props.filters.search ? 'Mais relevantes' : 'Mais recentes' },
    { valor: 'menor_preco', label: 'Menor preço' },
    { valor: 'maior_preco', label: 'Maior preço' },
    { valor: 'nome', label: 'Nome (A–Z)' },
]);

// Na busca, sem Departamento (o Lira não gostou dos departamentos à mostra
// nos resultados, 2026-10-15): só Mostrar, Desconto, Preço e Ordenar.
const gruposFiltro = computed(() => [
    ...(props.filters.search ? [] : [{
        chave: 'categoria',
        titulo: 'Departamentos',
        rotulo: 'Departamento',
        tituloPainel: 'Categoria',
        opcoes: [
            { valor: null, label: 'Todos os departamentos', labelPainel: 'Todos' },
            ...props.departamentosFiltro.map((d) => ({ valor: d.slug, label: d.name, total: d.total })),
        ],
    }]),
    { chave: 'tipo', titulo: 'Mostrar', opcoes: TIPO_TABS.map((tab) => ({ valor: tab.key, label: tab.label })) },
    { chave: 'desconto', titulo: 'Desconto', prefixoEtiqueta: 'Desconto de ', opcoes: DESCONTOS },
    { chave: 'preco', titulo: 'Preço', opcoes: PRECOS },
]);

const BENEFICIOS = [
    { icone: 'fa-truck-fast', titulo: 'Frete Grátis', texto: 'Entrega em todo Brasil' },
    { icone: 'fa-credit-card', titulo: 'Parcelamento', texto: 'Em 12x nos cartões' },
    { icone: 'fa-lock', titulo: 'Compra Segura', texto: 'Ambiente seguro para pagamentos online' },
    { icone: 'fa-face-smile', titulo: 'Satisfação Garantida', texto: 'Você 100% feliz ou seu reembolso garantido' },
];

// Catálogo 8 por vez (pedido 2026-10-10): busca só a próxima página dos
// produtos (sem recarregar a tela nem mudar o endereço) e junta embaixo.
// Trocar de aba/busca recomeça a lista. Desde 2026-10-13 sem botão: carrega
// sozinho quando o fim da lista chega perto da tela (rolagem infinita).
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
        onFinish: () => {
            carregandoMais.value = false;
            // Se a lista nova ainda não encheu a tela (monitor grande), o
            // marcador continua visível e o observador não avisa de novo:
            // observar outra vez faz ele conferir na hora.
            nextTick(observarFim);
        },
    });
};

// Marcador invisível no fim da lista: ao chegar a ~600px da tela, carrega a
// próxima página.
const fimDaLista = ref(null);
let observador = null;
const observarFim = () => {
    observador?.disconnect();
    if (!fimDaLista.value) return;
    observador ??= new IntersectionObserver((entradas) => {
        if (entradas.some((entrada) => entrada.isIntersecting)) carregarMais();
    }, { rootMargin: '0px 0px 600px 0px' });
    observador.observe(fimDaLista.value);
};
watch(fimDaLista, observarFim);
onBeforeUnmount(() => observador?.disconnect());
</script>

<template>
    <Head :title="filters.search ? `Busca: ${filters.search}` : 'KazaKora — eletrônicos, gadgets e cozinha'" />

    <AppLayout>
        <!-- Banner rotativo -->
        <BannerCarousel v-if="vitrine && banners.length" :banners="banners" reserva-base />

        <!-- Benefícios (pedido 2026-10-10, modelo izeshop): no computador metade em
             cima do banner; no celular logo abaixo dele (a imagem já é pequena). -->
        <section v-if="vitrine" class="relative z-10 mx-auto max-w-[1320px] px-4 md:px-6" :class="banners.length ? 'mb-8 mt-4 md:-mt-11' : 'my-6'">
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-4">
                <div v-for="beneficio in BENEFICIOS" :key="beneficio.titulo"
                    class="flex items-center gap-3 rounded-xl bg-store-bg-raised text-orange-500 px-3 py-3 shadow-[0_8px_24px_var(--store-shadow)] md:px-5 md:py-4">
                    <i class="fa-solid shrink-0 text-2xl text-orange-500 md:text-3xl" :class="beneficio.icone"></i>
                    <div class="min-w-0">
                        <h3 class="text-sm font-bold leading-tight md:text-base">{{ beneficio.titulo }}</h3>
                        <p class="mt-0.5 text-xs leading-snug text-orange-500 md:text-sm">{{ beneficio.texto }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Categories -->
        <section v-if="vitrine && categories.length" id="categorias" class="mx-auto max-w-[1320px] px-4 pb-6 md:px-6">
            <h2 class="mb-6 text-center font-display text-2xl font-semibold">Departamentos</h2>
            <CategoryCarousel :categories="categories" :ativa="filters.categoria ?? null" />
        </section>

        <!-- Vitrine da home (pedido 2026-10-10): Departamentos, Ofertas do dia,
             Mais buscados, Utilidades para casa, Últimas novidades e Você também pode gostar. -->
        <template v-if="vitrine">
            <!-- Âncora da faixa promocional do topo. -->
            <span id="ofertas" class="block scroll-mt-28"></span>
            <SecaoProdutos titulo="Ofertas do Dia" :produtos="vitrine.ofertas" cinco slider
                subtitulo="Desconto extra só hoje, em produtos escolhidos a dedo."
                :favorite-ids="favoriteIds" :reviewable-product-ids="reviewableProductIds" :reviewed-product-ids="reviewedProductIds">
                <template #acao>
                    <!-- Celular: largura toda, texto no centro e relógio pulsando (pedido 2026-10-10). -->
                    <div class="flex w-full flex-col gap-1.5 md:w-auto md:items-end">
                        <span class="flex w-full items-center justify-center gap-2 rounded-lg bg-[#E02424] px-3 py-2 text-sm font-bold text-white md:inline-flex md:w-auto md:py-1.5">
                            <i class="fa-solid fa-stopwatch animate-pulse"></i> Termina em <span class="font-mono tabular-nums">{{ restanteOfertas }}</span>
                        </span>
                        <!-- Página com todas as ofertas e filtros (pedido 2026-10-15). -->
                        <Link href="/ofertas" class="text-sm font-semibold text-store-accent hover:underline">
                            Ver todas as ofertas <i class="fas fa-arrow-right ml-1 text-xs"></i>
                        </Link>
                    </div>
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

        <!-- Lista (busca, aba ou departamento) com filtros e rolagem infinita -->
        <section v-else id="produtos" class="mx-auto max-w-[1320px] px-4 pb-20 pt-8 md:px-6">
            <div class="mb-6">
                <h1 class="text-2xl font-bold md:text-3xl">{{ listTitle }}</h1>
            </div>

            <FiltrosCatalogo :grupos="gruposFiltro" :ordens="ORDENS" :filters="filters" :href="href" :contagem="contagem"
                :extras="etiquetasExtras" limpar-href="/?todos=1#produtos" :painel="['categoria', 'preco', 'desconto']" />

            <div>
                <div>
                    <div v-if="listaProdutos.length === 0" class="py-16 text-center text-store-fg-muted">
                        <i class="fas fa-magnifying-glass mb-3 text-3xl text-store-fg-faint"></i>
                        <p class="text-base font-semibold text-store-fg">
                            Nenhum produto encontrado{{ filters.search ? ` para "${filters.search}"` : '' }}.
                        </p>
                        <p class="mt-1 text-sm">Tente outra palavra, use menos termos ou veja todos os produtos.</p>
                        <Link href="/?todos=1#produtos" class="mt-4 inline-block rounded-lg bg-store-accent px-5 py-2.5 text-sm font-semibold text-store-accent-contrast">Ver todos os produtos</Link>
                    </div>

                    <div v-else class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:gap-6 xl:grid-cols-4">
                        <ProductCard v-for="product in listaProdutos" :key="product.id" :product="product"
                            :is-favorite="isFavorite(product.id)" :is-authenticated="isAuthenticated"
                            :can-review="canReview(product.id)" :has-reviewed="hasReviewed(product.id)" />
                    </div>

                    <!-- Enquanto a próxima leva chega: cards fantasma na mesma grade (2026-10-13) -->
                    <CarregandoProdutos v-if="carregandoMais" />

                    <div v-if="temMais" ref="fimDaLista" class="mt-10 flex flex-col items-center gap-2">
                        <span class="text-xs text-store-fg-muted">Mostrando {{ listaProdutos.length }} de {{ products?.total }}</span>
                    </div>
                </div>
            </div>
        </section>

    </AppLayout>
</template>
