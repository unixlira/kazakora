<script setup>
import { computed, ref } from 'vue';
import { FlexRender, getCoreRowModel, getFilteredRowModel, getPaginationRowModel, getSortedRowModel, useVueTable } from '@tanstack/vue-table';
import DataTableToolbar from './DataTableToolbar.vue';
import DataTablePagination from './DataTablePagination.vue';
import DataTableColumnHeader from './DataTableColumnHeader.vue';

const props = defineProps({
    // TanStack column defs: [{ accessorKey, header, cell?, enableSorting? }]
    columns: { type: Array, required: true },
    data: { type: Array, required: true },
    loading: { type: Boolean, default: false },
    selectable: { type: Boolean, default: false },
    searchPlaceholder: { type: String, default: 'Buscar...' },
    emptyMessage: { type: String, default: 'Nenhum registro encontrado.' },
    createLabel: { type: String, default: null },
    createHref: { type: String, default: null },
    // [{ label, value }] — filtering the rows is left to the caller (via `data`),
    // this component just tracks which tab is active and emits it.
    filterTabs: { type: Array, default: () => [] },
    rowKey: { type: String, default: 'id' },
});

const emit = defineEmits(['update:activeTab']);

const globalFilter = ref('');
const sorting = ref([]);
const rowSelection = ref({});
const activeTab = ref('all');

const selectionColumn = {
    id: '__select',
    enableSorting: false,
    header: 'select-all',
    meta: { isSelectionColumn: true },
};

const columnDefs = computed(() => (props.selectable ? [selectionColumn, ...props.columns] : props.columns));

const table = useVueTable({
    get data() {
        return props.data;
    },
    get columns() {
        return columnDefs.value;
    },
    state: {
        get globalFilter() {
            return globalFilter.value;
        },
        get sorting() {
            return sorting.value;
        },
        get rowSelection() {
            return rowSelection.value;
        },
    },
    enableRowSelection: props.selectable,
    onGlobalFilterChange: (updater) => {
        globalFilter.value = typeof updater === 'function' ? updater(globalFilter.value) : updater;
    },
    onSortingChange: (updater) => {
        sorting.value = typeof updater === 'function' ? updater(sorting.value) : updater;
    },
    onRowSelectionChange: (updater) => {
        rowSelection.value = typeof updater === 'function' ? updater(rowSelection.value) : updater;
    },
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
    getFilteredRowModel: getFilteredRowModel(),
    getPaginationRowModel: getPaginationRowModel(),
    globalFilterFn: 'includesString',
});

const setActiveTab = (value) => {
    activeTab.value = value;
    emit('update:activeTab', value);
};
</script>

<template>
    <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
        <slot name="stats" />

        <DataTableToolbar v-model:global-filter="globalFilter" :search-placeholder="searchPlaceholder"
            :filter-tabs="filterTabs" :active-tab="activeTab" :create-label="createLabel" :create-href="createHref"
            @update:active-tab="setActiveTab" />

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-[var(--surface-border)]">
                    <tr v-for="headerGroup in table.getHeaderGroups()" :key="headerGroup.id">
                        <th v-for="header in headerGroup.headers" :key="header.id" class="px-4 py-3">
                            <input v-if="header.column.columnDef.meta?.isSelectionColumn" type="checkbox"
                                class="h-4 w-4 rounded border-[var(--surface-border)]"
                                :checked="table.getIsAllPageRowsSelected()"
                                :indeterminate="table.getIsSomePageRowsSelected() && !table.getIsAllPageRowsSelected()"
                                @change="table.toggleAllPageRowsSelected($event.target.checked)">
                            <DataTableColumnHeader v-else-if="!header.isPlaceholder" :column="header.column"
                                :label="String(header.column.columnDef.header)" />
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loading">
                        <td :colspan="columnDefs.length" class="p-6">
                            <div class="space-y-3">
                                <div v-for="i in 4" :key="i" class="h-4 animate-pulse rounded bg-[var(--surface-muted)]"></div>
                            </div>
                        </td>
                    </tr>
                    <tr v-else-if="table.getRowModel().rows.length === 0">
                        <td :colspan="columnDefs.length" class="p-10 text-center text-sm text-slate-400">
                            <i class="fas fa-inbox mb-2 block text-2xl"></i>
                            {{ emptyMessage }}
                        </td>
                    </tr>
                    <tr v-for="row in table.getRowModel().rows" v-else :key="row.original[rowKey] ?? row.id"
                        class="border-b border-[var(--surface-border)] last:border-0 hover:bg-[var(--surface-muted)]">
                        <td v-for="cell in row.getVisibleCells()" :key="cell.id" class="px-4 py-3">
                            <input v-if="cell.column.columnDef.meta?.isSelectionColumn" type="checkbox"
                                class="h-4 w-4 rounded border-[var(--surface-border)]"
                                :checked="row.getIsSelected()" @change="row.toggleSelected($event.target.checked)">
                            <FlexRender v-else :render="cell.column.columnDef.cell" :props="cell.getContext()" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <DataTablePagination :table="table" />
    </div>
</template>
