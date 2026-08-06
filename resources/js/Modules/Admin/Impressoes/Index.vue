<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import QueueCard from './QueueCard.vue';
import { Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
    channelCounts: { type: Array, default: () => [] },
    queue: { type: Array, default: () => [] },
});

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);

const current = computed(() => props.queue[0] ?? null);
const next = computed(() => props.queue[1] ?? null);
const rest = computed(() => props.queue.slice(2));

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

        <div v-if="current" class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
            <div class="space-y-6">
                <QueueCard :order="current" size="xl" />
                <QueueCard v-if="next" :order="next" size="lg" />
            </div>

            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
                <div class="border-b border-[var(--surface-border)] px-4 py-3">
                    <h3 class="font-semibold">Próximos na fila ({{ rest.length }})</h3>
                </div>
                <div class="max-h-[720px] divide-y divide-[var(--surface-border)] overflow-y-auto">
                    <div v-for="order in rest" :key="order.id" class="px-4 py-3">
                        <div class="flex items-center justify-between">
                            <span class="font-semibold">#{{ order.id }}</span>
                            <span class="rounded-full bg-lightwarning px-2 py-0.5 text-xs font-bold text-warning-emphasis">
                                {{ order.unitsCount }} un.
                            </span>
                        </div>
                        <p class="truncate text-sm text-slate-600 dark:text-slate-300">{{ order.customer || 'Cliente não informado' }}</p>
                        <p class="text-xs text-slate-400">
                            <i :class="order.channelIcon"></i> {{ order.channel }} · {{ order.createdAt }}
                        </p>
                    </div>
                    <p v-if="rest.length === 0" class="px-4 py-8 text-center text-sm text-slate-400">Nenhum outro pedido na fila.</p>
                </div>
            </div>
        </div>

        <div v-else class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-16 text-center shadow-sm">
            <i class="fas fa-circle-check mb-3 text-4xl text-success"></i>
            <p class="text-lg font-semibold">Tudo embalado!</p>
            <p class="text-sm text-slate-500 dark:text-slate-400">Nenhum pedido pago aguardando separação no momento.</p>
        </div>
    </AdminLayout>
</template>
