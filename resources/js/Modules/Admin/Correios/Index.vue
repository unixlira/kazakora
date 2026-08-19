<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import ActionIcon from '@/Shared/Components/ActionIcon.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { confirmDelete } from '@/Shared/notify';

const props = defineProps({
    items: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

const STATUS_META = {
    gerada: { label: 'Gerada', color: 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300' },
    erro: { label: 'Falhou', color: 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300' },
};

const monthFilter = ref(props.filters.mes ?? '');
const searchFilter = ref(props.filters.pedido ?? '');

const applyFilters = () => {
    router.get('/admin/correios', {
        mes: monthFilter.value || undefined,
        pedido: searchFilter.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
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
        </div>

        <div class="overflow-x-auto rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-[var(--surface-border)] text-xs uppercase text-slate-400">
                    <tr>
                        <th class="px-4 py-3">Cliente</th>
                        <th class="px-4 py-3">Onde comprou</th>
                        <th class="px-4 py-3">Serviço</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Gerado em</th>
                        <th class="px-4 py-3">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--surface-border)]">
                    <tr v-for="item in props.items.data" :key="item.id" class="hover:bg-[var(--surface-muted)]/50">
                        <td class="px-4 py-3 font-medium">{{ item.customerName }}</td>
                        <td class="px-4 py-3">
                            {{ item.originLabel }}
                            <span v-if="item.externalOrderId" class="block text-xs text-slate-400">{{ item.externalOrderId }}</span>
                        </td>
                        <td class="px-4 py-3">{{ item.serviceLabel ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium"
                                :class="STATUS_META[item.status]?.color ?? 'bg-slate-100 text-slate-700'">
                                {{ STATUS_META[item.status]?.label ?? item.status }}
                            </span>
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
                        <td colspan="6" class="px-4 py-10 text-center text-slate-400">
                            Nenhuma pré-postagem gerada nesse mês{{ filters.pedido ? ' pra essa busca' : '' }}.
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
