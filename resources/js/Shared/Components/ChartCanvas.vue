<script setup>
import { Chart } from 'chart.js/auto';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    type: { type: String, required: true },
    data: { type: Object, required: true },
    options: { type: Object, default: () => ({}) },
    // Plugins só deste gráfico (ex.: escrever o valor em cima da barra).
    plugins: { type: Array, default: () => [] },
    // Texto para leitor de tela (o canvas em si não é lido).
    label: { type: String, default: '' },
    // Altura do gráfico (classe Tailwind).
    heightClass: { type: String, default: 'h-64' },
});

const canvas = ref(null);
let chart = null;

const render = () => {
    if (chart) {
        chart.destroy();
    }

    chart = new Chart(canvas.value, {
        type: props.type,
        data: props.data,
        options: { responsive: true, maintainAspectRatio: false, ...props.options },
        plugins: props.plugins,
    });
};

onMounted(render);
onBeforeUnmount(() => chart?.destroy());

watch(() => [props.type, props.data, props.options], render, { deep: true });
</script>

<template>
    <div class="relative w-full" :class="heightClass">
        <canvas ref="canvas" :role="label ? 'img' : undefined" :aria-label="label || undefined"></canvas>
    </div>
</template>
