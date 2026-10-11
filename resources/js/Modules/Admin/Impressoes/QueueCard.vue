<script setup>
defineProps({
    order: { type: Object, required: true },
    // 'xl' pro pedido atual (topo), 'lg' pro próximo — os dois grandes o
    // bastante pra ler de longe, tipo painel de chamada de senha.
    size: { type: String, default: 'lg' },
});
</script>

<template>
    <div class="rounded-2xl border-2 border-primary bg-[var(--surface)] shadow-lg" :class="size === 'xl' ? 'p-8' : 'p-6'">
        <div class="flex items-center justify-between">
            <span class="inline-flex items-center gap-2 rounded-full bg-lightprimary px-3 py-1 font-semibold text-primary"
                :class="size === 'xl' ? 'text-base' : 'text-sm'">
                <i :class="order.channelIcon"></i> {{ order.channel }}
            </span>
            <span class="text-slate-400" :class="size === 'xl' ? 'text-base' : 'text-sm'">{{ order.createdAt }}</span>
        </div>

        <div class="mt-4 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="font-bold leading-none" :class="size === 'xl' ? 'text-6xl' : 'text-4xl'">#{{ order.id }}</p>
                <p class="mt-2 font-medium text-slate-600 dark:text-slate-300" :class="size === 'xl' ? 'text-2xl' : 'text-lg'">
                    {{ order.customer || 'Cliente não informado' }}
                </p>
            </div>
            <div class="rounded-xl bg-lightwarning px-5 py-3 text-center">
                <p class="font-bold leading-none text-warning-emphasis" :class="size === 'xl' ? 'text-7xl' : 'text-5xl'">{{ order.unitsCount }}</p>
                <p class="mt-1 font-semibold uppercase tracking-wide text-warning-emphasis" :class="size === 'xl' ? 'text-sm' : 'text-xs'">
                    produto{{ order.unitsCount === 1 ? '' : 's' }} pra separar
                </p>
            </div>
        </div>

        <ul class="mt-5 space-y-1.5 border-t border-[var(--surface-border)] pt-4" :class="size === 'xl' ? 'text-xl' : 'text-base'">
            <li v-for="(product, index) in order.products" :key="index" class="flex items-center gap-2">
                <i class="fas fa-box text-slate-400"></i> {{ product }}
            </li>
            <li v-if="order.products.length === 0" class="text-slate-400">Sem itens vinculados a produto do catálogo.</li>
        </ul>
    </div>
</template>
