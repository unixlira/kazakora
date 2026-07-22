<script setup>
defineProps({
    table: { type: Object, required: true },
});

const pageSizes = [10, 25, 50, 100];
</script>

<template>
    <div class="flex flex-col items-center justify-between gap-4 border-t border-[var(--surface-border)] px-4 py-3 sm:flex-row">
        <div class="flex items-center gap-2 text-sm text-slate-500">
            <span>Mostrando</span>
            <span class="font-medium">
                {{ table.getState().pagination.pageIndex * table.getState().pagination.pageSize + 1 }}-{{
                    Math.min(
                        (table.getState().pagination.pageIndex + 1) * table.getState().pagination.pageSize,
                        table.getFilteredRowModel().rows.length,
                    )
                }}
            </span>
            <span>de</span>
            <span class="font-medium">{{ table.getFilteredRowModel().rows.length }}</span>
        </div>

        <div class="flex items-center gap-4">
            <label class="flex items-center gap-2 text-sm text-slate-500">
                <span class="hidden sm:inline">Por página</span>
                <select :value="table.getState().pagination.pageSize"
                    class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1 text-sm"
                    @change="table.setPageSize(Number($event.target.value))">
                    <option v-for="size in pageSizes" :key="size" :value="size">{{ size }}</option>
                </select>
            </label>

            <div class="flex items-center gap-1">
                <button type="button"
                    class="flex h-8 w-8 items-center justify-center rounded-lg border border-[var(--surface-border)] text-sm disabled:cursor-not-allowed disabled:opacity-40"
                    :disabled="!table.getCanPreviousPage()" @click="table.previousPage()">
                    <i class="fas fa-chevron-left text-xs"></i>
                </button>
                <span class="px-2 text-sm text-slate-500">
                    Página {{ table.getState().pagination.pageIndex + 1 }} de {{ Math.max(table.getPageCount(), 1) }}
                </span>
                <button type="button"
                    class="flex h-8 w-8 items-center justify-center rounded-lg border border-[var(--surface-border)] text-sm disabled:cursor-not-allowed disabled:opacity-40"
                    :disabled="!table.getCanNextPage()" @click="table.nextPage()">
                    <i class="fas fa-chevron-right text-xs"></i>
                </button>
            </div>
        </div>
    </div>
</template>
