<script setup>
import { novoEventId, rastrearAddToCart } from '@/Shared/pixelMeta';
// Página de produto v2 (pedido 2026-10-09): mesmo layout da página de
// produto da izeshop que o Lira mandou como referência — faixa amarela,
// galeria + box de compra fixo à direita (60/40), cards brancos arredondados,
// avaliações com barras por estrela e perguntas em sanfona. Só o miolo: topo
// e rodapé continuam os da loja (AppLayout). Ficou de fora o que a KazaKora
// não oferece (desconto no Pix, boleto, sorteio, brindes). A v1
// (ProductDetail.vue) segue no código; o CatalogController escolhe qual mostrar.
import '../../../css/fontes-poppins.css';
import '../../../css/fontes-manrope.css';
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import Modal from '@/Shared/Modal.vue';
import CarrosselProdutos from '@/Shared/Components/CarrosselProdutos.vue';
import { COMPANY } from '@/Shared/company';
import { fotoAvaliacaoAmpliada, miniaturaAvaliacao } from '@/Shared/fotoAvaliacao';
import { formatPrice, toggleFavorite } from '@/Shared/productCard';
import { ehFavorito } from '@/Shared/favoritos';
import { maskCep } from '@/Shared/useCep';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    product: { type: Object, required: true },
    variations: { type: Array, default: () => [] },
    galeria: { type: Array, default: null },
    // Modelos ligados (2026-10-21): anúncios separados do mesmo grupo.
    modelos: { type: Array, default: () => [] },
    reviews: { type: Array, default: () => [] },
    shippingMethods: { type: Array, default: () => [] },
    isFavorite: { type: Boolean, default: false },
    canReview: { type: Boolean, default: false },
    hasReviewed: { type: Boolean, default: false },
    relatedProducts: { type: Array, default: () => [] },
    relatedFavoriteIds: { type: Array, default: () => [] },
    relatedReviewableIds: { type: Array, default: () => [] },
    relatedReviewedIds: { type: Array, default: () => [] },
    // Entregáveis do admin (pedido 2026-10-20).
    sorteios: { type: Array, default: () => [] },
    livrosDigitais: { type: Array, default: () => [] },
    // Imagem dos tablets do grupo de livros (admin); null = padrão (dor nas costas).
    livrosQuadro: { type: String, default: null },
    // Condições e botão de cada quadro, definidos em Promoções (2026-10-21): { airfryer: {condicoes, botao}, pdfs: {...} }.
    quadrosPromocao: { type: Object, default: () => ({}) },
    // Unidades vendidas de verdade, todos os canais (pedido 2026-10-23).
    salesCount: { type: Number, default: 0 },
});

const page = usePage();
const isAuthenticated = computed(() => !!page.props.auth?.user);

const PARCELAS = 12;
// Desconto no Pix: "Desconto Checkout" do produto, em % ou R$ (2026-10-18).
const DESCONTO_PIX = Number(props.product.checkout_discount_percentage ?? 0) > 0
    ? `${Number(props.product.checkout_discount_percentage)}%`
    : (Number(props.product.checkout_discount_amount ?? 0) > 0
        ? Number(props.product.checkout_discount_amount).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
        : '');
// Mesmas bandeiras e imagens do rodapé, com o boleto (pedido 2026-10-14).
// Fileira do "PAGAMENTO SEGURO E RÁPIDO" na ordem da Mavo (tarefa 2656); Diners
// entra porque o checkout aceita (CheckoutV2, detecção da bandeira).
const PAYMENT_BRANDS = ['visa', 'mastercard', 'elo', 'amex', 'diners', 'hipercard', 'pix', 'boleto'];
// Bandeiras do bloco de formas de pagamento (mesmas imagens do rodapé, pedido 2026-10-14).
const BANDEIRAS_FORMAS = ['mastercard', 'visa', 'elo', 'amex', 'hiper', 'boleto', 'pix'];
const NOME_BANDEIRA = { visa: 'Visa', mastercard: 'Mastercard', elo: 'Elo', amex: 'American Express', diners: 'Diners Club', hipercard: 'Hipercard', pix: 'Pix', boleto: 'Boleto' };

// Perguntas sobre a loja, com as regras reais (política de trocas e checkout).
const FAQ = [
    ['Como acompanho o meu pedido?', 'Assim que o pedido é postado, o código de rastreio aparece em "Meus Pedidos", no menu da sua conta.'],
    ['Qual é o prazo de entrega?', 'O prazo é calculado pelo seu CEP e aparece no checkout antes de você finalizar a compra.'],
    ['O frete é grátis?', 'Sim. O envio é grátis para a sua residência.'],
    ['Posso trocar ou devolver?', 'Sim. Você tem 7 dias após o recebimento para desistir da compra e 30 dias para trocar um produto com defeito.'],
    ['Quais são as formas de pagamento?', DESCONTO_PIX
        ? `Pix, com aprovação imediata e ${DESCONTO_PIX} de desconto, cartão de crédito em até ${PARCELAS}x sem juros ou boleto bancário.`
        : `Pix, com aprovação imediata, cartão de crédito em até ${PARCELAS}x sem juros ou boleto bancário.`],
];

const ratingAvg = computed(() => Number(props.product.reviews_avg_rating ?? 0));
const reviewsCount = computed(() => Number(props.product.reviews_count ?? props.reviews.length));
const stars = (value) => Math.round(value);

// Foto grande no celular baixa a de 512px quando ela basta (PageSpeed
// 2026-10-21); `sizes` é a largura da área da foto. O mesmo par vai no
// <link rel="preload"> do app.blade.php: mudar aqui, mudar lá.
const FOTO_SIZES = '(max-width: 767px) calc(100vw - 40px), (max-width: 900px) 75vw, (max-width: 1320px) 48vw, 600px';
const srcsetFoto = (image) => (image?.thumb_url && image.media_url && image.thumb_url !== image.media_url
    ? `${image.thumb_url} 512w, ${image.media_url} 1200w`
    : null);

// Galeria: vídeo primeiro, depois as imagens em ordem. `src` é a foto média
// (1200px; pedido 2026-10-15, página lenta) e `full` a original, só no zoom.
const mediaItems = computed(() => {
    const items = [];
    if (props.product.video_url) items.push({ type: 'video', src: props.product.video_url });
    // Variações: galeria única do grupo (mesmas fotos e ordem em toda variação);
    // a foto da variação escolhida só ganha borda, não muda de lugar.
    if (props.galeria?.length) {
        for (const image of props.galeria) items.push({ type: 'image', src: image.media_url ?? image.url, srcset: srcsetFoto(image), full: image.url, thumb: image.thumb_url, mini: image.mini_url, variantId: image.variant_id });
    } else {
        for (const image of props.product.images ?? []) items.push({ type: 'image', src: image.media_url ?? image.url, srcset: srcsetFoto(image), full: image.url, thumb: image.thumb_url, mini: image.mini_url });
    }
    return items;
});

// Variação escolhida (pedido 2026-10-15): trocar de modelo é na própria
// página, sem recarregar e sem mudar a URL. Preço, estoque, SKU e a compra
// seguem a escolhida; cada uma mantém o próprio SKU.
// Com variações, a página abre sem nenhuma escolhida (pedido 2026-10-15): o
// cliente precisa escolher antes de mudar a quantidade ou comprar. Até lá,
// preço e ficha mostram o produto da página.
const escolhida = ref(props.variations.length ? null : props.product);
const selecionada = computed(() => escolhida.value ?? props.product);
const faltaVariacao = computed(() => props.variations.length > 0 && !escolhida.value);
const alertaVariacao = ref(0); // conta os avisos: cada clique sem variação treme o alerta de novo
const variacoesEl = ref(null);
// Quantidade ou COMPRAR sem variação: alerta vermelho ao lado de "Escolha a variação".
const exigirVariacao = () => {
    if (!faltaVariacao.value) return false;
    alertaVariacao.value += 1;
    variacoesEl.value?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return true;
};
// "Cor: Verde" quando cada opção é só a cor (variação igual à cor cadastrada); senão "Variação: ...".
const rotuloVariacao = computed(() => (opcoesVariacao.value.length && opcoesVariacao.value.every((variant) => variant.color
    && String(variant.variation || variant.color).trim().toLowerCase() === String(variant.color).trim().toLowerCase()) ? 'Cor' : 'Variação'));
const opcoesVariacao = computed(() => (props.variations.length
    ? [props.product, ...props.variations].sort((a, b) => String(a.variation || a.name).localeCompare(String(b.variation || b.name), 'pt-BR', { numeric: true }))
    : []));
// Antes de escolher, o preço é o da variação mais barata (pedido 2026-10-16,
// ex.: Árvore de Natal), contando só as com estoque quando houver alguma.
// Ficha, estoque e SKU continuam do produto da página.
const maisBarata = computed(() => {
    if (!opcoesVariacao.value.length) return props.product;
    const comEstoque = opcoesVariacao.value.filter((variant) => variant.stock > 0);
    return (comEstoque.length ? comEstoque : opcoesVariacao.value)
        .reduce((menor, variant) => (Number(variant.final_price) < Number(menor.final_price) ? variant : menor));
});
const precoBase = computed(() => escolhida.value ?? maisBarata.value);
const aPartirDe = computed(() => !escolhida.value && opcoesVariacao.value.length > 0
    && new Set(opcoesVariacao.value.map((variant) => Number(variant.final_price))).size > 1);
const variantIndex = (id) => {
    const index = mediaItems.value.findIndex((item) => item.variantId === id);
    return index >= 0 ? index : 0;
};
// A página abre sempre na primeira foto do carrossel (pedido 2026-10-18,
// Árvore de Natal; antes abria na foto do modelo da página, o 303). Escolher
// a variação troca a foto principal pela dela, sem recarregar.
const activeIndex = ref(0);
const escolherVariacao = (variant) => {
    escolhida.value = variant;
    alertaVariacao.value = 0;
    activeIndex.value = variantIndex(variant.id);
    quantity.value = 1;
    prazo.value = null;
};
const activeMedia = computed(() => mediaItems.value[activeIndex.value] ?? null);

// Modelos ligados (pedido 2026-10-21, Caixa Sanfonada 85/168 peças): cada
// modelo é outro anúncio. O clique troca na hora a foto grande pela foto
// principal do outro (nunca o vídeo) e o <Link> leva para a página dele.
// Clique com Ctrl/Shift/botão do meio abre outra aba: aí a foto não troca.
const fotoModelo = ref(null);
const escolherModelo = (modelo, event) => {
    if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    if (modelo.foto_url) fotoModelo.value = modelo.foto_url;
};
const isLightboxOpen = ref(false);

// Vídeo sem autoplay (pedido 2026-10-15): mostra a primeira foto como capa com
// um botão de play; o vídeo só começa quando o cliente clica.
const videoPoster = computed(() => props.product.images?.[0]?.media_url ?? props.product.images?.[0]?.url ?? null);
const videoPosterSrcset = computed(() => srcsetFoto(props.product.images?.[0]));
const videoEl = ref(null);
const isVideoStarted = ref(false);
const startVideo = async () => {
    isVideoStarted.value = true;
    await nextTick();
    videoEl.value?.play().catch(() => {});
};
watch(activeIndex, () => { isVideoStarted.value = false; });

// Vídeo que não é o principal (avaliações, capa sem foto) não baixa nada ao
// abrir a página (pedido 2026-10-21, celular): nasce com preload="none" e só
// pede o começo do arquivo (1º quadro) quando chega perto da tela.
const vVideoPreguicoso = {
    mounted(el) {
        if (!('IntersectionObserver' in window)) { el.preload = 'metadata'; return; }
        el._observador = new IntersectionObserver(([entrada]) => {
            if (!entrada.isIntersecting) return;
            el._observador.disconnect();
            el.preload = 'metadata';
            el.load();
        }, { rootMargin: '200px' });
        el._observador.observe(el);
    },
    unmounted(el) { el._observador?.disconnect(); },
};

// A miniatura da foto ativa fica à vista na faixa (as fotos das variações
// estão no fim). Rola só a faixa, nunca a página.
const miniaturasEl = ref(null);
watch(activeIndex, async (index) => {
    await nextTick();
    const faixa = miniaturasEl.value;
    const botao = faixa?.children[index];
    if (!botao) return;
    const f = faixa.getBoundingClientRect();
    const b = botao.getBoundingClientRect();
    if (b.top < f.top || b.bottom > f.bottom) faixa.scrollBy({ top: b.top - f.top - (f.height - b.height) / 2, behavior: 'smooth' });
    if (b.left < f.left || b.right > f.right) faixa.scrollBy({ left: b.left - f.left - (f.width - b.width) / 2, behavior: 'smooth' });
});

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
// Recarga da mesma página (preço pelo CEP) mantém a variação escolhida com
// os valores novos; outro produto volta para a foto do modelo dele.
watch(() => [props.product, props.variations], ([novo], [antigo]) => {
    if (novo.id === antigo?.id) {
        if (escolhida.value) escolhida.value = [novo, ...props.variations].find((item) => item.id === escolhida.value.id) ?? novo;
        return;
    }
    escolhida.value = props.variations.length ? null : props.product;
    alertaVariacao.value = 0;
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
    // Miniatura leve (a foto cheia de 2048 px pesa até 700 KB); a de 320 px quando existe.
    const image = images.find((i) => i.is_variant) ?? images.find((i) => i.is_primary) ?? images[0];
    return image?.mini_url ?? image?.thumb_url ?? image?.url ?? null;
};


// Desconto por quantidade: mesma conta do backend (CartManager/CheckoutController).
const quantityDiscounts = computed(() =>
    [...(precoBase.value.quantity_discounts ?? [])].sort((a, b) => a.min_quantity - b.min_quantity)
);
// Oferta do Dia e Relâmpago não acumulam com o desconto por quantidade
// (Product::unitPriceForQuantity): aí as faixas não aparecem.
const emOferta = computed(() => !!(precoBase.value.oferta_do_dia || precoBase.value.oferta_relampago));
const faixasVisiveis = computed(() => (emOferta.value ? [] : quantityDiscounts.value));
const unitPriceForQuantity = (qty) => {
    const tier = emOferta.value ? null : [...quantityDiscounts.value].reverse().find((row) => row.min_quantity <= qty);
    if (!tier) return Number(precoBase.value.final_price);
    return Math.max(0, Math.round(Number(precoBase.value.final_price) * (1 - Number(tier.discount_percentage) / 100) * 100) / 100);
};

const quantity = ref(1);
const currentTotal = computed(() => unitPriceForQuantity(quantity.value) * quantity.value);
// Bloco de preço com a mesma conta do card (ProductCard): "por" = preço de
// venda, "de" = preço sem a promoção. O desconto do Pix só entra no checkout,
// quando o cliente escolhe Pix (pedido 2026-10-18: card 4% e página 9%).
const precoPor = computed(() => Number(precoBase.value.final_price));
const precoDe = computed(() => {
    if ((precoBase.value.oferta_do_dia || precoBase.value.oferta_relampago) && precoBase.value.preco_sem_oferta) return Number(precoBase.value.preco_sem_oferta);
    return precoBase.value.has_discount ? Number(precoBase.value.preco_de ?? precoBase.value.price) : null;
});
const percentualTotal = computed(() => (precoDe.value ? Math.max(1, Math.round((1 - precoPor.value / precoDe.value) * 100)) : 0));
const installmentValue = computed(() => Number(precoBase.value.final_price) / PARCELAS);

// Preço no Pix (pedido 2026-10-23): pagando o pedido todo no Pix. O de 1
// unidade vem pronto do backend (preco_pix, DescontoPix::precoPix); kits e
// total usam a mesma conta: % com 4 casas (R$ fixo vira % sobre a unidade)
// e desconto arredondado no centavo, sobre o subtotal.
const percentualPix = (item, unitario) => {
    const pct = Number(item?.checkout_discount_percentage ?? 0);
    if (pct > 0) return Math.min(100, pct);
    const valor = Number(item?.checkout_discount_amount ?? 0);
    if (valor > 0 && unitario > 0) return Math.round(Math.min(100, (valor * 100) / unitario) * 10000) / 10000;
    return 0;
};
const precoPixPara = (item, unitario, qtd = 1) => {
    const pct = percentualPix(item, unitario);
    if (pct <= 0 || unitario <= 0) return null;
    const subtotal = Math.round(unitario * qtd * 100) / 100;
    return Math.round((subtotal - Math.round(subtotal * pct) / 100) * 100) / 100;
};
const precoPix = computed(() => {
    const doBackend = precoBase.value.preco_pix;
    if (doBackend !== undefined) return doBackend === null ? null : Number(doBackend);
    return precoPixPara(precoBase.value, precoPor.value);
});
// Selo ao lado do valor no Pix: o % real do produto ou "R$ N OFF" quando é valor fixo.
const seloPix = computed(() => {
    const pct = Number(precoBase.value.checkout_discount_percentage ?? 0);
    if (pct > 0) return `${Number(pct.toFixed(2)).toLocaleString('pt-BR')}% OFF`;
    const valor = Number(precoBase.value.checkout_discount_amount ?? 0);
    return valor > 0 ? `${formatPrice(valor).replace(/,00$/, '')} OFF` : '';
});
// Total com a quantidade escolhida no Pix (kits).
const totalPix = computed(() => precoPixPara(precoBase.value, unitPriceForQuantity(quantity.value), quantity.value));
// Vendidos: texto fixo "+1.538 vendidos" em todo produto (tarefa 2704 do
// Lira em 2026-10-23, corrigindo o "mais de 1.538" da 2697; ele foi avisado
// do risco de publicidade enganosa e manteve). props.salesCount, o total
// real, segue vindo do servidor. "EXCLUSIVO" e "POR TEMPO LIMITADO!" em todo produto, como na Mavo (decisão
// do Lira em 2026-10-23 22h; antes só com Oferta do Dia ou Relâmpago no ar).
const ESTOQUE_BAIXO = 50;
const vendidosLegenda = computed(() => '+1.538 vendidos');
// "Só N unidades!" no fluxo que o Lira corrigiu (2026-10-23, decisão dele,
// no lugar do estoque real do #1020):
//   0 s    barra 100% cheia e um número sorteado entre 19 e 37, em preto, que
//          fica FIXO enquanto a barra desce (não conta para baixo);
//   0,06 s a barra recolhe até o tamanho proporcional ao número (de 50);
//   +3 s   parada; o número cai uma unidade e a barra perde o pedaço dessa
//          unidade. No momento da queda o fundo do número fica vermelho com a
//          fonte branca e some em 1 s (fade out); a fonte fica vermelha e
//          continua vermelha (correção do Lira em 2026-10-23).
// O sorteio é novo a cada carregamento da página e NUNCA passa de 37 (regra
// do Lira em 2026-10-23, exemplos 19, 26, 35), nem do estoque real da
// variação; sem estoque, não aparece.
const ESTOQUE_SORTEIO_MIN = 19;
const ESTOQUE_SORTEIO_MAX = 37;
const estoqueReal = computed(() => (faltaVariacao.value ? 0 : Number(selecionada.value.stock) || 0));
const estoqueBaixo = ref(null);
const estoqueBarra = ref(ESTOQUE_BAIXO);
const estoqueFundo = ref(false);
const estoqueVermelho = ref(false);
let estoqueTimers = [];
const pararEstoque = () => {
    estoqueTimers.forEach((id) => clearTimeout(id));
    estoqueTimers = [];
};
const depois = (ms, fn) => estoqueTimers.push(setTimeout(fn, ms));
const ESTOQUE_DESCIDA_MS = 1200;
// No celular a barra fica abaixo da dobra e a animação acabava antes de a
// pessoa chegar nela (Lira, 2026-10-23: "não aparece"). Lá ela espera cheia,
// com o número sorteado, e só roda quando a barra chega na faixa do meio da
// tela (30% centrais). Uma vez por página; trocar a variação depois roda na hora.
const estoqueEl = ref(null);
const ESTOQUE_ESPERA_MEIO = '(max-width: 900px)';
let estoqueVisto = false;
let observadorEstoque = null;
const esperaMeio = () => !estoqueVisto && 'IntersectionObserver' in window && window.matchMedia?.(ESTOQUE_ESPERA_MEIO).matches;
const rodarEstoque = (numero) => {
    pararEstoque();
    depois(60, () => { estoqueBarra.value = numero; });
    if (numero < 2) return;
    const parada = 60 + ESTOQUE_DESCIDA_MS + 3000;
    depois(parada, () => {
        estoqueFundo.value = true;
        estoqueVermelho.value = true;
        estoqueBaixo.value = numero - 1;
        estoqueBarra.value = numero - 1;
    });
};
const pararObservadorEstoque = () => {
    observadorEstoque?.disconnect();
    observadorEstoque = null;
};
watch(estoqueEl, (el) => {
    pararObservadorEstoque();
    if (!el || !esperaMeio()) return;
    observadorEstoque = new IntersectionObserver(([entrada]) => {
        // Também dispara se a barra já passou do meio (rolagem rápida ou link com âncora).
        const passou = entrada.rootBounds && entrada.boundingClientRect.top < entrada.rootBounds.top;
        if (estoqueVisto || !(entrada.isIntersecting || passou)) return;
        estoqueVisto = true;
        pararObservadorEstoque();
        if (estoqueBaixo.value) rodarEstoque(estoqueBaixo.value);
    }, { rootMargin: '-35% 0px -35% 0px', threshold: 0 });
    observadorEstoque.observe(el);
});
const animarEstoque = () => {
    pararEstoque();
    estoqueFundo.value = false;
    estoqueVermelho.value = false;
    const real = estoqueReal.value;
    if (real < 1) {
        estoqueBaixo.value = null;
        return;
    }
    const sorteio = ESTOQUE_SORTEIO_MIN + Math.floor(Math.random() * (ESTOQUE_SORTEIO_MAX - ESTOQUE_SORTEIO_MIN + 1));
    const numero = Math.min(real, sorteio, ESTOQUE_SORTEIO_MAX);
    estoqueBaixo.value = numero;
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
        estoqueBarra.value = numero;
        return;
    }
    // Pinta a barra cheia primeiro; o recolhimento sai no tick seguinte para a transição rodar.
    estoqueBarra.value = ESTOQUE_BAIXO;
    if (esperaMeio()) return;
    rodarEstoque(numero);
};
onMounted(animarEstoque);
onUnmounted(() => { pararEstoque(); pararObservadorEstoque(); });
watch(() => [selecionada.value.id, estoqueReal.value], animarEstoque);
const barraEstoque = computed(() => Math.max(4, Math.round((estoqueBarra.value / ESTOQUE_BAIXO) * 100)));
// Só cita o que vale para o produto: frete grátis e desconto do Pix.
const complementoEstoque = computed(() => {
    if (isFreeShipping.value && DESCONTO_PIX) return 'Frete grátis + desconto no checkout.';
    if (isFreeShipping.value) return 'Frete grátis.';
    return DESCONTO_PIX ? 'Desconto no checkout.' : '';
});

// Antes de escolher, vale o estoque de qualquer variação (o pai pode estar zerado).
const inStock = computed(() => (faltaVariacao.value
    ? opcoesVariacao.value.some((variant) => variant.stock > 0)
    : selecionada.value.stock > 0));

const setQuantity = (value) => {
    if (exigirVariacao()) return;
    quantity.value = Math.min(Math.max(1, Number(value) || 1), Math.max(1, selecionada.value.stock));
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
const imagemDoBloco = (indice) => (indice === null || indice === undefined ? null : props.product.images?.[indice]?.media_url ?? props.product.images?.[indice]?.url ?? null);
const srcsetDoBloco = (indice) => (indice === null || indice === undefined ? null : srcsetFoto(props.product.images?.[indice]));

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
    if (selecionada.value.model) list.push(['Modelo', selecionada.value.model]);
    if (selecionada.value.color) list.push(['Cor', selecionada.value.color]);
    if (selecionada.value.variation) list.push(['Variação', selecionada.value.variation]);
    if (props.product.category) list.push(['Categoria', props.product.category.name]);
    if (selecionada.value.sku) list.push(['SKU', selecionada.value.sku]);
    return list;
});

const isFreeShipping = computed(() => props.shippingMethods.length > 0);

// Prazo pelo CEP (pedido 2026-10-09): na área da entrega expressa da Grande
// SP mostra "Receba hoje até as 21h" (até 13h) ou "Receba amanhã"; fora
// dela, o prazo normal de envio.
const cep = ref('');
const prazo = ref(null); // null = não consultado; { endereco, opcoes } depois da consulta
const consultandoPrazo = ref(false);
const erroPrazo = ref('');
const resultadoPrazoEl = ref(null);

// Rua do CEP e opções de entrega (pedido 2026-10-23), com as regras reais:
// Full grátis só na área da Grande SP (até 21h pagando até o corte, senão
// amanhã); fora dela, a entrega grátis com o prazo e o TURBO (SEDEX) pelo
// mesmo valor da diferença cobrado no checkout.
const enderecoPrazo = computed(() => {
    const endereco = prazo.value?.endereco;
    if (!endereco) return '';
    // "Rua Mogi Mirim - São Paulo - SP", como no print.
    return [endereco.rua, endereco.cidade, endereco.uf].filter(Boolean).join(' - ');
});
// Tabela de entrega igual ao print do Lira (2026-10-23 21:24): duas linhas,
// "Frete | 8 a 11 dias | Grátis" e "Envio Prioritário | Postagem em 24h |
// R$ 19,90". O prazo e o valor vêm do servidor: Frete = entrega grátis
// (Full, Flex ou Padrão, "geralmente a até" dias); Prioritário = Turbo (SEDEX),
// com o mesmo valor cobrado no checkout.
// Regras do Lira (2026-10-23 21:31): no raio do Full a primeira coluna diz
// "Full" em azul; depois do corte das 13h (Full = "Amanhã") entra embaixo
// "Turbo | Chegará em até 3 horas" no lugar do Prioritário, pelo valor que
// ele deu para o Turbo de 3h. O checkout ainda não tem esse serviço.
// Fora do raio do Full (print do Lira 2026-10-23 21:34): a primeira linha
// vira "Flex | 1 a 7 dias | Grátis", prazo fixo pedido por ele.
const PRECO_TURBO_3H = 39.9;
const linhasEntrega = computed(() => {
    const opcoes = prazo.value?.opcoes ?? [];
    const gratis = opcoes.find((opcao) => opcao.tipo !== 'turbo');
    const turbo = opcoes.find((opcao) => opcao.tipo === 'turbo');
    const full = gratis?.tipo === 'full';
    const linhas = [];
    if (gratis) {
        const quando = full ? (gratis.quando === 'hoje' ? `Hoje até ${gratis.horario}` : 'Amanhã') : '1 a 7 dias';
        linhas.push({ tipo: full ? 'full' : 'flex', nome: full ? 'Full' : 'Flex', quando, valor: 'Grátis' });
    }
    if (full && gratis.quando !== 'hoje') {
        linhas.push({ tipo: 'turbo', nome: 'Turbo', quando: 'Chegará em até 3 horas', valor: formatPrice(PRECO_TURBO_3H) });
    } else if (turbo) {
        linhas.push({ tipo: 'prioritario', nome: 'Envio Prioritário', quando: 'Postagem em 24h', valor: formatPrice(turbo.preco) });
    }
    return linhas;
});

const onCepPrazo = (event) => {
    cep.value = maskCep(event.target.value);
    prazo.value = null;
    erroPrazo.value = '';
};

// Rolagem até a tabela (pedido 2026-10-23): no celular o resultado ficava
// abaixo da dobra e o cliente não via que o frete foi calculado. Desce só o
// que falta para a tabela inteira aparecer, com ela no pé da tela.
const rolarSuave = () => !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const MARGEM_PE = 16;
// O que fica preso no pé da tela e cobre o fim dela: a barra do rodapé (celular)
// ou o COMPRAR AGORA preso na caixa (computador). Conta o maior dos dois.
const barraEl = ref(null);
const alturaBarraRodape = () => Math.max(barraEl.value?.offsetHeight ?? 0, botaoCompraEl.value?.offsetHeight ?? 0);
const mostrarResultadoPrazo = async () => {
    await nextTick();
    const el = resultadoPrazoEl.value;
    if (!el) return;
    const caixa = el.getBoundingClientRect();
    const pe = window.innerHeight - alturaBarraRodape() - MARGEM_PE;
    if (caixa.top >= 0 && caixa.bottom <= pe) return;
    const falta = caixa.bottom - pe;
    // Tabela maior que a tela: o topo dela fica visível.
    window.scrollBy({ top: Math.min(falta, caixa.top - MARGEM_PE), behavior: rolarSuave() ? 'smooth' : 'auto' });
};

// Primeira rolagem da página (pedido 2026-10-23): quando o cliente para de rolar
// pela primeira vez, a tela completa a descida até o "Calcular o prazo" ficar
// no pé da área visível. Só uma vez, só para baixo e só se o campo ainda não
// apareceu inteiro; se o cliente já passou do campo, não mexe.
const prazoCepEl = ref(null);
let ajusteFeito = false;
let esperaFimRolagem = null;
const ajustarAoCep = () => {
    if (ajusteFeito || !prazoCepEl.value) return;
    ajusteFeito = true;
    pararAjusteCep();
    if (window.scrollY < 40) { ajusteFeito = false; ligarAjusteCep(); return; }
    const caixa = prazoCepEl.value.getBoundingClientRect();
    const pe = window.innerHeight - alturaBarraRodape() - MARGEM_PE;
    if (caixa.bottom <= pe || caixa.top < 0) return;
    window.scrollBy({ top: caixa.bottom - pe, behavior: rolarSuave() ? 'smooth' : 'auto' });
};
const aoRolar = () => {
    clearTimeout(esperaFimRolagem);
    esperaFimRolagem = setTimeout(ajustarAoCep, 180);
};
const ligarAjusteCep = () => window.addEventListener('scroll', aoRolar, { passive: true });
const pararAjusteCep = () => { window.removeEventListener('scroll', aoRolar); clearTimeout(esperaFimRolagem); };
onMounted(() => { if (!window.location.hash) ligarAjusteCep(); });
onUnmounted(pararAjusteCep);

const consultarPrazo = async ({ rolar = true } = {}) => {
    ajusteFeito = true;
    pararAjusteCep();
    if (cep.value.replace(/\D/g, '').length !== 8) {
        erroPrazo.value = 'Digite um CEP com 8 números.';
        return;
    }
    consultandoPrazo.value = true;
    erroPrazo.value = '';
    try {
        const response = await fetch(`/frete/prazo?cep=${encodeURIComponent(cep.value)}&produto=${selecionada.value.id}&quantidade=${quantity.value}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error();
        const data = await response.json();
        prazo.value = {
            endereco: data.endereco ?? null,
            opcoes: data.opcoes ?? [],
        };
        if (rolar) mostrarResultadoPrazo();
        // Frete embutido (2026-10-12): o CEP mudou o frete que está no preço;
        // recarrega os preços da página para o CEP digitado.
        if (data.final_price !== undefined && Number(data.final_price) !== Number(selecionada.value.final_price)) {
            router.reload({ preserveScroll: true, preserveState: true });
        }
    } catch {
        erroPrazo.value = 'Não foi possível consultar o prazo agora.';
    } finally {
        consultandoPrazo.value = false;
    }
};

// Outra variação ou quantidade muda a caixa: refaz a conta do CEP já consultado.
watch(() => [selecionada.value.id, quantity.value], () => {
    if (prazo.value && !consultandoPrazo.value) consultarPrazo({ rolar: false });
});

const buyingNow = ref(false);

// "Comprar": finalização só com este produto, sem colocar no carrinho
// (pedido 2026-10-11). O carrinho continua com o que já tinha.
const buyNow = () => {
    if (exigirVariacao()) return;
    buyingNow.value = true;
    // AddToCart da Meta (2026-10-18): mesmo event_id no Pixel e no servidor.
    const eventId = novoEventId();
    const item = { id: selecionada.value.id, preco: selecionada.value.final_price, quantidade: quantity.value };
    router.post('/comprar-agora', { product_id: selecionada.value.id, quantity: quantity.value, meta_event_id: eventId }, {
        onSuccess: () => rastrearAddToCart(item, eventId),
        onFinish: () => { buyingNow.value = false; },
    });
};

// Botão do CTA da descrição: sem variação escolhida, leva ao box de compra
// para escolher (o alerta aparece lá); com variação, compra direto.
const comprarPeloCta = () => {
    if (faltaVariacao.value) {
        document.getElementById('comprar')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        exigirVariacao();
        return;
    }
    buyNow();
};

// Barras por estrela (5 → 1) a partir das avaliações reais.
const ratingRows = computed(() => [5, 4, 3, 2, 1].map((star) => {
    const count = props.reviews.filter((review) => Number(review.rating) === star).length;
    return { star, count, percent: props.reviews.length ? Math.round((count / props.reviews.length) * 100) : 0 };
}));

const showReviews = computed(() => props.reviews.length > 0 || (props.canReview && !props.hasReviewed));

// Lista de avaliações (pedido 2026-10-11): já vem ordenada do servidor (mais
// estrelas primeiro, com foto antes). Mostra 20 no computador e 6 no celular;
// "Carregar mais" traz de 12 em 12 até a última.
const ehCelular = typeof window !== 'undefined' && window.matchMedia?.('(max-width: 767px)').matches;
const avaliacoesVisiveis = ref(ehCelular ? 6 : 20);
const reviewsNaTela = computed(() => props.reviews.slice(0, avaliacoesVisiveis.value));
const avaliacoesRestantes = computed(() => Math.max(0, props.reviews.length - avaliacoesVisiveis.value));
const carregarMaisAvaliacoes = () => { avaliacoesVisiveis.value += 12; };

// No celular o vídeo da avaliação não baixa nada até o toque (pedido 2026-10-23):
// a miniatura é só o quadrado com o play; o 1º quadro custava ~2 MB por página.

// Foto da avaliação ampliada dentro da loja (antes abria o site da Shopee).
const ehVideo = (midia) => /\.(mp4|mov|webm|m4v)(\?|$)/i.test(midia?.image_url ?? '');
const fotoAberta = ref(null);
// `largo`: imagem horizontal (quadro dos livros) abre maior que a foto da avaliação.
const abrirFoto = (imagens, indice, largo = false) => { fotoAberta.value = { imagens, indice, largo }; };

// Quadro dos livros: imagem do grupo (admin) ou a padrão das costas.
const livrosQuadroSrc = computed(() => props.livrosQuadro || '/images/promocoes/livros-revista-cama.webp');
const livrosQuadroAlt = computed(() => (props.livrosQuadro
    ? `Guias digitais da KazaKora em tablets: ${props.livrosDigitais.map((l) => l.titulo).join(', ')}`
    : 'Três guias digitais grátis da KazaKora em tablets, com capa de revista: Sentou, doeu? Dor nas costas de quem trabalha sentado; Travou a lombar? O que fazer nas primeiras 48 horas; Acordou com dor? O problema pode estar na sua noite'));
const fecharFoto = () => { fotoAberta.value = null; };
const passarFoto = (passo) => {
    if (!fotoAberta.value) return;
    const total = fotoAberta.value.imagens.length;
    fotoAberta.value = { ...fotoAberta.value, indice: (fotoAberta.value.indice + passo + total) % total };
};
const teclaFoto = (evento) => {
    if (!fotoAberta.value) return;
    if (evento.key === 'Escape') fecharFoto();
    if (evento.key === 'ArrowRight') passarFoto(1);
    if (evento.key === 'ArrowLeft') passarFoto(-1);
};
onMounted(() => window.addEventListener('keydown', teclaFoto));
onUnmounted(() => window.removeEventListener('keydown', teclaFoto));

// Barra do rodapé no computador (pedido 2026-10-23): a caixa de compra deixou de
// ficar presa no meio da tela; quando o COMPRAR AGORA da caixa sai da tela, a
// barra com preço e botão aparece presa embaixo. No celular a barra fica sempre.
const botaoCompraEl = ref(null);
const botaoNaTela = ref(true);
let observadorBotao = null;
onMounted(() => {
    if (!botaoCompraEl.value || !('IntersectionObserver' in window)) { botaoNaTela.value = false; return; }
    observadorBotao = new IntersectionObserver(([entrada]) => { botaoNaTela.value = entrada.isIntersecting; });
    observadorBotao.observe(botaoCompraEl.value);
});
onUnmounted(() => observadorBotao?.disconnect());

// Caixa de compra como na Mavo (pedido 2026-10-23): no computador ela rola com a
// página até o fim dela aparecer e trava com o pé no rodapé da tela, enquanto a
// coluna da esquerda segue rolando. Caixa menor que a tela fica presa embaixo do
// cabeçalho. O topo do sticky é recalculado quando a caixa muda de altura (CEP,
// variação) ou a janela muda de tamanho.
const MARGEM_CAIXA = 16;
const compraEl = ref(null);
const topoCompra = ref(null);
let observadorCaixa = null;
const ajustarTopoCompra = () => {
    const caixa = compraEl.value;
    if (!caixa) return;
    const cabecalho = document.querySelector('.loja-topo')?.offsetHeight ?? 0;
    topoCompra.value = Math.min(cabecalho + MARGEM_CAIXA, window.innerHeight - caixa.offsetHeight - MARGEM_CAIXA);
};
onMounted(() => {
    ajustarTopoCompra();
    window.addEventListener('resize', ajustarTopoCompra, { passive: true });
    if ('ResizeObserver' in window && compraEl.value) {
        observadorCaixa = new ResizeObserver(ajustarTopoCompra);
        observadorCaixa.observe(compraEl.value);
    }
});
onUnmounted(() => {
    window.removeEventListener('resize', ajustarTopoCompra);
    observadorCaixa?.disconnect();
});
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
    <Head :title="product.name" />

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
                                    <!-- Favorito no canto da foto desligado (pedido do Lira 2026-10-23); para voltar, descomente.
                                    <button type="button" class="fav fav-foto" :class="{ 'favorito-ativo': ehFavorito(product.id) }" :aria-pressed="ehFavorito(product.id)"
                                        :aria-label="ehFavorito(product.id) ? 'Tirar dos favoritos' : 'Adicionar aos favoritos'" @click.stop="toggleFavorite(product.id)">
                                        <i :class="ehFavorito(product.id) ? 'fas fa-check' : 'far fa-heart'"></i>
                                    </button>
                                    -->
                                    <span v-if="percentualTotal > 0" class="selo-desconto" :aria-label="`${percentualTotal}% de desconto`">-{{ percentualTotal }}%</span>
                                    <img v-if="fotoModelo" :src="fotoModelo" :alt="`${product.name} — outro modelo`">
                                    <template v-else-if="activeMedia?.type === 'video'">
                                        <video v-if="isVideoStarted" ref="videoEl" :src="activeMedia.src" :poster="videoPoster" controls playsinline preload="auto" @click.stop></video>
                                        <button v-else type="button" class="capa-video" aria-label="Assistir ao vídeo do produto" @click.stop="startVideo">
                                            <img v-if="videoPoster" :src="videoPoster" :srcset="videoPosterSrcset" :sizes="videoPosterSrcset ? FOTO_SIZES : null" :alt="`${product.name} — vídeo`" fetchpriority="high">
                                            <video v-else v-video-preguicoso :src="activeMedia.src" muted playsinline preload="none"></video>
                                            <span class="play grande"><i class="fas fa-play"></i></span>
                                        </button>
                                    </template>
                                    <img v-else-if="activeMedia" :src="activeMedia.src" :srcset="activeMedia.srcset" :sizes="activeMedia.srcset ? FOTO_SIZES : null" :alt="`${product.name} — foto ${activeIndex + 1}`" fetchpriority="high">
                                    <div v-else class="vazio"><i class="fas fa-box-open"></i></div>

                                    <template v-if="mediaItems.length > 1">
                                        <button type="button" class="seta esq" aria-label="Mídia anterior" @click.stop="goPrev"><i class="fas fa-chevron-left"></i></button>
                                        <button type="button" class="seta dir" aria-label="Próxima mídia" @click.stop="goNext"><i class="fas fa-chevron-right"></i></button>
                                    </template>
                                </div>
                                <div v-if="mediaItems.length > 1" ref="miniaturasEl" class="miniaturas">
                                    <button v-for="(item, index) in mediaItems" :key="index" type="button"
                                        :aria-label="`Ver ${item.type === 'video' ? 'vídeo' : 'foto ' + (index + 1)}`"
                                        :aria-current="index === activeIndex" :class="{ 'da-variacao': escolhida && item.variantId === escolhida.id }" @click="activeIndex = index">
                                        <template v-if="item.type === 'video'">
                                            <img v-if="videoPoster" :src="videoPoster" alt="">
                                            <video v-else v-video-preguicoso :src="item.src" muted preload="none"></video>
                                            <span class="play"><i class="fas fa-play"></i></span>
                                        </template>
                                        <img v-else :src="item.mini || item.thumb || item.src" alt="" loading="lazy">
                                    </button>
                                </div>
                            </div>
                        </section>

                        <!-- BOX DE COMPRA (fica à direita no computador; no celular entra aqui, logo depois da galeria) -->
                        <aside id="comprar" ref="compraEl" class="card compra" :style="topoCompra !== null ? { top: `${topoCompra}px` } : null">
                            <!-- Caixa de compra no formato da Mavo (tarefa 2656, print de 23/10 21h), com
                                 os números reais: vendidos, oferta no ar, nota, estoque e desconto do Pix. -->
                            <div class="condicao">
                                <span class="cond-texto">Novo<template v-if="vendidosLegenda"> | {{ vendidosLegenda }}</template></span>
                                <span class="tag-exclusivo">EXCLUSIVO</span>
                                <span class="tag-tempo">POR TEMPO LIMITADO!</span>
                            </div>

                            <h1>{{ selecionada.name }}</h1>

                            <!-- Três checks logo abaixo do título, antes das estrelas e do preço (pedido 2026-10-23). -->
                            <ul v-if="highlights.length" class="checks">
                                <li v-for="(item, index) in highlights" :key="index"><i class="fas fa-check"></i> {{ item }}</li>
                            </ul>

                            <!-- Linha de estrelas abaixo do título e dos checks, como na Mavo (tarefa 2656, print
                                 das 21h22). Sem avaliação: estrelas vazias e "(0 Avaliações)", nunca nota inventada. -->
                            <component :is="showReviews ? 'a' : 'span'" :href="showReviews ? '#avaliacoes' : undefined" class="nota" :class="{ 'sem-aval': reviewsCount === 0 }">
                                <span class="estrelas" aria-hidden="true"><i v-for="n in 5" :key="n" :class="n <= stars(ratingAvg) ? 'fas fa-star' : 'far fa-star'"></i></span>
                                <span v-if="reviewsCount > 0" class="media">{{ ratingAvg.toFixed(1) }}</span>
                                <span class="qtd-aval-topo">({{ reviewsCount }} {{ reviewsCount === 1 ? 'Avaliação' : 'Avaliações' }})</span>
                            </component>

                            <hr class="divisor">

                            <div class="preco">
                                <!-- Riscado em cima do preço atual (pedido 2026-10-23). -->
                                <span v-if="precoDe" class="de">{{ formatPrice(precoDe) }}</span>
                                <span v-if="aPartirDe" class="a-partir">A partir de</span>
                                <div class="linha-por">
                                    <span class="por">{{ formatPrice(precoPor) }}</span>
                                </div>
                                <div class="parc"><i class="far fa-credit-card" aria-hidden="true"></i> em até {{ PARCELAS }}x de <strong>{{ formatPrice(installmentValue) }}</strong></div>
                                <div v-if="precoPix !== null && seloPix" class="linha-pix">
                                    <i class="fa-brands fa-pix" aria-hidden="true"></i> pague no pix <b>{{ seloPix }}</b>
                                </div>
                            </div>

                            <div v-if="modelos.length > 1" class="variacoes modelos">
                                <span class="subtitulo">Modelo: <span class="valor-escolhido">{{ modelos.find((modelo) => modelo.atual)?.rotulo }}</span></span>
                                <div class="opcoes">
                                    <template v-for="modelo in modelos" :key="modelo.id">
                                        <span v-if="modelo.atual" class="opcao atual" aria-current="page">{{ modelo.rotulo }}</span>
                                        <Link v-else :href="modelo.url" class="opcao" @click="escolherModelo(modelo, $event)">{{ modelo.rotulo }}</Link>
                                    </template>
                                </div>
                            </div>

                            <div v-if="variations.length" ref="variacoesEl" class="variacoes" :class="{ 'com-alerta': alertaVariacao }">
                                <span class="subtitulo">{{ rotuloVariacao }}: <span class="valor-escolhido">{{ escolhida ? (escolhida.variation || escolhida.name) : '' }}</span>
                                    <span v-if="alertaVariacao" :key="alertaVariacao" class="alerta-variacao" role="alert"><i class="fas fa-circle-exclamation"></i> Escolha a sua variação</span>
                                </span>
                                <div class="opcoes">
                                    <button v-for="variant in opcoesVariacao" :key="variant.id" type="button"
                                        class="opcao" :class="{ atual: variant.id === escolhida?.id, esgotada: variant.stock < 1 }"
                                        :aria-pressed="variant.id === escolhida?.id" @click="escolherVariacao(variant)">
                                        {{ variant.variation || variant.name }}
                                    </button>
                                </div>
                            </div>

                            <!-- Kits (pedido 2026-10-23): "2 unidades · 9% OFF" com o desconto já
                                 cadastrado; o clique escolhe a quantidade e o total atualiza. -->
                            <div v-if="faixasVisiveis.length" class="leve-mais">
                                <span class="subtitulo">Leve mais, pague menos</span>
                                <ul>
                                    <li v-for="tier in faixasVisiveis" :key="tier.id">
                                        <button type="button" class="kit" :aria-pressed="quantity === tier.min_quantity" @click="setQuantity(tier.min_quantity)">
                                            <span>{{ tier.min_quantity }} unidades · <b class="kit-off">{{ Math.round(Number(tier.discount_percentage)) }}% OFF</b></span>
                                            <strong>{{ formatPrice(unitPriceForQuantity(tier.min_quantity)) }}/un.</strong>
                                        </button>
                                    </li>
                                </ul>
                            </div>

                            <template v-if="inStock">
                                <div v-if="estoqueBaixo" ref="estoqueEl" class="estoque-baixo">
                                    <p><b>Só <span class="num" :class="{ fundo: estoqueFundo, vermelho: estoqueVermelho }">{{ estoqueBaixo }}</span> {{ estoqueBaixo === 1 ? 'unidade' : 'unidades' }}!</b> {{ complementoEstoque }}</p>
                                    <div class="estoque-barra" role="progressbar" :aria-valuenow="estoqueBaixo" aria-valuemin="0" :aria-valuemax="ESTOQUE_BAIXO":aria-label="`Estoque: ${estoqueBaixo} unidades`">
                                        <span :style="{ width: `${barraEstoque}%` }"></span>
                                    </div>
                                </div>
                                <p v-if="faixasVisiveis.length && quantity > 1" class="total">Total: <strong>{{ formatPrice(currentTotal) }}</strong><span v-if="totalPix !== null" class="total-pix"> ou <b>{{ formatPrice(totalPix) }}</b> no Pix</span></p>
                            </template>
                            <p v-else ref="botaoCompraEl" class="esgotado">Produto esgotado</p>

                            <div class="pagamento-seguro">
                                <div class="ps-topo"><i class="far fa-credit-card" aria-hidden="true"></i> PAGAMENTO SEGURO E RÁPIDO</div>
                                <div class="bandeiras">
                                    <img v-for="brand in PAYMENT_BRANDS" :key="brand" :src="`/images/payments/mavo/${brand}.svg`" :alt="NOME_BANDEIRA[brand]" width="38" height="24" loading="lazy">
                                </div>
                            </div>

                            <!-- Quatro selos num quadro só, no desenho do print do Lira (pedido 2026-10-23):
                                 entre as bandeiras e o "Calcular o prazo"; substituem os cards soltos. -->
                            <ul class="selos-compra">
                                <li>
                                    <img src="/images/checkout/entrega-rapida-checkout-v4.webp" alt="" width="40" height="40" loading="lazy">
                                    <span v-if="isFreeShipping"><b>Entrega GRÁTIS</b> <span class="selo-full"><i class="fa-solid fa-bolt" aria-hidden="true"></i>FULL</span> para a sua casa!</span>
                                    <span v-else><b>Entrega</b> para todo o Brasil.</span>
                                </li>
                                <li>
                                    <img src="/images/checkout/satisfacao-garantida-checkout-v4.webp" alt="" width="40" height="40" loading="lazy">
                                    <span><b>Devolução fácil.</b> Você tem 7 dias para desistir e 30 dias para trocar se vier com defeito.</span>
                                </li>
                                <li>
                                    <img src="/images/checkout/pagamento-100-seguro-checkout-v4.webp" alt="" width="40" height="40" loading="lazy">
                                    <span><b>Compra segura e garantida</b>, receba o produto que está esperando ou devolvemos o dinheiro.</span>
                                </li>
                                <li>
                                    <img src="/images/checkout/avaliacoes-positivas-checkout-v4.webp" alt="" width="40" height="40" loading="lazy">
                                    <span><b>Empresa 100% legal</b><br>CNPJ: {{ COMPANY.cnpj }}<br>Vendido e enviado por {{ COMPANY.nomeFantasia }}, de São Paulo/SP.</span>
                                </li>
                            </ul>

                            <!-- Calcular o prazo logo abaixo das bandeiras de pagamento (pedido do Lira 2026-10-23;
                                 antes ficava depois dos cards, na posição da Mavo); os selos ficam entre os dois. -->
                            <form ref="prazoCepEl" class="prazo-cep" @submit.prevent="consultarPrazo()">
                                <label for="cep-prazo">Calcular o prazo de entrega</label>
                                <div class="linha">
                                    <input id="cep-prazo" :value="cep" type="text" inputmode="numeric" maxlength="9" placeholder="00000-000" autocomplete="postal-code" @input="onCepPrazo">
                                    <button type="submit" :disabled="consultandoPrazo">{{ consultandoPrazo ? '...' : 'calcular' }}</button>
                                </div>
                                <p v-if="erroPrazo" class="erro">{{ erroPrazo }}</p>
                                <div v-else-if="prazo" ref="resultadoPrazoEl">
                                    <p v-if="enderecoPrazo" class="rua"><i class="fas fa-location-dot" aria-hidden="true"></i> {{ enderecoPrazo }}</p>
                                    <table v-if="linhasEntrega.length" class="tabela-entrega">
                                        <thead>
                                            <tr><th scope="col">Entrega</th><th scope="col">Prazo</th><th scope="col">Preço</th></tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="linha in linhasEntrega" :key="linha.tipo" :class="`linha-${linha.tipo}`">
                                                <td class="nome">{{ linha.nome }}</td>
                                                <td class="quando">{{ linha.quando }}</td>
                                                <td class="valor">{{ linha.valor }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </form>

                            <!-- COMPRAR AGORA no fim da caixa, depois do frete/prazo (pedido do Lira 2026-10-23):
                                 no computador fica preso no rodapé da tela enquanto a caixa rola e encaixa aqui no fim.
                                 Sem variação escolhida o botão fica apagado, mas o clique mostra o alerta (por isso não usa disabled). -->
                            <div v-if="inStock" ref="botaoCompraEl" class="acao" :class="{ 'sem-variacao': faltaVariacao }">
                                <button type="button" class="btn comprar-agora" :aria-disabled="faltaVariacao" :disabled="buyingNow" @click="buyNow">COMPRAR AGORA</button>
                            </div>
                        </aside>

                        <!-- Descrição: visual da v1 (cabeçalho centralizado; sem a faixa roxa, pedido 2026-10-10) com o
                             conteúdo no formato da referência: blocos de título + imagem + texto
                             persuasivo, comparativo, benefícios e dúvidas. -->
                        <section v-if="blocosAnuncio.length || descriptionSections.length" class="card descricao-card">
                            <header class="desc-cabecalho">
                                <!-- Sem "Por que esse produto merece atenção?" (pedido 2026-10-20, definitivo). -->
                                <h2>{{ anuncio?.chamada || 'Descrição' }}</h2>
                            </header>

                            <div v-if="blocosAnuncio.length" class="descricao">
                                <!-- Foto pequena ao lado do texto, alternando o lado a cada bloco, na
                                     estrutura da página do FerraKora (pedido 2026-10-19). -->
                                <article v-for="(bloco, index) in blocosAnuncio" :key="index" class="bloco-anuncio"
                                    :class="{ 'com-foto': imagemDoBloco(bloco.imagem), 'foto-direita': index % 2 === 1 }">
                                    <img v-if="imagemDoBloco(bloco.imagem)" class="foto-desc" :src="imagemDoBloco(bloco.imagem)" :srcset="srcsetDoBloco(bloco.imagem)" :sizes="srcsetDoBloco(bloco.imagem) ? '320px' : null" :alt="bloco.titulo" loading="lazy" decoding="async">
                                    <div>
                                        <h3>{{ bloco.titulo }}</h3>
                                        <p>{{ bloco.texto }}</p>
                                    </div>
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

                                <!-- CTA depois das dúvidas (pedido 2026-10-20): texto do Gemini com
                                     emojis e as garantias reais da loja para a última objeção. -->
                                <div v-if="anuncio.cta?.titulo" class="cta-anuncio">
                                    <h3>{{ anuncio.cta.titulo }}</h3>
                                    <p>{{ anuncio.cta.texto }}</p>
                                    <ul v-if="anuncio.cta.garantias?.length">
                                        <li v-for="(garantia, index) in anuncio.cta.garantias" :key="index">{{ garantia }}</li>
                                    </ul>
                                    <button v-if="inStock" type="button" class="btn" :disabled="buyingNow" @click="comprarPeloCta">{{ anuncio.cta.botao }}</button>
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

                        <!-- Banners promocionais no formato da referência (izeshop), cadastrados no admin em
                             Entregáveis › Sorteios e Livros Digitais (pedido 2026-10-20; antes eram fixos). Logo depois
                             da descrição, antes de Garantia, Pagamento Seguro e Informação adicional (teste pedido 2026-10-21). -->
                        <section v-for="sorteio in sorteios" :key="`sorteio-${sorteio.id}`" class="card promo">
                            <div class="promo-texto">
                                <h2>{{ quadrosPromocao.airfryer?.titulo || sorteio.titulo }}</h2>
                                <!-- Texto do quadro (admin › Promoções, 2026-10-21) no lugar dos parágrafos do sorteio. -->
                                <p v-for="(paragrafo, i) in quadrosPromocao.airfryer?.texto ?? []" :key="`st-${i}`" class="promo-texto-livre"
                                   :class="{ 'sem-margem': i === quadrosPromocao.airfryer.texto.length - 1 && !quadrosPromocao.airfryer.condicoes && !quadrosPromocao.airfryer.botao }">{{ paragrafo }}</p>
                                <!-- Condições da Promoção Airfryer (admin › Promoções) no lugar do texto do sorteio. -->
                                <p v-if="quadrosPromocao.airfryer?.condicoes" class="promo-condicoes" :class="{ 'sem-margem': !quadrosPromocao.airfryer?.botao }">{{ quadrosPromocao.airfryer.condicoes }}</p>
                                <template v-else-if="!quadrosPromocao.airfryer?.texto?.length">
                                    <p :class="{ 'sem-margem': !sorteio.paragrafo_2 && !quadrosPromocao.airfryer?.botao }">{{ sorteio.paragrafo_1 }}</p>
                                    <p v-if="sorteio.paragrafo_2" :class="{ 'sem-margem': !quadrosPromocao.airfryer?.botao }">{{ sorteio.paragrafo_2 }}</p>
                                </template>
                                <Link v-if="quadrosPromocao.airfryer?.botao" :href="quadrosPromocao.airfryer.botao.url" class="promo-botao">
                                    {{ quadrosPromocao.airfryer.botao.texto }} <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                </Link>
                            </div>
                            <img class="promo-img promo-sorteio" :src="sorteio.imagem_url" :alt="sorteio.titulo" loading="lazy">
                        </section>

                        <!-- Quadro dos livros no formato da referência do Lira (2026-10-21): título e imagem pronta
                             com os 3 tablets e capas estilo revista coloridas (entregas/capas-revista-livros-2026-10-21 do Homer) à esquerda, oferta à
                             direita. Sem "preço vendido separadamente": os guias não são vendidos, seria preço inventado. -->
                        <section v-if="livrosDigitais.length" class="card promo-livros">
                            <div class="livros-vitrine">
                                <h2 v-if="quadrosPromocao.pdfs?.titulo">{{ quadrosPromocao.pdfs.titulo }}</h2>
                                <h2 v-else>Compre Hoje E Receba {{ livrosDigitais.length === 1 ? '1 Livro Digital' : `${livrosDigitais.length} Livros Digitais` }} GRÁTIS!</h2>
                                <!-- Clique amplia a imagem no mesmo lightbox das fotos de avaliação (pedido 2026-10-21). -->
                                <button type="button" class="livros-zoom" aria-label="Ampliar imagem dos livros"
                                        @click="abrirFoto([{ image_url: livrosQuadroSrc, alt: livrosQuadroAlt }], 0, true)">
                                    <img class="livros-img" :src="livrosQuadroSrc" :alt="livrosQuadroAlt" width="1200" height="600" loading="lazy">
                                </button>
                            </div>
                            <div class="livros-oferta">
                                <!-- Texto das costas só com a imagem padrão; grupo com imagem própria (ferramentas, Natal) usa texto neutro. -->
                                <!-- Texto do quadro (admin › Promoções, 2026-10-21) no lugar do texto de sempre. -->
                                <template v-if="quadrosPromocao.pdfs?.texto?.length">
                                    <p v-for="(paragrafo, i) in quadrosPromocao.pdfs.texto" :key="`lt-${i}`" class="promo-texto-livre">{{ paragrafo }}</p>
                                </template>
                                <p v-else-if="livrosQuadro">Guias em PDF com orientações práticas para você aproveitar melhor a sua compra.</p>
                                <p v-else>Guias em PDF com orientações práticas para cuidar das costas no trabalho, nas crises e na hora de dormir.</p>
                                <p class="livros-lista">
                                    <span v-for="livro in livrosDigitais" :key="`t-${livro.id}`"><i class="fa-solid fa-check" aria-hidden="true"></i> {{ livro.titulo }}</span>
                                </p>
                                <p v-if="quadrosPromocao.pdfs?.destaque" class="livros-gratis">{{ quadrosPromocao.pdfs.destaque }}</p>
                                <p v-else class="livros-gratis">Adquirindo hoje: <b>100% GRÁTIS</b></p>
                                <p v-if="quadrosPromocao.pdfs?.condicoes" class="promo-condicoes livros-condicoes">{{ quadrosPromocao.pdfs.condicoes }}</p>
                                <Link v-if="quadrosPromocao.pdfs?.botao" :href="quadrosPromocao.pdfs.botao.url" class="promo-botao livros-botao">
                                    {{ quadrosPromocao.pdfs.botao.texto }} <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                </Link>
                            </div>
                        </section>

                        <section class="card">
                            <h2>Garantia Total De Satisfação</h2>
                            <div class="garantia">
                                <img class="selo-garantia" src="/images/selo-garantia-100-satisfacao.webp" alt="Garantia de 100% de satisfação" loading="lazy">
                                <p>
                                    Confiamos tanto no produto que você tem 30 dias para testar e comprovar a qualidade, sem risco nenhum!
                                </p>
                            </div>
                        </section>

                        <section class="card">
                            <h2>Pagamento Seguro</h2>
                            <div class="bandeiras esquerda">
                                <img v-for="brand in BANDEIRAS_FORMAS" :key="brand" :src="`/images/payments/novas/${brand}.webp`" :alt="brand" loading="lazy">
                            </div>
                            <p v-if="DESCONTO_PIX">No Pix você tem {{ DESCONTO_PIX }} de desconto, com aprovação imediata.</p>
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
                        <div v-for="review in reviewsNaTela" :key="review.id" class="aval-item">
                            <div class="autor"><i class="fas fa-user"></i> {{ review.reviewer_display_name }}
                                <!-- Avaliação importada de outra loja (outra_loja): selo visível para não
                                     parecer compra na KazaKora (pedido 2026-10-21). -->
                                <span v-if="review.outra_loja" class="selo-outra-loja">Comprado em outra loja</span>
                            </div>
                            <div class="nota-data">
                                <span class="estrelas"><i v-for="n in 5" :key="n" :class="n <= review.rating ? 'fas fa-star' : 'far fa-star'"></i></span>
                                <small>{{ formatDate(review.created_at) }}</small>
                            </div>
                            <p v-if="review.comment">{{ review.comment }}</p>
                            <div v-if="review.images?.length" class="fotos-avaliacao">
                                <button v-for="(image, indice) in review.images" :key="image.id" type="button" class="foto-btn"
                                    :aria-label="`${ehVideo(image) ? 'Assistir vídeo' : 'Ver foto'} ${indice + 1} da avaliação`" @click="abrirFoto(review.images, indice)">
                                    <span v-if="ehVideo(image) && ehCelular" class="video-sem-capa"></span>
                                    <video v-else-if="ehVideo(image)" v-video-preguicoso :src="image.image_url + '#t=0.1'" muted playsinline preload="none"></video>
                                    <img v-else :src="miniaturaAvaliacao(image.image_url)" alt="" width="60" height="60" loading="lazy" decoding="async">
                                    <span v-if="ehVideo(image)" class="play-video" aria-hidden="true"><i class="fas fa-play"></i></span>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div v-if="avaliacoesRestantes > 0" class="aval-mais">
                        <button type="button" class="btn-sec" @click="carregarMaisAvaliacoes">
                            Carregar mais avaliações <span>({{ avaliacoesRestantes }} restantes)</span>
                        </button>
                    </div>
                    <p v-else class="sem-margem">Este produto ainda não recebeu avaliações. Seja o primeiro a avaliar.</p>
                </section>

                <!-- Foto da avaliação ampliada -->
                <Teleport to="body">
                    <div v-if="fotoAberta" class="aval-lightbox" :class="{ 'lb-largo': fotoAberta.largo }" role="dialog" aria-modal="true" :aria-label="fotoAberta.largo ? 'Imagem ampliada' : 'Foto da avaliação'" @click.self="fecharFoto">
                        <button type="button" class="lb-fechar" aria-label="Fechar" @click="fecharFoto"><i class="fas fa-xmark"></i></button>
                        <button v-if="fotoAberta.imagens.length > 1" type="button" class="lb-seta lb-esq" aria-label="Foto anterior" @click="passarFoto(-1)"><i class="fas fa-chevron-left"></i></button>
                        <video v-if="ehVideo(fotoAberta.imagens[fotoAberta.indice])" :key="fotoAberta.indice" :src="fotoAberta.imagens[fotoAberta.indice].image_url" autoplay controls playsinline></video>
                        <img v-else :src="fotoAvaliacaoAmpliada(fotoAberta.imagens[fotoAberta.indice].image_url)" :alt="fotoAberta.imagens[fotoAberta.indice].alt ?? 'Foto da avaliação'" @click.self="fotoAberta.largo && fecharFoto()">
                        <button v-if="fotoAberta.imagens.length > 1" type="button" class="lb-seta lb-dir" aria-label="Próxima foto" @click="passarFoto(1)"><i class="fas fa-chevron-right"></i></button>
                        <span v-if="fotoAberta.imagens.length > 1" class="lb-contador">{{ fotoAberta.indice + 1 }} / {{ fotoAberta.imagens.length }}</span>
                    </div>
                </Teleport>

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
                    <CarrosselProdutos :produtos="relatedProducts" :favorite-ids="relatedFavoriteIds"
                        :reviewable-product-ids="relatedReviewableIds" :reviewed-product-ids="relatedReviewedIds" />
                </section>
            </div>

            <!-- Barra fixa no rodapé (pedido 2026-10-23): valor no Pix à esquerda, botão à direita.
                 No celular fica sempre; no computador aparece quando o botão da caixa sai da tela. -->
            <div ref="barraEl" class="barra" :class="{ visivel: !botaoNaTela }">
                <div class="barra-preco">
                    <strong>{{ formatPrice(precoPix ?? precoPor) }}</strong>
                    <span v-if="precoPix !== null">no Pix</span>
                </div>
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
                    <video v-if="activeMedia?.type === 'video'" :src="activeMedia.src" :poster="videoPoster" controls playsinline preload="metadata" class="max-h-[90vh] max-w-[90vw] rounded-[2px]"></video>
                    <img v-else-if="activeMedia" :src="activeMedia.full ?? activeMedia.src" :alt="product.name" class="max-h-[90vh] max-w-[90vw] rounded-[2px] object-contain shadow-2xl">
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

<style>
/* Tudo preso em .pd2 pra não vazar pro resto da loja. Medidas e cores
   tiradas da página de referência (izeshop): fundo #F7F7F7, cards brancos
   raio 20px, amarelo #FCBD10, verde #00AB21, azul #0044C3 / #002F88
   (azul escuro trocado pelo azul claro #2563EB, pedido 2026-10-23).
   Verde fechado para #00851C (4,8:1 com branco; o #00AB21 dava 3,1 no PageSpeed, 2026-10-21);
   no tema escuro volta o #00AB21, que passa no fundo escuro. Cinza --muted #767676 → #6b6b6b
   (4,2 → 5,0:1 no fundo #F7F7F7). */
.pd2 {
    --bg: #f7f7f7; --card: #fff; --ink: #18212e; --text: #575757; --muted: #6b6b6b; --line: #ebebeb; --soft: #f9f9f9; --sunk: #f3f3f3;
    --yellow: #fcbd10; --yellow-edge: #5a4200; --green: #00851c; --green-edge: #006915; --blue: #2563eb; --navy: #2563eb; --star: #fcbd10;
    --shadow: 0 0 10px 0 rgba(218, 218, 218, .24); --shadow-strong: 0 17px 10px 0 rgba(218, 218, 218, .24);
    background: var(--bg); color: var(--text); font: 400 16px/1.5 Poppins, system-ui, sans-serif; -webkit-font-smoothing: antialiased;
    padding-bottom: 40px;
}
.dark .pd2 {
    --bg: #0b0f17; --card: #141a24; --ink: #f3f4f6; --text: #c4c9d2; --muted: #9ca3af; --line: #273042; --soft: #1a2130; --sunk: #1f2736;
    --blue: #7aa2ff; --green: #00ab21; --shadow: none; --shadow-strong: none;
}
/* Os estilos gerais da página não entram no card de produto (.card-produto, pedido
   2026-10-22): no "Você também pode gostar de" ele ficava diferente do resto da loja
   (título apertado, margem embaixo do "Frete grátis" e "Detalhes" cinza). */
.pd2 img:not(.card-produto *) { max-width: 100%; display: block; }
.pd2 a:not(.card-produto *) { color: inherit; }
.pd2 :is(h1, h2, h3):not(.card-produto *) { color: var(--ink); font-family: inherit; margin: 0; line-height: 1.2; }
.pd2 h2 { font-size: 28px; font-weight: 500; margin-bottom: 16px; }
.pd2 p:not(.card-produto *) { margin: 0 0 14px; }
.pd2 .sem-margem { margin: 0; }
/* Mesma largura do cabeçalho e do resto da loja (max-w-[1320px] px-6): com 1400px o produto passava do menu. */
.pd2 .wrap { max-width: 1320px; margin: 0 auto; padding: 0 24px; }
.pd2 .card { background: var(--card); border-radius: 20px; box-shadow: var(--shadow); padding: 20px; }
.pd2 :focus-visible { outline: 3px solid var(--blue); outline-offset: 2px; }

/* faixa amarela */
.pd2 .faixa { background: #F37021; color: #111; display: flex; justify-content: center; align-items: center; gap: 14px; flex-wrap: wrap; padding: 10px 16px; font-weight: 600; font-size: 14px; letter-spacing: .5px; }
.pd2 .faixa i { margin-right: 6px; }
.pd2 .selo-full i { margin: 0 1px 0 0; font-size: 0.9em; width: auto; color: inherit; }
/* Faixa laranja (cor da logo): ⚡FULL em preto só aqui (pedido 2026-10-10). */
.pd2 .faixa .selo-full { color: #111; }
/* Faixa laranja com a mesma fonte do "⚡FULL" (itálico 800), pedido do Lira 2026-10-23. */
.pd2 .faixa > span:not(.sep) { font-style: italic; font-weight: 800; }
.pd2 .faixa .sep { opacity: .4; }

.pd2 .migalhas { font-size: 14.5px; color: var(--muted); padding: 18px 0 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pd2 .migalhas a { color: var(--muted); text-decoration: none; }
.pd2 .migalhas a:hover { text-decoration: underline; }

/* grade 60/40: o box de compra ocupa a coluna da direita, fixo */
.pd2 .col-principal { display: grid; grid-template-columns: minmax(0, 60fr) minmax(0, 40fr); gap: 20px; align-items: start; }
.pd2 .col-principal > .card { grid-column: 1; }
/* Caixa presa como na Mavo (pedido 2026-10-23): rola até o fim e trava no rodapé da tela;
   o top vem do script (ajustarTopoCompra). Abaixo de 900px vira static. */
.pd2 .col-principal > .compra { grid-column: 2; grid-row: 1 / span 7; position: sticky; box-shadow: var(--shadow-strong); }
.pd2 .galeria-card { box-shadow: var(--shadow-strong); padding: 10px; }

/* galeria: miniaturas à esquerda (20%), foto à direita (80%) */
.pd2 .galeria { display: flex; flex-direction: row-reverse; gap: 20px; }
.pd2 .foto { position: relative; flex: 0 0 calc(80% - 10px); aspect-ratio: 1; border-radius: 14px; overflow: hidden; background: var(--card); cursor: zoom-in; user-select: none; }
.pd2 .galeria.sem-miniaturas .foto { flex-basis: 100%; }
.pd2 .foto img, .pd2 .foto video { width: 100%; height: 100%; object-fit: contain; }
.pd2 .foto .vazio { height: 100%; display: grid; place-items: center; font-size: 56px; color: var(--muted); opacity: .4; }
.pd2 .miniaturas { flex: 1; display: flex; flex-direction: column; gap: 20px; max-height: 640px; overflow-y: auto; scrollbar-width: thin; }
.pd2 .miniaturas button { position: relative; display: block; width: 100%; flex: 0 0 auto; padding: 0; border: 2px solid transparent; border-radius: 10px; overflow: hidden; background: none; cursor: pointer; aspect-ratio: 1; transition: border-color .2s; }
.pd2 .miniaturas button:hover { border-color: var(--line); }
.pd2 .miniaturas button[aria-current=true] { border-color: var(--yellow); }
/* Foto da variação escolhida: borda azul, sem sair do lugar na galeria. */
.pd2 .miniaturas button.da-variacao { border-color: var(--blue); }
.pd2 .miniaturas button.da-variacao[aria-current=true] { box-shadow: 0 0 0 2px var(--yellow); }
/* Foto e vídeo presos no quadrado da miniatura (o <video> tem altura própria
   e ficava maior que as fotos). */
.pd2 .miniaturas img, .pd2 .miniaturas video { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; border-radius: 0; background: #000; }
.pd2 .miniaturas img { background: none; }
.pd2 .play { position: absolute; inset: 0; display: grid; place-items: center; background: rgba(0, 0, 0, .35); color: #fff; font-size: 12px; pointer-events: none; }
.pd2 .play.grande { background: rgba(0, 0, 0, .18); }
.pd2 .play.grande i { width: 84px; height: 84px; border-radius: 50%; background: rgba(0, 0, 0, .6); border: 3px solid #fff; display: grid; place-items: center; font-size: 30px; padding-left: 6px; box-shadow: 0 6px 24px rgba(0, 0, 0, .35); transition: transform .2s, background .2s; }
.pd2 .capa-video { position: absolute; inset: 0; display: block; width: 100%; height: 100%; padding: 0; border: 0; background: none; cursor: pointer; }
.pd2 .capa-video:hover .play.grande i, .pd2 .capa-video:focus-visible .play.grande i { transform: scale(1.08); background: rgba(0, 0, 0, .75); }
.pd2 .seta { position: absolute; top: 50%; transform: translateY(-50%); width: 38px; height: 38px; border: 0; border-radius: 50%; background: rgba(0, 0, 0, .3); color: #fff; cursor: pointer; }
.pd2 .seta.esq { left: 10px; }
.pd2 .seta.dir { right: 10px; }

/* box de compra */
.pd2 .compra { padding: 20px 20px 24px; }
.pd2 .titulo { display: flex; gap: 12px; align-items: flex-start; justify-content: space-between; }
.pd2 .compra h1 { font-size: 26px; font-weight: 500; text-transform: capitalize; }
.pd2 .fav { flex: 0 0 auto; width: 40px; height: 40px; border-radius: 50%; border: 1px solid var(--line); background: var(--card); display: grid; place-items: center; color: var(--muted); cursor: pointer; text-decoration: none; }
.pd2 .fav.favorito-ativo { background: #f2540d; border-color: #f2540d; color: #fff; }
.pd2 .nota { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 12px 0 4px; font-size: 16px; font-weight: 500; color: var(--ink); line-height: 1.7; }
.pd2 .nota a { text-decoration: none; }
.pd2 .condicao { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; margin: 0 0 4px; font-size: 14px; color: var(--text); }
.pd2 .condicao + .nota { margin: 4px 0 8px; }
/* Tags copiadas da Mavo no ar (2026-10-23): mesmas cores (#fff600/#000), 3px 5px, cantos de 3px, 13px e 10px.
   POR TEMPO LIMITADO! = degradê amarelo que corre em 3s sem parar (featureAnimation da Mavo). */
.pd2 .tag-novo, .pd2 .tag-exclusivo, .pd2 .tag-tempo { display: inline-block; padding: 3px 5px; border-radius: 3px; font-size: 10px; font-weight: 400; line-height: 1.3; white-space: nowrap; }
.pd2 .tag-novo { background: #fff600; color: #000; font-size: 13px; }
.pd2 .tag-exclusivo { background: #000; color: #fff600; }
.pd2 .tag-tempo { color: #000; background: linear-gradient(90deg, #fff600 0, #dca201 50%, #fef777 100%); background-size: 300% 100%; animation: tag-tempo-corre 3s linear infinite; }
@keyframes tag-tempo-corre { 0% { background-position: 0 center; } 50% { background-position: 150% center; } 100% { background-position: 300% center; } }
/* "Só N unidades!" no formato da Mavo com as cores da loja (azul e marinho). */
.pd2 .estoque-baixo { margin: 12px 0 0; }
.pd2 .estoque-baixo p { margin: 0 0 6px; font-size: 15px; color: var(--navy); }
.pd2 .estoque-baixo b { color: var(--blue); font-weight: 700; }
.pd2 .estoque-baixo i { color: #f97316; }
.dark .pd2 .estoque-baixo p { color: var(--text); }
.pd2 .estoque-barra { height: 8px; border-radius: 9999px; background: #dbe4f7; overflow: hidden; }
.dark .pd2 .estoque-barra { background: rgba(255, 255, 255, .15); }
/* Barra abre cheia, recolhe até o número (1,2 s) e depois perde uma unidade; as listras correm por cima. */
.pd2 .estoque-barra span { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, var(--navy), var(--blue)); position: relative; overflow: hidden; transition: width 1.2s ease-in-out; }
.pd2 .estoque-barra span::after { content: ''; position: absolute; inset: 0; background: repeating-linear-gradient(45deg, rgba(255, 255, 255, .28) 0 8px, transparent 8px 16px); background-size: 22.6px 100%; animation: estoque-listras 1s linear infinite; }
@keyframes estoque-listras { to { background-position: 22.6px 0; } }
.dark .pd2 .estoque-barra span { background: var(--blue); }
.pd2 .nota .media { font-size: 15px; }
.pd2 .estrelas { color: var(--star); font-size: 15px; letter-spacing: 1px; white-space: nowrap; }
.pd2 .checks { list-style: none; margin: 14px 0 0; padding: 0; font-size: 16px; color: var(--text); }
/* Check sempre verde (o emoji ✔️ mudava de cor conforme o aparelho) — pedido 2026-10-10. */
.pd2 .checks li i { color: #00a650; margin-right: 6px; font-size: 1.1em; -webkit-text-stroke: 0.8px #00a650; }
.pd2 .divisor { border: 0; border-top: 1px solid #777; margin: 16px 0; opacity: .6; }

/* Preço em largura cheia (pedido 2026-10-23): riscado + −N%, preço, Pix, parcela. */
.pd2 .preco { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; margin-bottom: 15px; }
.pd2 .a-partir { font-size: 14px; color: #575757; }
.pd2 .linha-de { display: flex; align-items: center; gap: 8px; }
.pd2 .de { color: #8a8a8a; font-size: 16px; font-weight: 400; text-decoration: line-through; }
.pd2 .selo-off { display: inline-block; background: #c2410c; color: #fff; border-radius: 999px; padding: 2px 8px; font-size: 12px; font-weight: 700; line-height: 1.4; }
.pd2 .por { color: var(--green); font-size: 36px; font-weight: 700; line-height: 1.1; font-variant-numeric: tabular-nums; }
.pd2 .linha-pix { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; color: #00851c; }
.pd2 .linha-pix i { font-size: 18px; width: 18px; color: #00851c; }
.pd2 .valor-pix { font-size: 20px; font-weight: 700; line-height: 1.2; font-variant-numeric: tabular-nums; }
.pd2 .selo-pix { display: inline-block; background: #00851c; color: #fff; border-radius: 999px; padding: 2px 8px; font-size: 12px; font-weight: 700; line-height: 1.4; }
.pd2 .nota-pix { margin: -4px 0 0; font-size: 12px; color: #575757; }
.dark .pd2 .linha-pix, .dark .pd2 .linha-pix i { color: var(--green); }
.dark .pd2 .nota-pix, .dark .pd2 .a-partir { color: var(--text); }
.pd2 .parc { color: #18212e; font-size: 16px; }
.pd2 .parc strong { font-weight: 700; }
.dark .pd2 .parc { color: var(--ink); }

.pd2 .subtitulo { display: block; font-size: 16px; font-weight: 600; color: var(--ink); margin-bottom: 10px; }
.pd2 .variacoes, .pd2 .leve-mais { margin: 6px 0 16px; }
.pd2 .opcoes { display: flex; flex-wrap: wrap; gap: 8px; }
.pd2 .opcao { display: inline-flex; align-items: center; gap: 8px; border: 1px solid #b9b9b9; border-radius: 10px; padding: 6px 12px; font: inherit; font-size: 14px; text-align: left; text-decoration: none; color: var(--ink); background: #fff; cursor: pointer; }
.pd2 .opcao img { width: 34px; height: 34px; border-radius: 2px; object-fit: cover; }
.pd2 .opcao:hover { border-color: var(--ink); }
.pd2 .opcao.atual { border: 2px solid var(--yellow); font-weight: 600; }
.pd2 span.opcao.atual { cursor: default; }
.pd2 .opcao.esgotada { opacity: .5; text-decoration: line-through; }
/* Variação obrigatória (pedido 2026-10-15): alerta vermelho ao lado do título. */
.pd2 .alerta-variacao { display: inline-flex; align-items: center; gap: 6px; margin-left: 10px; padding: 3px 10px; border-radius: 999px; background: #dc2626; color: #fff; font-size: 13px; font-weight: 600; vertical-align: middle; animation: pd2-tremer .35s; }
.pd2 .variacoes.com-alerta .opcao { border-color: #dc2626; }
.pd2 .acao.sem-variacao .btn, .pd2 .acao.sem-variacao .qtd button[aria-label="Aumentar quantidade"] { opacity: .5; }
@keyframes pd2-tremer { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-4px); } 75% { transform: translateX(4px); } }
.pd2 .leve-mais ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; font-size: 14px; }
.pd2 .leve-mais .kit { width: 100%; display: flex; justify-content: space-between; gap: 10px; align-items: center; border: 1px solid #b9b9b9; border-radius: 10px; padding: 8px 12px; background: transparent; color: var(--ink); font: inherit; font-size: 14px; text-align: left; cursor: pointer; }
.pd2 .leve-mais .kit:hover { border-color: var(--ink); }
.pd2 .leve-mais .kit[aria-pressed=true] { border: 2px solid var(--yellow); padding: 7px 11px; }
.pd2 .leve-mais .kit-off { color: #00851c; font-weight: 700; }
.dark .pd2 .leve-mais .kit-off { color: var(--green); }
.pd2 .leve-mais strong { color: var(--ink); }
.pd2 .total-pix { color: #00851c; }

.pd2 .acao { display: flex; gap: 10px; margin: 6px 0 10px; }
.pd2 .qtd { display: inline-flex; align-items: center; gap: 4px; border-radius: 10px; background: var(--soft); border: 1px solid var(--line); padding: 5px; flex: 0 0 auto; }
.pd2 .qtd button { width: 34px; height: 100%; min-height: 40px; border: 0; border-radius: 10px; background: #e8e9ef; color: #000; font-weight: 700; font-size: 16px; cursor: pointer; }
.dark .pd2 .qtd button { background: var(--sunk); color: var(--ink); }
.pd2 .qtd button:hover { background: var(--soft); }
.pd2 .qtd button:disabled { opacity: .4; cursor: not-allowed; }
.pd2 .qtd input { width: 44px; border: 0; background: transparent; text-align: center; font: inherit; color: var(--ink); -moz-appearance: textfield; }
.pd2 .qtd input::-webkit-inner-spin-button, .pd2 .qtd input::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
.pd2 .btn { flex: 1; display: flex; align-items: center; justify-content: center; background: var(--green); color: #fff; font: 600 24px Poppins, sans-serif; text-transform: uppercase; border: 0; border-bottom: 2px solid var(--green-edge); border-radius: 10px; min-height: 54px; padding: 0 20px; cursor: pointer; transition: all .2s; }
.pd2 .btn:hover { background: var(--green-edge); border-color: #004f10; color: #fff; }
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
/* Bandeiras sem a sombra embutida (era a linha à direita): borda fina nos 4 lados e cantos de 4px, como no rodapé (pedido 2026-10-14). */
/* As 7 bandeiras sempre numa linha: cada uma ocupa 1/7 da largura, no máximo 67px (45px de altura). */
.pd2 .bandeiras img { width: calc((100% - 6 * 8px) / 7); max-width: 67px; height: auto; box-sizing: border-box; border: 1px solid rgba(0, 0, 0, .12); border-radius: 4px; }

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
.pd2 .fretefundo .escudo { position: relative; }
.pd2 .fretefundo .escudo .fa-check { position: absolute; font-size: 10px; color: #fff; margin-top: 1px; }
.pd2 .fretefundo .selo-full i { font-size: 0.9em; margin: 0 1px 0 0; color: inherit; text-align: left; }
.pd2 .fretefundo a { color: var(--blue); text-decoration: underline; }

.pd2 .prazo-cep { margin: 0 0 14px; background: #f8f9fb; border-radius: 14px; padding: 14px 12px; }
.pd2 .prazo-cep label { display: block; font-size: 16px; font-weight: 500; color: #111; margin-bottom: 10px; text-align: center; }
.pd2 .prazo-cep .linha { display: flex; gap: 10px; }
.pd2 .prazo-cep input { flex: 1; min-width: 0; height: 46px; border: 1px solid #cdd5e1; border-radius: 16px; padding: 0 14px; font: inherit; font-size: 16px; background: #e8f0fe; color: #111; }
.pd2 .prazo-cep button { height: 46px; padding: 0 26px; border: 0; border-radius: 16px; background: #13bce6; color: #fff; font: 600 19px Poppins, sans-serif; cursor: pointer; }
.pd2 .prazo-cep button:disabled { opacity: .6; }
.pd2 .prazo-cep p { margin: 8px 0 0; font-size: 14px; }
.pd2 .prazo-cep .expressa { display: inline-flex; align-items: center; gap: 6px; background: #e7f8ec; color: #00801a; font-weight: 700; border-radius: 8px; padding: 6px 10px; font-size: 15px; }
.dark .pd2 .prazo-cep .expressa { background: rgba(0, 171, 33, .15); color: #4ade80; }
/* "⚡ Full" na mesma cor da mensagem, só mais forte (pedido 2026-10-10). */
.pd2 .prazo-cep .expressa .full { font-weight: 800; }
.pd2 .prazo-cep .normal { color: #00801a; font-weight: 600; }
/* Rua e tabela de entrega iguais ao print do Lira (2026-10-23 21:24): rua em
   azul-claro com o pino; tabela com fundo cinza claro, bordas arredondadas,
   cabeçalhos pretos, tudo centralizado e o preço em azul-claro. */
.pd2 .prazo-cep .rua { color: #000; font-weight: 700; font-size: 16px; margin: 10px 0 0; }
.pd2 .prazo-cep .rua i { margin-right: 4px; }
.pd2 .tabela-entrega { width: 100%; margin: 10px 0 0; border-collapse: separate; border-spacing: 0; border: 1px solid #d4d7dd; border-radius: 12px; overflow: hidden; background: #fff; font-size: 15px; table-layout: fixed; }
.pd2 .tabela-entrega th { background: #f3f4f6; color: #111; font-weight: 500; padding: 11px 8px; text-align: center; }
.pd2 .tabela-entrega td { padding: 12px 8px; text-align: center; vertical-align: middle; border-top: 1px solid #d4d7dd; background: #fafafa; }
.pd2 .tabela-entrega td + td, .pd2 .tabela-entrega th + th { border-left: 1px solid #d4d7dd; }
.pd2 .tabela-entrega .nome { color: #444; font-weight: 500; }
.pd2 .tabela-entrega .quando { color: #111; font-weight: 700; }
.pd2 .tabela-entrega .valor { color: #000; font-weight: 700; white-space: nowrap; }
/* Textos que eram azuis (Full, preço e rua) em preto e negrito (pedido do Lira 2026-10-23 23h). */
.pd2 .tabela-entrega .linha-full .nome { color: #000; font-weight: 700; }
@media (max-width: 480px) { .pd2 .tabela-entrega th, .pd2 .tabela-entrega td { padding: 10px 6px; font-size: 14px; } }
.dark .pd2 .prazo-cep { background: #141a24; }
.dark .pd2 .prazo-cep label { color: #f3f4f6; }
.dark .pd2 .prazo-cep input { background: #1f2736; border-color: #3f3f46; color: #f3f4f6; }
.dark .pd2 .tabela-entrega { border-color: #3f3f46; }
.dark .pd2 .tabela-entrega th { background: #18181b; color: #f4f4f5; }
.dark .pd2 .tabela-entrega td { background: #27272a; border-color: #3f3f46; }
.dark .pd2 .tabela-entrega td + td, .dark .pd2 .tabela-entrega th + th { border-color: #3f3f46; }
.dark .pd2 .tabela-entrega .nome { color: #d4d4d8; }
.dark .pd2 .tabela-entrega .linha-full .nome, .dark .pd2 .tabela-entrega .valor, .dark .pd2 .prazo-cep .rua { color: #f4f4f5; }
.dark .pd2 .tabela-entrega .quando { color: #f4f4f5; }
.dark .pd2 .prazo-cep .normal { color: #4ade80; }
.pd2 .prazo-cep .normal .full { color: #00530f; font-weight: 800; font-style: italic; margin-right: 4px; }
.pd2 .prazo-cep .erro { color: #dc2626; }

/* descrição no visual da v1 */
.pd2 .descricao-card { position: relative; overflow: hidden; padding-top: 34px; }
.pd2 .desc-cabecalho { text-align: center; max-width: 640px; margin: 0 auto 26px; }
/* Definição fixa do Lira (2026-10-20): ícone branco no círculo laranja claro e frase em negrito no laranja
   oficial do logo da KazaKora (#F1691B). Não trocar por amarelo nem por cor do tema. */
.pd2 .desc-icone { display: inline-flex; width: 48px; height: 48px; border-radius: 50%; align-items: center; justify-content: center; font-size: 18px; background: #f59a62; color: #fff; }
.pd2 .desc-eyebrow { margin: 12px 0 6px; font-size: 13px; font-weight: 800; letter-spacing: .22em; text-transform: uppercase; color: #f1691b; }
.pd2 .desc-cabecalho h2 { margin: 0; font-size: 28px; }
.pd2 .bloco-anuncio + .bloco-anuncio { margin-top: 30px; padding-top: 26px; border-top: 1px solid var(--line); }
.pd2 .bloco-anuncio h3 { font-size: 22px; font-weight: 600; margin: 0 0 14px; }
.pd2 .bloco-anuncio p { font-size: 16px; line-height: 1.7; }
.pd2 .bloco-anuncio > div > :last-child { margin-bottom: 0; }
.pd2 .com-foto { display: grid; grid-template-columns: minmax(0, 320px) 1fr; gap: 32px; align-items: center; }
.pd2 .com-foto.foto-direita { grid-template-columns: 1fr minmax(0, 320px); }
.pd2 .com-foto.foto-direita .foto-desc { order: 2; }
.pd2 .foto-desc { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 12px; background: #fff; border: 1px solid var(--line); }
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
.pd2 .cta-anuncio { margin-top: 28px; padding: 22px 20px; border-radius: 14px; background: var(--soft); border: 1px solid var(--line); text-align: center; }
.pd2 .cta-anuncio h3 { font-size: 22px; font-weight: 700; margin: 0 0 10px; }
.pd2 .cta-anuncio p { margin: 0 auto; max-width: 620px; color: var(--ink); font-size: 16px; }
.pd2 .cta-anuncio ul { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px; margin: 16px 0 0; padding: 0; list-style: none; }
.pd2 .cta-anuncio li { padding: 6px 12px; border-radius: 999px; background: var(--card); border: 1px solid var(--line); color: var(--ink); font-size: 14px; font-weight: 500; }
.pd2 .cta-anuncio .btn { margin: 18px auto 0; max-width: 420px; width: 100%; font-size: 20px; text-transform: none; }

/* conteúdo */
.pd2 .descricao { color: #000; }
.dark .pd2 .descricao { color: var(--text); }
.pd2 .descricao h3 { font-size: 20px; font-weight: 600; margin: 0 0 10px; }
.pd2 .bloco + .bloco { margin-top: 22px; }
.pd2 .lista { margin: 0 0 14px; padding-left: 22px; list-style: disc; }
.pd2 .lista li { margin-bottom: 4px; }
.pd2 .linhas { list-style: none; margin: 0 0 14px; padding: 0; }
.pd2 .bloco > :last-child { margin-bottom: 0; }
/* banners promocionais (sorteio AirFryer e livros digitais) */
.pd2 .promo { display: flex; align-items: center; gap: 24px; }
.pd2 .promo h2 { margin-bottom: 12px; }
.pd2 .promo-texto { flex: 1 1 auto; }
.pd2 .promo-img { flex: 0 0 auto; height: auto; border-radius: 12px; }
.pd2 .promo-sorteio { width: 190px; }
.pd2 .promo-condicoes, .pd2 .promo-texto-livre { white-space: pre-line; }
.pd2 .livros-oferta .livros-condicoes { margin: 10px 0 0; font-size: 14px; color: var(--muted); }
.pd2 .promo-botao { display: inline-flex; align-items: center; gap: 8px; margin-top: 4px; padding: 10px 18px; border-radius: 999px; background: var(--green); color: #fff; font-size: 14px; font-weight: 700; text-decoration: none; }
.pd2 .promo-botao:hover { background: var(--green-edge); color: #fff; }
.pd2 .promo-botao:focus-visible { outline: 2px solid var(--green); outline-offset: 3px; }
.pd2 .livros-botao { margin-top: 14px; }
.pd2 .promo-livros { display: grid; grid-template-columns: minmax(0, 1fr) 240px; gap: 32px; align-items: center; }
.pd2 .promo-livros h2 { margin-bottom: 18px; }
.pd2 .livros-img { display: block; width: 100%; height: auto; }
.pd2 .livros-zoom { display: block; width: 100%; padding: 0; border: 0; background: none; cursor: zoom-in; line-height: 0; }
.pd2 .livros-zoom:focus-visible { outline: 2px solid var(--green); outline-offset: 4px; }
.pd2 .livros-oferta p { margin: 0 0 14px; font-size: 17px; line-height: 1.5; }
.pd2 .livros-lista { display: flex; flex-direction: column; gap: 6px; font-size: 14px !important; color: var(--ink); }
.pd2 .livros-lista i { color: var(--green); }
.pd2 .livros-oferta .livros-gratis { margin: 0; color: var(--ink); }
.pd2 .livros-gratis b { color: var(--green); font-weight: 700; white-space: nowrap; }
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
.pd2 .aval-item .selo-outra-loja { display: inline-block; margin-left: 6px; padding: 1px 8px; border-radius: 999px; background: #eef2f7; color: #475569; font-size: 11px; font-weight: 600; vertical-align: middle; }
.pd2 .aval-item p { margin: 6px 0; font-weight: 300; line-height: 1.5; }
.pd2 .aval-item .nota-data { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 2px 10px; }
.pd2 .aval-item small { color: var(--muted); font-size: 12px; white-space: nowrap; }
.pd2 .fotos-avaliacao { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 6px; }
.pd2 .video-sem-capa { display: block; }
.pd2 .fotos-avaliacao img, .pd2 .fotos-avaliacao video, .pd2 .video-sem-capa { width: 60px; height: 60px; border-radius: 6px; object-fit: cover; background: #000; }
.pd2 .foto-btn { position: relative; padding: 0; border: 0; background: none; cursor: zoom-in; line-height: 0; }
.pd2 .play-video { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; }
.pd2 .play-video i { display: flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 999px; background: rgba(0,0,0,.6); color: #fff; font-size: 10px; padding-left: 2px; box-shadow: 0 0 0 1.5px rgba(255,255,255,.9); }
.pd2 .aval-mais { margin-top: 18px; text-align: center; }
.pd2 .aval-mais .btn-sec { display: inline-flex; width: auto; min-width: 260px; padding: 0 22px; }
.pd2 .aval-mais span { opacity: .7; font-weight: 400; }
@media (max-width: 767px) { .pd2 .aval-mais .btn-sec { width: 100%; min-width: 0; } }
.aval-lightbox { position: fixed; inset: 0; z-index: 100; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,.88); padding: 48px 12px; }
.aval-lightbox img, .aval-lightbox video { max-width: min(92vw, 900px); max-height: 82vh; border-radius: 8px; object-fit: contain; }
.aval-lightbox.lb-largo img { max-width: min(96vw, 1400px); max-height: 88vh; cursor: zoom-out; }
.aval-lightbox button { position: absolute; display: flex; align-items: center; justify-content: center; width: 44px; height: 44px; border: 0; border-radius: 999px; background: rgba(255,255,255,.14); color: #fff; font-size: 18px; cursor: pointer; }
.aval-lightbox button:hover { background: rgba(255,255,255,.28); }
.aval-lightbox .lb-fechar { top: 14px; right: 14px; }
.aval-lightbox .lb-esq { left: 10px; top: 50%; transform: translateY(-50%); }
.aval-lightbox .lb-dir { right: 10px; top: 50%; transform: translateY(-50%); }
.aval-lightbox .lb-contador { position: absolute; bottom: 18px; left: 50%; transform: translateX(-50%); color: #fff; font-size: 13px; opacity: .85; }

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

/* Barra presa no rodapé: no computador entra quando o botão da caixa sai da tela,
   com o miolo na mesma largura da página (1320px). */
/* À direita sobra espaço para o botão flutuante do WhatsApp (right 24px, 56px). */
.pd2 .barra { display: flex; align-items: center; gap: 24px; position: fixed; inset: auto 0 0 0; z-index: 40; background: var(--card); padding: 12px max(104px, calc((100% - 1320px) / 2 + 24px)) 12px max(24px, calc((100% - 1320px) / 2 + 24px)); box-shadow: 0 0 10px 1px rgba(0, 0, 0, .2); transform: translateY(110%); visibility: hidden; transition: transform .25s ease, visibility .25s; }
.pd2 .barra.visivel { transform: none; visibility: visible; }
@media (min-width: 768px) {
    .pd2 .barra-preco { flex: 1 1 auto; min-width: 0; display: flex; align-items: baseline; gap: 8px; }
    .pd2 .barra-preco strong { font-size: 22px; font-weight: 700; color: #00851c; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .pd2 .barra-preco span { font-size: 14px; color: #575757; }
    .dark .pd2 .barra-preco strong { color: var(--green); }
    .dark .pd2 .barra-preco span { color: var(--text); }
    .pd2 .barra .btn { flex: 0 0 360px; min-height: 52px; border: 0; border-radius: 50px; background: #0aac48; font: 600 17px/1.2 Manrope, 'DM Sans', system-ui, sans-serif; }
    .pd2 .barra .btn:hover { background: #089a3f; }
    /* Espaço para a barra não cobrir o fim da página quando aparece. */
    .pd2 { padding-bottom: 76px; }
}

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
    /* Faixa amarela do topo numa linha só no celular (pedido 2026-10-10). */
    .pd2 .faixa { flex-wrap: nowrap; gap: 6px; padding: 8px 10px; font-size: 11px; letter-spacing: 0; white-space: nowrap; }
    .pd2 .faixa i { margin-right: 3px; }
    /* Bandeiras menores no celular: as 7 cabem numa linha (pedido 2026-10-10). */
    .pd2 .bandeiras { flex-wrap: nowrap; gap: 5px; }
    .pd2 .bandeiras img { width: calc((100% - 6 * 5px) / 7); }
    .pd2 .wrap { padding: 0 10px; }
    .pd2 .migalhas { display: none; }
    .pd2 .col-principal { padding-top: 10px; }
    .pd2 .card { padding: 20px; }
    .pd2 h2 { font-size: 24px; }
    .pd2 .desc-cabecalho h2 { font-size: 22px; }
    .pd2 .bloco-anuncio h3 { font-size: 19px; }
    .pd2 .com-foto, .pd2 .com-foto.foto-direita { grid-template-columns: 1fr; gap: 14px; }
    .pd2 .com-foto.foto-direita .foto-desc { order: 0; }
    .pd2 .foto-desc { max-width: 320px; margin: 0 auto; }
    .pd2 .compra h1 { font-size: 20px; }
    .pd2 .nota { font-size: 14px; }
    .pd2 .galeria { flex-direction: column; gap: 10px; }
    .pd2 .foto { flex-basis: auto; width: 100%; }
    .pd2 .miniaturas { flex-direction: row; gap: 8px; max-height: none; overflow-x: auto; }
    .pd2 .miniaturas button { flex: 0 0 calc(25% - 6px); }
    .pd2 .btn { font-size: 20px; }
    .pd2 .fretefundo li { font-size: 14px; }
    .pd2 .fretefundo i { color: #7b7b7b; }
    .pd2 .garantia { grid-template-columns: 30% 70%; }
    .pd2 .promo { flex-direction: column; align-items: flex-start; }
    .pd2 .promo-sorteio { width: 160px; align-self: center; }
    .pd2 .promo-livros { grid-template-columns: 1fr; gap: 20px; }
    .pd2 .aval-topo { grid-template-columns: 1fr; gap: 16px; }
    .pd2 .aval-grade { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .pd2 summary { font-size: 17px; padding: 12px; }
    .pd2 .confianca { grid-template-columns: 1fr 1fr; gap: 16px; }
    .pd2 .confianca div { flex-direction: column; align-items: center; text-align: center; gap: 6px; font-size: 14px; padding: 18px 12px; }
    .pd2 .confianca i { font-size: 40px; }
    .pd2 .confianca strong { font-size: 16px; }
    .pd2 .barra { display: flex; align-items: center; gap: 12px; position: fixed; inset: auto 0 0 0; z-index: 40; background: var(--card); padding: 10px 16px; box-shadow: 0 0 10px 1px rgba(0, 0, 0, .35); transform: none; visibility: visible; transition: none; }
    .pd2 .barra-preco { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; line-height: 1.2; }
    .pd2 .barra-preco strong { font-size: 17px; font-weight: 700; color: #00851c; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .pd2 .barra-preco span { font-size: 11px; color: #575757; }
    .dark .pd2 .barra-preco strong { color: var(--green); }
    .dark .pd2 .barra-preco span { color: var(--text); }
    .pd2 .barra .btn { flex: 0 0 60%; width: 60%; font-size: 17px; min-height: 46px; padding: 0 12px; }
    .pd2 { padding-bottom: 80px; }
}
@media (prefers-reduced-motion: reduce) {
    .pd2 * { transition: none !important; }
    .pd2 .tag-tempo, .pd2 .estoque-barra span, .pd2 .estoque-barra span::after { animation: none; }
}

/* Caixa de compra no formato da Mavo (tarefa 2656, print de 23/10 21h; medidas
   conferidas na página da Mavo no ar em 23/10 22h: Manrope, caixa com borda fina
   #f1f1f1 e cantos de 10px, preço 28px/600, botão 64px). Vem por último para valer sobre as regras
   antigas da caixa (e as do celular). */
.pd2 .compra { padding: 30px; color: #1c1c1c; font-family: Manrope, 'DM Sans', system-ui, sans-serif; border: 1px solid #f1f1f1; border-radius: 10px; box-shadow: none; }
/* Faixa "Novo | +N vendidos / EXCLUSIVO / POR TEMPO LIMITADO!" com os valores lidos da Mavo
   no ar (2026-10-23 21h45): Manrope 400, 13px #a7a5a5 no texto, tags 10px com 3px 5px. */
.pd2 .compra .condicao { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; margin: 0 0 8px; font-size: 13px; line-height: 1.4; }
.pd2 .compra .cond-texto { color: #a7a5a5; font-weight: 400; margin-right: 2px; }
.pd2 .compra .tag-exclusivo, .pd2 .compra .tag-tempo { padding: 3px 5px; border-radius: 3px; font-size: 10px; font-weight: 400; line-height: 1.2; letter-spacing: -.1px; }
.pd2 .compra .tag-exclusivo { background: #000; color: #fff600; }
.pd2 .compra h1 { font-size: 20px; font-weight: 700; line-height: 1.45; color: #1c1c1c; text-transform: none; letter-spacing: -.1px; margin: 0; }
.pd2 .compra .nota { display: inline-flex; flex-wrap: wrap; align-items: center; gap: 0 8px; margin: 12px 0 0; font-size: 15px; font-weight: 400; color: #555; line-height: 1.4; text-decoration: none; }
.pd2 .compra .nota .estrelas { color: #fcbd10; font-size: 16px; letter-spacing: 2px; }
.pd2 .compra .nota .media { font-size: 15px; font-weight: 400; color: #555; }
.pd2 .compra .divisor { border: 0; border-top: 1px solid #e9e9e9; opacity: 1; margin: 14px 0 18px; }
.pd2 .compra .preco { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; margin: 0 0 28px; }
.pd2 .compra .a-partir { font-size: 13px; color: #777; }
.pd2 .compra .linha-por { display: flex; align-items: baseline; flex-wrap: wrap; gap: 0 10px; }
.pd2 .compra .por { color: #000; font-size: 28px; font-weight: 600; line-height: 1.15; letter-spacing: 0; }
.pd2 .compra .de { color: #9a9a9a; font-size: 15px; font-weight: 400; line-height: 1.2; text-decoration: line-through; }
.pd2 .compra .parc { display: flex; align-items: center; gap: 4px; color: #1c1c1c; font-size: 15px; line-height: 1.35; }
.pd2 .compra .parc i { color: #1c1c1c; font-size: 14px; margin: 0; }
.pd2 .compra .parc strong { color: #0aac48; font-weight: 700; }
.pd2 .compra .linha-pix { display: flex; align-items: center; gap: 6px; margin-top: 4px; color: #1c1c1c; font-size: 15px; font-weight: 700; line-height: 1.35; }
.pd2 .compra .linha-pix i { color: #0aac48; font-size: 17px; width: 18px; }
.pd2 .compra .linha-pix b { color: #0aac48; font-weight: 600; }
.pd2 .compra .variacoes, .pd2 .compra .leve-mais { margin: 0 0 22px; }
.pd2 .compra .subtitulo { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; font-size: 14px; font-weight: 400; color: #555; margin: 0 0 12px; }
.pd2 .compra .valor-escolhido { color: #555; }
.pd2 .compra .opcoes { gap: 10px; }
.pd2 .compra .opcao { min-height: 44px; padding: 0 18px; border: 1px solid #d4d4d4; border-radius: 999px; background: #fff; color: #333; font-size: 15px; font-weight: 400; }
.pd2 .compra .opcao:hover { border-color: #888; }
.pd2 .compra .opcao.atual { border: 2px solid #2b2b2b; background: #f2f2f2; font-weight: 400; padding: 0 17px; }
.pd2 .compra .variacoes.com-alerta .opcao { border-color: #dc2626; }
.pd2 .compra .estoque-baixo { margin: 34px 0 10px; }
.pd2 .compra .estoque-baixo p { margin: 0 0 6px; text-align: center; font-size: 13.5px; color: #111; line-height: 1.4; }
.pd2 .compra .estoque-baixo b { color: #111; font-weight: 700; }
.pd2 .compra .estoque-baixo p { font-weight: 700; }
/* Número em preto; ao cair uma unidade, fundo vermelho com fonte branca que some em 1 s e deixa a fonte vermelha. */
.pd2 .compra .estoque-baixo .num { display: inline-block; padding: 0 3px; border-radius: 4px; color: inherit; }
.pd2 .compra .estoque-baixo .num.fundo { animation: estoque-fundo 1s ease-in; }
@keyframes estoque-fundo { from { background-color: #e11d2a; color: #fff; } to { background-color: transparent; color: #e11d2a; } }
.pd2 .compra .estoque-baixo .num.vermelho { color: #e11d2a; }
.pd2 .compra .estoque-barra { height: 9px; border-radius: 999px; background: #dedede; }
.pd2 .compra .estoque-barra span { background: #000; }
.pd2 .compra .estoque-barra span::after { background: repeating-linear-gradient(45deg, rgba(255, 255, 255, .1) 0 8px, transparent 8px 16px); background-size: 22.6px 100%; }
.pd2 .compra .acao { margin: 0; }
/* Preso no rodapé da tela enquanto a caixa rola; encaixa no fim do card, depois do frete/prazo.
   No celular a barra fixa já faz esse papel. */
@media (min-width: 768px) {
    .pd2 .compra .acao { position: sticky; bottom: 0; z-index: 2; margin: 0 0 -12px; padding: 12px 0; background: var(--card); }
}
.pd2 .compra .comprar-agora { width: 100%; min-height: 64px; padding: 0 14px; border: 0; border-radius: 50px; background: #0aac48; color: #fff; font: 600 17px/1.2 Manrope, 'DM Sans', system-ui, sans-serif; letter-spacing: 0; text-transform: uppercase; }
.pd2 .compra .comprar-agora:hover { background: #089a3f; }
.pd2 .compra .acao.sem-variacao .comprar-agora { opacity: .55; }
.pd2 .compra .total { margin: 10px 0 0; text-align: center; }
.pd2 .compra .pagamento-seguro { margin: 18px 0 10px; border: 1px solid #f6f6f8; border-radius: 6px; padding: 2px 2px 10px; background: #fff; }
.pd2 .compra .ps-topo { display: flex; align-items: center; justify-content: center; gap: 6px; min-height: 30px; border-radius: 6px; background: #f6f6f8; color: #565656; font-size: 12px; font-weight: 700; letter-spacing: 0; }
.pd2 .compra .ps-topo i { font-size: 15px; color: #333; }
.pd2 .compra .pagamento-seguro .bandeiras { display: flex; flex-wrap: nowrap; justify-content: center; gap: 4px; margin: 10px 0 0; }
.pd2 .compra .pagamento-seguro .bandeiras img { width: 38px; max-width: calc((100% - 7 * 4px) / 8); height: auto; padding: 0; background: #fff; border: 1px solid #e6e6e6; border-radius: 3px; box-sizing: border-box; overflow: hidden; }
.pd2 .compra .checks { margin: 10px 0 0; font-size: 14px; line-height: 1.6; }
/* Selos em caixinhas separadas, como "Frete Grátis" e "Devoluções Gratuitas" da Mavo:
   borda #f5f5f5, cantos de 6px, título 13px em negrito preto e texto 12px. */
.pd2 .compra .fretefundo { display: flex; flex-direction: column; gap: 10px; margin: 0 0 10px; padding: 0; border: 0; border-radius: 0; background: none; }
.pd2 .compra .fretefundo li { grid-template-columns: 38px 1fr; gap: 9px; padding: 10px 10px 10px 8px; border: 1px solid #f5f5f5; border-radius: 6px; background: #fff; font-size: 12px; line-height: 1.4; color: #1c1c1c; }
.pd2 .compra .fretefundo li + li { border-top: 1px solid #f5f5f5; }
.pd2 .compra .fretefundo b { color: #1c1c1c; font-size: 13px; font-weight: 600; }
.pd2 .compra .fretefundo .selo-img { width: 38px; height: 38px; }
.pd2 .compra .fretefundo .selo-icone { width: 38px; height: 30px; border-radius: 6px; background: #0d1b3e; }
.pd2 .compra .fretefundo .selo-icone i { color: #fff; font-size: 17px; }
.pd2 .compra .fretefundo .escudo { width: 32px; height: 32px; margin-left: 3px; border: 1.5px solid #0aac48; border-radius: 50%; background: #fff; }
.pd2 .compra .fretefundo .escudo i { color: #0aac48; font-size: 16px; }
.pd2 .compra .fretefundo .escudo .fa-check { color: #fff; font-size: 7px; }
.pd2 .compra .selos-compra { list-style: none; margin: 0 0 10px; padding: 4px 14px; border: 1px solid #ebebeb; border-radius: 8px; background: #f9f9f9; }
.pd2 .compra .selos-compra li { display: grid; grid-template-columns: 40px 1fr; gap: 12px; align-items: center; padding: 12px 0; font-size: 14px; line-height: 1.5; color: #545454; }
.pd2 .compra .selos-compra li + li { border-top: 1px solid #e6e6e6; }
.pd2 .compra .selos-compra img { width: 40px; height: 40px; object-fit: contain; }
.pd2 .compra .selos-compra b { color: #1846c7; font-weight: 700; }
.pd2 .compra .selos-compra .selo-full { color: #0aac48; font-style: italic; font-weight: 800; }
.pd2 .compra .selos-compra .selo-full i { margin: 0 2px 0 0; font-size: .95em; color: inherit; }
.dark .pd2 .compra .selos-compra { background: var(--card); border-color: var(--line); }
.dark .pd2 .compra .selos-compra li { color: var(--text); }
.dark .pd2 .compra .selos-compra li + li { border-color: var(--line); }
.dark .pd2 .compra .selos-compra b { color: var(--blue); }
.pd2 .compra .prazo-cep { margin: 0 0 10px; padding: 14px 10px; border: 1px solid #f5f5f5; border-radius: 6px; background: #fff; }
.pd2 .compra .prazo-cep label { font-size: 14px; font-weight: 600; color: #1c1c1c; }
.pd2 .compra .prazo-cep input { height: 40px; border: 1px solid #e3e3e3; border-radius: 50px; background: #fff; padding: 0 16px; font-size: 15px; }
.pd2 .compra .prazo-cep button { height: 40px; padding: 0 20px; border-radius: 50px; background: #00c3ee; font: 600 17px Manrope, 'DM Sans', sans-serif; }
/* Selo de desconto no canto da foto (pedido 2026-10-23): "-9%" quando o preço caiu. */
.pd2 .selo-desconto { position: absolute; top: 12px; left: 12px; z-index: 2; padding: 5px 10px; border-radius: 50px; background: #e11d2a; color: #fff; font: 700 15px/1 Manrope, 'DM Sans', sans-serif; letter-spacing: .2px; box-shadow: 0 2px 6px rgba(225, 29, 42, .3); pointer-events: none; }
.pd2 .fav-foto { position: absolute; top: 10px; right: 10px; z-index: 2; width: 40px; height: 40px; box-shadow: 0 1px 4px rgba(0, 0, 0, .12); }
.dark .pd2 .compra, .dark .pd2 .compra h1, .dark .pd2 .compra .por, .dark .pd2 .compra .parc, .dark .pd2 .compra .parc i, .dark .pd2 .compra .linha-pix,
.dark .pd2 .compra .estoque-baixo p, .dark .pd2 .compra .estoque-baixo b, .dark .pd2 .compra .ps-topo i { color: var(--ink); }
.dark .pd2 .compra .nota, .dark .pd2 .compra .nota .media, .dark .pd2 .compra .subtitulo, .dark .pd2 .compra .valor-escolhido, .dark .pd2 .compra .ps-topo { color: var(--text); }
.dark .pd2 .compra .divisor { border-top-color: var(--line); }
.dark .pd2 .compra .opcao { background: var(--card); color: var(--ink); border-color: var(--line); }
.dark .pd2 .compra .opcao.atual { background: var(--sunk); border-color: var(--ink); }
.dark .pd2 .compra .estoque-barra { background: rgba(255, 255, 255, .15); }
.dark .pd2 .compra .estoque-barra span { background: #e5e7eb; }
.dark .pd2 .compra .pagamento-seguro { background: var(--card); border-color: var(--line); }
.dark .pd2 .compra .ps-topo { background: var(--sunk); }
.dark .pd2 .compra { border-color: var(--line); }
.dark .pd2 .compra .fretefundo li, .dark .pd2 .compra .prazo-cep { background: var(--card); border-color: var(--line); color: var(--text); }
.dark .pd2 .compra .fretefundo b, .dark .pd2 .compra .prazo-cep label { color: var(--ink); }
.dark .pd2 .compra .prazo-cep input { background: var(--sunk); border-color: var(--line); }
@media (max-width: 767px) {
    .pd2 .compra { padding: 18px 18px 20px; }
    .pd2 .compra h1 { font-size: 19px; }
    /* "Só N unidades! Frete grátis + desconto no checkout." numa linha, como no print. */
    .pd2 .compra .estoque-baixo p { font-size: clamp(11px, 3.1vw, 13.5px); }
}
</style>
