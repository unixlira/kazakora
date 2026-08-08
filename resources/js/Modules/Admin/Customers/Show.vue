<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import { maskCpfCnpj, maskPhone } from '@/Shared/useMasks';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    customer: {
        type: Object,
        required: true,
    },
    products: {
        type: Array,
        default: () => [],
    },
    orders: {
        type: Array,
        default: () => [],
    },
});

const channelBadge = {
    loja: { color: 'shipped', label: 'Site' },
    mercado_livre: { color: 'pending', label: 'Mercado Livre' },
    shopee: { color: 'processing', label: 'Shopee' },
    tiktok_shop: { color: 'completed', label: 'TikTok Shop' },
    amazon: { color: 'active', label: 'Amazon' },
};

const statusLabels = {
    pending: 'Pendente',
    awaiting_payment: 'Aguardando pagamento',
    paid: 'Pago',
    shipped: 'Enviado',
    completed: 'Concluído',
    cancelled: 'Cancelado',
};

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const formatDate = (value) => (value ? new Date(value).toLocaleDateString('pt-BR') : '—');

const ticketMedio = props.customer.orders_count > 0
    ? props.customer.total_spent / props.customer.orders_count
    : 0;
</script>

<template>
    <Head :title="`Cliente — ${customer.name ?? 'Sem nome'}`" />

    <AdminLayout>
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold">{{ customer.name ?? 'Sem nome' }}</h1>
                <p class="text-sm text-slate-500">{{ maskCpfCnpj(customer.document) }}</p>
            </div>
            <Link href="/admin/clientes" class="text-sm text-primary hover:underline">
                <i class="fas fa-arrow-left mr-1"></i> Voltar para Clientes
            </Link>
        </div>

        <div class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
            <div class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
                <div>
                    <p class="text-xs font-medium uppercase text-slate-400">E-mail</p>
                    <p>{{ customer.email ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase text-slate-400">Telefone</p>
                    <p>{{ customer.phone ? maskPhone(customer.phone) : '—' }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase text-slate-400">Canais de compra</p>
                    <div class="mt-1 flex flex-wrap gap-1">
                        <StatusBadge v-for="origin in customer.origins" :key="origin"
                            :status="(channelBadge[origin] ?? { color: origin }).color"
                            :label="(channelBadge[origin] ?? { label: origin }).label" />
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <CardStats stat-subtitle="PEDIDOS" :stat-title="String(customer.orders_count)"
                stat-icon-name="fas fa-receipt" variant="primary" />
            <CardStats stat-subtitle="TOTAL GASTO" :stat-title="formatPrice(customer.total_spent)"
                stat-icon-name="fas fa-sack-dollar" variant="success" />
            <CardStats stat-subtitle="TICKET MÉDIO" :stat-title="formatPrice(ticketMedio)"
                stat-icon-name="fas fa-chart-line" variant="secondary" />
            <CardStats stat-subtitle="ÚLTIMA COMPRA" :stat-title="formatDate(customer.last_purchase_at)"
                stat-icon-name="fas fa-calendar-check" variant="info" />
        </div>

        <div class="mt-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
            <h2 class="mb-3 text-lg font-semibold">Produtos comprados</h2>
            <p v-if="!products.length" class="text-sm text-slate-400">Nenhuma compra concluída ainda.</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-[var(--surface-border)] text-xs uppercase text-slate-400">
                        <tr>
                            <th class="py-2 pr-4">Produto</th>
                            <th class="py-2 pr-4">Quantidade</th>
                            <th class="py-2 pr-4">Total gasto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="product in products" :key="product.product_id ?? product.product_name"
                            class="border-b border-[var(--surface-border)] last:border-0">
                            <td class="py-2 pr-4">
                                <Link v-if="product.product_id" :href="`/admin/produtos/${product.product_id}/editar`"
                                    class="hover:text-primary hover:underline">
                                    {{ product.product_name }}
                                </Link>
                                <span v-else>{{ product.product_name }}</span>
                            </td>
                            <td class="py-2 pr-4">{{ product.quantity }}</td>
                            <td class="py-2 pr-4">{{ formatPrice(product.total_spent) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
            <h2 class="mb-3 text-lg font-semibold">Histórico de pedidos</h2>
            <div class="space-y-3">
                <div v-for="order in orders" :key="order.id"
                    class="rounded-lg border border-[var(--surface-border)] p-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <Link :href="`/admin/pedidos/${order.id}`" class="font-medium hover:text-primary hover:underline">
                                #{{ order.id }}
                            </Link>
                            <StatusBadge :status="(channelBadge[order.origin] ?? { color: order.origin }).color"
                                :label="(channelBadge[order.origin] ?? { label: order.origin }).label" />
                            <StatusBadge :status="order.status" :label="statusLabels[order.status] ?? order.status" />
                        </div>
                        <div class="text-right text-sm">
                            <p class="font-medium">{{ formatPrice(order.total) }}</p>
                            <p class="text-xs text-slate-400">
                                {{ order.payment_method ?? 'Forma de pagamento não disponível' }} · {{ formatDate(order.created_at) }}
                            </p>
                        </div>
                    </div>
                    <ul class="mt-2 space-y-1 text-sm text-slate-500">
                        <li v-for="(item, index) in order.items" :key="index" class="flex justify-between">
                            <span>{{ item.quantity }}x {{ item.product_name }}</span>
                            <span>{{ formatPrice(item.subtotal) }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
