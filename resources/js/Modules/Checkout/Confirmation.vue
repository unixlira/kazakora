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
        <div class="mx-auto max-w-xl text-center">
            <h1 class="text-2xl font-bold">Pedido #{{ order.id }} confirmado!</h1>
            <p class="mt-2 text-gray-500">
                Enviamos os detalhes para o seu e-mail. Acompanhe abaixo.
            </p>
        </div>

        <div class="mx-auto mt-8 max-w-xl rounded-lg border border-gray-200 bg-white p-6">
            <ul class="space-y-2 text-sm">
                <li
                    v-for="item in order.items"
                    :key="item.id"
                    class="flex justify-between"
                >
                    <span>{{ item.product_name }} × {{ item.quantity }}</span>
                    <span>{{ formatPrice(item.subtotal) }}</span>
                </li>
            </ul>

            <div class="mt-4 flex justify-between border-t border-gray-200 pt-4 font-bold">
                <span>Total</span>
                <span>{{ formatPrice(order.total) }}</span>
            </div>

            <p class="mt-6 text-sm text-gray-500">
                Entrega para: {{ order.shipping_street }}, {{ order.shipping_number }}
                <span v-if="order.shipping_complement">- {{ order.shipping_complement }}</span><br>
                {{ order.shipping_neighborhood }} - {{ order.shipping_city }}/{{ order.shipping_state }}<br>
                CEP {{ order.shipping_zip }}
            </p>
        </div>

        <div class="mt-8 text-center">
            <Link href="/" class="underline">Voltar ao catálogo</Link>
        </div>
    </AppLayout>
</template>
