<script setup>
// Página de produto v2 (pedido 2026-10-09): mesmo layout do modelo
// "pagina-produto.html" que o Lira mandou — galeria + box de compra fixo à
// direita, conteúdo em cards embaixo da galeria. Só o miolo: topo e rodapé
// continuam os da loja (AppLayout). A v1 (ProductDetail.vue) segue no
// código; o CatalogController escolhe qual mostrar.
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import Modal from '@/Shared/Modal.vue';
import ProductCard from '@/Shared/Components/ProductCard.vue';
import { COMPANY } from '@/Shared/company';
import { formatPrice, toggleFavorite } from '@/Shared/productCard';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    product: { type: Object, required: true },
    variations: { type: Array, default: () => [] },
    reviews: { type: Array, default: () => [] },
    shippingMethods: { type: Array, default: () => [] },
    isFavorite: { type: Boolean, default: false },
    canReview: { type: Boolean, default: false },
    hasReviewed: { type: Boolean, default: false },
    relatedProducts: { type: Array, default: () => [] },
    relatedFavoriteIds: { type: Array, default: () => [] },
    relatedReviewableIds: { type: Array, default: () => [] },
    relatedReviewedIds: { type: Array, default: () => [] },
    salesCount: { type: Number, default: 0 },
});

const page = usePage();
const isAuthenticated = computed(() => !!page.props.auth?.user);

const PARCELAS = 12;
const PAYMENT_BRANDS = ['pix', 'visa', 'mastercard', 'elo', 'amex', 'diners'];

// Perguntas sobre a loja, com as regras reais (política de trocas e checkout).
const FAQ = [
    ['Como acompanho o meu pedido?', 'Assim que o pedido é postado, o código de rastreio aparece em "Meus pedidos", na sua conta.'],
    ['Qual é o prazo de entrega?', 'O prazo é calculado pelo seu CEP e aparece no checkout antes de você finalizar a compra.'],
    ['O frete é grátis?', 'Sim. O envio é grátis para a sua residência.'],
    ['Posso trocar ou devolver?', 'Sim. Você tem 7 dias após o recebimento para desistir da compra e 30 dias para trocar um produto com defeito.'],
    ['Quais são as formas de pagamento?', `Pix, com aprovação imediata, ou cartão de crédito em até ${PARCELAS}x sem juros.`],
];

const ratingAvg = computed(() => Number(props.product.reviews_avg_rating ?? 0));
const reviewsCount = computed(() => Number(props.product.reviews_count ?? props.reviews.length));
const stars = (value) => Math.round(value);

// Galeria: vídeo primeiro, depois as imagens em ordem.
const mediaItems = computed(() => {
    const items = [];
    if (props.product.video_url) items.push({ type: 'video', src: props.product.video_url });
    for (const image of props.product.images ?? []) items.push({ type: 'image', src: image.url });
    return items;
});

const activeIndex = ref(0);
const activeMedia = computed(() => mediaItems.value[activeIndex.value] ?? null);
const isLightboxOpen = ref(false);

const goPrev = () => {
    if (mediaItems.value.length < 2) return;
    activeIndex.value = (activeIndex.value - 1 + mediaItems.value.length) % mediaItems.value.length;
};
const goNext = () => {
    if (mediaItems.value.length < 2) return;
    activeIndex.value = (activeIndex.value + 1) % mediaItems.value.length;
};

const touchStartX = ref(null);
const onTouchStart = (event) => { touchStartX.value = event.touches[0]?.clientX ?? null; };
const onTouchEnd = (event) => {
    if (touchStartX.value === null) return;
    const deltaX = (event.changedTouches[0]?.clientX ?? touchStartX.value) - touchStartX.value;
    if (Math.abs(deltaX) > 40) deltaX > 0 ? goPrev() : goNext();
    touchStartX.value = null;
};

const onKeydown = (event) => {
    const tag = event.target?.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA') return;
    if (event.key === 'ArrowLeft') goPrev();
    if (event.key === 'ArrowRight') goNext();
    if (event.key === 'Escape') isLightboxOpen.value = false;
};

watch(isLightboxOpen, (open) => { document.body.style.overflow = open ? 'hidden' : ''; });
watch(() => props.product.id, () => {
    activeIndex.value = 0;
    isLightboxOpen.value = false;
    quantity.value = 1;
});
onMounted(() => document.addEventListener('keydown', onKeydown));
onUnmounted(() => {
    document.removeEventListener('keydown', onKeydown);
    document.body.style.overflow = '';
});

const variantThumbnail = (item) => {
    const images = item?.images ?? [];
    return images.find((image) => image.is_primary)?.url ?? images[0]?.url ?? null;
};

const discountPercent = computed(() => {
    if (props.product.discount_percentage) return Math.round(Number(props.product.discount_percentage));
    if (props.product.discount_amount && props.product.price > 0) {
        return Math.round((Number(props.product.discount_amount) / Number(props.product.price)) * 100);
    }
    return 0;
});

// Desconto por quantidade: mesma conta do backend (CartManager/CheckoutController).
const quantityDiscounts = computed(() =>
    [...(props.product.quantity_discounts ?? [])].sort((a, b) => a.min_quantity - b.min_quantity)
);
const unitPriceForQuantity = (qty) => {
    const tier = [...quantityDiscounts.value].reverse().find((row) => row.min_quantity <= qty);
    if (!tier) return Number(props.product.final_price);
    return Math.max(0, Math.round(Number(props.product.final_price) * (1 - Number(tier.discount_percentage) / 100) * 100) / 100);
};

const quantity = ref(1);
const currentTotal = computed(() => unitPriceForQuantity(quantity.value) * quantity.value);
const installmentValue = computed(() => Number(props.product.final_price) / PARCELAS);
const inStock = computed(() => props.product.stock > 0);

const setQuantity = (value) => {
    quantity.value = Math.min(Math.max(1, Number(value) || 1), Math.max(1, props.product.stock));
};

// Descrição livre em seções, como os blocos do modelo: linha terminada em
// ":" vira título; "- item" vira lista; linha curta sem ponto final
// ("Material: Bambu", "Cesto Grande") entra numa lista sem marcador; o resto
// é parágrafo.
const descriptionSections = computed(() => {
    if (!props.product.description) return [];
    const sections = [];
    let section = null;
    const current = () => section ?? (sections.push(section = { heading: null, parts: [] }), section);
    const lastPart = (type) => {
        const parts = current().parts;
        const last = parts[parts.length - 1];
        if (last?.type === type) return last;
        parts.push({ type, items: [] });
        return parts[parts.length - 1];
    };

    for (const raw of props.product.description.split('\n')) {
        const line = raw.trim();
        if (!line) {
            if (section) section.parts.push({ type: 'gap' });
            continue;
        }
        if (line.endsWith(':') && line.length <= 120) {
            sections.push(section = { heading: line.slice(0, -1), parts: [] });
        } else if (/^[-•]/.test(line)) {
            lastPart('ul').items.push(line.replace(/^[-•]\s*/, ''));
        } else if (line.length <= 40 && !/[.!?]$/.test(line)) {
            lastPart('linhas').items.push(line);
        } else {
            current().parts.push({ type: 'p', items: [line] });
        }
    }

    return sections
        .map((item) => ({ ...item, parts: item.parts.filter((part) => part.type !== 'gap') }))
        .filter((item) => item.heading || item.parts.length);
});

// Destaques do box de compra: os 3 primeiros itens da primeira lista da
// própria descrição (nada inventado à parte).
const highlights = computed(() => {
    for (const section of descriptionSections.value) {
        const list = section.parts.find((part) => part.type === 'ul');
        if (list) return list.items.slice(0, 3);
    }
    return [];
});

const specs = computed(() => {
    const list = [];
    if (props.product.brand) list.push(['Marca', props.product.brand]);
    if (props.product.model) list.push(['Modelo', props.product.model]);
    if (props.product.color) list.push(['Cor', props.product.color]);
    if (props.product.variation) list.push(['Variação', props.product.variation]);
    if (props.product.category) list.push(['Categoria', props.product.category.name]);
    if (props.product.sku) list.push(['SKU', props.product.sku]);
    list.push(['Estoque disponível', `${props.product.stock} unidade${props.product.stock === 1 ? '' : 's'}`]);
    return list;
});

const isFreeShipping = computed(() => props.shippingMethods.length > 0);

const addingToCart = ref(false);
const buyingNow = ref(false);

const addToCart = () => {
    addingToCart.value = true;
    router.post('/carrinho', { product_id: props.product.id, quantity: quantity.value }, {
        preserveScroll: true,
        onFinish: () => { addingToCart.value = false; },
    });
};

const buyNow = () => {
    buyingNow.value = true;
    router.post('/carrinho', { product_id: props.product.id, quantity: quantity.value }, {
        preserveScroll: true,
        onSuccess: () => router.get('/finalizacao'),
        onFinish: () => { buyingNow.value = false; },
    });
};

const showReviews = computed(() => props.reviews.length > 0 || (props.canReview && !props.hasReviewed));
const showReviewModal = ref(false);
const reviewForm = useForm({ rating: 5, comment: '' });
const submitReview = () => {
    reviewForm.post(`/produtos/${props.product.id}/avaliacoes`, {
        preserveScroll: true,
        onSuccess: () => {
            showReviewModal.value = false;
            reviewForm.reset();
        },
    });
};

const formatDate = (value) => new Intl.DateTimeFormat('pt-BR', { dateStyle: 'medium' }).format(new Date(value));
</script>

<template>
    <Head :title="product.name">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    </Head>

    <AppLayout>
        <div class="pd2">
            <div class="wrap">
                <nav class="migalhas" aria-label="Você está em">
                    <Link href="/">Início</Link>
                    <template v-if="product.category">
                        / <Link :href="`/?search=${encodeURIComponent(product.category.name)}`">{{ product.category.name }}</Link>
                    </template>
                    / <span>{{ product.name }}</span>
                </nav>

                <div class="grade">
                    <!-- GALERIA -->
                    <section class="card col-galeria">
                        <div class="galeria" :class="{ 'sem-miniaturas': mediaItems.length < 2 }">
                            <div v-if="mediaItems.length > 1" class="miniaturas">
                                <button v-for="(item, index) in mediaItems" :key="index" type="button"
                                    :aria-label="`Ver ${item.type === 'video' ? 'vídeo' : 'foto ' + (index + 1)}`"
                                    :aria-current="index === activeIndex" @click="activeIndex = index">
                                    <template v-if="item.type === 'video'">
                                        <video :src="item.src" muted preload="metadata"></video>
                                        <span class="play"><i class="fas fa-play"></i></span>
                                    </template>
                                    <img v-else :src="item.src" alt="" loading="lazy">
                                </button>
                            </div>
                            <div class="foto" @click="activeMedia && (isLightboxOpen = true)" @touchstart="onTouchStart" @touchend="onTouchEnd">
                                <template v-if="activeMedia?.type === 'video'">
                                    <video :src="activeMedia.src" muted preload="metadata"></video>
                                    <span class="play grande"><i class="fas fa-play"></i></span>
                                </template>
                                <img v-else-if="activeMedia" :src="activeMedia.src" :alt="`${product.name} — foto ${activeIndex + 1}`">
                                <div v-else class="vazio"><i class="fas fa-box-open"></i></div>

                                <template v-if="mediaItems.length > 1">
                                    <button type="button" class="seta esq" aria-label="Mídia anterior" @click.stop="goPrev"><i class="fas fa-chevron-left"></i></button>
                                    <button type="button" class="seta dir" aria-label="Próxima mídia" @click.stop="goNext"><i class="fas fa-chevron-right"></i></button>
                                    <span class="contador">{{ activeIndex + 1 }}/{{ mediaItems.length }}</span>
                                </template>
                            </div>
                        </div>
                    </section>

                    <!-- BOX DE COMPRA -->
                    <aside id="comprar" class="card col-compra compra">
                        <div class="titulo">
                            <h1>{{ product.name }}</h1>
                            <button v-if="isAuthenticated" type="button" class="fav" :aria-pressed="isFavorite" aria-label="Favoritar" @click="toggleFavorite(product.id)">
                                <i :class="isFavorite ? 'fas fa-heart ativo' : 'far fa-heart'"></i>
                            </button>
                            <Link v-else href="/entrar" class="fav" aria-label="Favoritar"><i class="far fa-heart"></i></Link>
                        </div>

                        <div v-if="reviewsCount > 0 || salesCount > 0" class="nota">
                            <template v-if="reviewsCount > 0">
                                <span class="estrelas">{{ '★'.repeat(stars(ratingAvg)) }}{{ '☆'.repeat(5 - stars(ratingAvg)) }}</span>
                                <a href="#avaliacoes">({{ reviewsCount }} avaliaç{{ reviewsCount === 1 ? 'ão' : 'ões' }})</a>
                            </template>
                            <span v-if="salesCount > 0">+{{ salesCount }} vendidos</span>
                        </div>

                        <ul v-if="highlights.length" class="checks">
                            <li v-for="(item, index) in highlights" :key="index">{{ item }}</li>
                        </ul>

                        <div class="preco">
                            <span class="rot">Preço:</span>
                            <div v-if="product.has_discount">
                                <span class="de">{{ formatPrice(product.price) }}</span>
                                <span v-if="discountPercent > 0" class="selo">↓ {{ discountPercent }}%</span>
                            </div>
                            <div class="por">{{ formatPrice(product.final_price) }}</div>
                            <div class="parc">Em até {{ PARCELAS }}x de {{ formatPrice(installmentValue) }} sem juros</div>
                        </div>

                        <div v-if="variations.length" class="variacoes">
                            <span class="subtitulo">Escolha a variação</span>
                            <div class="opcoes">
                                <span class="opcao atual">
                                    <img v-if="variantThumbnail(product)" :src="variantThumbnail(product)" alt="">
                                    {{ product.variation || product.name }}
                                </span>
                                <Link v-for="variant in variations" :key="variant.id" :href="`/produtos/${variant.slug}`" preserve-scroll
                                    class="opcao" :class="{ esgotada: variant.stock < 1 }">
                                    <img v-if="variantThumbnail(variant)" :src="variantThumbnail(variant)" alt="">
                                    {{ variant.variation || variant.name }}
                                </Link>
                            </div>
                        </div>

                        <div v-if="quantityDiscounts.length" class="leve-mais">
                            <span class="subtitulo">Leve mais, pague menos</span>
                            <ul>
                                <li v-for="tier in quantityDiscounts" :key="tier.id">
                                    <span>{{ tier.min_quantity }} unidades</span>
                                    <strong>{{ formatPrice(unitPriceForQuantity(tier.min_quantity)) }}/un.</strong>
                                    <span class="selo">-{{ Math.round(Number(tier.discount_percentage)) }}%</span>
                                </li>
                            </ul>
                        </div>

                        <template v-if="inStock">
                            <div class="acao">
                                <div class="qtd">
                                    <button type="button" aria-label="Diminuir quantidade" :disabled="quantity <= 1" @click="setQuantity(quantity - 1)">−</button>
                                    <input :value="quantity" type="number" min="1" :max="product.stock" aria-label="Quantidade" @change="setQuantity($event.target.value)">
                                    <button type="button" aria-label="Aumentar quantidade" :disabled="quantity >= product.stock" @click="setQuantity(quantity + 1)">+</button>
                                </div>
                                <button type="button" class="btn" :disabled="buyingNow" @click="buyNow">COMPRAR</button>
                            </div>
                            <p v-if="quantityDiscounts.length && quantity > 1" class="total">Total: <strong>{{ formatPrice(currentTotal) }}</strong></p>
                            <button type="button" class="btn-sec" :disabled="addingToCart" @click="addToCart">
                                <i class="fas fa-bag-shopping"></i> Adicionar ao carrinho
                            </button>
                        </template>
                        <p v-else class="esgotado">Produto esgotado</p>

                        <div class="bandeiras">
                            <img v-for="brand in PAYMENT_BRANDS" :key="brand" :src="`/images/payments/${brand}@2x.png`" :alt="brand" loading="lazy">
                        </div>

                        <div class="infos">
                            <Link :href="`/produtos/${product.slug}/envio`" class="info">
                                <svg viewBox="0 0 24 24"><path d="M2 7h11v9H2zM13 10h4l3 3v3h-7zM6 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM17 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4z" /></svg>
                                <div v-if="isFreeShipping"><strong>Frete grátis.</strong> Entrega na sua residência; o prazo é calculado pelo CEP no checkout.</div>
                                <div v-else><strong>Entrega.</strong> O frete e o prazo são calculados pelo CEP no checkout.</div>
                            </Link>
                            <Link href="/trocas-e-devolucoes" class="info">
                                <svg viewBox="0 0 24 24"><path d="M4 12a8 8 0 1 0 2.5-5.8M4 4v4h4" /></svg>
                                <div><strong>Devolução fácil.</strong> 7 dias para desistir e 30 dias para trocar se vier com defeito.</div>
                            </Link>
                            <div class="info">
                                <svg viewBox="0 0 24 24"><path d="M12 3 5 6v5c0 4.5 3 8 7 10 4-2 7-5.5 7-10V6zM9 12l2 2 4-4" /></svg>
                                <div><strong>Compra segura.</strong> Pagamento processado em ambiente seguro. Vendido por {{ COMPANY.nomeFantasia }}.</div>
                            </div>
                        </div>
                    </aside>

                    <!-- CONTEÚDO -->
                    <div class="col-conteudo">
                        <section v-if="descriptionSections.length" class="card">
                            <h2>Descrição</h2>
                            <div v-for="(section, index) in descriptionSections" :key="index" class="bloco">
                                <h3 v-if="section.heading">{{ section.heading }}</h3>
                                <template v-for="(part, partIndex) in section.parts" :key="partIndex">
                                    <ul v-if="part.type === 'ul'" class="lista">
                                        <li v-for="(item, itemIndex) in part.items" :key="itemIndex">{{ item }}</li>
                                    </ul>
                                    <ul v-else-if="part.type === 'linhas'" class="linhas">
                                        <li v-for="(item, itemIndex) in part.items" :key="itemIndex">{{ item }}</li>
                                    </ul>
                                    <p v-else>{{ part.items[0] }}</p>
                                </template>
                            </div>
                        </section>

                        <section class="card">
                            <h2>Garantia de Satisfação</h2>
                            <div class="garantia">
                                <div class="selo-garantia"><span><b>30</b>DIAS DE GARANTIA</span></div>
                                <p>
                                    Se o produto chegar com defeito, você tem 30 dias após o recebimento para pedir a troca ou o reembolso.
                                    Mudou de ideia? São 7 dias para devolver.
                                    <Link href="/trocas-e-devolucoes">Ver política de trocas</Link>
                                </p>
                            </div>
                        </section>

                        <section class="card">
                            <h2>Pagamento Seguro</h2>
                            <p class="sem-margem">Aceitamos Pix, com aprovação imediata, e cartão de crédito em até {{ PARCELAS }}x sem juros. Seus dados trafegam criptografados e não ficam armazenados na loja.</p>
                        </section>

                        <section class="card">
                            <h2>Informação adicional</h2>
                            <div class="tabela-wrap">
                                <table class="ficha">
                                    <tr v-for="[label, value] in specs" :key="label"><th>{{ label }}</th><td>{{ value }}</td></tr>
                                </table>
                            </div>
                        </section>

                        <section v-if="showReviews" id="avaliacoes" class="card">
                            <div class="cabecalho">
                                <h2>Avaliações de Clientes</h2>
                                <button v-if="canReview && !hasReviewed" type="button" class="btn-sec pequeno" @click="showReviewModal = true">Avaliar produto</button>
                            </div>
                            <template v-if="reviews.length">
                                <div class="resumo-nota">
                                    <b>{{ ratingAvg.toFixed(1).replace('.', ',') }}</b>
                                    <div>
                                        <div class="estrelas">{{ '★'.repeat(stars(ratingAvg)) }}{{ '☆'.repeat(5 - stars(ratingAvg)) }}</div>
                                        {{ reviewsCount }} avaliaç{{ reviewsCount === 1 ? 'ão' : 'ões' }}
                                    </div>
                                </div>
                                <div class="avaliacoes">
                                    <div v-for="review in reviews" :key="review.id" class="avaliacao">
                                        <strong>{{ review.reviewer_display_name }}</strong>
                                        <div class="estrelas">{{ '★'.repeat(review.rating) }}{{ '☆'.repeat(5 - review.rating) }}</div>
                                        <p v-if="review.comment">{{ review.comment }}</p>
                                        <div v-if="review.images?.length" class="fotos-avaliacao">
                                            <a v-for="image in review.images" :key="image.id" :href="image.image_url" target="_blank" rel="noopener">
                                                <img :src="image.image_url" alt="">
                                            </a>
                                        </div>
                                        <small>{{ formatDate(review.created_at) }}</small>
                                    </div>
                                </div>
                            </template>
                            <p v-else class="sem-margem">Este produto ainda não recebeu avaliações. Seja o primeiro a avaliar.</p>
                        </section>

                        <section class="card">
                            <h2>Perguntas Frequentes</h2>
                            <details v-for="[pergunta, resposta] in FAQ" :key="pergunta">
                                <summary>{{ pergunta }}</summary>
                                <p>{{ resposta }}</p>
                            </details>
                        </section>
                    </div>
                </div>

                <div class="confianca">
                    <div><svg viewBox="0 0 24 24"><path d="M2 7h11v9H2zM13 10h4l3 3v3h-7zM6 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM17 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4z" /></svg><strong>Frete Grátis</strong>Para a sua residência</div>
                    <div><svg viewBox="0 0 24 24"><path d="M3 6h18v12H3zM3 10h18M7 15h4" /></svg><strong>Parcelamento</strong>Até {{ PARCELAS }}x sem juros</div>
                    <div><svg viewBox="0 0 24 24"><path d="M6 11V8a6 6 0 0 1 12 0v3M5 11h14v10H5z" /></svg><strong>Compra Segura</strong>Dados protegidos</div>
                    <div><svg viewBox="0 0 24 24"><path d="M12 3 5 6v5c0 4.5 3 8 7 10 4-2 7-5.5 7-10V6zM9 12l2 2 4-4" /></svg><strong>Troca Garantida</strong>7 dias para devolver</div>
                </div>

                <section v-if="relatedProducts.length" class="relacionados">
                    <h2>Você também pode gostar de</h2>
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5 lg:gap-6">
                        <ProductCard v-for="related in relatedProducts" :key="related.id" :product="related"
                            :is-favorite="relatedFavoriteIds.includes(related.id)" :is-authenticated="isAuthenticated"
                            :can-review="relatedReviewableIds.includes(related.id)" :has-reviewed="relatedReviewedIds.includes(related.id)" />
                    </div>
                </section>
            </div>

            <!-- Barra fixa no celular -->
            <div class="barra">
                <div>
                    <small v-if="product.has_discount">De {{ formatPrice(product.price) }} por</small>
                    <span class="por">{{ formatPrice(product.final_price) }}</span>
                </div>
                <button type="button" class="btn" :disabled="!inStock || buyingNow" @click="buyNow">{{ inStock ? 'COMPRAR' : 'ESGOTADO' }}</button>
            </div>
        </div>

        <Modal :open="showReviewModal" max-width="max-w-[480px]" @close="showReviewModal = false">
            <h3 class="font-display text-xl font-semibold">Avaliar {{ product.name }}</h3>
            <div class="mt-4 flex gap-1">
                <button v-for="star in 5" :key="star" type="button" @click="reviewForm.rating = star"><i class="text-2xl" :class="star <= reviewForm.rating ? 'fas fa-star text-amber-400' : 'far fa-star text-store-fg-faint'"></i></button>
            </div>
            <textarea v-model="reviewForm.comment" rows="3" placeholder="Conte como foi sua experiência (opcional)" class="mt-4 w-full rounded-lg border border-store-border-strong bg-store-bg px-3 py-2 text-sm"></textarea>
            <p v-if="reviewForm.errors.review" class="mt-2 text-sm text-red-600">{{ reviewForm.errors.review }}</p>
            <button type="button" :disabled="reviewForm.processing" class="mt-4 rounded-lg bg-store-accent px-5 py-2.5 text-sm font-semibold text-store-accent-contrast hover:opacity-90 disabled:opacity-50" @click="submitReview">Enviar avaliação</button>
        </Modal>

        <Teleport to="body">
            <div v-if="isLightboxOpen" class="fixed inset-0 z-[110] flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/82 backdrop-blur-sm" @click="isLightboxOpen = false"></div>
                <button type="button" class="absolute right-4 top-4 z-10 flex h-11 w-11 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20" aria-label="Fechar" @click="isLightboxOpen = false"><i class="fas fa-xmark text-lg"></i></button>
                <template v-if="mediaItems.length > 1">
                    <button type="button" class="absolute left-3 top-1/2 z-10 flex h-12 w-12 -translate-y-1/2 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20 md:left-8" aria-label="Mídia anterior" @click="goPrev"><i class="fas fa-chevron-left"></i></button>
                    <button type="button" class="absolute right-3 top-1/2 z-10 flex h-12 w-12 -translate-y-1/2 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20 md:right-8" aria-label="Próxima mídia" @click="goNext"><i class="fas fa-chevron-right"></i></button>
                </template>
                <div class="relative z-0 flex max-h-[90vh] max-w-[90vw] items-center justify-center" @touchstart="onTouchStart" @touchend="onTouchEnd">
                    <video v-if="activeMedia?.type === 'video'" :src="activeMedia.src" autoplay controls class="max-h-[90vh] max-w-[90vw] rounded-2xl"></video>
                    <img v-else-if="activeMedia" :src="activeMedia.src" :alt="product.name" class="max-h-[90vh] max-w-[90vw] rounded-2xl object-contain shadow-2xl">
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

<style>
/* Tudo preso em .pd2 pra não vazar pro resto da loja. Cores do modelo
   (fundo cinza claro, CTA amarelo, preço verde, azul de marca) com
   variante para o modo escuro da loja. */
.pd2 {
    --bg: #f7f7f7; --card: #fff; --ink: #111827; --text: #374151; --muted: #6b7280; --line: #e5e7eb; --soft: #f9fafb; --sunk: #f3f4f6;
    --cta: #fcbd10; --cta-ink: #111827; --cta-shadow: #d99e00; --price: #16a34a; --brand: #1e3a8a; --bad: #dc2626; --star: #f5b301;
    --r: 16px; --shadow: 0 1px 3px rgba(17, 24, 39, .06);
    background: var(--bg); color: var(--text); font: 400 14px/1.6 Poppins, system-ui, sans-serif; -webkit-font-smoothing: antialiased;
    padding-bottom: 32px;
}
.dark .pd2 {
    --bg: #0b0f17; --card: #141a24; --ink: #f3f4f6; --text: #d1d5db; --muted: #9ca3af; --line: #273042; --soft: #1a2130; --sunk: #1f2736;
    --price: #22c55e; --brand: #93b4ff; --shadow: none;
}
.pd2 img { max-width: 100%; display: block; }
.pd2 a { color: inherit; }
.pd2 h1, .pd2 h2, .pd2 h3 { color: var(--ink); line-height: 1.25; margin: 0; text-wrap: balance; font-family: inherit; }
.pd2 h2 { font-size: 22px; font-weight: 600; margin-bottom: 12px; }
.pd2 h3 { font-size: 17px; font-weight: 600; margin-bottom: 8px; }
.pd2 p { margin: 0 0 12px; }
.pd2 .sem-margem { margin: 0; }
.pd2 .wrap { max-width: 1200px; margin: 0 auto; padding: 0 16px; }
.pd2 .card { background: var(--card); border-radius: var(--r); box-shadow: var(--shadow); padding: 22px; }
.pd2 :focus-visible { outline: 3px solid var(--brand); outline-offset: 2px; }

.pd2 .migalhas { font-size: 12.5px; color: var(--muted); padding: 14px 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pd2 .migalhas a { text-decoration: none; }
.pd2 .migalhas a:hover { text-decoration: underline; }

/* grade principal */
.pd2 .grade { display: grid; grid-template-columns: minmax(0, 1fr) 420px; gap: 20px; align-items: start; }
.pd2 .col-galeria { grid-column: 1; }
.pd2 .col-compra { grid-column: 2; grid-row: 1 / span 2; position: sticky; top: 16px; }
.pd2 .col-conteudo { grid-column: 1; display: grid; gap: 20px; min-width: 0; }

/* galeria */
.pd2 .galeria { display: grid; grid-template-columns: 76px minmax(0, 1fr); gap: 12px; }
.pd2 .galeria.sem-miniaturas { grid-template-columns: minmax(0, 1fr); }
.pd2 .miniaturas { display: flex; flex-direction: column; gap: 8px; max-height: 620px; overflow-y: auto; }
.pd2 .miniaturas button { position: relative; flex: 0 0 auto; padding: 0; border: 2px solid transparent; border-radius: 10px; overflow: hidden; background: var(--sunk); cursor: pointer; aspect-ratio: 1; }
.pd2 .miniaturas button[aria-current=true] { border-color: var(--cta); }
.pd2 .miniaturas img, .pd2 .miniaturas video { width: 100%; height: 100%; object-fit: cover; }
.pd2 .play { position: absolute; inset: 0; display: grid; place-items: center; background: rgba(0, 0, 0, .35); color: #fff; font-size: 12px; pointer-events: none; }
.pd2 .play.grande { background: transparent; }
.pd2 .play.grande i { width: 64px; height: 64px; border-radius: 50%; background: rgba(0, 0, 0, .55); display: grid; place-items: center; font-size: 22px; }
.pd2 .foto { position: relative; border-radius: 12px; overflow: hidden; background: var(--sunk); aspect-ratio: 1; cursor: zoom-in; user-select: none; }
.pd2 .foto img, .pd2 .foto video { width: 100%; height: 100%; object-fit: contain; }
.pd2 .foto .vazio { height: 100%; display: grid; place-items: center; font-size: 56px; color: var(--muted); opacity: .4; }
.pd2 .seta { position: absolute; top: 50%; transform: translateY(-50%); width: 40px; height: 40px; border: 0; border-radius: 50%; background: rgba(255, 255, 255, .92); color: #111827; box-shadow: 0 1px 4px rgba(0, 0, 0, .15); cursor: pointer; opacity: 0; transition: opacity .15s; }
.pd2 .seta.esq { left: 12px; }
.pd2 .seta.dir { right: 12px; }
.pd2 .foto:hover .seta { opacity: 1; }
.pd2 .contador { position: absolute; bottom: 12px; left: 50%; transform: translateX(-50%); background: rgba(0, 0, 0, .6); color: #fff; font-size: 12px; border-radius: 999px; padding: 3px 10px; display: none; }

/* box de compra */
.pd2 .titulo { display: flex; gap: 12px; align-items: flex-start; justify-content: space-between; }
.pd2 .compra h1 { font-size: 19px; font-weight: 600; }
.pd2 .fav { flex: 0 0 auto; width: 38px; height: 38px; border-radius: 50%; border: 1px solid var(--line); background: var(--card); display: grid; place-items: center; color: var(--muted); cursor: pointer; text-decoration: none; }
.pd2 .fav .ativo { color: #e11d48; }
.pd2 .nota { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 8px 0; font-size: 12.5px; color: var(--muted); }
.pd2 .estrelas { color: var(--star); letter-spacing: 1px; font-size: 14px; }
.pd2 .checks { list-style: none; margin: 12px 0 0; padding: 0; font-size: 12.5px; }
.pd2 .checks li { padding-left: 22px; position: relative; }
.pd2 .checks li::before { content: "✔"; position: absolute; left: 0; color: var(--brand); }
.pd2 .preco { display: grid; grid-template-columns: auto 1fr; gap: 4px 16px; align-items: center; margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--line); }
.pd2 .preco .rot { color: var(--muted); grid-row: 1 / span 3; }
.pd2 .de { color: var(--muted); text-decoration: line-through; font-weight: 600; font-size: 15px; }
.pd2 .selo { display: inline-block; background: var(--brand); color: #fff; font-size: 11px; font-weight: 600; border-radius: 5px; padding: 2px 7px; margin-left: 6px; }
.dark .pd2 .selo { color: #0b0f17; }
.pd2 .por { color: var(--price); font-size: 30px; font-weight: 700; line-height: 1.1; font-variant-numeric: tabular-nums; }
.pd2 .parc { font-size: 13.5px; }
.pd2 .subtitulo { display: block; font-size: 13px; font-weight: 600; color: var(--ink); margin-bottom: 8px; }
.pd2 .variacoes, .pd2 .leve-mais { margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--line); }
.pd2 .opcoes { display: flex; flex-wrap: wrap; gap: 8px; }
.pd2 .opcao { display: inline-flex; align-items: center; gap: 8px; border: 1px solid var(--line); border-radius: 10px; padding: 6px 10px; font-size: 12.5px; font-weight: 500; text-decoration: none; }
.pd2 .opcao img { width: 32px; height: 32px; border-radius: 6px; object-fit: cover; }
.pd2 .opcao:hover { border-color: var(--muted); }
.pd2 .opcao.atual { border: 2px solid var(--cta); font-weight: 600; color: var(--ink); }
.pd2 .opcao.esgotada { opacity: .5; text-decoration: line-through; }
.pd2 .leve-mais ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; font-size: 12.5px; }
.pd2 .leve-mais li { display: grid; grid-template-columns: 1fr auto auto; gap: 10px; align-items: center; }
.pd2 .leve-mais strong { color: var(--ink); }
.pd2 .acao { display: grid; grid-template-columns: 120px 1fr; gap: 10px; margin: 18px 0 10px; }
.pd2 .qtd { display: grid; grid-template-columns: 36px 1fr 36px; border: 1px solid var(--line); border-radius: 10px; overflow: hidden; }
.pd2 .qtd button { border: 0; background: var(--sunk); color: var(--ink); font: 600 18px Poppins, sans-serif; cursor: pointer; }
.pd2 .qtd button:disabled { opacity: .4; cursor: not-allowed; }
.pd2 .qtd input { border: 0; text-align: center; font: inherit; width: 100%; min-width: 0; background: var(--card); color: var(--ink); -moz-appearance: textfield; }
.pd2 .qtd input::-webkit-inner-spin-button, .pd2 .qtd input::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
.pd2 .btn { display: flex; align-items: center; justify-content: center; background: var(--cta); color: var(--cta-ink); font: 700 18px Poppins, sans-serif; letter-spacing: .5px; border: 0; border-radius: 10px; min-height: 50px; cursor: pointer; text-decoration: none; box-shadow: 0 3px 0 var(--cta-shadow); transition: transform .1s; }
.pd2 .btn:hover { filter: brightness(1.04); }
.pd2 .btn:active { transform: translateY(2px); box-shadow: 0 1px 0 var(--cta-shadow); }
.pd2 .btn:disabled { opacity: .5; cursor: not-allowed; }
.pd2 .btn-sec { width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px; background: transparent; color: var(--ink); border: 1px solid var(--line); border-radius: 10px; min-height: 44px; font: 600 14px Poppins, sans-serif; cursor: pointer; }
.pd2 .btn-sec:hover { background: var(--soft); }
.pd2 .btn-sec:disabled { opacity: .5; cursor: not-allowed; }
.pd2 .btn-sec.pequeno { width: auto; min-height: 36px; padding: 0 14px; font-size: 13px; }
.pd2 .total { font-size: 13px; margin: 0 0 10px; }
.pd2 .total strong { color: var(--ink); }
.pd2 .esgotado { margin: 18px 0 12px; color: var(--bad); font-weight: 600; }
.pd2 .bandeiras { display: flex; flex-wrap: wrap; justify-content: center; gap: 6px; margin: 14px 0; }
.pd2 .bandeiras img { height: 22px; width: auto; }
.pd2 .infos { display: grid; gap: 8px; }
.pd2 .info { display: grid; grid-template-columns: 28px 1fr; gap: 10px; background: var(--soft); border: 1px solid var(--line); border-radius: 10px; padding: 10px 12px; font-size: 12.5px; line-height: 1.45; text-decoration: none; }
.pd2 a.info:hover { border-color: var(--muted); }
.pd2 .info strong { color: var(--brand); }
.pd2 .info svg, .pd2 .confianca svg { fill: none; stroke-linecap: round; stroke-linejoin: round; }
.pd2 .info svg { width: 24px; height: 24px; stroke: var(--ink); stroke-width: 1.6; margin-top: 2px; }

/* conteúdo */
.pd2 .bloco + .bloco { margin-top: 18px; }
.pd2 .lista { margin: 0; padding-left: 20px; list-style: disc; }
.pd2 .lista li { margin-bottom: 4px; }
.pd2 .lista, .pd2 .linhas { margin-bottom: 12px; }
.pd2 .linhas { list-style: none; padding: 0; }
.pd2 .bloco > :last-child { margin-bottom: 0; }
.pd2 .tabela-wrap { overflow-x: auto; }
.pd2 table { width: 100%; border-collapse: collapse; font-size: 13px; }
.pd2 th, .pd2 td { border: 1px solid var(--line); padding: 10px 12px; text-align: left; vertical-align: top; }
.pd2 .ficha th { font-weight: 600; color: var(--ink); background: var(--soft); width: 38%; }
.pd2 .garantia { display: grid; grid-template-columns: 84px 1fr; gap: 16px; align-items: center; }
.pd2 .garantia p { margin: 0; }
.pd2 .garantia a { color: var(--brand); font-weight: 500; }
.pd2 .selo-garantia { width: 84px; height: 84px; border-radius: 50%; background: radial-gradient(circle, #ffe27a, #e0a100); display: grid; place-items: center; text-align: center; font-weight: 700; color: #5b4300; font-size: 11px; line-height: 1.15; border: 3px dashed #fff; box-shadow: 0 0 0 3px #e0a100; }
.pd2 .selo-garantia b { font-size: 22px; display: block; }
.pd2 details { border-bottom: 1px solid var(--line); }
.pd2 summary { cursor: pointer; font-weight: 500; color: var(--ink); padding: 12px 0; font-size: 15px; }
.pd2 details p { padding: 0 0 12px 18px; margin: 0; }
.pd2 .cabecalho { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
.pd2 .cabecalho h2 { margin: 0; }
.pd2 .resumo-nota { display: flex; align-items: center; gap: 14px; margin-bottom: 16px; }
.pd2 .resumo-nota b { font-size: 36px; color: var(--ink); line-height: 1; }
.pd2 .avaliacoes { columns: 3 220px; column-gap: 14px; }
.pd2 .avaliacao { break-inside: avoid; background: var(--sunk); border-radius: 10px; padding: 12px; margin-bottom: 14px; font-size: 12.5px; box-shadow: 2px 2px 4px rgba(0, 0, 0, .08); }
.pd2 .avaliacao strong { color: var(--ink); display: block; }
.pd2 .avaliacao p { margin: 4px 0 6px; }
.pd2 .avaliacao small { color: var(--muted); }
.pd2 .fotos-avaliacao { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 6px; }
.pd2 .fotos-avaliacao img { width: 56px; height: 56px; border-radius: 8px; object-fit: cover; }

/* faixa de confiança e relacionados */
.pd2 .confianca { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin: 28px auto; }
.pd2 .confianca div { text-align: center; font-size: 12.5px; }
.pd2 .confianca svg { width: 34px; height: 34px; stroke: var(--brand); stroke-width: 1.5; margin: 0 auto 6px; }
.pd2 .confianca strong { display: block; color: var(--ink); font-size: 14px; }
.pd2 .relacionados { margin-top: 12px; }

/* barra fixa no celular */
.pd2 .barra { display: none; }

@media (max-width: 900px) {
    .pd2 .grade { grid-template-columns: minmax(0, 1fr); }
    .pd2 .col-compra { grid-column: 1; grid-row: auto; position: static; }
    .pd2 .galeria { grid-template-columns: minmax(0, 1fr); }
    .pd2 .miniaturas { flex-direction: row; order: 2; overflow-x: auto; max-height: none; }
    .pd2 .miniaturas button { flex: 0 0 62px; }
    .pd2 .seta { display: none; }
    .pd2 .contador { display: block; }
    .pd2 .card { padding: 16px; }
    .pd2 h2 { font-size: 19px; }
    .pd2 .confianca { grid-template-columns: repeat(2, 1fr); }
    .pd2 .barra { display: grid; grid-template-columns: 1fr 1.2fr; gap: 10px; align-items: center; position: fixed; inset: auto 0 0 0; background: var(--card); border-top: 1px solid var(--line); padding: 10px 16px; z-index: 40; box-shadow: 0 -4px 12px rgba(0, 0, 0, .08); }
    .pd2 .barra .por { font-size: 20px; }
    .pd2 .barra small { color: var(--muted); display: block; font-size: 11px; }
    .pd2 .barra .btn { min-height: 46px; font-size: 16px; }
    .pd2 { padding-bottom: 88px; }
}
@media (prefers-reduced-motion: reduce) {
    .pd2 * { transition: none !important; }
}
</style>
