<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import Modal from '@/Shared/Modal.vue';
import { addToCart, cardImageUrl, formatPrice, primaryImage, specLine, toggleFavorite, vendidosTexto } from '@/Shared/productCard';

const props = defineProps({
    product: {
        type: Object,
        required: true,
    },
    isFavorite: {
        type: Boolean,
        default: false,
    },
    isAuthenticated: {
        type: Boolean,
        default: false,
    },
    canReview: {
        type: Boolean,
        default: false,
    },
    hasReviewed: {
        type: Boolean,
        default: false,
    },
});


const secondImage = computed(() => {
    const images = props.product.images ?? [];
    if (images.length < 2) return null;
    const primary = images.find((img) => img.is_primary) ?? images[0];
    return cardImageUrl(images.find((img) => img.url !== primary.url));
});

/**
 * Performance 2026-09-03: a segunda imagem (a que troca no hover) tinha
 * `src` real desde a primeira renderização. Mesmo com opacity 0 o browser
 * baixa a imagem, então a home baixava o dobro de imagens do que mostrava
 * — numa vitrine de 17 cards isso era metade do peso da página, gasto em
 * imagem que a maioria das visitas nunca vê.
 *
 * Agora o `src` só existe depois do primeiro hover de verdade, e em
 * dispositivo sem hover (celular/tablet) nunca — lá a troca no hover não
 * acontece na prática, então baixar a segunda imagem era 100% desperdício.
 */
const supportsHover = typeof window !== 'undefined' && window.matchMedia
    ? window.matchMedia('(hover: hover)').matches
    : false;

const isHovering = ref(false);
const hasHovered = ref(false);
const secondImageLoaded = ref(false);

const showSecondImage = computed(() => supportsHover && hasHovered.value && !!secondImage.value);

// Só esconde a imagem principal quando a segunda já carregou — senão o
// primeiro hover piscaria o fundo vazio enquanto a segunda baixa.
const secondImageVisible = computed(() => isHovering.value && secondImageLoaded.value);

// Navegação rápida (pedido 2026-10-10): ao passar o mouse ou encostar o dedo
// no card, a página do produto já é buscada; o clique abre na hora.
const prefetchProduct = () => router.prefetch(`/produtos/${props.product.slug}`, { method: 'get' }, { cacheFor: '1m' });

const onPointerEnter = () => {
    isHovering.value = true;
    prefetchProduct();

    if (supportsHover) {
        hasHovered.value = true;
    }
};

const goToProduct = () => router.visit(`/produtos/${props.product.slug}`);

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

// Selo do mês na faixa do celular: "Ofertas 10.10" em outubro, "11.11" em novembro...
const mes = String(new Date().getMonth() + 1).padStart(2, '0');
const SELO_DO_MES = `Oferta ${mes}.${mes}`;

// Nome do produto (pedido 2026-10-10): 2 linhas no celular e 3 no computador.
// Se não couber, mede de verdade e corta na última palavra que deixa espaço
// para "… Ver mais" terminar exatamente no fim da última linha.
const tituloEl = ref(null);
const medidorEl = ref(null);
const titulo = ref({ texto: String(props.product.name ?? '').trim(), cortado: false });
const SELINHO = '<span class="titulo-ver-mais">Ver mais</span>';
const escapar = (texto) => texto.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

const ajustarTitulo = () => {
    const alvo = tituloEl.value;
    const medidor = medidorEl.value;
    if (!alvo || !medidor) return;
    const nome = String(props.product.name ?? '').trim();
    medidor.style.width = `${alvo.clientWidth}px`;
    const limite = alvo.clientHeight + 1;
    const cabe = (html) => { medidor.innerHTML = html; return medidor.scrollHeight <= limite; };

    if (cabe(escapar(nome))) {
        titulo.value = { texto: nome, cortado: false };
        return;
    }
    const palavras = nome.split(/\s+/);
    let baixo = 1;
    let alto = palavras.length - 1;
    let melhor = 1;
    while (baixo <= alto) {
        const meio = Math.floor((baixo + alto) / 2);
        const texto = palavras.slice(0, meio).join(' ').replace(/[\s,.;:–|-]+$/, '');
        if (cabe(`${escapar(texto)}… ${SELINHO}`)) { melhor = meio; baixo = meio + 1; } else { alto = meio - 1; }
    }
    // Completa letra a letra com a próxima palavra, pra linha acabar no "Ver mais".
    let texto = palavras.slice(0, melhor).join(' ');
    const resto = nome.slice(texto.length);
    let b = 0;
    let c = resto.length;
    while (b < c) {
        const m = Math.ceil((b + c) / 2);
        const tentativa = (texto + resto.slice(0, m)).replace(/[\s,.;:–|-]+$/, '');
        if (cabe(`${escapar(tentativa)}… ${SELINHO}`)) b = m; else c = m - 1;
    }
    texto = (texto + resto.slice(0, b)).replace(/[\s,.;:–|-]+$/, '');
    titulo.value = { texto, cortado: true };
};

let observador = null;
onMounted(() => {
    nextTick(ajustarTitulo);
    if (window.ResizeObserver && tituloEl.value) {
        let largura = 0;
        observador = new ResizeObserver(([entrada]) => {
            if (Math.abs(entrada.contentRect.width - largura) > 1) { largura = entrada.contentRect.width; ajustarTitulo(); }
        });
        observador.observe(tituloEl.value);
    }
    document.fonts?.ready?.then(ajustarTitulo);
});
onBeforeUnmount(() => observador?.disconnect());

// Preço do card: o "antes" (riscado) e o % de desconto em cima dele.
const precoAntes = computed(() => {
    if (props.product.oferta_do_dia && props.product.preco_sem_oferta) return Number(props.product.preco_sem_oferta);
    return props.product.has_discount ? Number(props.product.price) : null;
});
const descontoPct = computed(() => (precoAntes.value
    ? Math.max(1, Math.round((1 - Number(props.product.final_price) / precoAntes.value) * 100))
    : 0));
const precoPartes = computed(() => {
    const [inteiro, centavos] = Number(props.product.final_price).toFixed(2).split('.');
    return { inteiro: Number(inteiro).toLocaleString('pt-BR'), centavos };
});
</script>

<template>
    <article class="group flex h-full flex-col overflow-hidden rounded-[1.15rem] border border-store-border bg-store-bg-raised shadow-[0_10px_28px_rgba(43,22,65,0.06)] transition hover:-translate-y-0.5 hover:border-store-border-strong hover:shadow-[0_18px_44px_rgba(43,22,65,0.12)]">
        <div class="relative aspect-square cursor-pointer bg-white"
            @mouseenter="onPointerEnter" @mouseleave="isHovering = false" @touchstart.passive="prefetchProduct" @click="goToProduct">
            <template v-if="primaryImage(product)">
                <img :src="primaryImage(product)" :alt="product.name" loading="lazy" decoding="async"
                    class="absolute inset-0 h-full w-full object-cover transition-opacity duration-500"
                    :class="secondImageVisible ? 'opacity-0' : 'opacity-100'">
                <img v-if="showSecondImage" :src="secondImage" :alt="product.name" decoding="async"
                    class="absolute inset-0 h-full w-full object-cover transition-opacity duration-500"
                    :class="secondImageVisible ? 'opacity-100' : 'opacity-0'"
                    @load="secondImageLoaded = true">
            </template>
            <div v-else class="flex h-full w-full items-center justify-center">
                <i class="fas fa-box-open text-4xl text-store-accent-strong opacity-40"></i>
            </div>
            <button v-if="isAuthenticated" type="button"
                class="absolute right-2.5 top-2.5 flex h-8 w-8 items-center justify-center rounded-full bg-store-bg-raised shadow"
                :aria-pressed="isFavorite" @click.stop="toggleFavorite(product.id)">
                <i class="text-sm" :class="isFavorite ? 'fas fa-heart text-store-accent' : 'far fa-heart text-store-fg-muted'"></i>
            </button>
            <Link v-else href="/entrar" @click.stop
                class="absolute right-2.5 top-2.5 flex h-8 w-8 items-center justify-center rounded-full bg-store-bg-raised shadow">
                <i class="far fa-heart text-sm text-store-fg-muted"></i>
            </Link>


            <!-- Selo promocional sobre a foto (canto inferior esquerdo): "Oferta do dia"
                 nos produtos em oferta, "Oferta MM.MM" do mês nos demais. -->
            <div class="promo-badge">
                <span class="promo-badge-icon">%</span>
                <span class="promo-badge-text">{{ product.oferta_do_dia ? 'Oferta do dia' : SELO_DO_MES }}</span>
            </div>

            <!-- Adicionar ao carrinho: encostado na lateral direita, sobre a linha imagem/descrição -->
            <button type="button" :disabled="product.stock < 1"
                class="absolute right-[5px] z-10 flex h-10 w-10 items-center justify-center rounded-full border border-store-border-strong bg-store-bg-raised shadow-md transition-colors hover:bg-store-accent hover:text-store-accent-contrast disabled:cursor-not-allowed disabled:opacity-40"
                :class="'bottom-0 translate-y-1/2'"
                aria-label="Adicionar ao carrinho" @click.stop="addToCart(product.id)">
                <i class="fas fa-cart-shopping text-sm"></i>
            </button>
        </div>

        <div class="flex flex-1 flex-col gap-2 px-4 pb-4 pt-6">
            <!-- Nome: 2 linhas no celular, 3 no computador; se não couber, "… Ver mais"
                 fecha a última linha (ajustarTitulo). Altura fixa = cards alinhados. -->
            <div class="relative">
                <h4 ref="tituloEl" class="titulo-card cursor-pointer overflow-hidden font-semibold hover:text-store-accent" :title="product.name" @click="goToProduct">{{ titulo.texto }}<template v-if="titulo.cortado">… <span class="titulo-ver-mais">Ver mais</span></template></h4>
                <div ref="medidorEl" class="titulo-card pointer-events-none invisible absolute left-0 top-0 font-semibold" aria-hidden="true" style="height: auto"></div>
            </div>
            <!-- Envio Express (pedido 2026-10-10): ocupa a linha inteira do card, laterais
                 inclinadas "/ texto /", parte cinza do caminhão arredondada à direita. -->
            <div class="envio-express flex h-7 w-full text-white">
                <span class="relative z-[1] flex w-9 shrink-0 items-center justify-center rounded-r-[40px] bg-slate-500 pl-1.5 pr-0.5">
                    <i class="fa-solid fa-truck-fast -skew-x-12 text-xs md:text-sm"></i>
                </span>
                <span class="-ml-3 flex flex-1 items-center justify-center whitespace-nowrap bg-[#7B1E3A] pl-3 pr-3 text-[11px] font-extrabold uppercase italic leading-none tracking-tight md:text-xs">Envio Express</span>
            </div>
            <div class="mt-auto pt-1">
                <!-- Preço no estilo do Mercado Livre (pedido 2026-10-10): pílula verde
                     "X% OFF" + preço antigo riscado; embaixo o preço grande com os
                     centavos pequenos no alto. Estrelas ficam só na página do produto. -->
                <div v-if="precoAntes" class="flex flex-wrap items-center gap-1.5">
                    <span class="rounded bg-[#00A650] px-1 py-px text-[10px] font-bold leading-tight text-white">{{ descontoPct }}% OFF</span>
                    <s class="text-xs text-store-fg-faint">{{ formatPrice(precoAntes) }}</s>
                </div>
                <div class="flex flex-wrap items-end gap-x-1.5 gap-y-0.5">
                    <div class="flex items-start leading-none text-store-fg">
                        <span class="mr-0.5 mt-[3px] text-sm font-medium md:text-base">R$</span>
                        <span class="text-[22px] font-semibold tracking-tight md:text-2xl">{{ precoPartes.inteiro }}</span>
                        <span class="ml-px mt-[3px] text-[11px] font-semibold md:text-xs">{{ precoPartes.centavos }}</span>
                    </div>
                    <!-- Vendidos como no Mercado Livre: só aparece se já vendeu. -->
                    <span v-if="vendidosTexto(product.vendidos)" class="pb-0.5 text-[11px] text-store-fg-muted md:text-xs">{{ vendidosTexto(product.vendidos) }}</span>
                </div>
                <!-- Mesmo tamanho/peso do "Frete grátis", em vermelho (pedido 2026-10-10). -->
                <p v-if="product.stock <= 0" class="mt-1.5 text-xs font-semibold text-red-600 md:text-sm">Esgotado</p>
                <!-- Frete como no Mercado Livre: "Frete grátis ⚡FULL" em verde, mesma fonte. -->
                <p v-else class="mt-1.5 flex items-center gap-1 whitespace-nowrap text-xs font-semibold text-[#00A650] md:text-sm">
                    Frete grátis
                    <span class="font-extrabold italic"><i class="fa-solid fa-bolt"></i>FULL</span>
                </p>
            </div>
            <button v-if="canReview && !hasReviewed" type="button"
                class="mt-1 self-start text-[11px] font-medium text-store-accent hover:underline"
                @click="showReviewModal = true">
                Avaliar produto
            </button>
        </div>
    </article>

    <Modal :open="showReviewModal" max-width="max-w-[480px]" @close="showReviewModal = false">
        <h3 class="font-display text-xl font-semibold">Avaliar {{ product.name }}</h3>
        <div class="mt-4 flex gap-1">
            <button v-for="star in 5" :key="star" type="button" @click="reviewForm.rating = star">
                <i class="text-2xl" :class="star <= reviewForm.rating ? 'fas fa-star text-amber-400' : 'far fa-star text-store-fg-faint'"></i>
            </button>
        </div>
        <textarea v-model="reviewForm.comment" rows="3" placeholder="Conte como foi sua experiência (opcional)"
            class="mt-4 w-full rounded-lg border border-store-border-strong bg-store-bg px-3 py-2 text-sm"></textarea>
        <p v-if="reviewForm.errors.review" class="mt-2 text-sm text-red-600">{{ reviewForm.errors.review }}</p>
        <button type="button" :disabled="reviewForm.processing"
            class="mt-4 rounded-lg bg-store-accent px-5 py-2.5 text-sm font-semibold text-store-accent-contrast hover:opacity-90 disabled:opacity-50"
            @click="submitReview">
            Enviar avaliação
        </button>
    </Modal>
</template>
