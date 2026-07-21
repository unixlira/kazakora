<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    products: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
});

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const addToCart = (productId) => {
    router.post(
        '/cart',
        { product_id: productId, quantity: 1 },
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head title="Loja de Decoração" />

    <AppLayout>
        <!-- Page Header -->
        <div class="container-fluid page-header py-5 mb-5" style="background:#f8f9fa;">
            <div class="container py-5">
                <h1 class="display-3 text-capitalize mb-3">
                    {{ filters.search ? `Resultados para "${filters.search}"` : 'Nosso Catálogo' }}
                </h1>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><Link href="/" class="text-decoration-none">Início</Link></li>
                        <li class="breadcrumb-item active text-primary">Catálogo</li>
                    </ol>
                </nav>
            </div>
        </div>

        <!-- Products -->
        <div class="container-fluid py-5">
            <div class="container">
                <p v-if="products.data.length === 0" class="text-center text-muted py-5">
                    Nenhum produto encontrado.
                </p>

                <div v-else class="row g-4 product">
                    <div v-for="product in products.data" :key="product.id" class="col-md-6 col-lg-4">
                        <div class="product-item rounded">
                            <div class="product-item-inner border rounded">
                                <div class="product-item-inner-item">
                                    <div class="d-flex align-items-center justify-content-center bg-light rounded-top"
                                        style="height: 220px;">
                                        <i class="fas fa-couch fa-3x text-secondary opacity-50"></i>
                                    </div>
                                </div>
                                <div class="text-center rounded-bottom p-4">
                                    <span v-if="product.category" class="d-block mb-2 text-muted small">
                                        {{ product.category.name }}
                                    </span>
                                    <span class="d-block h4">{{ product.name }}</span>
                                    <span class="text-primary fs-5">{{ formatPrice(product.price) }}</span>
                                    <p class="mt-2 mb-0 small" :class="product.stock > 0 ? 'text-success' : 'text-danger'">
                                        {{ product.stock > 0 ? `${product.stock} em estoque` : 'Esgotado' }}
                                    </p>
                                </div>
                            </div>
                            <div class="product-item-add border border-top-0 rounded-bottom text-center p-4 pt-0">
                                <button type="button"
                                    class="btn btn-primary border-secondary rounded-pill py-2 px-4 mb-0 w-100"
                                    :disabled="product.stock < 1"
                                    @click="addToCart(product.id)">
                                    <i class="fas fa-shopping-cart me-2"></i> Adicionar ao Carrinho
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <nav v-if="products.last_page > 1" class="mt-5">
                    <ul class="pagination justify-content-center">
                        <template v-for="link in products.links" :key="link.label">
                            <li class="page-item" :class="{ active: link.active, disabled: !link.url }">
                                <Link v-if="link.url" class="page-link" :href="link.url" v-html="link.label" />
                                <span v-else class="page-link" v-html="link.label" />
                            </li>
                        </template>
                    </ul>
                </nav>
            </div>
        </div>
    </AppLayout>
</template>
