<script setup>
import { computed } from 'vue';

const props = defineProps({
    value: { type: String, required: true },
    height: { type: Number, default: 44 },
    showText: { type: Boolean, default: false },
    ariaLabel: { type: String, default: null },
});

// Padrões oficiais Code 128: cada string alterna largura de barra/espaço.
// Implementação local e sem dependência para não travar build/deploy na Hostinger.
const PATTERNS = [
    '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
    '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
    '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
    '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
    '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
    '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
    '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
    '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
    '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
    '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
    '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
];

const encodeCode128B = (text) => {
    const codes = [104];

    for (const char of text) {
        const code = char.charCodeAt(0);
        if (code < 32 || code > 127) {
            continue;
        }
        codes.push(code - 32);
    }

    return codes;
};

const encodeCode128C = (text) => {
    const codes = [105];

    for (let i = 0; i < text.length; i += 2) {
        codes.push(Number(text.slice(i, i + 2)));
    }

    return codes;
};

const encoded = computed(() => {
    const normalized = String(props.value ?? '').replace(/\s+/g, '');
    const codes = /^\d+$/.test(normalized) && normalized.length % 2 === 0
        ? encodeCode128C(normalized)
        : encodeCode128B(normalized);

    let checksum = codes[0];
    for (let i = 1; i < codes.length; i += 1) {
        checksum += codes[i] * i;
    }

    return [...codes, checksum % 103, 106];
});

const bars = computed(() => {
    const quietZone = 10;
    let x = quietZone;
    const rects = [];

    for (const code of encoded.value) {
        const pattern = PATTERNS[code];
        if (! pattern) {
            continue;
        }

        [...pattern].forEach((width, index) => {
            const numericWidth = Number(width);
            if (index % 2 === 0) {
                rects.push({ x, width: numericWidth });
            }
            x += numericWidth;
        });
    }

    return { rects, width: x + quietZone };
});

const accessibleLabel = computed(() => props.ariaLabel || `Código DANFE ${props.value}`);
</script>

<template>
    <svg class="code128-barcode" :viewBox="`0 0 ${bars.width} ${height}`" preserveAspectRatio="none" role="img" :aria-label="accessibleLabel">
        <rect width="100%" height="100%" fill="#fff" />
        <rect v-for="(bar, index) in bars.rects" :key="index" :x="bar.x" y="0" :width="bar.width" :height="height" fill="#000" />
        <text v-if="showText" :x="bars.width / 2" :y="height - 2" text-anchor="middle" font-family="monospace" font-size="7" fill="#000">{{ value }}</text>
    </svg>
</template>
