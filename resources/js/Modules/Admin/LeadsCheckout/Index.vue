<script setup>
// Carrinhos abandonados (pedido 2026-10-24): quem clicou "Ir para Entrega" no
// checkout, com os itens e se virou pedido. Só leitura: nenhuma mensagem para
// o cliente sai daqui (falar com cliente pede OK do Lira).
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import { maskCpfCnpj, maskPhone } from '@/Shared/useMasks';
import { useServerTable } from '@/Shared/useServerTable';
import { Head, Link } from '@inertiajs/vue3';
import { computed, h } from 'vue';

const props = defineProps({
    leads: { type: Object, default: () => ({ data: [] }) },
    resumo: { type: Object, default: () => ({ abertos: 0, convertidos: 0, valor_aberto: 0 }) },
    sites: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const { visit } = useServerTable('/admin/carrinhos-abandonados', {
    search: props.filters.search ?? null,
    status: props.filters.status ?? null,
    site: props.filters.site ?? null,
});

const linhas = computed(() => props.leads?.data ?? []);
const nomeDoSite = (id) => props.sites.find((s) => s.id === id)?.nome ?? id;
const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value) || 0);
const formatDate = (value) => (value
    ? new Date(value).toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
    : '—');

const abas = [
    { label: 'Todos', value: 'all' },
    { label: 'Abertos', value: 'aberto' },
    { label: 'Convertidos', value: 'convertido' },
];

const columns = [
    {
        id: 'cliente',
        header: 'Cliente',
        enableSorting: false,
        cell: ({ row }) => h('div', {}, [
            h('div', { class: 'font-medium' }, row.original.nome ?? 'Sem nome'),
            h('div', { class: 'text-xs text-slate-400' }, row.original.cpf ? maskCpfCnpj(row.original.cpf) : 'Sem CPF'),
        ]),
    },
    {
        id: 'contato',
        header: 'Contato',
        enableSorting: false,
        cell: ({ row }) => h('div', { class: 'text-sm' }, [
            h('div', {}, row.original.email ?? '—'),
            h('div', { class: 'text-xs text-slate-400' }, row.original.telefone ? maskPhone(row.original.telefone) : '—'),
        ]),
    },
    {
        id: 'itens',
        header: 'Produtos',
        enableSorting: false,
        cell: ({ row }) => h('ul', { class: 'space-y-0.5 text-xs' }, (row.original.itens ?? []).map((item) => h('li', { key: item.product_id }, [
            h('span', { class: 'font-semibold' }, `${item.quantidade}x `),
            `${item.nome}${item.variacao ? ` (${item.variacao})` : ''}`,
            h('span', { class: 'text-slate-400' }, ` · ${formatPrice(item.preco_unitario)}`),
        ]))),
    },
    { id: 'subtotal', header: 'Subtotal', enableSorting: false, cell: ({ row }) => formatPrice(row.original.subtotal) },
    {
        id: 'status',
        header: 'Situação',
        enableSorting: false,
        cell: ({ row }) => h('div', { class: 'space-y-1' }, [
            h(StatusBadge, {
                status: row.original.status,
                tone: row.original.status === 'convertido' ? 'green' : 'yellow',
                label: row.original.status === 'convertido' ? 'Convertido' : `Aberto (${row.original.etapa})`,
            }),
            row.original.pedido
                ? h(Link, { href: `/admin/pedidos/${row.original.pedido.id}`, class: 'block text-xs text-sky-700 hover:underline' }, () => `Pedido ${row.original.pedido.numero ?? `#${row.original.pedido.id}`}`)
                : null,
        ]),
    },
    {
        id: 'origem',
        header: 'Loja / origem',
        enableSorting: false,
        cell: ({ row }) => h('div', { class: 'text-xs' }, [
            h('div', { class: 'font-medium' }, nomeDoSite(row.original.site)),
            h('div', { class: 'text-slate-400' }, [row.original.origem, row.original.utm_source].filter(Boolean).join(' · ') || '—'),
        ]),
    },
    {
        id: 'quando',
        header: 'Última atividade',
        enableSorting: false,
        meta: { cellClass: 'whitespace-nowrap' },
        cell: ({ row }) => h('div', { class: 'text-xs' }, [
            h('div', {}, formatDate(row.original.atualizado_em)),
            h('div', { class: 'text-slate-400' }, `criado ${formatDate(row.original.criado_em)}`),
        ]),
    },
];
</script>

<template>
    <Head title="Carrinhos abandonados" />

    <AdminLayout>
        <h1 class="mb-2 text-2xl font-bold">Carrinhos abandonados</h1>
        <p class="mb-4 text-sm text-slate-500">
            Quem preencheu a Identificação do checkout e clicou em "Ir para Entrega". Vira "Convertido" quando o
            pedido sai da mesma sessão, do mesmo e-mail ou do mesmo CPF. Só consulta: nada é enviado ao cliente daqui.
        </p>

        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <div class="rounded-lg border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Abertos</div>
                <div class="text-2xl font-bold tabular-nums">{{ resumo.abertos }}</div>
            </div>
            <div class="rounded-lg border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Valor em aberto</div>
                <div class="text-2xl font-bold tabular-nums">{{ formatPrice(resumo.valor_aberto) }}</div>
            </div>
            <div class="rounded-lg border border-slate-200 p-4">
                <div class="text-xs text-slate-500">Convertidos</div>
                <div class="text-2xl font-bold tabular-nums">{{ resumo.convertidos }}</div>
            </div>
        </div>

        <div class="mb-3 flex flex-wrap items-center gap-2">
            <label class="text-sm text-slate-600" for="filtro-site">Loja</label>
            <select id="filtro-site" class="rounded-md border border-slate-300 px-2 py-1 text-sm" :value="filters.site ?? ''" @change="visit({ site: $event.target.value || null })">
                <option value="">Todas</option>
                <option v-for="s in sites" :key="s.id" :value="s.id">{{ s.nome }}</option>
            </select>
        </div>

        <DataTable
            :columns="columns"
            :data="linhas"
            :paginator="leads"
            :search="filters.search ?? ''"
            :filter-tabs="abas"
            :initial-active-tab="filters.status ?? 'all'"
            dense
            search-placeholder="Buscar por nome, e-mail, telefone ou CPF..."
            empty-message="Nenhum carrinho abandonado ainda."
            @update:search="visit({ search: $event })"
            @update:active-tab="visit({ status: $event === 'all' ? null : $event })"
        />
    </AdminLayout>
</template>
