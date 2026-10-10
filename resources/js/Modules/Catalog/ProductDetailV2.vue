<script setup>
// Página de produto v2 (pedido 2026-10-09): mesmo layout da página de
// produto da izeshop que o Lira mandou como referência — faixa amarela,
// galeria + box de compra fixo à direita (60/40), cards brancos arredondados,
// avaliações com barras por estrela e perguntas em sanfona. Só o miolo: topo
// e rodapé continuam os da loja (AppLayout). Ficou de fora o que a KazaKora
// não oferece (desconto no Pix, boleto, sorteio, brindes). A v1
// (ProductDetail.vue) segue no código; o CatalogController escolhe qual mostrar.
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import Modal from '@/Shared/Modal.vue';
import ProductCard from '@/Shared/Components/ProductCard.vue';
import { COMPANY } from '@/Shared/company';
import { formatPrice, toggleFavorite, vendidosTexto } from '@/Shared/productCard';
import { maskCep } from '@/Shared/useCep';
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
// Desconto no Pix (pedido 2026-10-09) — % compartilhado pelo backend.
const DESCONTO_PIX = Number(page.props.descontoPix ?? 0);
const PAYMENT_BRANDS = ['pix', 'visa', 'mastercard', 'elo', 'amex', 'diners'];

// Perguntas sobre a loja, com as regras reais (política de trocas e checkout).
const FAQ = [
    ['Como acompanho o meu pedido?', 'Assim que o pedido é postado, o código de rastreio aparece em "Meus pedidos", na sua conta.'],
    ['Qual é o prazo de entrega?', 'O prazo é calculado pelo seu CEP e aparece no checkout antes de você finalizar a compra.'],
    ['O frete é grátis?', 'Sim. O envio é grátis para a sua residência.'],
    ['Posso trocar ou devolver?', 'Sim. Você tem 7 dias após o recebimento para desistir da compra e 30 dias para trocar um produto com defeito.'],
    ['Quais são as formas de pagamento?', DESCONTO_PIX > 0
        ? `Pix, com aprovação imediata e ${DESCONTO_PIX}% de desconto, ou cartão de crédito em até ${PARCELAS}x sem juros.`
        : `Pix, com aprovação imediata, ou cartão de crédito em até ${PARCELAS}x sem juros.`],
];

const ratingAvg = computed(() => Number(props.product.reviews_avg_rating ?? 0));
const reviewsCount = computed(() => Number(props.product.reviews_count ?? props.reviews.length));
const vendidosLegenda = computed(() => vendidosTexto(props.salesCount));
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
const pixPrice = computed(() => Math.round(Number(props.product.final_price) * (1 - DESCONTO_PIX / 100) * 100) / 100);
// Referência do bloco de preço: "de" = preço cheio (ou o do cartão, quando
// não há promoção e existe desconto no Pix); "por" = valor no Pix.
const precoPor = computed(() => (DESCONTO_PIX > 0 ? pixPrice.value : Number(props.product.final_price)));
const precoDe = computed(() => {
    if (props.product.has_discount) return Number(props.product.price);
    return DESCONTO_PIX > 0 ? Number(props.product.final_price) : null;
});
const percentualTotal = computed(() => (precoDe.value ? Math.round((1 - precoPor.value / precoDe.value) * 100) : 0));
const descontoTotal = computed(() => (precoDe.value ? Math.round(precoDe.value - precoPor.value) : 0));
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
// Conteúdo do anúncio gerado (Gemini ou regra automática) — pedido
// 2026-10-09. Sem ele, a página usa a descrição como está (nunca fica vazia).
const anuncio = computed(() => props.product.ad_content ?? null);
const blocosAnuncio = computed(() => anuncio.value?.blocos ?? []);
const imagemDoBloco = (indice) => (indice === null || indice === undefined ? null : props.product.images?.[indice]?.url ?? null);

const highlights = computed(() => {
    if (anuncio.value?.destaques?.length) return anuncio.value.destaques.slice(0, 3);
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

// Prazo pelo CEP (pedido 2026-10-09): na área da entrega expressa da Grande
// SP mostra "Receba hoje até as 21h" (até 13h) ou "Receba amanhã"; fora
// dela, o prazo normal de envio.
const cep = ref('');
const prazo = ref(null); // null = não consultado; { expressa, dias, modalidade } depois da consulta
const consultandoPrazo = ref(false);
const erroPrazo = ref('');
// Fora do Full (pedido 2026-10-10): frase em verde; com "Flex" quando os
// Correios entregam em até 3 dias úteis nesse CEP (aí "geralmente chega em 3").
const prazoNormal = computed(() => {
    const ate = Math.max(7, Number(prazo.value?.dias) || 0);
    const base = `Entrega pelos Correios ou transportadora em até ${ate} dias úteis.`;
    return prazo.value?.modalidade === 'flex' ? `${base} (Mas geralmente chega em 3)` : base;
});

const onCepPrazo = (event) => {
    cep.value = maskCep(event.target.value);
    prazo.value = null;
    erroPrazo.value = '';
};

const consultarPrazo = async () => {
    if (cep.value.replace(/\D/g, '').length !== 8) {
        erroPrazo.value = 'Digite um CEP com 8 números.';
        return;
    }
    consultandoPrazo.value = true;
    erroPrazo.value = '';
    try {
        const response = await fetch(`/frete/prazo?cep=${encodeURIComponent(cep.value)}&produto=${props.product.id}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error();
        const data = await response.json();
        prazo.value = { expressa: data.entrega_expressa ?? null, dias: data.prazo_dias ?? null, modalidade: data.modalidade ?? null };
    } catch {
        erroPrazo.value = 'Não foi possível consultar o prazo agora.';
    } finally {
        consultandoPrazo.value = false;
    }
};

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

// Barras por estrela (5 → 1) a partir das avaliações reais.
const ratingRows = computed(() => [5, 4, 3, 2, 1].map((star) => {
    const count = props.reviews.filter((review) => Number(review.rating) === star).length;
    return { star, count, percent: props.reviews.length ? Math.round((count / props.reviews.length) * 100) : 0 };
}));

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
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    </Head>

    <AppLayout>
        <div class="pd2">
            <!-- Faixa amarela do topo -->
            <div class="faixa">
                <span><i class="fas fa-truck-fast"></i> FRETE GRÁTIS <span class="selo-full"><i class="fa-solid fa-bolt"></i>FULL</span></span>
                <span class="sep">|</span>
                <span><i class="fas fa-lock"></i> COMPRA 100% SEGURA</span>
            </div>

            <div class="wrap">
                <nav class="migalhas" aria-label="Você está em">
                    <Link href="/">Início</Link>
                    <template v-if="product.category">
                        &nbsp;/&nbsp;<Link :href="`/?search=${encodeURIComponent(product.category.name)}`">{{ product.category.name }}</Link>
                    </template>
                    &nbsp;/&nbsp;<span>{{ product.name }}</span>
                </nav>

                <div class="grade">
                    <!-- COLUNA PRINCIPAL (60%) -->
                    <main class="col-principal">
                        <section class="card galeria-card">
                            <div class="galeria" :class="{ 'sem-miniaturas': mediaItems.length < 2 }">
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
                                    </template>
                                </div>
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
                            </div>
                        </section>

                        <!-- BOX DE COMPRA (fica à direita no computador; no celular entra aqui, logo depois da galeria) -->
                        <aside id="comprar" class="card compra">
                            <div class="titulo">
                                <h1>{{ product.name }}</h1>
                                <button v-if="isAuthenticated" type="button" class="fav" :aria-pressed="isFavorite" aria-label="Favoritar" @click="toggleFavorite(product.id)">
                                    <i :class="isFavorite ? 'fas fa-heart ativo' : 'far fa-heart'"></i>
                                </button>
                                <Link v-else href="/entrar" class="fav" aria-label="Favoritar"><i class="far fa-heart"></i></Link>
                            </div>

                            <!-- Estrelas e vendidos em destaque (pedido 2026-10-10: saem do card e
                                 ficam aqui). Vendidos no formato do Mercado Livre: +5, +10, +50, +100... -->
                            <div v-if="reviewsCount > 0 || salesCount > 0" class="nota">
                                <span v-if="salesCount > 0" class="vendidos"><i class="fas fa-fire"></i> {{ vendidosLegenda }}</span>
                                <span v-if="salesCount > 0 && reviewsCount > 0" class="separador">|</span>
                                <template v-if="reviewsCount > 0">
                                    <strong class="media">{{ ratingAvg.toFixed(1).replace('.', ',') }}</strong>
                                    <span class="estrelas"><i v-for="n in 5" :key="n" :class="n <= stars(ratingAvg) ? 'fas fa-star' : 'far fa-star'"></i></span>
                                    <a href="#avaliacoes">({{ reviewsCount }})</a>
                                </template>
                            </div>

                            <ul v-if="highlights.length" class="checks">
                                <li v-for="(item, index) in highlights" :key="index">✔️ {{ item }}</li>
                            </ul>

                            <hr class="divisor">

                            <!-- Bloco de preço no formato da referência: "de" riscado + ⇣%, preço à
                                 vista (Pix) em destaque, 12x no cartão e "R$ X de desconto" — tudo
                                 com os valores reais (preço de cartão = final_price). -->
                            <div class="preco">
                                <span class="rot">Preço:</span>
                                <div class="valores">
                                    <div v-if="precoDe" class="linha-de">
                                        <span class="de">{{ formatPrice(precoDe) }}</span>
                                        <span v-if="percentualTotal > 0" class="selo">⇣ {{ percentualTotal }}%</span>
                                    </div>
                                    <div class="por">{{ formatPrice(precoPor) }}<small v-if="DESCONTO_PIX > 0" class="no-pix">no Pix</small></div>
                                    <div class="parc"><i class="fas fa-credit-card"></i> Em até {{ PARCELAS }}x de {{ formatPrice(installmentValue) }}<template v-if="DESCONTO_PIX > 0"> no cartão</template></div>
                                    <span v-if="descontoTotal > 0" class="selo">R$ {{ descontoTotal }} de desconto</span>
                                </div>
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
                                        <button type="button" aria-label="Diminuir quantidade" :disabled="quantity <= 1" @click="setQuantity(quantity - 1)">-</button>
                                        <input :value="quantity" type="number" min="1" :max="product.stock" aria-label="Quantidade" @change="setQuantity($event.target.value)">
                                        <button type="button" aria-label="Aumentar quantidade" :disabled="quantity >= product.stock" @click="setQuantity(quantity + 1)">+</button>
                                    </div>
                                    <button type="button" class="btn" :disabled="buyingNow" @click="buyNow">COMPRAR</button>
                                </div>
                                <p v-if="quantityDiscounts.length && quantity > 1" class="total">Total: <strong>{{ formatPrice(currentTotal) }}</strong></p>
                                <button type="button" class="btn-sec" :disabled="addingToCart" @click="addToCart">
                                    <i class="fas fa-cart-shopping"></i> Adicionar ao carrinho
                                </button>
                            </template>
                            <p v-else class="esgotado">Produto esgotado</p>

                            <div class="bandeiras">
                                <img v-for="brand in PAYMENT_BRANDS" :key="brand" :src="`/images/payments/cartao/${brand}.png`" :alt="brand" loading="lazy">
                            </div>

                            <form class="prazo-cep" @submit.prevent="consultarPrazo">
                                <label for="cep-prazo"><i class="fas fa-location-dot"></i> Consultar prazo de entrega</label>
                                <div class="linha">
                                    <input id="cep-prazo" :value="cep" type="text" inputmode="numeric" maxlength="9" placeholder="00000-000" autocomplete="postal-code" @input="onCepPrazo">
                                    <button type="submit" :disabled="consultandoPrazo">{{ consultandoPrazo ? '...' : 'OK' }}</button>
                                </div>
                                <p v-if="erroPrazo" class="erro">{{ erroPrazo }}</p>
                                <p v-else-if="prazo?.expressa" class="expressa"><span class="full"><i class="fas fa-bolt"></i> Full</span> {{ prazo.expressa.mensagem }}</p>
                                <p v-else-if="prazo" class="normal">
                                    <span v-if="prazo.modalidade === 'flex'" class="full"><i class="fas fa-bolt"></i> Flex</span>
                                    {{ prazoNormal }}
                                </p>
                            </form>

                            <!-- Mesmos selos com imagem da finalização (pedido 2026-10-10), mantendo
                                 as frases e o negrito azul daqui. -->
                            <ul class="fretefundo">
                                <li>
                                    <span class="selo-icone"><i class="fas fa-truck-fast"></i></span>
                                    <span v-if="isFreeShipping"><b>Entrega GRÁTIS</b> <span class="selo-full"><i class="fa-solid fa-bolt"></i>FULL</span> para a sua casa!<br>O prazo é calculado pelo seu CEP no checkout.</span>
                                    <span v-else><b>Entrega</b> para todo o Brasil.<br>O frete e o prazo são calculados pelo seu CEP no checkout.</span>
                                </li>
                                <li>
                                    <img class="selo-img" src="/images/checkout/satisfacao-garantida-checkout-v4.png" alt="" width="44" height="44" loading="lazy">
                                    <span><b>Devolução fácil.</b> Você tem 7 dias para desistir e 30 dias para trocar se vier com defeito.</span>
                                </li>
                                <li>
                                    <img class="selo-img" src="/images/checkout/pagamento-100-seguro-checkout-v4.png" alt="" width="44" height="44" loading="lazy">
                                    <span><b>Compra segura e garantida</b>, receba o produto que está esperando ou devolvemos o dinheiro.</span>
                                </li>
                                <li>
                                    <img class="selo-img" src="/images/checkout/avaliacoes-positivas-checkout-v4.png" alt="" width="44" height="44" loading="lazy">
                                    <span><b>Empresa 100% legal</b><br>CNPJ: {{ COMPANY.cnpj }}<br>Vendido e enviado por {{ COMPANY.nomeFantasia }}, de São Paulo/SP.</span>
                                </li>
                            </ul>
                        </aside>

                        <!-- Descrição: visual da v1 (cabeçalho centralizado; sem a faixa roxa, pedido 2026-10-10) com o
                             conteúdo no formato da referência: blocos de título + imagem + texto
                             persuasivo, comparativo, benefícios e dúvidas. -->
                        <section v-if="blocosAnuncio.length || descriptionSections.length" class="card descricao-card">
                            <header class="desc-cabecalho">
                                <span class="desc-icone bg-store-accent-soft text-store-accent-strong"><i class="fas fa-wand-magic-sparkles"></i></span>
                                <p class="desc-eyebrow">Por que esse produto merece atenção</p>
                                <h2>{{ anuncio?.chamada || 'Descrição' }}</h2>
                            </header>

                            <div v-if="blocosAnuncio.length" class="descricao">
                                <article v-for="(bloco, index) in blocosAnuncio" :key="index" class="bloco-anuncio">
                                    <h3>{{ bloco.titulo }}</h3>
                                    <img v-if="imagemDoBloco(bloco.imagem)" :src="imagemDoBloco(bloco.imagem)" :alt="bloco.titulo" loading="lazy">
                                    <p>{{ bloco.texto }}</p>
                                </article>

                                <div v-if="anuncio.comparativo?.linhas?.length" class="bloco-anuncio">
                                    <h3>{{ anuncio.comparativo.titulo }}</h3>
                                    <p v-if="anuncio.comparativo.intro">{{ anuncio.comparativo.intro }}</p>
                                    <div class="tabela-wrap">
                                        <table class="comparativo">
                                            <thead><tr><th>Critério</th><th>Solução comum</th><th>Com este produto</th></tr></thead>
                                            <tbody>
                                                <tr v-for="(linha, index) in anuncio.comparativo.linhas" :key="index">
                                                    <td>{{ linha[0] }}</td>
                                                    <td class="ruim">✕ {{ linha[1] }}</td>
                                                    <td class="bom">✓ {{ linha[2] }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div v-if="anuncio.beneficios?.length" class="bloco-anuncio">
                                    <h3>Benefícios que fazem diferença na rotina</h3>
                                    <ul class="beneficios">
                                        <li v-for="(item, index) in anuncio.beneficios" :key="index">
                                            <i class="fas fa-circle-check"></i>
                                            <span><strong>{{ item[0] }}</strong><template v-if="item[1]">: {{ item[1] }}</template></span>
                                        </li>
                                    </ul>
                                </div>

                                <div v-if="anuncio.duvidas?.length" class="bloco-anuncio">
                                    <h3>Dúvidas comuns antes de escolher o seu</h3>
                                    <div v-for="(duvida, index) in anuncio.duvidas" :key="index" class="duvida">
                                        <strong>{{ duvida[0] }}</strong>
                                        <p>{{ duvida[1] }}</p>
                                    </div>
                                </div>
                            </div>

                            <div v-else class="descricao">
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
                            </div>
                        </section>

                        <section class="card">
                            <h2>Garantia Total De Satisfação</h2>
                            <div class="garantia">
                                <img class="selo-garantia" src="/images/selo-satisfacao-garantida.png" alt="Satisfação 100% garantida" loading="lazy">
                                <p>
                                    Se o produto chegar com defeito, você tem 30 dias após o recebimento para pedir a troca ou o reembolso.
                                    Mudou de ideia? São 7 dias para devolver, sem nenhum risco para você.
                                </p>
                            </div>
                        </section>

                        <section class="card">
                            <h2>Pagamento Seguro</h2>
                            <div class="bandeiras esquerda">
                                <img v-for="brand in PAYMENT_BRANDS" :key="brand" :src="`/images/payments/cartao/${brand}.png`" :alt="brand" loading="lazy">
                            </div>
                            <p v-if="DESCONTO_PIX > 0">No Pix você tem {{ DESCONTO_PIX }}% de desconto, com aprovação imediata.</p>
                            <p class="sem-margem">Suas informações de pagamento são processadas com segurança. Nós não armazenamos dados do cartão de crédito nem temos acesso aos números do seu cartão.</p>
                        </section>

                        <section class="card">
                            <h2>Informação adicional</h2>
                            <table class="ficha">
                                <tr v-for="[label, value] in specs" :key="label"><th>{{ label }}</th><td>{{ value }}</td></tr>
                            </table>
                        </section>
                    </main>
                </div>

                <!-- AVALIAÇÕES (largura total) -->
                <section v-if="showReviews" id="avaliacoes" class="card largura-total">
                    <div class="aval-topo">
                        <div class="aval-resumo">
                            <h2>Avaliações de Clientes</h2>
                            <div v-if="reviews.length" class="aval-media">
                                <span class="caixa-nota">{{ ratingAvg.toFixed(2) }}</span>
                                <div>
                                    <span class="estrelas"><i v-for="n in 5" :key="n" :class="n <= stars(ratingAvg) ? 'fas fa-star' : 'far fa-star'"></i></span>
                                    <div class="base">Baseado em {{ reviewsCount }} avaliaç{{ reviewsCount === 1 ? 'ão' : 'ões' }}</div>
                                </div>
                            </div>
                            <button v-if="canReview && !hasReviewed" type="button" class="btn-sec pequeno" @click="showReviewModal = true">Avaliar produto</button>
                        </div>
                        <div v-if="reviews.length" class="aval-barras">
                            <div v-for="row in ratingRows" :key="row.star" class="barra-linha">
                                <span class="estrelas"><i v-for="n in 5" :key="n" :class="n <= row.star ? 'fas fa-star' : 'far fa-star'"></i></span>
                                <span class="trilho"><span :style="{ width: row.percent + '%' }"></span><em>{{ row.percent }}%</em></span>
                                <span class="qtd-aval">{{ row.count }}</span>
                            </div>
                        </div>
                    </div>

                    <div v-if="reviews.length" class="aval-grade">
                        <div v-for="review in reviews" :key="review.id" class="aval-item">
                            <div class="autor"><i class="fas fa-user"></i> {{ review.reviewer_display_name }}</div>
                            <span class="estrelas"><i v-for="n in 5" :key="n" :class="n <= review.rating ? 'fas fa-star' : 'far fa-star'"></i></span>
                            <p v-if="review.comment">{{ review.comment }}</p>
                            <div v-if="review.images?.length" class="fotos-avaliacao">
                                <a v-for="image in review.images" :key="image.id" :href="image.image_url" target="_blank" rel="noopener">
                                    <img :src="image.image_url" alt="">
                                </a>
                            </div>
                            <small>{{ formatDate(review.created_at) }}</small>
                        </div>
                    </div>
                    <p v-else class="sem-margem">Este produto ainda não recebeu avaliações. Seja o primeiro a avaliar.</p>
                </section>

                <!-- PERGUNTAS FREQUENTES (largura total) -->
                <section class="card largura-total">
                    <h2>Perguntas Frequentes</h2>
                    <details v-for="[pergunta, resposta] in FAQ" :key="pergunta">
                        <summary><i class="fas fa-caret-right fechado"></i><i class="fas fa-caret-up aberto"></i> {{ pergunta }}</summary>
                        <p>{{ resposta }}</p>
                    </details>
                </section>

                <!-- FAIXA DE CONFIANÇA -->
                <div class="confianca">
                    <div><i class="fas fa-truck-fast"></i><span><strong>Frete Grátis <span class="selo-full"><i class="fa-solid fa-bolt"></i>FULL</span></strong>Entrega em todo Brasil</span></div>
                    <div><i class="fas fa-credit-card"></i><span><strong>Parcelamento</strong>Em {{ PARCELAS }}x nos cartões</span></div>
                    <div><i class="fas fa-lock"></i><span><strong>Compra Segura</strong>Ambiente seguro para pagamentos online</span></div>
                    <div><i class="far fa-face-smile"></i><span><strong>Satisfação Garantida</strong>Troca ou reembolso garantido</span></div>
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

            <!-- Botão fixo no celular -->
            <div class="barra">
                <button type="button" class="btn" :disabled="!inStock || buyingNow" @click="buyNow">{{ inStock ? 'COMPRAR AGORA' : 'ESGOTADO' }}</button>
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
                    <video v-if="activeMedia?.type === 'video'" :src="activeMedia.src" autoplay controls class="max-h-[90vh] max-w-[90vw] rounded-[2px]"></video>
                    <img v-else-if="activeMedia" :src="activeMedia.src" :alt="product.name" class="max-h-[90vh] max-w-[90vw] rounded-[2px] object-contain shadow-2xl">
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

<style>
/* Tudo preso em .pd2 pra não vazar pro resto da loja. Medidas e cores
   tiradas da página de referência (izeshop): fundo #F7F7F7, cards brancos
   raio 20px, amarelo #FCBD10, verde #00AB21, azul #0044C3 / #002F88. */
.pd2 {
    --bg: #f7f7f7; --card: #fff; --ink: #18212e; --text: #575757; --muted: #767676; --line: #ebebeb; --soft: #f9f9f9; --sunk: #f3f3f3;
    --yellow: #fcbd10; --yellow-edge: #5a4200; --green: #00ab21; --green-edge: #006915; --blue: #0044c3; --navy: #002f88; --star: #fcbd10;
    --shadow: 0 0 10px 0 rgba(218, 218, 218, .24); --shadow-strong: 0 17px 10px 0 rgba(218, 218, 218, .24);
    background: var(--bg); color: var(--text); font: 400 16px/1.5 Poppins, system-ui, sans-serif; -webkit-font-smoothing: antialiased;
    padding-bottom: 40px;
}
.dark .pd2 {
    --bg: #0b0f17; --card: #141a24; --ink: #f3f4f6; --text: #c4c9d2; --muted: #9ca3af; --line: #273042; --soft: #1a2130; --sunk: #1f2736;
    --blue: #7aa2ff; --shadow: none; --shadow-strong: none;
}
.pd2 img { max-width: 100%; display: block; }
.pd2 a { color: inherit; }
.pd2 h1, .pd2 h2, .pd2 h3 { color: var(--ink); font-family: inherit; margin: 0; line-height: 1.2; }
.pd2 h2 { font-size: 28px; font-weight: 500; margin-bottom: 16px; }
.pd2 p { margin: 0 0 14px; }
.pd2 .sem-margem { margin: 0; }
.pd2 .wrap { max-width: 1400px; margin: 0 auto; padding: 0 20px; }
.pd2 .card { background: var(--card); border-radius: 20px; box-shadow: var(--shadow); padding: 20px; }
.pd2 :focus-visible { outline: 3px solid var(--blue); outline-offset: 2px; }

/* faixa amarela */
.pd2 .faixa { background: var(--yellow); color: #111; display: flex; justify-content: center; align-items: center; gap: 14px; flex-wrap: wrap; padding: 10px 16px; font-weight: 600; font-size: 14px; letter-spacing: .5px; }
.pd2 .faixa i { margin-right: 6px; }
.pd2 .selo-full i { margin: 0 1px 0 0; font-size: 0.9em; width: auto; color: inherit; }
.pd2 .faixa .sep { opacity: .4; }

.pd2 .migalhas { font-size: 14.5px; color: var(--muted); padding: 18px 0 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pd2 .migalhas a { color: var(--muted); text-decoration: none; }
.pd2 .migalhas a:hover { text-decoration: underline; }

/* grade 60/40: o box de compra ocupa a coluna da direita, fixo */
.pd2 .col-principal { display: grid; grid-template-columns: minmax(0, 60fr) minmax(0, 40fr); gap: 20px; align-items: start; }
.pd2 .col-principal > .card { grid-column: 1; }
.pd2 .col-principal > .compra { grid-column: 2; grid-row: 1 / span 5; position: sticky; top: 10px; box-shadow: var(--shadow-strong); }
.pd2 .galeria-card { box-shadow: var(--shadow-strong); padding: 10px; }

/* galeria: miniaturas à esquerda (20%), foto à direita (80%) */
.pd2 .galeria { display: flex; flex-direction: row-reverse; gap: 20px; }
.pd2 .foto { position: relative; flex: 0 0 calc(80% - 10px); aspect-ratio: 1; border-radius: 2px; overflow: hidden; background: var(--card); cursor: zoom-in; user-select: none; }
.pd2 .galeria.sem-miniaturas .foto { flex-basis: 100%; }
.pd2 .foto img, .pd2 .foto video { width: 100%; height: 100%; object-fit: contain; }
.pd2 .foto .vazio { height: 100%; display: grid; place-items: center; font-size: 56px; color: var(--muted); opacity: .4; }
.pd2 .miniaturas { flex: 1; display: flex; flex-direction: column; gap: 20px; max-height: 640px; overflow-y: auto; scrollbar-width: thin; }
.pd2 .miniaturas button { position: relative; display: block; width: 100%; flex: 0 0 auto; padding: 0; border: 2px solid transparent; border-radius: 2px; overflow: hidden; background: none; cursor: pointer; aspect-ratio: 1; transition: border-color .2s; }
.pd2 .miniaturas button:hover { border-color: var(--line); }
.pd2 .miniaturas button[aria-current=true] { border-color: var(--yellow); }
/* Foto e vídeo presos no quadrado da miniatura (o <video> tem altura própria
   e ficava maior que as fotos). */
.pd2 .miniaturas img, .pd2 .miniaturas video { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; border-radius: 0; background: #000; }
.pd2 .miniaturas img { background: none; }
.pd2 .play { position: absolute; inset: 0; display: grid; place-items: center; background: rgba(0, 0, 0, .35); color: #fff; font-size: 12px; pointer-events: none; }
.pd2 .play.grande { background: transparent; }
.pd2 .play.grande i { width: 64px; height: 64px; border-radius: 50%; background: rgba(0, 0, 0, .55); display: grid; place-items: center; font-size: 22px; }
.pd2 .seta { position: absolute; top: 50%; transform: translateY(-50%); width: 38px; height: 38px; border: 0; border-radius: 50%; background: rgba(0, 0, 0, .3); color: #fff; cursor: pointer; }
.pd2 .seta.esq { left: 10px; }
.pd2 .seta.dir { right: 10px; }

/* box de compra */
.pd2 .compra { padding: 20px 20px 24px; }
.pd2 .titulo { display: flex; gap: 12px; align-items: flex-start; justify-content: space-between; }
.pd2 .compra h1 { font-size: 26px; font-weight: 500; text-transform: capitalize; }
.pd2 .fav { flex: 0 0 auto; width: 40px; height: 40px; border-radius: 50%; border: 1px solid var(--line); background: var(--card); display: grid; place-items: center; color: var(--muted); cursor: pointer; text-decoration: none; }
.pd2 .fav .ativo { color: #e11d48; }
.pd2 .nota { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 12px 0 4px; font-size: 16px; font-weight: 500; color: var(--ink); line-height: 1.7; }
.pd2 .nota a { text-decoration: none; }
.pd2 .nota .vendidos { font-weight: 600; color: var(--ink); font-size: 14px; }
.pd2 .nota .vendidos i { color: #f97316; }
.pd2 .nota .separador { color: var(--line); }
.pd2 .nota .media { font-size: 15px; }
.pd2 .estrelas { color: var(--star); font-size: 15px; letter-spacing: 1px; white-space: nowrap; }
.pd2 .checks { list-style: none; margin: 14px 0 0; padding: 0; font-size: 16px; color: var(--text); }
.pd2 .divisor { border: 0; border-top: 1px solid #777; margin: 16px 0; opacity: .6; }

.pd2 .preco { display: grid; grid-template-columns: 25% 75%; align-items: center; margin-bottom: 15px; }
.pd2 .preco .rot { font-size: 18px; color: var(--ink); }
.pd2 .valores { display: flex; flex-direction: column; align-items: flex-start; gap: 8px; }
.pd2 .linha-de { display: flex; align-items: center; gap: 15px; }
.pd2 .de { color: var(--text); font-size: 22px; font-weight: 600; text-decoration: line-through; }
.pd2 .selo { display: inline-block; background: var(--navy); color: #fff; border-radius: 5px; padding: 4px 8px; font-size: 12px; line-height: 1.3; }
.pd2 .por { color: var(--green); font-size: 36px; font-weight: 700; line-height: 1.1; font-variant-numeric: tabular-nums; }
.pd2 .parc { color: var(--ink); font-size: 18px; }
.pd2 .por .no-pix { font-size: 15px; font-weight: 500; color: var(--text); margin-left: 6px; }
.pd2 .parc i { color: var(--navy); margin-right: 4px; }
.dark .pd2 .parc i { color: var(--blue); }

.pd2 .subtitulo { display: block; font-size: 16px; font-weight: 600; color: var(--ink); margin-bottom: 10px; }
.pd2 .variacoes, .pd2 .leve-mais { margin: 6px 0 16px; }
.pd2 .opcoes { display: flex; flex-wrap: wrap; gap: 8px; }
.pd2 .opcao { display: inline-flex; align-items: center; gap: 8px; border: 1px solid #b9b9b9; border-radius: 10px; padding: 6px 12px; font-size: 14px; text-decoration: none; color: var(--ink); }
.pd2 .opcao img { width: 34px; height: 34px; border-radius: 2px; object-fit: cover; }
.pd2 .opcao:hover { border-color: var(--ink); }
.pd2 .opcao.atual { border: 2px solid var(--yellow); font-weight: 600; }
.pd2 .opcao.esgotada { opacity: .5; text-decoration: line-through; }
.pd2 .leve-mais ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; font-size: 14px; }
.pd2 .leve-mais li { display: grid; grid-template-columns: 1fr auto auto; gap: 10px; align-items: center; }
.pd2 .leve-mais strong { color: var(--ink); }

.pd2 .acao { display: flex; gap: 10px; margin: 6px 0 10px; }
.pd2 .qtd { display: inline-flex; align-items: center; gap: 4px; border-radius: 10px; background: var(--soft); border: 1px solid var(--line); padding: 5px; flex: 0 0 auto; }
.pd2 .qtd button { width: 34px; height: 100%; min-height: 40px; border: 0; border-radius: 10px; background: #e8e9ef; color: #000; font-weight: 700; font-size: 16px; cursor: pointer; }
.dark .pd2 .qtd button { background: var(--sunk); color: var(--ink); }
.pd2 .qtd button:hover { background: var(--soft); }
.pd2 .qtd button:disabled { opacity: .4; cursor: not-allowed; }
.pd2 .qtd input { width: 44px; border: 0; background: transparent; text-align: center; font: inherit; color: var(--ink); -moz-appearance: textfield; }
.pd2 .qtd input::-webkit-inner-spin-button, .pd2 .qtd input::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
.pd2 .btn { flex: 1; display: flex; align-items: center; justify-content: center; background: var(--yellow); color: #000; font: 600 24px Poppins, sans-serif; text-transform: uppercase; border: 0; border-bottom: 2px solid var(--yellow-edge); border-radius: 10px; min-height: 54px; padding: 0 20px; cursor: pointer; transition: all .2s; }
.pd2 .btn:hover { background: var(--green); border-color: var(--green-edge); color: #fff; }
.pd2 .btn:disabled { opacity: .5; cursor: not-allowed; }
.pd2 .btn-sec { width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px; background: transparent; color: var(--ink); border: 1px solid #b9b9b9; border-radius: 10px; min-height: 44px; font: 500 15px Poppins, sans-serif; cursor: pointer; }
.pd2 .btn-sec:hover { background: var(--soft); }
.pd2 .btn-sec:disabled { opacity: .5; cursor: not-allowed; }
.pd2 .btn-sec.pequeno { width: auto; min-height: 38px; padding: 0 16px; font-size: 14px; margin-top: 12px; }
.pd2 .total { font-size: 14px; margin: 0 0 10px; }
.pd2 .total strong { color: var(--ink); }
.pd2 .esgotado { margin: 10px 0; color: #dc2626; font-weight: 600; }
.pd2 .bandeiras { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 8px; margin: 18px 0; }
.pd2 .bandeiras.esquerda { justify-content: flex-start; margin-top: 0; }
.pd2 .bandeiras img { height: 45px; width: auto; }

.pd2 .fretefundo { list-style: none; margin: 0; padding: 5px 15px; border-radius: 7px; background: var(--soft); border: 1px solid var(--line); }
.pd2 .fretefundo li { display: grid; grid-template-columns: 44px 1fr; gap: 12px; align-items: center; padding: 10px 0; font-size: 15px; line-height: 22px; color: #545454; }
.dark .pd2 .fretefundo li { color: var(--text); }
.pd2 .fretefundo li + li { border-top: 1px solid var(--line); }
.pd2 .fretefundo i { font-size: 22px; color: #000; text-align: center; margin-top: 2px; }
.dark .pd2 .fretefundo i { color: var(--ink); }
.pd2 .fretefundo b { color: var(--blue); }
.pd2 .fretefundo .selo-img { width: 44px; height: 44px; object-fit: contain; }
.pd2 .fretefundo .selo-icone { display: flex; width: 44px; height: 44px; align-items: center; justify-content: center; border-radius: 9999px; background: #e7f8ec; }
.pd2 .fretefundo .selo-icone i { margin: 0; color: #00a650; }
.pd2 .fretefundo .selo-full i { font-size: 0.9em; margin: 0 1px 0 0; color: inherit; text-align: left; }
.pd2 .fretefundo a { color: var(--blue); text-decoration: underline; }

.pd2 .prazo-cep { margin: 0 0 14px; }
.pd2 .prazo-cep label { display: block; font-size: 15px; font-weight: 500; color: var(--ink); margin-bottom: 6px; }
.pd2 .prazo-cep label i { color: var(--blue); margin-right: 4px; }
.pd2 .prazo-cep .linha { display: flex; gap: 8px; }
.pd2 .prazo-cep input { flex: 1; min-width: 0; height: 44px; border: 1px solid #b9b9b9; border-radius: 10px; padding: 0 12px; font: inherit; background: var(--card); color: var(--ink); }
.pd2 .prazo-cep button { height: 44px; padding: 0 18px; border: 0; border-radius: 10px; background: var(--navy); color: #fff; font: 600 15px Poppins, sans-serif; cursor: pointer; }
.pd2 .prazo-cep button:disabled { opacity: .6; }
.pd2 .prazo-cep p { margin: 8px 0 0; font-size: 14px; }
.pd2 .prazo-cep .expressa { display: inline-flex; align-items: center; gap: 6px; background: #e7f8ec; color: #00801a; font-weight: 700; border-radius: 8px; padding: 6px 10px; font-size: 15px; }
.dark .pd2 .prazo-cep .expressa { background: rgba(0, 171, 33, .15); color: #4ade80; }
/* "⚡ Full" na mesma cor da mensagem, só mais forte (pedido 2026-10-10). */
.pd2 .prazo-cep .expressa .full { font-weight: 800; }
.pd2 .prazo-cep .normal { color: #00801a; font-weight: 600; }
.dark .pd2 .prazo-cep .normal { color: #4ade80; }
.pd2 .prazo-cep .normal .full { color: #00530f; font-weight: 800; font-style: italic; margin-right: 4px; }
.pd2 .prazo-cep .erro { color: #dc2626; }

/* descrição no visual da v1 */
.pd2 .descricao-card { position: relative; overflow: hidden; padding-top: 34px; }
.pd2 .desc-cabecalho { text-align: center; max-width: 640px; margin: 0 auto 26px; }
.pd2 .desc-icone { display: inline-flex; width: 48px; height: 48px; border-radius: 50%; align-items: center; justify-content: center; font-size: 18px; }
.pd2 .desc-eyebrow { margin: 12px 0 6px; font-size: 12px; letter-spacing: .22em; text-transform: uppercase; color: var(--muted); }
.pd2 .desc-cabecalho h2 { margin: 0; font-size: 28px; }
.pd2 .bloco-anuncio + .bloco-anuncio { margin-top: 30px; padding-top: 26px; border-top: 1px solid var(--line); }
.pd2 .bloco-anuncio h3 { font-size: 22px; font-weight: 600; margin: 0 0 14px; }
.pd2 .bloco-anuncio img { width: 100%; max-width: 600px; height: auto; margin: 0 auto 16px; border-radius: 2px; }
.pd2 .bloco-anuncio p { font-size: 16px; line-height: 1.7; }
.pd2 .tabela-wrap { overflow-x: auto; }
.pd2 .comparativo { width: 100%; border-collapse: collapse; font-size: 14px; margin: 6px 0; }
.pd2 .comparativo th, .pd2 .comparativo td { border: 1px solid #ddd; padding: 10px; text-align: left; vertical-align: top; }
.dark .pd2 .comparativo th, .dark .pd2 .comparativo td { border-color: var(--line); }
.pd2 .comparativo th { background: var(--soft); color: var(--ink); text-align: center; }
.pd2 .comparativo .ruim { color: #b42318; font-weight: 700; }
.pd2 .comparativo .bom { color: #16803c; font-weight: 700; }
.dark .pd2 .comparativo .ruim { color: #f87171; }
.dark .pd2 .comparativo .bom { color: #4ade80; }
.pd2 .beneficios { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.pd2 .beneficios li { display: flex; gap: 10px; align-items: flex-start; }
.pd2 .beneficios i { color: #10b981; margin-top: 4px; }
.pd2 .beneficios strong { color: var(--ink); }
.pd2 .duvida { margin-top: 14px; }
.pd2 .duvida strong { display: block; color: var(--ink); }
.pd2 .duvida p { margin: 2px 0 0; }

/* conteúdo */
.pd2 .descricao { color: #000; }
.dark .pd2 .descricao { color: var(--text); }
.pd2 .descricao h3 { font-size: 20px; font-weight: 600; margin: 0 0 10px; }
.pd2 .bloco + .bloco { margin-top: 22px; }
.pd2 .lista { margin: 0 0 14px; padding-left: 22px; list-style: disc; }
.pd2 .lista li { margin-bottom: 4px; }
.pd2 .linhas { list-style: none; margin: 0 0 14px; padding: 0; }
.pd2 .bloco > :last-child { margin-bottom: 0; }
.pd2 .garantia { display: grid; grid-template-columns: 20% 80%; gap: 10px; align-items: center; }
.pd2 .garantia p { margin: 0 0 0 10px; }
.pd2 .selo-garantia { width: 100%; max-width: 160px; height: auto; }
.pd2 .ficha { width: 100%; border-collapse: collapse; font-size: 15px; }
.pd2 .ficha th, .pd2 .ficha td { padding: 8px; border-bottom: 1px dotted rgba(0, 0, 0, .15); text-align: left; vertical-align: top; }
.dark .pd2 .ficha th, .dark .pd2 .ficha td { border-color: var(--line); }
.pd2 .ficha th { width: 150px; font-weight: 700; color: var(--ink); }
.pd2 .ficha td { font-style: italic; }
.pd2 .ficha tr:nth-child(even) th, .pd2 .ficha tr:nth-child(even) td { background: rgba(0, 0, 0, .025); }

/* avaliações e FAQ em largura total */
.pd2 .largura-total { margin-top: 20px; }
.pd2 .aval-topo { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 30px; align-items: center; padding-bottom: 30px; }
.pd2 .aval-media { display: flex; align-items: center; gap: 10px; }
.pd2 .caixa-nota { background: var(--green); color: #fff; font-weight: 700; border-radius: 3px; padding: 15px 10px; font-size: 16px; }
.pd2 .base { font-size: 14px; color: var(--text); white-space: nowrap; }
.pd2 .barra-linha { display: grid; grid-template-columns: 7.5em 1fr 40px; gap: 10px; align-items: center; height: 22px; font-size: 14px; }
.pd2 .barra-linha .estrelas { font-size: 13px; }
.pd2 .trilho { position: relative; height: 6px; background: rgba(0, 0, 0, .1); border-radius: 3px; overflow: hidden; }
.dark .pd2 .trilho { background: var(--line); }
.pd2 .trilho span { position: absolute; inset: 0 auto 0 0; background: var(--blue); border-radius: 3px; }
.pd2 .trilho em { position: absolute; left: -9999px; }
.pd2 .qtd-aval { text-align: right; color: var(--ink); }
.pd2 .aval-grade { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 20px; align-items: start; }
.pd2 .aval-item { background: var(--sunk); border-radius: 15px; padding: 14px 15px 10px; box-shadow: 3px 4px 8px rgba(0, 0, 0, .2); color: #000; font-size: 14px; transition: box-shadow .2s; }
.pd2 .aval-item:hover { box-shadow: 3px 4px 15px rgba(0, 0, 0, .4); }
.dark .pd2 .aval-item { color: var(--text); box-shadow: none; }
.pd2 .aval-item .autor { font-weight: 700; color: var(--ink); }
.pd2 .aval-item .autor i { margin-right: 5px; font-size: 12px; }
.pd2 .aval-item p { margin: 6px 0; font-weight: 300; line-height: 1.5; }
.pd2 .aval-item small { color: var(--muted); font-size: 12px; }
.pd2 .fotos-avaliacao { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 6px; }
.pd2 .fotos-avaliacao img { width: 60px; height: 60px; border-radius: 2px; object-fit: cover; }

.pd2 details { border-bottom: 1px solid #d5d8dc; }
.dark .pd2 details { border-color: var(--line); }
.pd2 summary { list-style: none; cursor: pointer; padding: 15px; font-size: 20px; font-weight: 500; color: var(--ink); line-height: 1.3; }
.pd2 summary::-webkit-details-marker { display: none; }
.pd2 summary i { width: 1em; }
.pd2 summary .aberto, .pd2 details[open] summary .fechado { display: none; }
.pd2 details[open] summary .aberto { display: inline-block; }
.pd2 details[open] summary { color: var(--blue); }
.pd2 details p { padding: 0 15px 15px; margin: 0; }

.pd2 .confianca { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; align-items: stretch; margin: 20px 0; }
.pd2 .confianca div { display: flex; align-items: center; gap: 19px; color: var(--text); background: var(--card); border-radius: 20px; box-shadow: var(--shadow); padding: 24px 20px; }
.pd2 .confianca i { font-size: 48px; color: #000; width: 56px; text-align: center; flex: 0 0 auto; }
.dark .pd2 .confianca i { color: var(--ink); }
.pd2 .confianca strong { display: block; color: var(--ink); font-size: 20px; font-weight: 500; }
.pd2 .relacionados h2 { margin-top: 10px; }

/* botão fixo no celular */
.pd2 .barra { display: none; }

@media (max-width: 1024px) {
    .pd2 .card { padding: 30px; }
    .pd2 .galeria-card { padding: 10px; }
    .pd2 .confianca { grid-template-columns: repeat(2, 1fr); }
    .pd2 .aval-grade { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 900px) {
    .pd2 .col-principal { grid-template-columns: minmax(0, 1fr); }
    .pd2 .col-principal > .compra { grid-column: 1; grid-row: auto; position: static; }
}
@media (max-width: 767px) {
    /* Bandeiras menores no celular: as 6 cabem numa linha (pedido 2026-10-10). */
    .pd2 .bandeiras { flex-wrap: nowrap; gap: 5px; }
    .pd2 .bandeiras img { height: 30px; }
    .pd2 .wrap { padding: 0 10px; }
    .pd2 .migalhas { display: none; }
    .pd2 .col-principal { padding-top: 10px; }
    .pd2 .card { padding: 20px; }
    .pd2 h2 { font-size: 24px; }
    .pd2 .desc-cabecalho h2 { font-size: 22px; }
    .pd2 .bloco-anuncio h3 { font-size: 19px; }
    .pd2 .compra h1 { font-size: 20px; }
    .pd2 .nota { font-size: 14px; }
    .pd2 .galeria { flex-direction: column; gap: 10px; }
    .pd2 .foto { flex-basis: auto; width: 100%; }
    .pd2 .miniaturas { flex-direction: row; gap: 8px; max-height: none; overflow-x: auto; }
    .pd2 .miniaturas button { flex: 0 0 calc(25% - 6px); }
    .pd2 .preco { grid-template-columns: 20% 80%; }
    .pd2 .btn { font-size: 20px; }
    .pd2 .fretefundo li { font-size: 14px; }
    .pd2 .fretefundo i { color: #7b7b7b; }
    .pd2 .garantia { grid-template-columns: 30% 70%; }
    .pd2 .aval-topo { grid-template-columns: 1fr; gap: 16px; }
    .pd2 .aval-grade { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .pd2 summary { font-size: 17px; padding: 12px; }
    .pd2 .confianca { grid-template-columns: 1fr 1fr; gap: 16px; }
    .pd2 .confianca div { flex-direction: column; align-items: center; text-align: center; gap: 6px; font-size: 14px; padding: 18px 12px; }
    .pd2 .confianca i { font-size: 40px; }
    .pd2 .confianca strong { font-size: 16px; }
    .pd2 .barra { display: block; position: fixed; inset: auto 0 0 0; z-index: 40; background: var(--card); padding: 10px 16px; box-shadow: 0 0 10px 1px rgba(0, 0, 0, .35); }
    .pd2 .barra .btn { width: 100%; font-size: 18px; min-height: 46px; }
    .pd2 { padding-bottom: 80px; }
}
@media (prefers-reduced-motion: reduce) {
    .pd2 * { transition: none !important; }
}
</style>
