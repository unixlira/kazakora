<script setup>
defineProps({
    column: { type: Object, required: true },
    label: { type: String, required: true },
});
</script>

<template>
    <button v-if="column.getCanSort()" type="button"
        class="flex items-center gap-1 text-xs font-semibold uppercase tracking-wide text-slate-400 hover:text-primary"
        @click="column.toggleSorting(column.getIsSorted() === 'asc')">
        {{ label }}
        <i v-if="column.getIsSorted() === 'asc'" class="fas fa-arrow-up text-[10px]"></i>
        <i v-else-if="column.getIsSorted() === 'desc'" class="fas fa-arrow-down text-[10px]"></i>
        <i v-else class="fas fa-sort text-[10px] opacity-40"></i>
    </button>
    <span v-else class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ label }}</span>
</template>
