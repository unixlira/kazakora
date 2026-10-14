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
 * `altura` (px, sem unidade) encolhe o selo proporcionalmente para alinhar
 * com os outros selos da faixa. Aceita a variável CSS --ra-altura para tamanho
 * responsivo (ex.: class="[--ra-altura:30] lg:[--ra-altura:51]").
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    altura: { type: Number, default: null },
});

const LINK_PADRAO = 'https://www.reclameaqui.com.br/rav/F68R';
const SCRIPT_SRC = 'https://s3.amazonaws.com/raichu-beta/ra-verified/bundle.js';
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

const estiloCaixa = computed(() => {
    const h = props.altura != null ? String(props.altura) : 'var(--ra-altura, 52)';
    return {
        height: `calc(${h} * 1px)`,
        width: `calc(${h} * ${LARGURA / ALTURA} * 1px)`,
        '--ra-escala': `calc(${h} / ${ALTURA})`,
    };
});

function ajustarLink() {
    const a = alvo.value?.querySelector('a.ra-widget-verified-wrapper');
    if (!a) return false;
    if (a.getAttribute('href') !== link.value) a.setAttribute('href', link.value);
    a.setAttribute('target', '_blank');
    a.setAttribute('rel', 'noopener');
    carregado.value = true;
    return true;
}

onMounted(() => {
    if (!alvo.value) return;

    observador = new MutationObserver(() => ajustarLink());
    observador.observe(alvo.value, { childList: true, subtree: true, attributes: true, attributeFilter: ['href'] });

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
});

onBeforeUnmount(() => {
    observador?.disconnect();
    observador = null;
    clearTimeout(desistir);
    script?.remove();
    script = null;
    if (alvo.value) alvo.value.innerHTML = '';
});
</script>

<template>
    <div class="selo-ra relative shrink-0 overflow-hidden" :style="estiloCaixa">
        <!-- Fallback: imagem local do selo, até o oficial carregar. -->
        <a v-if="!carregado" :href="link" target="_blank" rel="noopener"
            class="absolute inset-0 flex items-center justify-center rounded-md bg-white transition hover:opacity-90"
            title="Verificada por Reclame AQUI">
            <img src="/images/selos/reclame-aqui-verificada-qrcode.png" alt="Verificada por Reclame AQUI" width="294" height="88"
                class="h-[90%] w-auto">
        </a>
        <div class="selo-ra-escala absolute left-0 top-0" :class="{ invisible: !carregado }"
            :style="{ width: `${LARGURA}px`, height: `${ALTURA}px` }">
            <div :id="alvoId" ref="alvo"></div>
        </div>
    </div>
</template>

<style scoped>
.selo-ra-escala {
    transform: scale(var(--ra-escala));
    transform-origin: top left;
}
</style>
