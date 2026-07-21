<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
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
        <div class="flex flex-wrap">
            <div class="w-full px-4 lg:w-6/12 xl:w-3/12">
                <CardStats stat-subtitle="PEDIDOS" :stat-title="String(stats.ordersCount)"
                    stat-icon-name="fas fa-receipt" stat-icon-color="bg-red-500" />
            </div>
            <div class="w-full px-4 lg:w-6/12 xl:w-3/12">
                <CardStats stat-subtitle="FATURAMENTO" :stat-title="formatPrice(stats.revenue)"
                    stat-icon-name="fas fa-sack-dollar" stat-icon-color="bg-orange-500" />
            </div>
            <div class="w-full px-4 lg:w-6/12 xl:w-3/12">
                <CardStats stat-subtitle="PRODUTOS" :stat-title="String(stats.productsCount)"
                    stat-icon-name="fas fa-couch" stat-icon-color="bg-emerald-500" />
            </div>
            <div class="w-full px-4 lg:w-6/12 xl:w-3/12">
                <CardStats stat-subtitle="ESTOQUE BAIXO" :stat-title="String(stats.lowStockCount)"
                    stat-icon-name="fas fa-triangle-exclamation" stat-icon-color="bg-rose-600" />
            </div>
        </div>

        <div class="mt-8 flex flex-wrap">
            <div class="w-full px-4 lg:w-6/12">
                <div class="rounded bg-white shadow-lg">
                    <div class="border-b border-slate-200 px-4 py-4">
                        <h3 class="text-base font-semibold text-slate-700">Pedidos recentes</h3>
                    </div>
                    <div class="p-4">
                        <p v-if="recentOrders.length === 0" class="text-sm text-slate-500">Nenhum pedido ainda.</p>
                        <ul v-else class="space-y-2 text-sm">
                            <li v-for="order in recentOrders" :key="order.id"
                                class="flex justify-between border-b border-slate-100 pb-2">
                                <Link :href="`/admin/orders/${order.id}`" class="text-slate-600 hover:underline">
                                    #{{ order.id }} — {{ order.user?.name }}
                                </Link>
                                <span class="font-medium">{{ formatPrice(order.total) }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="w-full px-4 lg:w-6/12">
                <div class="rounded bg-white shadow-lg">
                    <div class="border-b border-slate-200 px-4 py-4">
                        <h3 class="text-base font-semibold text-slate-700">Estoque baixo</h3>
                    </div>
                    <div class="p-4">
                        <p v-if="lowStockProducts.length === 0" class="text-sm text-slate-500">Nenhum produto com estoque baixo.</p>
                        <ul v-else class="space-y-2 text-sm">
                            <li v-for="product in lowStockProducts" :key="product.id"
                                class="flex justify-between border-b border-slate-100 pb-2">
                                <Link :href="`/admin/products/${product.id}/edit`" class="text-slate-600 hover:underline">
                                    {{ product.name }}
                                </Link>
                                <span class="font-medium text-rose-600">{{ product.stock }} un.</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
