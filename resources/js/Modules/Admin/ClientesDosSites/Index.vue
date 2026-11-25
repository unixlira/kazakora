<script setup>
// Clientes dos sites (pedido do Lira 2026-11-16): as contas criadas na
// KazaKora (users, sem a equipe) e na FerraKora (ferrakora_clientes), com os
// pedidos pagos de cada uma. Só leitura: nada é enviado, editado ou apagado
// daqui. O WhatsApp só abre a conversa no wa.me; quem escreve é a pessoa.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable } from '@/Shared/Components/DataTable';
import { maskCpfCnpj, maskPhone } from '@/Shared/useMasks';
import { useServerTable } from '@/Shared/useServerTable';
import { Head, Link } from '@inertiajs/vue3';
import { computed, h } from 'vue';

const props = defineProps({
    site: { type: String, default: 'kazakora' },
    sites: { type: Array, default: () => [] },
    clientes: { type: Object, default: () => ({ data: [] }) },
    filters: { type: Object, default: () => ({}) },
});

const { filters: atuais, visit } = useServerTable('/admin/clientes-dos-sites', {
    site: props.filters.site ?? props.site,
    search: props.filters.busca ?? null,
    de: props.filters.de ?? null,
    ate: props.filters.ate ?? null,
    pedido: props.filters.pedido ?? null,
    ordem: props.filters.ordem && props.filters.ordem !== 'recentes' ? props.filters.ordem : null,
});

const linhas = computed(() => props.clientes?.data ?? []);
const total = computed(() => props.clientes?.total ?? linhas.value.length);

// Mesmo filtro da tela vai para o CSV.
const linkCsv = computed(() => {
    const params = new URLSearchParams(
        Object.entries(atuais).filter(([, v]) => v !== null && v !== undefined && String(v).trim() !== ''),
    );

    return `/admin/clientes-dos-sites/exportar?${params.toString()}`;
});

const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value) || 0);
const formatDate = (value) => (value ? new Date(value).toLocaleDateString('pt-BR', { timeZone: 'America/Sao_Paulo' }) : '-');

const trocarSite = (id) => visit({ site: id, search: null, de: null, ate: null, pedido: null, ordem: null });

const abas = [
    { label: 'Todos', value: 'all' },
    { label: 'Com pedido', value: 'com' },
    { label: 'Sem pedido', value: 'sem' },
];

const ordens = [
    { value: '', label: 'Cadastro mais recente' },
    { value: 'antigos', label: 'Cadastro mais antigo' },
    { value: 'ultimo_pedido', label: 'Último pedido' },
    { value: 'maior_gasto', label: 'Maior gasto' },
    { value: 'nome', label: 'Nome (A-Z)' },
];

const columns = [
    {
        id: 'cliente',
        header: 'Cliente',
        enableSorting: false,
        cell: ({ row }) => h('div', {}, [
            h(Link, { href: `/admin/clientes-dos-sites/${props.site}/${row.original.id}`, class: 'font-medium text-sky-700 hover:underline' }, () => row.original.nome || 'Sem nome'),
            h('div', { class: 'text-xs text-slate-400' }, row.original.cpf ? maskCpfCnpj(row.original.cpf) : 'Sem CPF'),
        ]),
    },
    {
        id: 'contato',
        header: 'Contato',
        enableSorting: false,
        cell: ({ row }) => h('div', { class: 'text-sm' }, [
            h('div', { class: 'break-all' }, row.original.email ?? '-'),
            row.original.telefone
                ? h('div', { class: 'flex items-center gap-2 text-xs text-slate-500' }, [
                    maskPhone(row.original.telefone),
                    row.original.whatsapp
                        ? h('a', { href: row.original.whatsapp, target: '_blank', rel: 'noopener noreferrer', class: 'text-green-600 hover:text-green-700', title: 'Abrir conversa no WhatsApp' }, [h('i', { class: 'fab fa-whatsapp' })])
                        : null,
                ])
                : h('div', { class: 'text-xs text-slate-400' }, 'Sem telefone'),
        ]),
    },
    {
        id: 'local',
        header: 'Cidade/UF',
        enableSorting: false,
        cell: ({ row }) => (row.original.cidade ? `${row.original.cidade}/${row.original.uf ?? ''}` : '-'),
    },
    { id: 'cadastro', header: 'Cadastro', enableSorting: false, meta: { cellClass: 'whitespace-nowrap' }, cell: ({ row }) => formatDate(row.original.cadastro_em) },
    {
        id: 'promocoes',
        header: 'Promoções',
        enableSorting: false,
        cell: ({ row }) => (row.original.recebe_promocoes === null
            ? h('span', { class: 'text-xs text-slate-400', title: 'O cadastro da FerraKora não tem essa opção' }, '-')
            : h('span', { class: row.original.recebe_promocoes ? 'text-green-700' : 'text-slate-500' }, row.original.recebe_promocoes ? 'Aceita' : 'Não aceita')),
    },
    {
        // Selo em vez do número solto (pedido do Lira 25/11/2026): verde com pedido pago, cinza sem nenhum.
        id: 'pedidos', header: 'Pedidos pagos', enableSorting: false, meta: { cellClass: 'text-center' },
        cell: ({ row }) => {
            const n = Number(row.original.pedidos_pagos) || 0;
            return h('span', {
                class: ['inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold',
                    n > 0 ? 'bg-emerald-100 text-emerald-800 ring-1 ring-emerald-300' : 'bg-slate-100 text-slate-500 ring-1 ring-slate-200'],
                title: n > 0 ? `${n} pedido(s) pago(s)` : 'Nenhum pedido pago',
            }, n > 0 ? `${n} ${n === 1 ? 'pago' : 'pagos'}` : 'Sem compra');
        },
    },
    { id: 'gasto', header: 'Total gasto', enableSorting: false, meta: { cellClass: 'whitespace-nowrap' }, cell: ({ row }) => formatPrice(row.original.total_gasto) },
    { id: 'ultimo', header: 'Último pedido', enableSorting: false, meta: { cellClass: 'whitespace-nowrap' }, cell: ({ row }) => formatDate(row.original.ultimo_pedido_em) },
];
</script>

<template>
    <Head title="Clientes dos sites" />

    <AdminLayout>
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-2xl font-bold">Clientes dos sites</h1>
            <a :href="linkCsv" class="inline-flex items-center gap-2 rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                <i class="fas fa-file-csv"></i> Exportar CSV
            </a>
        </div>
        <p class="mb-4 text-sm text-slate-500">
            Contas criadas no site, com o cadastro e os pedidos pagos. Equipe e admin não aparecem. Só consulta: nada é
            enviado, alterado ou apagado daqui.
        </p>

        <div class="mb-4 flex gap-1 border-b border-slate-200">
            <button v-for="s in sites" :key="s.id" type="button"
                :class="['-mb-px border-b-2 px-4 py-2 text-sm font-semibold', s.id === site ? 'border-sky-600 text-sky-700' : 'border-transparent text-slate-500 hover:text-slate-700']"
                @click="trocarSite(s.id)">
                {{ s.nome }}
            </button>
        </div>

        <div class="mb-3 flex flex-wrap items-end gap-3 text-sm">
            <label class="flex flex-col gap-1 text-slate-600">
                Cadastro de
                <input type="date" class="rounded-md border border-slate-300 px-2 py-1" :value="filters.de ?? ''" @change="visit({ de: $event.target.value || null })">
            </label>
            <label class="flex flex-col gap-1 text-slate-600">
                até
                <input type="date" class="rounded-md border border-slate-300 px-2 py-1" :value="filters.ate ?? ''" @change="visit({ ate: $event.target.value || null })">
            </label>
            <label class="flex flex-col gap-1 text-slate-600">
                Ordenar por
                <select class="rounded-md border border-slate-300 px-2 py-1" :value="filters.ordem && filters.ordem !== 'recentes' ? filters.ordem : ''" @change="visit({ ordem: $event.target.value || null })">
                    <option v-for="o in ordens" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
            </label>
            <span class="ml-auto text-slate-500">{{ total }} {{ total === 1 ? 'cliente' : 'clientes' }}</span>
        </div>

        <DataTable
            :key="site"
            :columns="columns"
            :data="linhas"
            :paginator="clientes"
            :search="filters.busca ?? ''"
            :filter-tabs="abas"
            :initial-active-tab="filters.pedido ?? 'all'"
            dense
            search-placeholder="Buscar por nome, e-mail, telefone ou CPF..."
            empty-message="Nenhum cliente encontrado."
            @update:search="visit({ search: $event })"
            @update:active-tab="visit({ pedido: $event === 'all' ? null : $event })"
        />
    </AdminLayout>
</template>
