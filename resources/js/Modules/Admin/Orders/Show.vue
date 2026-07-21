<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    order: {
        type: Object,
        required: true,
    },
    statuses: {
        type: Array,
        default: () => [],
    },
});

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const form = useForm({
    status: props.order.status,
});

const updateStatus = () => {
    form.patch(`/admin/orders/${props.order.id}`);
};
</script>

<template>
    <Head :title="`Pedido #${order.id}`" />

    <AdminLayout>
        <h1 class="text-2xl font-bold">Pedido #{{ order.id }}</h1>

        <div class="mt-6 grid grid-cols-1 gap-8 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <div class="rounded-lg border border-gray-200 bg-white p-4">
                    <h2 class="font-semibold">Itens</h2>

                    <ul class="mt-4 space-y-2 text-sm">
                        <li v-for="item in order.items" :key="item.id" class="flex justify-between">
                            <span>{{ item.product_name }} × {{ item.quantity }}</span>
                            <span>{{ formatPrice(item.subtotal) }}</span>
                        </li>
                    </ul>

                    <div class="mt-4 flex justify-between border-t border-gray-200 pt-4 font-bold">
                        <span>Total</span>
                        <span>{{ formatPrice(order.total) }}</span>
                    </div>
                </div>

                <div class="mt-6 rounded-lg border border-gray-200 bg-white p-4">
                    <h2 class="font-semibold">Entrega</h2>
                    <p class="mt-2 text-sm text-gray-600">
                        {{ order.shipping_name }} — {{ order.shipping_phone }}<br>
                        {{ order.shipping_street }}, {{ order.shipping_number }}
                        <span v-if="order.shipping_complement">- {{ order.shipping_complement }}</span><br>
                        {{ order.shipping_neighborhood }} - {{ order.shipping_city }}/{{ order.shipping_state }}<br>
                        CEP {{ order.shipping_zip }}
                    </p>
                </div>
            </div>

            <div class="h-fit rounded-lg border border-gray-200 bg-white p-4">
                <h2 class="font-semibold">Cliente</h2>
                <p class="mt-2 text-sm text-gray-600">
                    {{ order.user?.name }}<br>
                    {{ order.user?.email }}
                </p>

                <form class="mt-6 space-y-2" @submit.prevent="updateStatus">
                    <label for="status" class="block text-sm font-medium">Status</label>
                    <select
                        id="status"
                        v-model="form.status"
                        class="w-full rounded border border-gray-300 px-3 py-2 text-sm"
                    >
                        <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
                    </select>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full rounded bg-gray-900 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:cursor-not-allowed disabled:bg-gray-300"
                    >
                        Atualizar status
                    </button>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
