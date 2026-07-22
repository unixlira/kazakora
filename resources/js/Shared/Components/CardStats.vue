<script setup>
defineProps({
    statSubtitle: { type: String, default: '' },
    statTitle: { type: String, default: '' },
    statIconName: { type: String, default: 'fas fa-chart-bar' },
    statIconColor: { type: String, default: 'bg-primary' },
});
</script>

<template>
    <div class="relative flex min-w-0 flex-col break-words rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm transition-shadow hover:shadow-md">
        <div class="flex-auto p-4">
            <div class="flex flex-wrap items-center">
                <div class="relative w-full max-w-full flex-1 flex-grow pr-4">
                    <h5 class="text-xs font-bold uppercase text-slate-400">{{ statSubtitle }}</h5>
                    <span class="text-xl font-semibold">{{ statTitle }}</span>
                </div>
                <div class="relative w-auto flex-initial pl-4">
                    <div class="inline-flex h-12 w-12 items-center justify-center rounded-full p-3 text-center text-white shadow-sm"
                        :class="statIconColor">
                        <i :class="statIconName"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
