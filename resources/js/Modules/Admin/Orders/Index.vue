<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import ActionIcon from '@/Shared/Components/ActionIcon.vue';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { h, reactive } from 'vue';

const { can } = usePermissions();

const props = defineProps({
    orders: {
        type: Array,
        default: () => [],
    },
    channels: {
        type: Array,
        default: () => [],
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
});

const channelBadge = {
    loja: { color: 'shipped', label: 'Site' },
    mercado_livre: { color: 'pending', label: 'Mercado Livre' },
    shopee: { color: 'processing', label: 'Shopee' },
    tiktok_shop: { color: 'completed', label: 'TikTok Shop' },
    amazon: { color: '#146EB4', label: 'Amazon' },
};

const filterState = reactive({ origin: props.filters.origin ?? '' });

const applyFilters = () => {
    router.get('/admin/pedidos', filterState, { preserveState: true });
};

// Botão "Corrigir etiquetas de hoje" — pedido explícito 2026-08-21, urgente
// (ver OrderController::fixTodaysLabels()): reprocessa toda etiqueta de
// Mercado Livre/Shopee marcada como pronta HOJE, pra pegar o layout
// corrigido depois das tentativas com bug de mais cedo no mesmo dia. Não
// precisa selecionar pedido nenhum — roda pra todos de uma vez.
const fixLabelsForm = useForm({});
const fixTodaysLabels = () => {
    if (!confirm('Isso vai baixar de novo a etiqueta de TODOS os pedidos de Mercado Livre/Shopee prontos hoje e substituir o PDF salvo pelo layout corrigido. Continuar?')) {
        return;
    }
    fixLabelsForm.post('/admin/pedidos/corrigir-etiquetas-hoje');
};

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const statusLabels = {
    pending: 'Pendente',
    paid: 'Pago',
    shipped: 'Enviado',
    completed: 'Concluído',
    cancelled: 'Cancelado',
};

// StatusBadge escolhe a cor pelo valor de "status" — reaproveitamos as
// cores já definidas lá (não são específicas de pedido) pra nota fiscal e
// e-mail em vez de duplicar uma paleta nova.
const invoiceBadge = {
    pending: { color: 'pending', label: 'Pendente' },
    signed: { color: 'shipped', label: 'Assinada' },
    sent: { color: 'shipped', label: 'Enviada à SEFAZ' },
    authorized: { color: 'completed', label: 'Emitida' },
    rejected: { color: 'cancelled', label: 'Rejeitada' },
    denied: { color: 'cancelled', label: 'Denegada' },
    cancelled: { color: 'cancelled', label: 'Cancelada' },
    error: { color: 'cancelled', label: 'Erro' },
};

const emailBadge = {
    sent: { color: 'completed', label: 'Enviado' },
    failed: { color: 'cancelled', label: 'Falhou' },
};

const columns = [
    {
        accessorKey: 'id',
        header: 'Pedido',
        cell: ({ row }) => h('div', {}, [
            h(Link, { href: `/admin/pedidos/${row.original.id}`, class: 'hover:text-primary hover:underline' }, () => `#${row.original.id}`),
            row.original.external_order_id
                ? h('div', { class: 'text-xs text-slate-400' }, row.original.external_order_id)
                : null,
        ]),
    },
    {
        id: 'origin',
        header: 'Canal',
        cell: ({ row }) => {
            const badge = channelBadge[row.original.origin] ?? { color: row.original.origin, label: row.original.origin };
            return h(StatusBadge, { status: badge.color, label: badge.label });
        },
    },
    { id: 'customer', header: 'Cliente', accessorFn: (row) => row.user?.name ?? '—' },
    { accessorKey: 'items_count', header: 'Itens' },
    { accessorKey: 'total', header: 'Total', cell: ({ row }) => formatPrice(row.original.total) },
    {
        accessorKey: 'status',
        header: 'Status',
        cell: ({ row }) => h(StatusBadge, { status: row.original.status, label: statusLabels[row.original.status] ?? row.original.status }),
    },
    {
        id: 'invoice_status',
        header: 'Nota Fiscal',
        cell: ({ row }) => {
            const status = row.original.invoice?.status;
            if (!status) return h('span', { class: 'text-xs text-slate-400' }, '—');
            const badge = invoiceBadge[status] ?? { color: status, label: status };
            return h(StatusBadge, { status: badge.color, label: badge.label });
        },
    },
    {
        id: 'email_status',
        header: 'E-mail',
        cell: ({ row }) => {
            const log = row.original.latest_email_log;
            if (!log) return h('span', { class: 'text-xs text-slate-400' }, '—');
            const badge = emailBadge[log.status] ?? { color: log.status, label: log.status };
            return h('div', { class: 'flex items-center gap-1.5' }, [
                h(StatusBadge, { status: badge.color, label: badge.label }),
                log.status === 'sent' && !log.invoice_attached
                    ? h('span', { class: 'text-xs text-amber-600 dark:text-amber-400', title: 'Enviado sem a nota fiscal em anexo' }, 'sem anexo')
                    : null,
            ]);
        },
    },
    {
        accessorKey: 'created_at',
        header: 'Data',
        cell: ({ row }) => new Date(row.original.created_at).toLocaleDateString('pt-BR'),
    },
    {
        id: 'actions',
        header: 'Ações',
        enableSorting: false,
        cell: ({ row }) => h('div', { class: 'flex justify-end' }, h(ActionIcon, {
            icon: 'fa-eye',
            label: 'Ver pedido',
            color: 'blue',
            href: `/admin/pedidos/${row.original.id}`,
        })),
    },
];
</script>

<template>
    <Head title="Pedidos" />

    <AdminLayout>
        <h1 class="mb-4 text-2xl font-bold">Pedidos</h1>

        <div class="mb-4 flex items-end gap-2">
            <div>
                <label class="text-xs font-medium text-slate-500">Canal</label>
                <select v-model="filterState.origin" @change="applyFilters"
                    class="mt-1 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm">
                    <option value="">Todos</option>
                    <option v-for="channel in props.channels" :key="channel" :value="channel">
                        {{ channelBadge[channel]?.label ?? channel }}
                    </option>
                </select>
            </div>

            <button v-if="can('pedidos.edit')" type="button" :disabled="fixLabelsForm.processing"
                @click="fixTodaysLabels"
                class="rounded-lg border border-amber-500 bg-amber-50 px-3 py-1.5 text-sm font-medium text-amber-700 hover:bg-amber-100 disabled:opacity-50 dark:bg-amber-500/10 dark:text-amber-400">
                <i class="fas fa-rotate" :class="{ 'animate-spin': fixLabelsForm.processing }"></i>
                Corrigir etiquetas de hoje (ML/Shopee)
            </button>
        </div>

        <DataTable
            :columns="columns"
            :data="props.orders"
            search-placeholder="Buscar pedido..."
            empty-message="Nenhum pedido encontrado."
            :create-label="can('pedidos.edit') ? 'Adicionar pedido manualmente' : null"
            :create-href="can('pedidos.edit') ? '/admin/pedidos/criar' : null"
        />
    </AdminLayout>
</template>
