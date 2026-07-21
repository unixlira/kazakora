<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    stats: {
        type: Object,
        required: true,
    },
    recentOrders: {
        type: Array,
        default: () => [],
    },
    lowStockProducts: {
        type: Array,
        default: () => [],
    },
});

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
</script>

<template>
    <Head title="Dashboard" />

    <AdminLayout>
        <h1 class="text-2xl font-bold">Dashboard</h1>

        <div class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-xs uppercase text-gray-400">Pedidos</p>
                <p class="mt-1 text-2xl font-bold">{{ stats.ordersCount }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-xs uppercase text-gray-400">Faturamento</p>
                <p class="mt-1 text-2xl font-bold">{{ formatPrice(stats.revenue) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-xs uppercase text-gray-400">Produtos</p>
                <p class="mt-1 text-2xl font-bold">{{ stats.productsCount }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-xs uppercase text-gray-400">Estoque baixo</p>
                <p class="mt-1 text-2xl font-bold" :class="stats.lowStockCount > 0 ? 'text-red-500' : ''">
                    {{ stats.lowStockCount }}
                </p>
            </div>
        </div>

        <div class="mt-8 grid grid-cols-1 gap-8 lg:grid-cols-2">
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <h2 class="font-semibold">Pedidos recentes</h2>

                <p v-if="recentOrders.length === 0" class="mt-4 text-sm text-gray-500">
                    Nenhum pedido ainda.
                </p>

                <ul v-else class="mt-4 space-y-2 text-sm">
                    <li
                        v-for="order in recentOrders"
                        :key="order.id"
                        class="flex justify-between border-b border-gray-100 pb-2"
                    >
                        <Link :href="`/admin/orders/${order.id}`" class="hover:underline">
                            #{{ order.id }} — {{ order.user?.name }}
                        </Link>
                        <span>{{ formatPrice(order.total) }}</span>
                    </li>
                </ul>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <h2 class="font-semibold">Estoque baixo</h2>

                <p v-if="lowStockProducts.length === 0" class="mt-4 text-sm text-gray-500">
                    Nenhum produto com estoque baixo.
                </p>

                <ul v-else class="mt-4 space-y-2 text-sm">
                    <li
                        v-for="product in lowStockProducts"
                        :key="product.id"
                        class="flex justify-between border-b border-gray-100 pb-2"
                    >
                        <Link :href="`/admin/products/${product.id}/edit`" class="hover:underline">
                            {{ product.name }}
                        </Link>
                        <span class="text-red-500">{{ product.stock }} un.</span>
                    </li>
                </ul>
            </div>
        </div>
    </AdminLayout>
</template>
