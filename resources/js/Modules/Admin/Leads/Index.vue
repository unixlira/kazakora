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
import { computed, h, onMounted, onUnmounted, ref } from 'vue';
import FluxoCheckout from '../LeadsCheckout/FluxoCheckout.vue';

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

// "Parou em" + "Ver fluxo" (pedido do Lira 2026-11-23): a janela carrega o passo a passo sob demanda.
// Desde 25/11/2026 o "Ver fluxo" é o botão de olho da última coluna.
const fluxoLead = ref(null);
const COR_PAROU = { comprou: 'text-emerald-700', pagamento: 'text-violet-700', finalizar: 'text-rose-700', entrega: 'text-amber-700', identificacao: 'text-indigo-700' };

const abas = [
    { label: 'Todos', value: 'all' },
    { label: 'Abertos', value: 'aberto' },
    { label: 'Convertidos', value: 'convertido' },
];

// 6 colunas sem rolagem lateral (pedido do Lira 25/11/2026): Cliente junta nome, CPF, contato e CEP;
// Checkout junta produtos e total; Fluxo junta situação, pedido, "parou em" e origem; o olho abre o fluxo.
// Nenhum dado some, só muda a disposição. Larguras em meta.width com a tabela em table-layout fixed.
const columns = [
    {
        // Coluna própria do projeto (pedido do Lira em 15/11/2026).
        id: 'projeto',
        header: 'Projeto',
        enableSorting: false,
        meta: { width: '8%' },
        cell: ({ row }) => h('span', {
            class: ['inline-block rounded px-2 py-0.5 text-xs font-semibold', row.original.site === 'ferrakora' ? 'bg-indigo-100 text-indigo-800' : 'bg-orange-100 text-orange-800'],
        }, nomeDoSite(row.original.site)),
    },
    {
        id: 'cliente',
        header: 'Cliente',
        enableSorting: false,
        meta: { width: '21%' },
        cell: ({ row }) => {
            const l = row.original;
            const temDado = l.nome || l.email || l.telefone || l.cpf || l.cep;
            return h('div', { class: 'min-w-0 space-y-0.5' }, [
                h('div', { class: 'flex items-center gap-1.5 font-semibold' }, [
                    l.agora ? h('span', { class: 'inline-block h-2 w-2 shrink-0 animate-pulse rounded-full bg-green-500', title: 'No checkout agora' }) : null,
                    l.nome ?? (temDado ? 'Sem nome' : 'Só abriu o checkout'),
                ]),
                l.cpf ? h('div', { class: 'text-xs text-slate-500' }, maskCpfCnpj(l.cpf)) : (temDado ? null : h('div', { class: 'text-xs text-slate-400' }, 'Sem CPF')),
                l.email ? h('div', { class: 'break-all text-xs text-slate-600' }, l.email) : null,
                l.telefone ? h('div', { class: 'text-xs text-slate-600' }, maskPhone(l.telefone)) : null,
                l.cep ? h('div', { class: 'text-xs text-slate-500' }, `CEP ${maskCep(l.cep)}`) : null,
            ]);
        },
    },
    {
        // Produtos + total (o "Subtotal" separado só repetia o valor).
        id: 'checkout',
        header: 'Checkout',
        enableSorting: false,
        meta: { width: '35%' },
        cell: ({ row }) => h('div', { class: 'space-y-1 text-xs' }, [
            h('ul', { class: 'space-y-0.5' }, (row.original.itens ?? []).map((item) => h('li', { key: item.product_id, class: 'break-words' }, [
                h('span', { class: 'font-semibold' }, `${item.quantidade}x `),
                `${item.nome}${item.variacao ? ` (${item.variacao})` : ''}`,
                h('span', { class: 'text-slate-500' }, ` · ${formatPrice(item.preco_unitario)}`),
            ]))),
            (row.original.itens ?? []).length ? h('div', { class: 'text-sm font-bold' }, `Total: ${formatPrice(row.original.subtotal)}`) : null,
        ]),
    },
    {
        id: 'fluxo',
        header: 'Fluxo',
        enableSorting: false,
        meta: { width: '18%' },
        cell: ({ row }) => {
            const l = row.original;
            const origem = [l.origem, l.utm_source, l.utm_campaign].filter(Boolean).join(' · ');
            return h('div', { class: 'space-y-1' }, [
                h(StatusBadge, {
                    status: l.status,
                    tone: l.status === 'convertido' ? 'green' : (l.agora ? 'blue' : 'yellow'),
                    label: l.status === 'convertido' ? 'Convertido' : (l.agora ? 'No checkout agora' : 'Abandonou'),
                }),
                l.pedido
                    ? h(Link, { href: `/admin/pedidos/${l.pedido.id}`, class: 'block break-all text-xs text-sky-700 hover:underline' }, () => `Pedido ${l.pedido.numero ?? `#${l.pedido.id}`}`)
                    : null,
                h('div', { class: ['text-xs font-medium', COR_PAROU[l.parou?.etapa] ?? 'text-slate-600'] }, l.parou?.texto ?? '-'),
                origem ? h('div', { class: 'break-words text-[11px] text-slate-400' }, origem) : null,
            ]);
        },
    },
    {
        id: 'quando',
        header: 'Última atividade',
        enableSorting: false,
        meta: { width: '13%' },
        cell: ({ row }) => h('div', { class: 'text-xs' }, [
            h('div', {}, formatDate(row.original.atualizado_em)),
            row.original.ultimo_campo ? h('div', { class: 'text-slate-400' }, `último campo: ${NOME_CAMPO[row.original.ultimo_campo] ?? row.original.ultimo_campo}`) : null,
            h('div', { class: 'text-slate-400' }, `começou ${formatDate(row.original.criado_em)}`),
        ]),
    },
    {
        id: 'acao',
        header: '',
        enableSorting: false,
        meta: { width: '5%', cellClass: 'text-center' },
        cell: ({ row }) => h('button', {
            type: 'button',
            title: 'Ver fluxo',
            'aria-label': 'Ver fluxo',
            class: 'inline-flex h-8 w-8 items-center justify-center rounded-full border border-slate-300 text-sky-700 hover:bg-sky-50',
            onClick: () => { fluxoLead.value = row.original; },
        }, [h('i', { class: 'fas fa-eye text-sm', 'aria-hidden': 'true' })]),
    },
];
</script>

<template>
    <Head title="Leads" />

    <AdminLayout>
        <h1 class="mb-2 text-2xl font-bold">Leads</h1>
        <p class="mb-4 text-sm text-slate-500">
            O que o cliente está preenchendo no checkout, campo a campo, com os produtos do carrinho. Atualiza sozinho a
            cada 5 segundos (última às {{ formatDate(resumo.atualizado_em) }}). Vira "Convertido" quando o pedido da
            mesma sessão, do mesmo e-mail ou do mesmo CPF é pago. "Fluxo" mostra a situação, a etapa do checkout e a origem; o
            olho abre o passo a passo com o horário. Só consulta: nada é enviado ao cliente daqui.
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
            <label class="text-sm text-slate-600" for="filtro-site">Projeto</label>
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
            fixed
            search-placeholder="Buscar por nome, e-mail, telefone, CPF ou CEP..."
            empty-message="Nenhum lead ainda."
            @update:search="visit({ search: $event })"
            @update:active-tab="visit({ status: $event === 'all' ? null : $event })"
        />
        <FluxoCheckout :lead="fluxoLead" base="/admin/leads" @close="fluxoLead = null" />
    </AdminLayout>
</template>
