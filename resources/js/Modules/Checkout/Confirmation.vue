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
        <div class="container-fluid py-5">
            <div class="container py-5">
                <div class="text-center mb-5">
                    <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                    <h1 class="display-5">Pedido #{{ order.id }} confirmado!</h1>
                    <p class="text-muted">Enviamos os detalhes para o seu e-mail. Acompanhe abaixo.</p>
                </div>

                <div class="row justify-content-center">
                    <div class="col-lg-6">
                        <div class="bg-light rounded p-4">
                            <div v-for="item in order.items" :key="item.id"
                                class="d-flex justify-content-between mb-3">
                                <span>{{ item.product_name }} × {{ item.quantity }}</span>
                                <span>{{ formatPrice(item.subtotal) }}</span>
                            </div>
                            <div class="py-3 border-top d-flex justify-content-between">
                                <h5 class="mb-0">Total</h5>
                                <p class="mb-0 fs-5 text-primary fw-bold">{{ formatPrice(order.total) }}</p>
                            </div>
                            <p class="text-muted small mt-3 mb-0">
                                Entrega para: {{ order.shipping_street }}, {{ order.shipping_number }}
                                <span v-if="order.shipping_complement">- {{ order.shipping_complement }}</span><br>
                                {{ order.shipping_neighborhood }} - {{ order.shipping_city }}/{{ order.shipping_state }}<br>
                                CEP {{ order.shipping_zip }}
                            </p>
                        </div>

                        <div class="text-center mt-4">
                            <Link href="/" class="btn btn-primary rounded-pill px-5 py-3">Voltar ao catálogo</Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
