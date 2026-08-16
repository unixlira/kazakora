<script setup>
// Pedido explícito 2026-08-16: modal de estoque baixo toda vez que um
// admin loga. Componente próprio (não parte do AdminLayout) montado nos
// DOIS layouts (Admin e App) — login redireciona pra `catalogo.inicio`
// (loja, AppLayout) quando não há URL "intended" salva, então o modal
// precisa disparar dali também, não só de dentro do admin, senão o
// alerta nunca apareceria de verdade num login direto.
import { Link, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const page = usePage();
const open = ref(false);
const products = ref([]);

watch(
    () => page.props.flash?.lowStockProducts,
    (value) => {
        if (Array.isArray(value) && value.length > 0) {
            products.value = value;
            open.value = true;
        }
    },
    { immediate: true },
);

const close = () => { open.value = false; };
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4" @click.self="close">
        <div class="w-full max-w-md rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-lg">
            <div class="flex items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-warning/15 text-warning">
                    <i class="fas fa-triangle-exclamation"></i>
                </span>
                <div class="min-w-0">
                    <h3 class="text-base font-semibold">Estoque baixo</h3>
                    <p class="text-sm text-slate-400">
                        {{ products.length === 1 ? '1 produto está acabando — hora de comprar mais.' : `${products.length} produtos estão acabando — hora de comprar mais.` }}
                    </p>
                </div>
            </div>

            <ul class="mt-4 max-h-64 space-y-1.5 overflow-y-auto">
                <li v-for="product in products" :key="product.id"
                    class="flex items-center justify-between gap-3 rounded-lg bg-[var(--surface-muted)] px-3 py-2 text-sm">
                    <span class="min-w-0 truncate">
                        <span class="block truncate font-medium">{{ product.name }}</span>
                        <span class="text-xs text-slate-400">{{ product.sku }}</span>
                    </span>
                    <span class="shrink-0 rounded-full bg-error/15 px-2 py-0.5 text-xs font-semibold text-error">
                        {{ product.stock }} un.
                    </span>
                </li>
            </ul>

            <div class="mt-5 flex items-center justify-end gap-2">
                <button type="button" class="rounded-lg px-4 py-2 text-sm font-medium text-slate-500 hover:bg-[var(--surface-muted)]" @click="close">
                    Fechar
                </button>
                <Link href="/admin/estoque" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis" @click="close">
                    Ver estoque
                </Link>
            </div>
        </div>
    </div>
</template>
