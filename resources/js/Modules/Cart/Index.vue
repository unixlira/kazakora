<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
    total: {
        type: Number,
        default: 0,
    },
});

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const updateQuantity = (productId, quantity) => {
    router.patch(`/cart/${productId}`, { quantity }, { preserveScroll: true });
};

const increment = (item) => updateQuantity(item.product.id, item.quantity + 1);
const decrement = (item) => updateQuantity(item.product.id, item.quantity - 1);

const removeItem = (productId) => {
    router.delete(`/cart/${productId}`, { preserveScroll: true });
};
</script>

<template>
    <Head title="Carrinho" />

    <AppLayout>
        <div class="container-fluid page-header py-5 mb-5" style="background:#f8f9fa;">
            <div class="container py-5">
                <h1 class="display-3 text-capitalize mb-3">Carrinho</h1>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><Link href="/" class="text-decoration-none">Início</Link></li>
                        <li class="breadcrumb-item active text-primary">Carrinho</li>
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

                <template v-else>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th scope="col">Produto</th>
                                    <th scope="col">Preço</th>
                                    <th scope="col">Quantidade</th>
                                    <th scope="col">Subtotal</th>
                                    <th scope="col"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="item in items" :key="item.product.id">
                                    <th scope="row"><p class="mb-0 py-4">{{ item.product.name }}</p></th>
                                    <td><p class="mb-0 py-4">{{ formatPrice(item.product.price) }}</p></td>
                                    <td>
                                        <div class="input-group quantity py-4" style="width: 120px;">
                                            <button type="button" class="btn btn-sm btn-minus rounded-circle bg-light border"
                                                @click="decrement(item)">
                                                <i class="fa fa-minus"></i>
                                            </button>
                                            <input type="text" class="form-control form-control-sm text-center border-0"
                                                :value="item.quantity" readonly>
                                            <button type="button" class="btn btn-sm btn-plus rounded-circle bg-light border"
                                                :disabled="item.quantity >= item.product.stock"
                                                @click="increment(item)">
                                                <i class="fa fa-plus"></i>
                                            </button>
                                        </div>
                                    </td>
                                    <td><p class="mb-0 py-4">{{ formatPrice(item.subtotal) }}</p></td>
                                    <td class="py-4">
                                        <button type="button" class="btn btn-md rounded-circle bg-light border"
                                            @click="removeItem(item.product.id)">
                                            <i class="fa fa-times text-danger"></i>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="row g-4 justify-content-end">
                        <div class="col-sm-8 col-md-7 col-lg-6 col-xl-4">
                            <div class="bg-light rounded">
                                <div class="py-4 mb-4 border-bottom d-flex justify-content-between">
                                    <h5 class="mb-0 ps-4 me-4">Total</h5>
                                    <p class="mb-0 pe-4 fs-5 text-primary fw-bold">{{ formatPrice(total) }}</p>
                                </div>
                                <Link href="/checkout"
                                    class="btn btn-primary rounded-pill px-4 py-3 text-uppercase mb-4 ms-4">
                                    Finalizar Compra
                                </Link>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </AppLayout>
</template>
