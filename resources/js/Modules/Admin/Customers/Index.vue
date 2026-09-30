<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import ActionIcon from '@/Shared/Components/ActionIcon.vue';
import { maskCpfCnpj, maskPhone } from '@/Shared/useMasks';
import { useServerTable, toTableSort } from '@/Shared/useServerTable';
import { Head, Link } from '@inertiajs/vue3';
import { computed, h } from 'vue';

const props = defineProps({
    // Paginator do Laravel (~50 por página) — busca e ordenação são query
    // param tratados em CustomerController@index/CustomerAggregator::paginate().
    customers: {
        type: Object,
        default: () => ({ data: [] }),
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
});

const { visit, sortParams } = useServerTable('/admin/clientes', {
    search: props.filters.search ?? null,
    sort: props.filters.sort ?? null,
    direction: props.filters.sort ? props.filters.direction : null,
});

const customerRows = computed(() => props.customers?.data ?? []);

// Mesmas cores já usadas em Admin/Orders/Index.vue pra canal — reaproveitar
// aqui deixa "Shopee" com a mesma cor em qualquer tela do admin.
const channelBadge = {
    loja: { color: 'shipped', label: 'Site' },
    mercado_livre: { color: 'pending', label: 'Mercado Livre' },
    shopee: { color: 'processing', label: 'Shopee' },
    tiktok_shop: { color: 'completed', label: 'TikTok Shop' },
    amazon: { color: '#146EB4', label: 'Amazon' },
};

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const formatDate = (value) => (value ? new Date(value).toLocaleDateString('pt-BR') : '—');

const columns = [
    {
        accessorKey: 'name',
        header: 'Cliente',
        cell: ({ row }) => h('div', {}, [
            h(Link, { href: `/admin/clientes/${row.original.document}`, class: 'font-medium hover:text-primary hover:underline' }, () => row.original.name ?? 'Sem nome'),
            h('div', { class: 'text-xs text-slate-400' }, maskCpfCnpj(row.original.document)),
        ]),
    },
    {
        id: 'contact',
        header: 'Contato',
        enableSorting: false,
        cell: ({ row }) => h('div', { class: 'text-sm' }, [
            h('div', {}, row.original.email ?? '—'),
            h('div', { class: 'text-xs text-slate-400' }, row.original.phone ? maskPhone(row.original.phone) : '—'),
        ]),
    },
    {
        id: 'origins',
        header: 'Canais',
        enableSorting: false,
        cell: ({ row }) => h('div', { class: 'flex flex-wrap gap-1' }, row.original.origins.map((origin) => {
            const badge = channelBadge[origin] ?? { color: origin, label: origin };
            return h(StatusBadge, { key: origin, status: badge.color, label: badge.label });
        })),
    },
    { accessorKey: 'orders_count', header: 'Pedidos' },
    { accessorKey: 'total_spent', header: 'Total gasto', cell: ({ row }) => formatPrice(row.original.total_spent) },
    {
        accessorKey: 'last_purchase_at',
        header: 'Última compra',
        cell: ({ row }) => formatDate(row.original.last_purchase_at),
    },
    {
        id: 'actions',
        header: 'Ações',
        enableSorting: false,
        cell: ({ row }) => h('div', { class: 'flex justify-end' }, h(ActionIcon, {
            icon: 'fa-chart-line',
            label: 'Ver analítico',
            color: 'blue',
            href: `/admin/clientes/${row.original.document}`,
        })),
    },
];
</script>

<template>
    <Head title="Clientes" />

    <AdminLayout>
        <h1 class="mb-4 text-2xl font-bold">Clientes</h1>
        <p class="mb-4 text-sm text-slate-500">
            Compradores reais, identificados pelo CPF/CNPJ — a mesma pessoa que compra pelo site e por um canal
            aparece uma vez só. Pedido sem documento identificado não entra aqui.
        </p>

        <DataTable
            :columns="columns"
            :data="customerRows"
            :paginator="customers"
            :search="filters.search ?? ''"
            :sort="toTableSort(filters.sort, filters.direction)"
            search-placeholder="Buscar por nome, e-mail, telefone ou CPF/CNPJ..."
            empty-message="Nenhum cliente encontrado."
            @update:search="visit({ search: $event })"
            @update:sort="visit(sortParams($event))"
        />
    </AdminLayout>
</template>
