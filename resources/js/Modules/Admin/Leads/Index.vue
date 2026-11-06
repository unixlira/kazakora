<script setup>
// Leads (pedido do Lira 2026-11-06): o que o cliente digita no checkout,
// campo a campo (nome, CPF, e-mail, telefone, CEP), e os produtos do
// carrinho. A lista se atualiza sozinha a cada 5 segundos. Só leitura:
// nenhuma mensagem para o cliente sai daqui (falar com cliente pede OK do Lira).
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import { maskCpfCnpj, maskPhone } from '@/Shared/useMasks';
import { maskCep } from '@/Shared/useCep';
import { useServerTable } from '@/Shared/useServerTable';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, h, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    leads: { type: Object, default: () => ({ data: [] }) },
    resumo: { type: Object, default: () => ({ agora: 0, abertos: 0, convertidos: 0, valor_aberto: 0, atualizado_em: null }) },
    sites: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const { visit } = useServerTable('/admin/leads', {
    search: props.filters.search ?? null,
    status: props.filters.status ?? null,
    site: props.filters.site ?? null,
});

// Tempo real: recarga parcial (só lista e resumo) a cada 5 s, parada com a aba escondida.
const INTERVALO_MS = 5000;
let relogio = null;
let recarregando = false;
const recarregar = () => {
    if (document.hidden || recarregando) return;
    recarregando = true;
    router.reload({ only: ['leads', 'resumo'], preserveScroll: true, preserveState: true, onFinish: () => { recarregando = false; } });
};
onMounted(() => { relogio = setInterval(recarregar, INTERVALO_MS); });
onUnmounted(() => clearInterval(relogio));

const linhas = computed(() => props.leads?.data ?? []);
const nomeDoSite = (id) => props.sites.find((s) => s.id === id)?.nome ?? id;
const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value) || 0);
const formatDate = (value) => (value
    ? new Date(value).toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' })
    : '-');
const NOME_CAMPO = { nome: 'nome', email: 'e-mail', cpf: 'CPF', telefone: 'telefone', cep: 'CEP' };

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
            h('div', { class: 'flex items-center gap-1.5 font-medium' }, [
                row.original.agora ? h('span', { class: 'inline-block h-2 w-2 animate-pulse rounded-full bg-green-500', title: 'No checkout agora' }) : null,
                row.original.nome ?? 'Sem nome',
            ]),
            h('div', { class: 'text-xs text-slate-400' }, row.original.cpf ? maskCpfCnpj(row.original.cpf) : 'Sem CPF'),
        ]),
    },
    {
        id: 'contato',
        header: 'Contato',
        enableSorting: false,
        cell: ({ row }) => h('div', { class: 'text-sm' }, [
            h('div', {}, row.original.email ?? '-'),
            h('div', { class: 'text-xs text-slate-400' }, row.original.telefone ? maskPhone(row.original.telefone) : '-'),
        ]),
    },
    { id: 'cep', header: 'CEP', enableSorting: false, meta: { cellClass: 'whitespace-nowrap' }, cell: ({ row }) => (row.original.cep ? maskCep(row.original.cep) : '-') },
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
                tone: row.original.status === 'convertido' ? 'green' : (row.original.agora ? 'blue' : 'yellow'),
                label: row.original.status === 'convertido' ? 'Convertido' : (row.original.agora ? 'No checkout agora' : 'Abandonou'),
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
            h('div', { class: 'text-slate-400' }, [row.original.origem, row.original.utm_source].filter(Boolean).join(' · ') || '-'),
        ]),
    },
    {
        id: 'quando',
        header: 'Última atividade',
        enableSorting: false,
        meta: { cellClass: 'whitespace-nowrap' },
        cell: ({ row }) => h('div', { class: 'text-xs' }, [
            h('div', {}, formatDate(row.original.atualizado_em)),
            row.original.ultimo_campo ? h('div', { class: 'text-slate-400' }, `último campo: ${NOME_CAMPO[row.original.ultimo_campo] ?? row.original.ultimo_campo}`) : null,
            h('div', { class: 'text-slate-400' }, `começou ${formatDate(row.original.criado_em)}`),
        ]),
    },
];
</script>

<template>
    <Head title="Leads" />

    <AdminLayout>
        <h1 class="mb-2 text-2xl font-bold">Leads</h1>
        <p class="mb-4 text-sm text-slate-500">
            O que o cliente está preenchendo no checkout, campo a campo, com os produtos do carrinho. Atualiza sozinho a
            cada 5 segundos (última às {{ formatDate(resumo.atualizado_em) }}). Vira "Convertido" quando o pedido sai da
            mesma sessão, do mesmo e-mail ou do mesmo CPF. Só consulta: nada é enviado ao cliente daqui.
        </p>

        <div class="mb-4 grid gap-3 sm:grid-cols-4">
            <div class="rounded-lg border border-slate-200 p-4">
                <div class="text-xs text-slate-500">No checkout agora (5 min)</div>
                <div class="text-2xl font-bold tabular-nums">{{ resumo.agora }}</div>
            </div>
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
            search-placeholder="Buscar por nome, e-mail, telefone, CPF ou CEP..."
            empty-message="Nenhum lead ainda."
            @update:search="visit({ search: $event })"
            @update:active-tab="visit({ status: $event === 'all' ? null : $event })"
        />
    </AdminLayout>
</template>
