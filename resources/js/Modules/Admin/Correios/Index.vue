<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import ActionIcon from '@/Shared/Components/ActionIcon.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { confirmDelete } from '@/Shared/notify';

const props = defineProps({
    items: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

const monthFilter = ref(props.filters.mes ?? '');
const searchFilter = ref(props.filters.pedido ?? '');
const situacaoFilter = ref(props.filters.situacao ?? '');

const applyFilters = () => {
    router.get('/admin/correios', {
        mes: monthFilter.value || undefined,
        pedido: searchFilter.value || undefined,
        situacao: situacaoFilter.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

// Filtro rápido "Expiradas / não postadas" (2026-11-16): liga e desliga.
const toggleNaoPostadas = () => {
    situacaoFilter.value = situacaoFilter.value === 'nao_postadas' ? '' : 'nao_postadas';
    applyFilters();
};

const clearFilters = () => {
    searchFilter.value = '';
    situacaoFilter.value = '';
    applyFilters();
};

const destroy = async (item) => {
    if (await confirmDelete({ title: `Remover a pré-postagem de "${item.customerName}"?` })) {
        router.delete(`/admin/correios/${item.id}`);
    }
};
</script>

<template>
    <Head title="Correios" />

    <AdminLayout>
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="mb-1 text-2xl font-bold">Correios — Pré-Postagem</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Histórico dos QR Codes de pré-postagem gerados, por cliente e origem do pedido.
                </p>
            </div>
            <Link href="/admin/correios/nova" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis">
                <i class="fas fa-qrcode mr-1.5"></i>
                Gerar QR Code
            </Link>
        </div>

        <div class="mb-4 flex flex-wrap items-end gap-2 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Mês</label>
                <input v-model="monthFilter" type="month"
                    class="rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-1.5 text-sm"
                    @change="applyFilters" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Cliente ou pedido</label>
                <input v-model="searchFilter" type="text" placeholder="Nome, número do pedido ou rastreio"
                    class="w-64 rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-1.5 text-sm"
                    @keyup.enter="applyFilters" />
            </div>
            <button type="button" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis"
                @click="applyFilters">
                Filtrar
            </button>
            <button type="button"
                class="rounded-lg border px-4 py-2 text-sm font-medium"
                :class="situacaoFilter === 'nao_postadas'
                    ? 'border-red-300 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-900/30 dark:text-red-300'
                    : 'border-[var(--surface-border)] text-slate-600 hover:bg-[var(--surface-muted)] dark:text-slate-300'"
                @click="toggleNaoPostadas">
                <i class="fas fa-triangle-exclamation mr-1.5"></i>
                Expiradas / não postadas
            </button>
            <button v-if="filters.todoHistorico" type="button"
                class="rounded-lg px-3 py-2 text-sm text-slate-500 hover:text-primary"
                @click="clearFilters">
                Limpar
            </button>
        </div>

        <p v-if="filters.todoHistorico" class="mb-4 text-sm text-slate-500 dark:text-slate-400">
            <i class="fas fa-circle-info mr-1"></i>
            Com busca ou filtro rápido a lista mostra todo o histórico; o mês não é usado.
        </p>

        <div class="overflow-x-auto rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-[var(--surface-border)] text-xs uppercase text-slate-400">
                    <tr>
                        <th class="px-4 py-3">Cliente</th>
                        <th class="px-4 py-3">Onde comprou</th>
                        <th class="px-4 py-3">Serviço</th>
                        <th class="px-4 py-3">Valor</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Nos Correios</th>
                        <th class="px-4 py-3">Gerado em</th>
                        <th class="px-4 py-3">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--surface-border)]">
                    <tr v-for="item in props.items.data" :key="item.id" class="hover:bg-[var(--surface-muted)]/50">
                        <td class="px-4 py-3 font-medium">{{ item.customerName }}<span v-if="item.devolucao" class="ml-1.5 inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-300"><i class="fas fa-rotate-left" aria-hidden="true"></i> Devolução</span></td>
                        <td class="px-4 py-3">
                            {{ item.originLabel }}
                            <span v-if="item.externalOrderId" class="block text-xs text-slate-400">{{ item.externalOrderId }}</span>
                        </td>
                        <td class="px-4 py-3">{{ item.serviceLabel ?? '—' }}</td>
                        <td class="px-4 py-3">{{ item.postagePrice != null ? `R$ ${item.postagePrice.toFixed(2)}` : '—' }}</td>
                        <td class="px-4 py-3">
                            <StatusBadge :status="item.status" context="correios" />
                        </td>
                        <td class="px-4 py-3">
                            <template v-if="item.situacao">
                                <StatusBadge :label="item.situacaoLabel" :tone="item.situacaoTone" />
                                <span v-if="item.codigoObjeto" class="block pt-1 font-mono text-xs text-slate-400">{{ item.codigoObjeto }}</span>
                            </template>
                            <span v-else class="text-slate-400">—</span>
                        </td>
                        <td class="px-4 py-3 text-slate-500">{{ item.createdAt ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <ActionIcon icon="fa-qrcode" label="Ver / imprimir" color="blue" :href="`/admin/correios/${item.id}`" />
                                <ActionIcon v-if="item.status === 'erro'" icon="fa-pen" label="Corrigir e tentar de novo" color="amber" :href="`/admin/correios/${item.id}/editar`" />
                                <ActionIcon icon="fa-trash" label="Remover" color="red" @click="destroy(item)" />
                            </div>
                        </td>
                    </tr>

                    <tr v-if="props.items.data.length === 0">
                        <td colspan="8" class="px-4 py-10 text-center text-slate-400">
                            <template v-if="filters.todoHistorico">Nenhuma pré-postagem encontrada em todo o histórico.</template>
                            <template v-else>Nenhuma pré-postagem gerada nesse mês.</template>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div v-if="props.items.links.length > 3" class="flex flex-wrap items-center justify-center gap-1 border-t border-[var(--surface-border)] px-4 py-3">
                <template v-for="(link, index) in props.items.links" :key="index">
                    <Link v-if="link.url" :href="link.url" preserve-scroll preserve-state
                        class="rounded-lg px-3 py-1.5 text-sm"
                        :class="link.active ? 'bg-primary text-white' : 'text-slate-500 hover:bg-[var(--surface-muted)]'"
                        v-html="link.label" />
                    <span v-else class="rounded-lg px-3 py-1.5 text-sm text-slate-300" v-html="link.label"></span>
                </template>
            </div>
        </div>
    </AdminLayout>
</template>
