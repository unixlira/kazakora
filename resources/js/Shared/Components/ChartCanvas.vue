<script setup>
import { Chart } from 'chart.js/auto';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    type: { type: String, required: true },
    data: { type: Object, required: true },
    options: { type: Object, default: () => ({}) },
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
    });
};

onMounted(render);
onBeforeUnmount(() => chart?.destroy());

watch(() => [props.type, props.data, props.options], render, { deep: true });
</script>

<template>
    <div class="relative h-64 w-full">
        <canvas ref="canvas"></canvas>
    </div>
</template>
