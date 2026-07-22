<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    orders: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    statuses: {
        type: Array,
        default: () => [],
    },
});

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const filterByStatus = (event) => {
    router.get('/admin/pedidos', { status: event.target.value }, { preserveState: true });
};
</script>

<template>
    <Head title="Pedidos" />

    <AdminLayout>
        <h1 class="text-2xl font-bold">Pedidos</h1>

        <select
            class="mt-6 rounded border border-gray-300 px-3 py-2 text-sm"
            :value="filters.status ?? ''"
            @change="filterByStatus"
        >
            <option value="">Todos os status</option>
            <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
        </select>

        <div class="mt-6 overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-gray-200 text-xs uppercase text-gray-400">
                    <tr>
                        <th class="px-4 py-3">Pedido</th>
                        <th class="px-4 py-3">Cliente</th>
                        <th class="px-4 py-3">Itens</th>
                        <th class="px-4 py-3">Total</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Data</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="order in orders.data" :key="order.id" class="border-b border-gray-100">
                        <td class="px-4 py-3">
                            <Link :href="`/admin/pedidos/${order.id}`" class="hover:underline">#{{ order.id }}</Link>
                        </td>
                        <td class="px-4 py-3">{{ order.user?.name }}</td>
                        <td class="px-4 py-3">{{ order.items_count }}</td>
                        <td class="px-4 py-3">{{ formatPrice(order.total) }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs">{{ order.status }}</span>
                        </td>
                        <td class="px-4 py-3 text-gray-400">
                            {{ new Date(order.created_at).toLocaleDateString('pt-BR') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="orders.last_page > 1" class="mt-6 flex flex-wrap gap-2">
            <template v-for="link in orders.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-state
                    class="rounded px-3 py-1 text-sm"
                    :class="link.active ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'"
                    v-html="link.label"
                />
                <span v-else class="rounded px-3 py-1 text-sm text-gray-300" v-html="link.label" />
            </template>
        </nav>
    </AdminLayout>
</template>
