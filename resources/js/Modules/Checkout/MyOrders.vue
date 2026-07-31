<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    orders: { type: Object, required: true },
});

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const formatDate = (value) => new Intl.DateTimeFormat('pt-BR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));

const STATUS_LABELS = {
    pending: 'Pendente',
    awaiting_payment: 'Aguardando pagamento',
    paid: 'Pago',
    shipped: 'Enviado',
    completed: 'Concluído',
    cancelled: 'Cancelado',
};

const STATUS_STYLES = {
    pending: 'bg-store-bg-sunken text-store-fg-muted',
    awaiting_payment: 'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-300',
    paid: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300',
    shipped: 'bg-sky-100 text-sky-700 dark:bg-sky-900/50 dark:text-sky-300',
    completed: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300',
    cancelled: 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300',
};
</script>

<template>
    <Head title="Meus pedidos" />

    <AppLayout>
        <div class="mx-auto max-w-[900px] px-4 py-12 md:px-6">
            <h1 class="font-display text-3xl font-semibold">Meus pedidos</h1>

            <p v-if="orders.data.length === 0" class="mt-12 text-center text-store-fg-muted">
                Você ainda não fez nenhum pedido.
                <Link href="/" class="mt-3 block font-medium text-store-accent hover:underline">Ver catálogo</Link>
            </p>

            <div v-else class="mt-8 flex flex-col gap-4">
                <div v-for="order in orders.data" :key="order.id"
                    class="rounded-2xl border border-store-border bg-store-bg-raised p-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="font-semibold">Pedido #{{ order.id }}</p>
                            <p class="text-xs text-store-fg-muted">{{ formatDate(order.created_at) }}</p>
                        </div>
                        <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="STATUS_STYLES[order.status] ?? 'bg-store-bg-sunken text-store-fg-muted'">
                            {{ STATUS_LABELS[order.status] ?? order.status }}
                        </span>
                    </div>

                    <div class="mt-3 flex flex-col gap-1 border-t border-store-border pt-3 text-sm text-store-fg-muted">
                        <div v-for="item in order.items" :key="item.id" class="flex justify-between">
                            <span>{{ item.product_name }} × {{ item.quantity }}</span>
                            <span class="font-store-mono">{{ formatPrice(item.subtotal) }}</span>
                        </div>
                    </div>

                    <div class="mt-3 flex items-baseline justify-between border-t border-store-border pt-3">
                        <span class="text-sm font-semibold">Total</span>
                        <span class="font-semibold text-store-accent">{{ formatPrice(order.total) }}</span>
                    </div>
                </div>
            </div>

            <nav v-if="orders.last_page > 1" class="mt-8 flex flex-wrap justify-center gap-2">
                <template v-for="link in orders.links" :key="link.label">
                    <Link v-if="link.url" :href="link.url" preserve-state
                        class="rounded-lg px-3 py-1.5 text-sm"
                        :class="link.active ? 'bg-store-accent text-store-accent-contrast' : 'border border-store-border-strong text-store-fg hover:border-store-fg'"
                        v-html="link.label" />
                    <span v-else class="rounded-lg px-3 py-1.5 text-sm text-store-fg-faint" v-html="link.label" />
                </template>
            </nav>
        </div>
    </AppLayout>
</template>
