<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import QueueCard from './QueueCard.vue';
import { DataTable } from '@/Shared/Components/DataTable';
import { Head, Link } from '@inertiajs/vue3';
import { computed, h, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
    channelCounts: { type: Array, default: () => [] },
    queue: { type: Array, default: () => [] },
});

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);

const current = computed(() => props.queue[0] ?? null);
const next = computed(() => props.queue[1] ?? null);

// Lista completa da fila em DataTable (busca/ordenação/paginação de graça,
// mesmo padrão já usado em Vendas — Mercado Livre e outras telas do admin) —
// antes era uma lista simples sem busca nem ordenação, pedido explícito do
// usuário 2026-08-07 pra padronizar. Os 2 cards "chamada de senha" acima
// continuam mostrando os mesmos pedidos #1/#2 da tabela — intencional,
// aquilo é o painel físico de separação, isso aqui é a lista/consulta.
const queueColumns = [
    {
        accessorKey: 'id',
        header: 'Pedido',
        cell: ({ row }) => h('div', {}, [
            h(Link, { href: `/admin/pedidos/${row.original.id}`, class: 'font-semibold hover:text-primary hover:underline' }, () => `#${row.original.id}`),
            row.original.externalOrderId
                ? h('div', { class: 'text-xs text-slate-400' }, row.original.externalOrderId)
                : null,
        ]),
    },
    { id: 'customer', header: 'Cliente', accessorFn: (row) => row.customer || 'Cliente não informado' },
    {
        id: 'channel',
        header: 'Canal',
        accessorFn: (row) => row.channel,
        cell: ({ row }) => h('span', { class: 'inline-flex items-center gap-1.5' }, [
            h('i', { class: [row.original.channelIcon, 'text-slate-400'] }),
            row.original.channel,
        ]),
    },
    {
        accessorKey: 'unitsCount',
        header: 'Itens',
        cell: ({ row }) => h('span', { class: 'rounded-full bg-lightwarning px-2 py-0.5 text-xs font-bold text-warning-emphasis' }, `${row.original.unitsCount} un.`),
    },
    {
        id: 'products',
        header: 'Produtos',
        accessorFn: (row) => row.products.join(', '),
        cell: ({ row }) => h('span', { class: 'block max-w-xs truncate text-slate-500', title: row.original.products.join(', ') }, row.original.products.join(', ')),
    },
    { accessorKey: 'createdAt', header: 'Criado em' },
];

// Botões de maximizar/minimizar além do F11/Esc nativos do navegador (que
// continuam funcionando do mesmo jeito, sem nenhum código aqui) — pedido
// explícito do usuário pra quem tá controlando por mouse/touch numa tela
// de expedição, sem teclado por perto.
const isFullscreen = ref(false);
const enterFullscreen = () => document.documentElement.requestFullscreen?.();
const exitFullscreen = () => document.exitFullscreen?.();
const handleFullscreenChange = () => { isFullscreen.value = Boolean(document.fullscreenElement); };

onMounted(() => document.addEventListener('fullscreenchange', handleFullscreenChange));
onBeforeUnmount(() => document.removeEventListener('fullscreenchange', handleFullscreenChange));
</script>

<template>
    <Head title="Painel de Expedição" />

    <AdminLayout>
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="mb-1 text-2xl font-bold">Painel de Expedição</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Pedidos pagos aguardando separação e envio, do mais recente pro mais antigo.</p>
            </div>
            <div class="flex gap-2">
                <button v-if="!isFullscreen" type="button" title="Maximizar"
                    class="inline-flex items-center gap-2 rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:bg-lightprimary"
                    @click="enterFullscreen">
                    <i class="fas fa-expand"></i> Maximizar
                </button>
                <button v-else type="button" title="Minimizar"
                    class="inline-flex items-center gap-2 rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:bg-lightprimary"
                    @click="exitFullscreen">
                    <i class="fas fa-compress"></i> Minimizar
                </button>
            </div>
        </div>

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                <p class="text-sm text-slate-500 dark:text-slate-400">Faturamento do mês</p>
                <p class="mt-1 text-2xl font-bold">{{ formatPrice(props.stats.revenueMonth) }}</p>
            </div>
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                <p class="text-sm text-slate-500 dark:text-slate-400">Faturamento de hoje</p>
                <p class="mt-1 text-2xl font-bold">{{ formatPrice(props.stats.revenueToday) }}</p>
            </div>
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                <p class="text-sm text-slate-500 dark:text-slate-400">Total de pedidos</p>
                <p class="mt-1 text-2xl font-bold">{{ props.stats.ordersTotal ?? 0 }}</p>
            </div>
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                <p class="mb-2 text-sm text-slate-500 dark:text-slate-400">Pedidos por canal</p>
                <div class="grid grid-cols-4 gap-2">
                    <div v-for="channel in props.channelCounts" :key="channel.channel" class="text-center">
                        <i :class="channel.icon" class="text-slate-400"></i>
                        <p class="text-lg font-bold">{{ channel.total }}</p>
                        <p class="truncate text-[0.65rem] text-slate-400">{{ channel.label }}</p>
                    </div>
                </div>
            </div>
        </div>

        <template v-if="current">
            <div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                <QueueCard :order="current" size="xl" />
                <QueueCard v-if="next" :order="next" size="lg" />
            </div>

            <h3 class="mb-3 font-semibold">Fila de separação ({{ props.queue.length }})</h3>
            <DataTable :columns="queueColumns" :data="props.queue" search-placeholder="Buscar por pedido, cliente ou canal..."
                empty-message="Nenhum pedido na fila." />
        </template>

        <div v-else class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-16 text-center shadow-sm">
            <i class="fas fa-circle-check mb-3 text-4xl text-success"></i>
            <p class="text-lg font-semibold">Tudo embalado!</p>
            <p class="text-sm text-slate-500 dark:text-slate-400">Nenhum pedido pago aguardando separação no momento.</p>
        </div>
    </AdminLayout>
</template>
