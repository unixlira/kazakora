<script>
// Compartilhado por todos os cards (PageSpeed, 2026-10-21): títulos já medidos
// e a fila de medidas, que roda em partes de ~12 ms para não travar a página.
const titulosMedidos = new Map();
const filaMedidas = [];
let filaAgendada = false;
const rodarFila = () => {
    const inicio = performance.now();
    while (filaMedidas.length && performance.now() - inicio < 12) filaMedidas.shift()();
    if (filaMedidas.length) setTimeout(rodarFila, 0);
    else filaAgendada = false;
};
// A loja carrega o CSS sem travar a página (app.blade.php): medir antes dele
// chegar dá a altura errada, então a fila só começa com o CSS aplicado.
const cssPronto = typeof document === 'undefined'
    ? Promise.resolve()
    : Promise.all([...document.querySelectorAll('link[data-adiado]')]
        .filter((link) => !link.sheet)
        .map((link) => new Promise((pronto) => {
            link.addEventListener('load', pronto, { once: true });
            link.addEventListener('error', pronto, { once: true });
        })));
const medirNaFila = (medir) => {
    filaMedidas.push(medir);
    if (!filaAgendada) {
        filaAgendada = true;
        cssPronto.then(() => setTimeout(rodarFila, 0));
    }
};
// Fonte da loja chega sem travar a página (app.blade.php): quando uma fonte
// termina de baixar, os cards na tela medem de novo com ela.
const cardsNaTela = new Set();
let geracaoFontes = 0;
if (typeof document !== 'undefined' && document.fonts?.addEventListener) {
    // Várias fontes chegam juntas (texto e ícones): remede uma vez só no fim.
    let espera = null;
    document.fonts.addEventListener('loadingdone', () => {
        clearTimeout(espera);
        espera = setTimeout(() => {
            geracaoFontes += 1;
            cardsNaTela.forEach((medir) => medirNaFila(medir));
        }, 150);
    });
}
</script>

<script setup>
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import Modal from '@/Shared/Modal.vue';
import { cardImageUrl, formatPrice, primaryImage, specLine, toggleFavorite } from '@/Shared/productCard';
import { ehFavorito } from '@/Shared/favoritos';
import { parcelaMaxima } from '@/Shared/parcelas';
import { imagensLiberadas } from '@/Shared/imagensDepoisDoTopo';

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
    // Card deitado (pedido 2026-10-24, Utilidades para casa no celular): foto à
    // esquerda; nome, estrelas, preço e parcela à direita; "X% OFF" na foto.
    horizontal: {
        type: Boolean,
        default: false,
    },
    // Card de kit da página de brinquedo (pedido do Lira 24/11/2026, "os cards das 3 ofertas
    // devem ser os cards do 'você também pode gostar'"): mesmo card, com o nome do kit, o selo
    // (Para começar / Recomendado / Mais completo) e o clique escolhendo o kit na página em vez
    // de abrir outro produto. Fora da página de brinquedo nada disso é passado e o card não muda.
    nome: { type: String, default: null },
    selo: { type: Object, default: null },
    escolher: { type: Boolean, default: false },
    selecionado: { type: Boolean, default: false },
});
const emit = defineEmits(['escolher']);
const nomeDoCard = computed(() => String(props.nome || props.product.name || '').trim());

// Sem tabela do Mercado Pago, a parcela não mostra valor.
const PARCELAS = 12;


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

// Esqueleto da foto (pedido 2026-10-13): até a foto principal baixar, o
// quadrado mostra um bloco com brilho (o mesmo "osso" de CarregandoProdutos)
// em vez de ficar em branco; a foto entra com fade. Erro também tira o osso.
const fotoEl = ref(null);
const fotoCarregada = ref(false);

const showSecondImage = computed(() => supportsHover && hasHovered.value && !!secondImage.value);

// Só esconde a imagem principal quando a segunda já carregou — senão o
// primeiro hover piscaria o fundo vazio enquanto a segunda baixa.
const secondImageVisible = computed(() => isHovering.value && secondImageLoaded.value);

// Navegação rápida (pedido 2026-10-10): ao passar o mouse ou encostar o dedo
// no card, a página do produto já é buscada; o clique abre na hora.
const prefetchProduct = () => props.escolher || router.prefetch(`/produtos/${props.product.slug}`, { method: 'get' }, { cacheFor: '1m' });

const onPointerEnter = () => {
    isHovering.value = true;

    if (supportsHover) {
        hasHovered.value = true;
    }
};

const goToProduct = () => router.visit(`/produtos/${props.product.slug}`);

// Card inteiro clicável (pedido 2026-10-23): clicar em qualquer parte abre o
// produto. Botões e links de dentro (carrinho, Avaliar, Detalhes) fazem a
// própria ação. Ctrl/Cmd+clique abre em outra aba, como num link.
const clicarCard = (evento) => {
    if (props.escolher) {
        if (!evento.target.closest('a, button, input, textarea, select')) emit('escolher');
        return;
    }
    if (evento.target.closest('a, button, input, textarea, select')) return;
    if (window.getSelection?.().toString()) return;
    if (evento.ctrlKey || evento.metaKey) {
        window.open(`/produtos/${props.product.slug}`, '_blank', 'noopener');
        return;
    }
    goToProduct();
};

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

// Média das avaliações (0 = sem avaliação, aí não mostra estrela nenhuma).
const nota = computed(() => Number(props.product.reviews_avg_rating ?? 0));
const notaTexto = computed(() => nota.value.toFixed(1).replace('.', ','));
const totalAvaliacoes = computed(() => Number(props.product.reviews_count ?? 0));
const estrelaClasse = (n) => (nota.value >= n ? 'fas fa-star' : nota.value >= n - 0.5 ? 'fas fa-star-half-stroke' : 'far fa-star');

// Nome do produto (pedido 2026-10-10): 2 linhas no celular e 3 no computador.
// Se não couber, mede de verdade e corta na última palavra que deixa espaço
// para "… Ver mais" terminar exatamente no fim da última linha.
const tituloEl = ref(null);
const medidorEl = ref(null);
const titulo = ref({ texto: nomeDoCard.value, cortado: false });
const SELINHO = '<span class="titulo-ver-mais">Ver mais</span>';
const escapar = (texto) => texto.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

// Desempenho (PageSpeed, 2026-10-21): cada medida força o navegador a recalcular
// a página. O resultado fica guardado por nome + largura + fonte (o mesmo produto
// aparece em várias vitrines) e cada card mede uma vez só na montagem.
// `titulosMedidos` é de todos os cards (bloco <script> no fim do arquivo).
let larguraMedida = 0;
let alturaMedida = 0;

const ajustarTitulo = () => {
    const alvo = tituloEl.value;
    const medidor = medidorEl.value;
    if (!alvo || !medidor) return;
    const nome = nomeDoCard.value;
    larguraMedida = alvo.clientWidth;
    alturaMedida = alvo.clientHeight;
    const limite = alturaMedida + 1;
    const chave = `${larguraMedida}|${limite}|${geracaoFontes}|${nome}`;
    const guardado = titulosMedidos.get(chave);
    if (guardado) {
        titulo.value = guardado;
        return;
    }
    medidor.style.width = `${larguraMedida}px`;
    const cabe = (html) => { medidor.innerHTML = html; return medidor.scrollHeight <= limite; };

    if (cabe(escapar(nome))) {
        titulo.value = { texto: nome, cortado: false };
        titulosMedidos.set(chave, titulo.value);
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
    titulosMedidos.set(chave, titulo.value);
};

let observador = null;
let montado = true;
const medirSeMontado = () => montado && ajustarTitulo();
onMounted(() => {
    // Foto que já estava no cache do navegador pode terminar antes do @load.
    // Sem src (antes de imagensLiberadas) o `complete` também é true: confere o src.
    if (fotoEl.value?.complete && fotoEl.value.currentSrc) fotoCarregada.value = true;
    // Vários cards montam juntos: a medida entra numa fila que roda em partes,
    // sem travar a página (antes eram ~400 medidas seguidas na home).
    nextTick(() => medirNaFila(medirSeMontado));
    cardsNaTela.add(medirSeMontado);
    if (window.ResizeObserver && tituloEl.value) {
        // Só remede quando o tamanho muda de verdade (o aviso inicial do
        // observador tem o mesmo tamanho da medida da montagem).
        observador = new ResizeObserver(([entrada]) => {
            const largura = Math.round(entrada.contentRect.width);
            const altura = Math.round(entrada.contentRect.height);
            if (larguraMedida && (Math.abs(largura - larguraMedida) > 1 || Math.abs(altura - alturaMedida) > 1)) medirNaFila(medirSeMontado);
        });
        observador.observe(tituloEl.value);
    }
});
onBeforeUnmount(() => {
    montado = false;
    cardsNaTela.delete(medirSeMontado);
    observador?.disconnect();
});

// Preço do card: o "antes" (riscado) e o % de desconto em cima dele.
// Anúncio com variação mostra o da variação mais barata (pedido 2026-10-16).
// Kit: o preço do próprio kit (preco_vitrine seria o da variação mais barata do grupo).
const preco = computed(() => (props.escolher ? props.product : (props.product.preco_vitrine ?? props.product)));
// Black Friday automática (pedido do Lira 2026-11-24): o servidor manda pronto o preço com o
// cupom ({ de, por, pix, code }, BlackFriday::precos, a mesma conta do checkout). Riscado = preço
// sem o cupom; grande = com o cupom; embaixo, no Pix. Null fora da BF: o card de sempre.
const cupomBf = computed(() => preco.value.preco_cupom ?? null);
const bfNoAr = computed(() => !!inertia.props.blackfriday);
// principal 'venda' (Compressor 4 em 1, 26/11/2026): grande = preço de venda, riscado = "de" do cadastro.
const cupomSecundario = computed(() => cupomBf.value?.principal === 'venda');
const precoVenda = computed(() => Number(cupomBf.value && !cupomSecundario.value ? cupomBf.value.por : preco.value.final_price));
const precoAntes = computed(() => {
    if (cupomSecundario.value) return cupomBf.value.antes ? Number(cupomBf.value.antes) : null;
    if (cupomBf.value) return Number(cupomBf.value.de);
    // Oferta Relâmpago (2026-10-14) risca o preço sem ela, como a Oferta do Dia.
    if ((preco.value.oferta_do_dia || preco.value.oferta_relampago) && preco.value.preco_sem_oferta) return Number(preco.value.preco_sem_oferta);
    return preco.value.has_discount ? Number(preco.value.preco_de ?? preco.value.price) : null;
});
const descontoPct = computed(() => (precoAntes.value
    ? Math.max(1, Math.round((1 - precoVenda.value / precoAntes.value) * 100))
    : 0));
const precoPartes = computed(() => {
    const [inteiro, centavos] = precoVenda.value.toFixed(2).split('.');
    return { inteiro: Number(inteiro).toLocaleString('pt-BR'), centavos };
});
// Parcela real, com os juros do Mercado Pago (pedido do Lira 2026-11-02).
const inertia = usePage();
const tabelaParcelas = computed(() => inertia.props.parcelamento);
const parcela = computed(() => parcelaMaxima(cupomBf.value ? Number(cupomBf.value.por) : precoVenda.value, tabelaParcelas.value));
</script>

<template>
    <!-- card-produto + font-store: o card é igual em toda vitrine, mesmo dentro de página
         com estilo próprio (página do produto, pedido 2026-10-22). -->
    <article class="card-produto group flex cursor-pointer font-store text-store-fg overflow-hidden border border-store-border bg-store-bg-raised shadow-[0_10px_28px_rgba(43,22,65,0.06)] transition hover:-translate-y-0.5 hover:border-store-border-strong hover:shadow-[0_18px_44px_rgba(43,22,65,0.12)]"
        :class="[horizontal ? 'card-horizontal flex-row items-center rounded-xl' : 'h-full flex-col rounded-[1.15rem]', { 'card-kit-selecionado': selecionado }]"
        @mouseenter="prefetchProduct" @touchstart.passive="prefetchProduct" @click="clicarCard">
        <div class="relative aspect-square bg-white"
            :class="{ 'm-2 w-[120px] shrink-0 overflow-hidden rounded-lg': horizontal }"
            @mouseenter="onPointerEnter" @mouseleave="isHovering = false">
            <template v-if="primaryImage(product)">
                <div v-if="!fotoCarregada" class="osso-foto absolute inset-0" aria-hidden="true"></div>
                <img ref="fotoEl" :src="imagensLiberadas ? primaryImage(product) : undefined" :alt="nomeDoCard" loading="lazy" decoding="async"
                    class="absolute inset-0 h-full w-full object-cover transition-opacity duration-500"
                    :class="secondImageVisible || !fotoCarregada ? 'opacity-0' : 'opacity-100'"
                    @load="fotoCarregada = true" @error="fotoCarregada = true">
                <img v-if="showSecondImage" :src="secondImage" :alt="nomeDoCard" decoding="async"
                    class="absolute inset-0 h-full w-full object-cover transition-opacity duration-500"
                    :class="secondImageVisible ? 'opacity-100' : 'opacity-0'"
                    @load="secondImageLoaded = true">
            </template>
            <div v-else class="flex h-full w-full items-center justify-center">
                <i class="fas fa-box-open text-4xl text-store-accent-strong opacity-40"></i>
            </div>
            <!-- Favorito desligado (pedido do Lira 2026-10-23); para voltar, descomente.
                 Era: com ou sem login; guardado = fundo laranja com check (pedido 2026-10-15).
            <button type="button"
                class="absolute right-2.5 top-2.5 flex h-8 w-8 items-center justify-center rounded-full shadow transition-colors"
                :class="ehFavorito(product.id) ? 'favorito-ativo' : 'bg-store-bg-raised'"
                :aria-pressed="ehFavorito(product.id)" :aria-label="ehFavorito(product.id) ? 'Tirar dos favoritos' : 'Adicionar aos favoritos'"
                @click.stop="toggleFavorite(product.id)">
                <i class="text-sm" :class="ehFavorito(product.id) ? 'fas fa-check' : 'far fa-heart text-store-fg-muted'"></i>
            </button>
            -->


            <!-- Selo do kit (página de brinquedo) no alto da foto. -->
            <span v-if="selo" class="selo-kit" :class="`selo-kit-${selo.tom}`"><i v-if="selo.estrela" class="fas fa-star" aria-hidden="true"></i>{{ selo.texto }}</span>

            <!-- Card deitado: o "X% OFF" vermelho fica no canto de cima da foto (exemplo do Lira 2026-10-24). -->
            <span v-if="horizontal && precoAntes" class="absolute left-0 top-1.5 z-[6] whitespace-nowrap rounded-r-full bg-[#d71920] py-0.5 pl-1.5 pr-2 text-[10px] font-bold leading-tight text-white shadow">{{ descontoPct }}% OFF</span>

            <!-- Quem comprou e ainda não avaliou: selo no canto da foto (não muda a altura do card).
                 No card deitado vai para a direita, longe do "X% OFF". -->
            <button v-if="canReview && !hasReviewed" type="button"
                class="absolute z-10 flex items-center gap-1 rounded-full bg-store-bg-raised px-2.5 py-1 text-[11px] font-semibold text-store-accent shadow hover:underline"
                :class="horizontal ? 'right-1.5 top-1.5' : 'left-2.5 top-2.5'"
                @click.stop="showReviewModal = true">
                <i class="fas fa-star text-amber-400"></i> Avaliar
            </button>

            <!-- Selo promocional sobre a foto (canto inferior esquerdo): só nas ofertas
                 (Relâmpago e do Dia). Sem selo de campanha (pedido 2026-10-23). -->
            <div v-if="preco.oferta_relampago || preco.oferta_do_dia" class="promo-badge" :class="{ 'promo-badge-compacto': horizontal }">
                <span class="promo-badge-icon">%</span>
                <span class="promo-badge-text">{{ preco.oferta_relampago ? 'Oferta Relâmpago' : 'Oferta do Dia' }}</span>
            </div>

        </div>

        <div class="flex min-w-0 flex-1 flex-col" :class="horizontal ? 'gap-1.5 py-2.5 pl-1 pr-3' : 'gap-2 px-4 pb-4 pt-4'">
            <!-- Nome: 2 linhas no celular, 3 no computador; se não couber, "… Ver mais"
                 fecha a última linha (ajustarTitulo). Altura fixa = cards alinhados. -->
            <div class="relative">
                <h3 ref="tituloEl" class="titulo-card overflow-hidden font-semibold group-hover:text-store-accent" :title="nomeDoCard">{{ titulo.texto }}<template v-if="titulo.cortado">… <span class="titulo-ver-mais">Ver mais</span></template></h3>
                <div ref="medidorEl" class="titulo-card pointer-events-none invisible absolute left-0 top-0 font-semibold" aria-hidden="true" style="height: auto"></div>
            </div>
            <!-- Estrelas da média, a nota em negrito e a quantidade de avaliações, em
                 todo card (pedidos 2026-10-20 e 2026-10-22). Sem avaliação real (pedido do
                 Lira 26/11/2026): nada de estrelas vazias nem "(0)"; a linha fica vazia na
                 mesma altura, para os cards continuarem alinhados. -->
            <!-- No celular, fonte um ponto menor: no card de 149 px, "★★★★★ 4,8 (735)" cabe certo.
                 Card mais estreito (tela de 320 px): a nota sai e ficam estrelas e quantidade. -->
            <div class="@container">
                <div v-if="totalAvaliacoes === 0" class="h-4" aria-hidden="true"></div>
                <div v-else class="flex h-4 items-center gap-px whitespace-nowrap text-[10px] text-amber-400 md:text-[11px]" role="img"
                    :aria-label="`Nota ${notaTexto} de 5, ${totalAvaliacoes} ${totalAvaliacoes === 1 ? 'avaliação' : 'avaliações'}`">
                    <i v-for="n in 5" :key="n" :class="estrelaClasse(n)" aria-hidden="true"></i>
                    <span v-if="nota > 0" class="ml-1 hidden text-[11px] font-bold text-store-fg @[7rem]:inline md:text-xs" aria-hidden="true">{{ notaTexto }}</span>
                    <span class="ml-0.5 text-[11px] text-store-fg-muted md:ml-1 md:text-xs" aria-hidden="true">({{ totalAvaliacoes.toLocaleString('pt-BR') }})</span>
                </div>
            </div>
            <!-- Card deitado (exemplo do Lira 2026-10-24): preço com o riscado ao lado,
                 parcela embaixo e o frete. O "X% OFF" está na foto. -->
            <div v-if="horizontal">
                <div class="flex items-end gap-x-1.5">
                    <div class="flex shrink-0 items-start leading-none text-store-fg">
                        <span class="mr-0.5 mt-[2px] text-xs font-medium">R$</span>
                        <span class="text-xl font-semibold tracking-tight">{{ precoPartes.inteiro }}</span>
                        <span class="ml-px mt-[2px] text-[10px] font-semibold">{{ precoPartes.centavos }}</span>
                    </div>
                    <s v-if="precoAntes" class="min-w-0 truncate whitespace-nowrap pb-px text-[11px] text-store-fg-faint">{{ formatPrice(precoAntes) }}</s>
                </div>
                <p v-if="cupomBf" class="mt-0.5 truncate whitespace-nowrap text-[11px] text-store-fg-muted" data-card-cupom>
                    com cupom <b>{{ cupomBf.code }}</b><template v-if="cupomSecundario">: <b>{{ formatPrice(cupomBf.por) }}</b></template><template v-if="cupomBf.pix !== null"> · <span class="font-semibold text-store-verde-texto">No Pix: {{ formatPrice(cupomBf.pix) }}</span></template>
                </p>
                <p v-if="parcela || !tabelaParcelas?.length" class="mt-1 flex items-center gap-1 whitespace-nowrap text-[11px] text-store-fg-muted">
                    <i class="far fa-credit-card" aria-hidden="true"></i>
                    <template v-if="parcela">ou {{ parcela.parcelas }}x de <span class="font-semibold text-store-verde-texto">{{ formatPrice(parcela.valor) }}</span></template>
                    <template v-else>ou em até {{ PARCELAS }}x</template>
                </p>
                <p v-if="product.stock <= 0" class="mt-1 text-[11px] font-semibold text-red-600">Esgotado</p>
                <p v-else class="mt-1 flex items-center gap-1 whitespace-nowrap text-[11px] font-semibold text-store-verde-texto">
                    Frete grátis
                    <i class="fa-solid fa-bolt" aria-hidden="true"></i>
                </p>
            </div>
            <div v-else class="mt-auto pt-1">
                <!-- Preço no estilo do Mercado Livre (pedido 2026-10-10): pílula verde
                     "X% OFF" + preço antigo riscado; embaixo o preço grande com os
                     centavos pequenos no alto. -->
                <!-- Altura fixa em todas as linhas (pedido 2026-10-11): com ou sem
                     desconto, todo card tem o mesmo tamanho e o preço fica
                     sempre na mesma posição. -->
                <!-- Sem "A partir de" antes do selo (pedido 2026-10-22): quebrava a linha.
                     Card estreito (celular, 2 por linha): a linha mede a própria largura
                     (@container) e o riscado só aparece quando cabe; o "X% OFF" fica inteiro. -->
                <div class="@container">
                    <div class="flex h-[18px] items-center gap-1.5 overflow-hidden">
                        <template v-if="precoAntes">
                            <!-- Selo numa linha só (pedido 2026-10-20): no carrossel com 5 cards o "12% OFF" quebrava. -->
                            <span class="shrink-0 whitespace-nowrap rounded bg-store-verde px-1 py-px text-[10px] font-bold leading-tight text-white">{{ descontoPct }}% OFF</span>
                            <s class="hidden min-w-0 truncate whitespace-nowrap text-xs text-store-fg-faint @[7rem]:block">{{ formatPrice(precoAntes) }}</s>
                        </template>
                    </div>
                </div>
                <div class="flex h-[30px] items-end gap-x-1.5 overflow-hidden">
                    <div class="flex shrink-0 items-start leading-none text-store-fg">
                        <span class="mr-0.5 mt-[3px] text-sm font-medium md:text-base">R$</span>
                        <span class="text-[22px] font-semibold tracking-tight md:text-2xl">{{ precoPartes.inteiro }}</span>
                        <span class="ml-px mt-[3px] text-[11px] font-semibold md:text-xs">{{ precoPartes.centavos }}</span>
                    </div>
                </div>
                <!-- Black Friday: duas linhas de altura fixa em todo card enquanto a BF está no ar
                     (produto sem cupom, como Oferta do Dia, fica com elas vazias: cards do mesmo tamanho). -->
                <template v-if="bfNoAr">
                    <p class="flex h-4 items-center truncate whitespace-nowrap text-[11px] text-store-fg-muted md:text-xs" data-card-cupom>
                        <template v-if="cupomBf">com cupom <b class="ml-1">{{ cupomBf.code }}</b><template v-if="cupomSecundario">:<b class="ml-1">{{ formatPrice(cupomBf.por) }}</b></template></template>
                    </p>
                    <p class="flex h-4 items-center truncate whitespace-nowrap text-[11px] font-semibold text-store-verde-texto md:text-xs" data-card-pix>
                        <template v-if="cupomBf && cupomBf.pix !== null">No Pix: {{ formatPrice(cupomBf.pix) }}</template>
                    </p>
                </template>
                <!-- Extra do card de kit (preço por brinquedo e economia); vazio nas vitrines. -->
                <slot />
                <div class="mt-1.5 flex h-5 items-center gap-2">
                    <!-- Mesmo tamanho/peso do "Frete grátis", em vermelho (pedido 2026-10-10). -->
                    <p v-if="product.stock <= 0" class="flex h-5 min-w-0 flex-1 items-center text-[11px] font-semibold text-red-600 md:text-xs">Esgotado</p>
                    <!-- Frete como no Mercado Livre: "Frete grátis ⚡FULL" em verde, mesma fonte.
                         Menor (pedido 2026-10-22): no card estreito o FULL ficava embaixo do "Detalhes". -->
                    <p v-else class="flex h-5 min-w-0 flex-1 items-center gap-1 whitespace-nowrap text-[11px] font-semibold text-store-verde-texto md:text-xs">
                        Frete grátis
                        <i class="fa-solid fa-bolt" aria-hidden="true"></i>
                    </p>
                    <!-- Detalhes (pedido 2026-10-15, mesmo comportamento do FerraKora): no
                         computador fica na linha do "Frete grátis ⚡FULL", alinhado com o FULL
                         (margem negativa: o botão é mais alto que a linha e não muda a altura
                         do card); no celular vira a última linha do card. Menor no computador
                         (pedido 2026-10-17): era 30px de altura e ficava grande no card.
                         Laranja vivo #ff9500 (pedido 2026-10-22): o #c2450c era opaco e
                         avermelhado. Texto branco (pedido 2026-10-22, depois do grafite). -->
                    <button v-if="escolher" type="button" :disabled="product.stock <= 0"
                        class="-my-[2px] hidden h-6 shrink-0 items-center botao-detalhes md:inline-flex rounded-md bg-[#ff9500] px-2.5 text-[11px] font-semibold text-white shadow-sm transition-colors hover:bg-[#ffa726] disabled:opacity-50"
                        @click.stop="emit('escolher')">
                        {{ selecionado ? 'Escolhido' : 'Escolher' }}
                    </button>
                    <Link v-else :href="`/produtos/${product.slug}`"
                        class="-my-[2px] hidden h-6 shrink-0 items-center botao-detalhes rounded-md bg-[#ff9500] px-2.5 text-[11px] font-semibold text-white shadow-sm transition-colors hover:bg-[#ffa726] md:inline-flex">
                        Detalhes
                    </Link>
                </div>
            </div>
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
