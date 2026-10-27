// Metadados das campanhas da Meta (2026-10-27): colunas de performance
// compartilhadas pelas tabelas de campanha, conjunto, anúncio e dia.
import { h } from 'vue';
import { formatarNumero, formatarReais, statusMeta } from '../metaAds';

const porcento = (v) => (v === null || v === undefined ? '-' : `${String(v.toFixed(2)).replace('.', ',')}%`);
const roas = (v) => (v === null || v === undefined ? '-' : String(v.toFixed(2)).replace('.', ','));

const direita = { cellClass: 'whitespace-nowrap text-right' };

// accessorFn com o número cru (ordena certo); cell formata.
const coluna = (id, header, formatar, titulo) => ({
    id,
    header,
    accessorFn: (row) => row.metricas?.[id] ?? -1,
    cell: ({ row }) => h('span', { title: titulo }, formatar(row.original.metricas?.[id])),
    meta: direita,
});

export const colunasDeMetricas = () => [
    coluna('gasto', 'Gasto', formatarReais),
    coluna('impressoes', 'Impressões', formatarNumero),
    coluna('cliques_link', 'Cliques no link', formatarNumero),
    coluna('ctr_link', 'CTR', porcento, 'Cliques no link ÷ impressões'),
    coluna('cpc_link', 'CPC', formatarReais, 'Gasto ÷ cliques no link'),
    coluna('cpm', 'CPM', formatarReais, 'Gasto por mil impressões'),
    coluna('visualizacoes_pagina', 'Visitas à página', formatarNumero),
    coluna('carrinho', 'Carrinho', formatarNumero),
    coluna('checkout', 'Checkout', formatarNumero),
    coluna('compras', 'Compras', formatarNumero),
    coluna('vendas', 'Vendas', formatarReais),
    coluna('roas', 'ROAS', roas, 'Vendas ÷ gasto'),
];

export const colunaStatus = () => ({
    id: 'status',
    header: 'Status',
    accessorFn: (row) => statusMeta(row.status)[0],
    cell: ({ row }) => {
        const [rotulo, cores] = statusMeta(row.original.status);
        return h('span', { class: `inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-bold ${cores}` }, rotulo);
    },
});

// Abas de status: Ativos, Pausados (inclui campanha/conjunto pausado), Excluídos, Todos.
export const ABAS_STATUS = [
    { label: 'Todos', value: 'all' },
    { label: 'Ativos', value: 'ativos' },
    { label: 'Pausados', value: 'pausados' },
    { label: 'Excluídos ou arquivados', value: 'excluidos' },
];

export const filtrarPorAba = (linhas, aba) => linhas.filter((l) => {
    if (aba === 'ativos') return l.status === 'ACTIVE';
    if (aba === 'pausados') return ['PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED'].includes(l.status);
    if (aba === 'excluidos') return ['DELETED', 'ARCHIVED'].includes(l.status);
    return true;
});

export { porcento, roas };
