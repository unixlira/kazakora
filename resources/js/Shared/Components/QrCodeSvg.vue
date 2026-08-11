<script setup>
// QR Code gerado no navegador via lib vendorizada (ver
// resources/js/vendor/qrcode-generator.mjs — motivo do vendoring documentado
// lá). `scalable: true` omite width/height fixos do SVG gerado, só o
// viewBox — o wrapper abaixo controla o tamanho real via CSS, então isso
// funciona igual na tela e impresso em qualquer resolução.
import { qrcode } from '@/vendor/qrcode-generator.mjs';
import { computed } from 'vue';

const props = defineProps({
    value: { type: String, required: true },
    // 'L','M','Q','H' — 'M' (padrão da lib) é suficiente pra um protocolo
    // numérico curto sem logo sobreposto.
    errorCorrectionLevel: { type: String, default: 'M' },
});

const svgMarkup = computed(() => {
    const qr = qrcode(0, props.errorCorrectionLevel);
    qr.addData(props.value);
    qr.make();

    return qr.createSvgTag({ cellSize: 4, margin: 8, scalable: true });
});
</script>

<template>
    <div class="qr-code-svg" v-html="svgMarkup"></div>
</template>

<style scoped>
.qr-code-svg :deep(svg) {
    display: block;
    width: 100%;
    height: 100%;
}
</style>
