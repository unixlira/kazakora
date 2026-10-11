<script setup>
import { Link } from '@inertiajs/vue3';

const globalFilter = defineModel('globalFilter', { type: String, default: '' });

defineProps({
    searchPlaceholder: { type: String, default: 'Buscar...' },
    filterTabs: { type: Array, default: () => [] },
    activeTab: { type: String, default: 'all' },
    createLabel: { type: String, default: null },
    createHref: { type: String, default: null },
});

defineEmits(['update:activeTab']);
</script>

<template>
    <div class="flex flex-col gap-4 border-b border-[var(--surface-border)] p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="relative w-full max-w-sm">
                <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                <input v-model="globalFilter" type="text" :placeholder="searchPlaceholder"
                    class="w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface-muted)] py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none">
            </div>

            <Link v-if="createLabel && createHref" :href="createHref"
                class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis">
                <i class="fas fa-plus text-xs"></i>
                {{ createLabel }}
            </Link>
        </div>

        <div v-if="filterTabs.length" class="flex flex-wrap gap-2">
            <button v-for="tab in filterTabs" :key="tab.value" type="button"
                class="rounded-full px-3 py-1 text-xs font-medium transition-colors"
                :class="activeTab === tab.value
                    ? 'bg-primary text-white'
                    : 'bg-[var(--surface-muted)] text-slate-500 hover:bg-[var(--surface-border)]'"
                @click="$emit('update:activeTab', tab.value)">
                {{ tab.label }}
            </button>
        </div>
    </div>
</template>
