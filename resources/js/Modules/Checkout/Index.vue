<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
    total: {
        type: Number,
        default: 0,
    },
    user: {
        type: Object,
        required: true,
    },
});

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const form = useForm({
    shipping_name: props.user.name,
    shipping_phone: '',
    shipping_zip: '',
    shipping_street: '',
    shipping_number: '',
    shipping_complement: '',
    shipping_neighborhood: '',
    shipping_city: '',
    shipping_state: '',
});

const submit = () => {
    form.post('/checkout');
};
</script>

<template>
    <Head title="Checkout" />

    <AppLayout>
        <div class="container-fluid page-header py-5 mb-5" style="background:#f8f9fa;">
            <div class="container py-5">
                <h1 class="display-3 text-capitalize mb-3">Checkout</h1>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><Link href="/" class="text-decoration-none">Início</Link></li>
                        <li class="breadcrumb-item active text-primary">Checkout</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="container-fluid py-5">
            <div class="container">
                <p v-if="items.length === 0" class="text-center text-muted py-5">
                    Seu carrinho está vazio.
                    <Link href="/" class="d-block mt-3">Ver catálogo</Link>
                </p>

                <div v-else class="row g-5">
                    <div class="col-lg-7">
                        <p v-if="form.errors.cart" class="text-danger">{{ form.errors.cart }}</p>

                        <h4 class="mb-4">Endereço de entrega</h4>
                        <form @submit.prevent="submit">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label">Nome completo<sup>*</sup></label>
                                    <input v-model="form.shipping_name" type="text" class="form-control" required>
                                    <div v-if="form.errors.shipping_name" class="text-danger small mt-1">{{ form.errors.shipping_name }}</div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Telefone<sup>*</sup></label>
                                    <input v-model="form.shipping_phone" type="tel" class="form-control" required>
                                    <div v-if="form.errors.shipping_phone" class="text-danger small mt-1">{{ form.errors.shipping_phone }}</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">CEP<sup>*</sup></label>
                                    <input v-model="form.shipping_zip" type="text" class="form-control" required>
                                    <div v-if="form.errors.shipping_zip" class="text-danger small mt-1">{{ form.errors.shipping_zip }}</div>
                                </div>

                                <div class="col-md-8">
                                    <label class="form-label">Rua<sup>*</sup></label>
                                    <input v-model="form.shipping_street" type="text" class="form-control" required>
                                    <div v-if="form.errors.shipping_street" class="text-danger small mt-1">{{ form.errors.shipping_street }}</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Número<sup>*</sup></label>
                                    <input v-model="form.shipping_number" type="text" class="form-control" required>
                                    <div v-if="form.errors.shipping_number" class="text-danger small mt-1">{{ form.errors.shipping_number }}</div>
                                </div>

                                <div class="col-12">
                                    <label class="form-label">Complemento</label>
                                    <input v-model="form.shipping_complement" type="text" class="form-control">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Bairro<sup>*</sup></label>
                                    <input v-model="form.shipping_neighborhood" type="text" class="form-control" required>
                                    <div v-if="form.errors.shipping_neighborhood" class="text-danger small mt-1">{{ form.errors.shipping_neighborhood }}</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Cidade<sup>*</sup></label>
                                    <input v-model="form.shipping_city" type="text" class="form-control" required>
                                    <div v-if="form.errors.shipping_city" class="text-danger small mt-1">{{ form.errors.shipping_city }}</div>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">UF<sup>*</sup></label>
                                    <input v-model="form.shipping_state" type="text" maxlength="2" class="form-control text-uppercase" required>
                                    <div v-if="form.errors.shipping_state" class="text-danger small mt-1">{{ form.errors.shipping_state }}</div>
                                </div>
                            </div>

                            <button type="submit" :disabled="form.processing"
                                class="btn btn-primary rounded-pill px-5 py-3 text-uppercase mt-4">
                                Confirmar Pedido
                            </button>
                        </form>
                    </div>

                    <div class="col-lg-5">
                        <div class="bg-light rounded p-4">
                            <h4 class="mb-4">Resumo do Pedido</h4>
                            <div v-for="item in items" :key="item.product.id"
                                class="d-flex justify-content-between mb-3">
                                <span>{{ item.product.name }} × {{ item.quantity }}</span>
                                <span>{{ formatPrice(item.subtotal) }}</span>
                            </div>
                            <div class="py-3 mt-3 border-top d-flex justify-content-between">
                                <h5 class="mb-0">Total</h5>
                                <p class="mb-0 fs-5 text-primary fw-bold">{{ formatPrice(total) }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
