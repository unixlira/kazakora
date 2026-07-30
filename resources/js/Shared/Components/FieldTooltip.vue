<script setup>
defineProps({
    text: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <span :title="text" class="ml-1 inline-flex h-4 w-4 cursor-help items-center justify-center rounded-full bg-slate-200 text-[10px] font-bold text-slate-500 hover:bg-slate-300">
        ?
    </span>
</template>
