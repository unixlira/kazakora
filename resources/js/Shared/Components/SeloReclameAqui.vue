<script setup>
/*
 * Selo OFICIAL "RA Verificada" do Reclame AQUI (modelo horizontal_1, 142x52).
 * Código do embed fornecido pelo Lira em 2026-10-14 (verificação aprovada).
 *
 * <script> colado no template do Vue não executa, então cada instância cria o
 * script do Reclame AQUI na montagem, com um data-target ÚNICO. O bundle.js
 * procura os script[id="ra-embed-verified-seal"], tira o id e desenha o selo
 * dentro do data-target. Depois que o selo aparece, o link dele é trocado pelo
 * link exigido pelo Lira (página RA Verificada da loja).
 *
 * Enquanto o selo não carrega (ou se um bloqueador barrar o script), aparece
 * a imagem local do selo apontando para o mesmo link.
 *
 * `semLink` (checkout, pedido 2026-10-21): só a imagem local do selo, sem <a>
 * e sem o script do Reclame AQUI (o widget oficial sempre desenha um link),
 * para o cliente não sair da tela de pagamento.
 *
 * `altura` (px, sem unidade) encolhe o selo proporcionalmente para alinhar
 * com os outros selos da faixa. Aceita a variável CSS --ra-altura para tamanho
 * responsivo (ex.: class="[--ra-altura:30] lg:[--ra-altura:51]").
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    altura: { type: Number, default: null },
    semLink: { type: Boolean, default: false },
});

const LINK_PADRAO = 'https://www.reclameaqui.com.br/rav/F68R';
// Cópia local do bundle oficial (PageSpeed, 2026-10-21): o styles.css do s3
// puxava Open Sans e Inter Tight do Google Fonts. O bundle monta o caminho do
// CSS e das imagens a partir deste src; em public/vendor/reclame-aqui o CSS é
// o mesmo, sem os @import, e as fontes são servidas pelo site. Os dados do
// selo continuam vindo da API do Reclame AQUI.
const SCRIPT_SRC = '/vendor/reclame-aqui/bundle.js';
const DATA_ID = 'c0tVZkQyUnBkUVltM3R1QTo2NS02MDQtNTkwLWpvc2Utcm9iZXJ0by1saXJh';
const LARGURA = 142;
const ALTURA = 52;

const page = usePage();
const link = computed(() => page.props.reclameAqui?.url || LINK_PADRAO);

const alvoId = `ra-verified-seal-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;

const alvo = ref(null);
const carregado = ref(false);
let script = null;
let observador = null;
let desistir = null;
let medidor = null;

// Tamanho que o selo oficial desenhou de verdade. O Reclame AQUI passou a
// desenhar "Estamos há mais de 1 mês" + logo em ~156x76, maior que os 142x52
// do modelo, e o logo saía cortado no rodapé (revisão 2026-10-26). A escala
// segue o tamanho medido; até carregar vale o do modelo (imagem reserva).
const natural = ref({ w: LARGURA, h: ALTURA });

const estiloCaixa = computed(() => {
    const h = props.altura != null ? String(props.altura) : 'var(--ra-altura, 52)';
    return {
        height: `calc(${h} * 1px)`,
        width: `calc(${h} * ${natural.value.w / natural.value.h} * 1px)`,
        '--ra-escala': `calc(${h} / ${natural.value.h})`,
    };
});

function medir() {
    const raiz = alvo.value?.querySelector('[id="ra-widget-verified"]');
    if (!raiz) return;
    const w = Math.max(LARGURA, raiz.offsetWidth);
    const h = Math.max(1, raiz.offsetHeight);
    if (w !== natural.value.w || h !== natural.value.h) natural.value = { w, h };
}

function ajustarLink() {
    const a = alvo.value?.querySelector('a.ra-widget-verified-wrapper');
    if (!a) return false;
    if (a.getAttribute('href') !== link.value) a.setAttribute('href', link.value);
    a.setAttribute('target', '_blank');
    a.setAttribute('rel', 'noopener');
    // O widget desenha numa área fixa de 156x76 com folga de 12px 14px
    // (!important no CSS dele, com #id); só estilo inline com !important vence.
    // Área no tamanho do conteúdo e folga pequena: o selo cabe na caixa inteiro.
    const raiz = a.closest('[id="ra-widget-verified"]');
    const conteudo = a.querySelector('.ra-widget-verified-content');
    [raiz, a].forEach((el) => {
        el?.style.setProperty('width', 'auto', 'important');
        el?.style.setProperty('height', 'auto', 'important');
    });
    conteudo?.style.setProperty('padding', '2px 4px', 'important');
    if (raiz && !medidor && 'ResizeObserver' in window) {
        medidor = new ResizeObserver(medir);
        medidor.observe(raiz);
    }
    medir();
    carregado.value = true;
    return true;
}

let adiarScript = null;
let visivel = null;
const INTERACOES = ['scroll', 'pointerdown', 'touchstart', 'keydown'];

// Desempenho (pedido 2026-10-21): o script do Reclame AQUI só entra depois
// que a página terminou de carregar E quando o selo chega perto da tela ou o
// visitante interage; até lá fica a imagem local do selo (mesmo visual).
function quandoPrecisar() {
    pararDeEsperar();
    adiarScript = setTimeout(injetarScript, 300);
}

function pararDeEsperar() {
    window.removeEventListener('load', aoCarregar);
    INTERACOES.forEach((ev) => window.removeEventListener(ev, quandoPrecisar));
    visivel?.disconnect();
    visivel = null;
}

function aoCarregar() {
    if (!alvo.value) return;
    INTERACOES.forEach((ev) => window.addEventListener(ev, quandoPrecisar, { once: true, passive: true }));
    if ('IntersectionObserver' in window) {
        visivel = new IntersectionObserver((entradas) => {
            if (entradas.some((e) => e.isIntersecting)) quandoPrecisar();
        }, { rootMargin: '300px' });
        visivel.observe(alvo.value.parentElement ?? alvo.value);
    }
}

onMounted(() => {
    if (props.semLink || !alvo.value) return;

    observador = new MutationObserver(() => ajustarLink());
    observador.observe(alvo.value, { childList: true, subtree: true, attributes: true, attributeFilter: ['href'] });

    if (document.readyState === 'complete') aoCarregar();
    else window.addEventListener('load', aoCarregar, { once: true });
});

function injetarScript() {
    if (!alvo.value || script) return;
    script = document.createElement('script');
    script.type = 'text/javascript';
    script.id = 'ra-embed-verified-seal';
    script.src = SCRIPT_SRC;
    script.async = true;
    script.setAttribute('data-id', DATA_ID);
    script.setAttribute('data-target', alvoId);
    script.setAttribute('data-model', 'horizontal_1');
    alvo.value.appendChild(script);

    // Se em 15 s nada apareceu, fica só o fallback e para de observar.
    desistir = setTimeout(() => {
        if (!carregado.value) observador?.disconnect();
    }, 15000);
}

onBeforeUnmount(() => {
    pararDeEsperar();
    clearTimeout(adiarScript);
    observador?.disconnect();
    observador = null;
    medidor?.disconnect();
    medidor = null;
    clearTimeout(desistir);
    script?.remove();
    script = null;
    if (alvo.value) alvo.value.innerHTML = '';
});
</script>

<template>
    <div class="selo-ra relative shrink-0 overflow-hidden" :style="estiloCaixa">
        <div v-if="semLink" class="absolute inset-0 flex items-center justify-center rounded-[8px] bg-white">
            <img src="/images/selos/reclame-aqui-verificada-qrcode.webp" alt="Verificada por Reclame AQUI" width="294" height="88"
                class="h-[90%] w-auto">
        </div>
        <!-- Fallback: imagem local do selo, até o oficial carregar. -->
        <a v-else-if="!carregado" :href="link" target="_blank" rel="noopener"
            class="absolute inset-0 flex items-center justify-center rounded-[8px] bg-white transition hover:opacity-90"
            title="Verificada por Reclame AQUI">
            <img src="/images/selos/reclame-aqui-verificada-qrcode.webp" alt="Verificada por Reclame AQUI" width="294" height="88"
                class="h-[90%] w-auto">
        </a>
        <div v-if="!semLink" class="selo-ra-escala absolute left-0 top-0" :class="{ invisible: !carregado }"
            :style="{ width: `${natural.w}px`, height: `${natural.h}px` }">
            <div :id="alvoId" ref="alvo"></div>
        </div>
    </div>
</template>

<style scoped>
.selo-ra-escala {
    transform: scale(var(--ra-escala));
    transform-origin: top left;
}

/* O bundle.js do Reclame AQUI desenha uma borda azul de 1px no selo.
   O Lira não quis a borda (2026-10-14). */
.selo-ra :deep(.ra-widget-verified-content) {
    border: 0 !important;
}

/* Cantos do selo vêm da caixa de fora (rounded-[8px]), não dos 8px do widget
   (pedido 2026-10-14: rodapé com 8px em todas as imagens). */
.selo-ra :deep(.ra-widget-verified-content),
.selo-ra :deep(.ra-widget-verified-wrapper) {
    border-radius: 0 !important;
}
</style>
