<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, router } from '@inertiajs/vue3';
import { confirmDelete } from '@/Shared/notify';

const props = defineProps({
    purchaseOrder: { type: Object, required: true },
});

const { can } = usePermissions();
const statusLabels = { draft: 'Rascunho', sent: 'Enviado', received: 'Recebido', cancelled: 'Cancelado' };
const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const setStatus = (status) => {
    router.patch(`/admin/pedidos-de-compra/${props.purchaseOrder.id}/status`, { status }, { preserveScroll: true });
};

const receive = async () => {
    if (await confirmDelete({
        title: 'Confirmar recebimento?',
        text: 'Isso vai dar entrada no estoque de todos os itens deste pedido. Essa ação não pode ser desfeita.',
        confirmButtonText: 'Confirmar recebimento',
    })) {
        router.post(`/admin/pedidos-de-compra/${props.purchaseOrder.id}/receber`, {}, { preserveScroll: true });
    }
};
</script>

<template>
    <Head :title="`Pedido de compra #${purchaseOrder.id}`" />

    <AdminLayout>
        <div class="mb-4 flex items-center gap-3">
            <h1 class="text-2xl font-bold">Pedido de compra #{{ purchaseOrder.id }}</h1>
            <StatusBadge :status="purchaseOrder.status" :label="statusLabels[purchaseOrder.status] ?? purchaseOrder.status" />
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <h2 class="font-semibold">Itens</h2>
                <table class="mt-4 w-full text-left text-sm">
                    <thead class="border-b border-[var(--surface-border)] text-xs uppercase text-slate-400">
                        <tr>
                            <th class="py-2">Produto</th>
                            <th class="py-2">Qtd.</th>
                            <th class="py-2">Custo un.</th>
                            <th class="py-2 text-right">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in purchaseOrder.items" :key="item.id" class="border-b border-[var(--surface-border)] last:border-0">
                            <td class="py-2">{{ item.product?.name }}</td>
                            <td class="py-2">{{ item.quantity }}</td>
                            <td class="py-2">{{ formatPrice(item.unit_cost) }}</td>
                            <td class="py-2 text-right">{{ formatPrice(item.quantity * item.unit_cost) }}</td>
                        </tr>
                    </tbody>
                </table>
                <div class="mt-4 flex justify-between border-t border-[var(--surface-border)] pt-4 font-bold">
                    <span>Total</span>
                    <span>{{ formatPrice(purchaseOrder.total) }}</span>
                </div>
            </div>

            <div class="h-fit space-y-6">
                <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                    <h2 class="font-semibold">Fornecedor</h2>
                    <p class="mt-2 text-sm text-slate-500">
                        {{ purchaseOrder.supplier?.name }}<br>
                        {{ purchaseOrder.supplier?.email }}<br>
                        {{ purchaseOrder.supplier?.phone }}
                    </p>
                    <p v-if="purchaseOrder.notes" class="mt-4 text-sm text-slate-500">
                        <strong>Observações:</strong> {{ purchaseOrder.notes }}
                    </p>
                </div>

                <div v-if="can('operacional.edit') && purchaseOrder.status !== 'received'" class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                    <h2 class="mb-3 font-semibold">Ações</h2>
                    <div class="flex flex-col gap-2">
                        <button v-if="purchaseOrder.status === 'draft'" type="button" class="rounded-lg bg-info px-4 py-2 text-sm font-medium text-white hover:opacity-90" @click="setStatus('sent')">
                            Marcar como enviado
                        </button>
                        <button v-if="purchaseOrder.status !== 'cancelled'" type="button" class="rounded-lg bg-success px-4 py-2 text-sm font-medium text-white hover:opacity-90" @click="receive">
                            Confirmar recebimento (dá entrada no estoque)
                        </button>
                        <button v-if="purchaseOrder.status !== 'cancelled'" type="button" class="rounded-lg border border-error px-4 py-2 text-sm font-medium text-error hover:bg-error/10" @click="setStatus('cancelled')">
                            Cancelar pedido
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
