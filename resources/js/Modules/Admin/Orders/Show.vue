<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import Can from '@/Shared/Components/Can.vue';
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
    form.patch(`/admin/pedidos/${props.order.id}`);
};
</script>

<template>
    <Head :title="`Pedido #${order.id}`" />

    <AdminLayout>
        <h1 class="text-2xl font-bold">Pedido #{{ order.id }}</h1>

        <div class="mt-6 grid grid-cols-1 gap-8 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                    <h2 class="font-semibold">Itens</h2>

                    <ul class="mt-4 space-y-2 text-sm">
                        <li v-for="item in order.items" :key="item.id" class="flex justify-between">
                            <span>{{ item.product_name }} × {{ item.quantity }}</span>
                            <span>{{ formatPrice(item.subtotal) }}</span>
                        </li>
                    </ul>

                    <div class="mt-4 flex justify-between border-t border-[var(--surface-border)] pt-4 font-bold">
                        <span>Total</span>
                        <span>{{ formatPrice(order.total) }}</span>
                    </div>
                </div>

                <div class="mt-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                    <h2 class="font-semibold">Entrega</h2>
                    <p class="mt-2 text-sm text-slate-500">
                        {{ order.shipping_name }} — {{ order.shipping_phone }}<br>
                        {{ order.shipping_street }}, {{ order.shipping_number }}
                        <span v-if="order.shipping_complement">- {{ order.shipping_complement }}</span><br>
                        {{ order.shipping_neighborhood }} - {{ order.shipping_city }}/{{ order.shipping_state }}<br>
                        CEP {{ order.shipping_zip }}
                    </p>
                </div>
            </div>

            <div class="h-fit rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <h2 class="font-semibold">Cliente</h2>
                <p class="mt-2 text-sm text-slate-500">
                    {{ order.user?.name }}<br>
                    {{ order.user?.email }}
                </p>

                <Can permission="pedidos.edit">
                    <form class="mt-6 space-y-2" @submit.prevent="updateStatus">
                        <label for="status" class="block text-sm font-medium">Status</label>
                        <select
                            id="status"
                            v-model="form.status"
                            class="w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm"
                        >
                            <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
                        </select>

                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="w-full rounded-lg bg-primary py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Atualizar status
                        </button>
                    </form>
                </Can>
            </div>
        </div>
    </AdminLayout>
</template>
