<script setup>
import { computed } from 'vue';
import { TONE_CLASSES, resolveTone, statusLabel, statusTone } from '@/Shared/statusLabels';

// Badge único de status do admin. Rótulo e cor vêm do mapa central
// (Shared/statusLabels.js), então o mesmo status sai igual em toda tela.
//
//   status  — valor cru do banco ('cancelled', 'authorized'...). Também
//             aceita um nome de tom ('green', 'red'...) ou uma cor hex
//             ('#146EB4', badge de canal com a cor da marca).
//   label   — opcional; sobrescreve o rótulo do mapa (telas antigas que já
//             passavam label continuam funcionando).
//   context — opcional; entidade ('invoice', 'shipment', 'payment'...) pra
//             quando o mesmo valor tem outro significado/concordância.
//   tone    — opcional; força a cor (green/yellow/blue/red/purple/gray).
//   wrap    — opcional; deixa o rótulo quebrar em até duas linhas, centralizado,
//             pra coluna não alargar (pedido do Lira 2026-10-16: "Aguardando
//             pagamento" na listagem de pedidos; o Status voltou a uma linha no
//             mesmo dia e hoje só o selo de Envios usa). Rótulo curto continua igual.
//             Um "\n" no label força a quebra naquele ponto (ex.:
//             "Mercado\nEnvios", "Ponto de\nColeta"), sem depender da
//             largura da coluna.
const props = defineProps({
    status: { type: [String, Number, Boolean], default: null },
    label: { type: String, default: null },
    context: { type: String, default: null },
    tone: { type: String, default: null },
    wrap: { type: Boolean, default: false },
});

// Além da paleta fixa, aceita uma cor de marca em hex (ex: '#146EB4') pra
// badge de canal/plataforma que precisa da cor real dela (pedido explícito
// 2026-08-14: badges da Amazon).
const isHexColor = computed(() => typeof props.status === 'string' && /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(props.status));

const hexToRgba = (hex, alpha) => {
    let value = hex.replace('#', '');
    if (value.length === 3) {
        value = value.split('').map((c) => c + c).join('');
    }
    const r = parseInt(value.substring(0, 2), 16);
    const g = parseInt(value.substring(2, 4), 16);
    const b = parseInt(value.substring(4, 6), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
};

const tone = computed(() => resolveTone(props.tone)
    ?? resolveTone(props.status)
    ?? statusTone(props.status, props.context));

const classes = computed(() => (isHexColor.value ? '' : TONE_CLASSES[tone.value] ?? TONE_CLASSES.gray));

const hexStyle = computed(() => (isHexColor.value
    ? { color: props.status, backgroundColor: hexToRgba(props.status, 0.15) }
    : null));

const layout = computed(() => (props.wrap
    ? 'max-w-[6rem] whitespace-pre-line rounded-xl text-center leading-tight'
    : 'whitespace-nowrap rounded-full'));

const text = computed(() => props.label ?? statusLabel(props.status, props.context));
</script>

<template>
    <span class="inline-block px-2.5 py-1 text-xs font-medium" :class="[layout, classes]" :style="hexStyle">{{ text }}</span>
</template>
