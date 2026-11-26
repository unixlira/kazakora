<script setup>
// Clientes dos sites (pedido do Lira 2026-11-16): as contas criadas na
// KazaKora (users, sem a equipe) e na FerraKora (ferrakora_clientes), com os
// pedidos pagos de cada uma. Só leitura: nada é enviado, editado ou apagado
// daqui. O WhatsApp só abre a conversa no wa.me; quem escreve é a pessoa.
// 26/11/2026: filtro por loja (as duas juntas ou uma só) e, para admin, as
// colunas de acompanhamento (acesso, foto, origem, pagamento, senha) e o
// link da linha do tempo completa.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable } from '@/Shared/Components/DataTable';
import { maskCpfCnpj, maskPhone } from '@/Shared/useMasks';
import { useServerTable } from '@/Shared/useServerTable';
import { Head, Link } from '@inertiajs/vue3';
import { computed, h } from 'vue';

const props = defineProps({
    site: { type: String, default: 'todas' },
    sites: { type: Array, default: () => [] },
    clientes: { type: Object, default: () => ({ data: [] }) },
    filters: { type: Object, default: () => ({}) },
    podeVerHistorico: { type: Boolean, default: false },
});

const { filters: atuais, visit } = useServerTable('/admin/clientes-dos-sites', {
    site: props.filters.site ?? props.site,
    search: props.filters.busca ?? null,
    de: props.filters.de ?? null,
    ate: props.filters.ate ?? null,
    pedido: props.filters.pedido ?? null,
    foto: props.filters.foto ?? null,
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
const formatDateTime = (value) => (value
    ? new Date(value).toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo', day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' })
    : '-');

const trocarSite = (id) => visit({ site: id, search: null, de: null, ate: null, pedido: null, foto: null, ordem: null });

const NOMES_LOJA = { kazakora: 'KazaKora', ferrakora: 'FerraKora' };
const CORES_LOJA = { kazakora: 'bg-sky-100 text-sky-800 ring-sky-300', ferrakora: 'bg-orange-100 text-orange-800 ring-orange-300' };
const CORES_SENHA = { redefiniu: 'text-amber-700', trocou: 'text-emerald-700', pediu_link: 'text-slate-600', temporaria: 'text-rose-700' };
const selo = (texto, classe, title = '') => h('span', { class: ['inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold ring-1', classe], title }, texto);

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

const colunaLoja = {
    id: 'loja',
    header: 'Loja',
    enableSorting: false,
    cell: ({ row }) => selo(NOMES_LOJA[row.original.site] ?? row.original.site, CORES_LOJA[row.original.site] ?? 'bg-slate-100 text-slate-700 ring-slate-200', 'Loja em que a conta foi criada'),
};

// Acompanhamento (só admin): vem pronto do servidor em row.original.acompanhamento.
const colunasDeAcompanhamento = [
    {
        id: 'acesso',
        header: 'Último acesso',
        enableSorting: false,
        meta: { cellClass: 'whitespace-nowrap' },
        cell: ({ row }) => {
            const a = row.original.acompanhamento ?? {};
            return h('div', { class: 'text-sm' }, [
                h('div', {}, a.ultimo_acesso_em ? formatDateTime(a.ultimo_acesso_em) : 'Sem registro'),
                h('div', { class: 'text-xs text-slate-400', title: 'Dias diferentes com login ou página vista logado' }, `${a.dias_com_acesso ?? 0} ${a.dias_com_acesso === 1 ? 'dia' : 'dias'} com acesso`),
            ]);
        },
    },
    {
        id: 'foto',
        header: 'Foto',
        enableSorting: false,
        meta: { cellClass: 'text-center' },
        cell: ({ row }) => (row.original.tem_foto
            ? h('i', { class: 'fas fa-circle-user text-emerald-600', title: 'Tem foto de perfil' })
            : h('span', { class: 'text-xs text-slate-400', title: 'Sem foto de perfil' }, '-')),
    },
    {
        id: 'origem',
        header: 'Origem do cadastro',
        enableSorting: false,
        cell: ({ row }) => {
            const o = row.original.acompanhamento?.origem;
            if (!o) return h('span', { class: 'text-xs text-slate-400' }, 'Sem registro');
            return h('div', { class: 'max-w-[220px] text-xs', title: o.estimada ? 'Estimada: 1ª visita de fora ligada à conta ou carrinho do mesmo e-mail' : (o.via ?? '') }, [
                h('span', { class: 'text-slate-700' }, o.texto),
                o.estimada ? h('span', { class: 'ml-1 text-slate-400' }, '(estimada)') : null,
            ]);
        },
    },
    {
        id: 'pagamento',
        header: 'Pagamento',
        enableSorting: false,
        cell: ({ row }) => {
            const pgs = row.original.acompanhamento?.pagamentos ?? [];
            if (!pgs.length) return h('span', { class: 'text-xs text-slate-400' }, '-');
            return h('div', { class: 'flex flex-wrap gap-1' }, pgs.map((p) => selo(
                p.nome,
                p.aprovado ? 'bg-emerald-50 text-emerald-800 ring-emerald-200' : 'bg-slate-50 text-slate-500 ring-slate-200',
                `${p.vezes} tentativa(s)${p.aprovado ? ', com pagamento aprovado' : ', nenhuma aprovada'}`,
            )));
        },
    },
    {
        id: 'senha',
        header: 'Senha',
        enableSorting: false,
        cell: ({ row }) => {
            const s = row.original.acompanhamento?.senha;
            if (!s) return h('span', { class: 'text-xs text-slate-400' }, '-');
            return h('div', { class: ['text-xs', CORES_SENHA[s.status] ?? 'text-slate-600'] }, [
                h('div', {}, s.texto),
                s.em ? h('div', { class: 'text-slate-400' }, formatDate(s.em)) : null,
            ]);
        },
    },
];

const colunasBase = [
    {
        id: 'cliente',
        header: 'Cliente',
        enableSorting: false,
        cell: ({ row }) => h('div', {}, [
            h('div', { class: 'flex items-center gap-2' }, [
                h(Link, { href: `/admin/clientes-dos-sites/${row.original.site}/${row.original.id}`, class: 'font-medium text-sky-700 hover:underline' }, () => row.original.nome || 'Sem nome'),
                props.podeVerHistorico
                    ? h(Link, { href: `/admin/clientes-dos-sites/${row.original.site}/${row.original.id}/historico`, class: 'text-xs text-slate-400 hover:text-sky-700', title: 'Linha do tempo completa' }, () => [h('i', { class: 'fas fa-timeline' })])
                    : null,
            ]),
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

const columns = computed(() => [
    ...(props.site === 'todas' ? [colunaLoja] : []),
    ...colunasBase,
    ...(props.podeVerHistorico ? colunasDeAcompanhamento : []),
]);
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
            Contas criadas na KazaKora e na FerraKora, com o cadastro e os pedidos pagos. Equipe e admin não aparecem. Só
            consulta: nada é enviado, alterado ou apagado daqui.
            <template v-if="podeVerHistorico">
                Acesso, origem e senha são gravados por completo desde 26/11/2026; antes disso a tela mostra o que já existia
                (páginas vistas logado, carrinho, pedidos). O ícone <i class="fas fa-timeline"></i> abre a linha do tempo do cliente.
            </template>
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
                Foto de perfil
                <select class="rounded-md border border-slate-300 px-2 py-1" :value="filters.foto ?? ''" @change="visit({ foto: $event.target.value || null })">
                    <option value="">Todas</option>
                    <option value="com">Com foto</option>
                    <option value="sem">Sem foto</option>
                </select>
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
