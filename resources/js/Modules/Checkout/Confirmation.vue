<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    order: {
        type: Object,
        required: true,
    },
});

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
</script>

<template>
    <Head title="Pedido confirmado" />

    <AppLayout>
        <div class="mx-auto max-w-[640px] px-4 py-16 text-center md:px-6">
            <i class="fas fa-circle-check mb-4 text-5xl text-store-accent"></i>
            <h1 class="font-display text-3xl font-semibold">Pedido #{{ order.id }} confirmado!</h1>
            <p class="mt-2 text-store-fg-muted">Enviamos os detalhes para o seu e-mail. Acompanhe abaixo.</p>

            <div class="mt-8 rounded-2xl border border-store-border bg-store-bg-raised p-6 text-left">
                <div class="flex flex-col gap-3 text-sm">
                    <div v-for="item in order.items" :key="item.id" class="flex justify-between">
                        <span>{{ item.product_name }} × {{ item.quantity }}</span>
                        <span class="font-store-mono">{{ formatPrice(item.subtotal) }}</span>
                    </div>
                </div>
                <div class="mt-4 flex items-baseline justify-between border-t border-store-border pt-4">
                    <span class="font-semibold">Total</span>
                    <span class="text-lg font-semibold text-store-accent">{{ formatPrice(order.total) }}</span>
                </div>
                <p class="mt-4 text-sm text-store-fg-muted">
                    Entrega para: {{ order.shipping_street }}, {{ order.shipping_number }}
                    <span v-if="order.shipping_complement">- {{ order.shipping_complement }}</span><br>
                    {{ order.shipping_neighborhood }} - {{ order.shipping_city }}/{{ order.shipping_state }}<br>
                    CEP {{ order.shipping_zip }}
                </p>
            </div>

            <Link href="/" class="mt-8 inline-block rounded-lg bg-store-accent px-6 py-3 text-sm font-semibold text-store-accent-contrast hover:opacity-90">
                Voltar ao catálogo
            </Link>
        </div>
    </AppLayout>
</template>
