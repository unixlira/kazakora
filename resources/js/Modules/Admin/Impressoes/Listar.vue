<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import ActionIcon from '@/Shared/Components/ActionIcon.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { toRef } from 'vue';
import { usePollWhilePending } from '@/Shared/usePollWhilePending';
import { confirmDelete } from '@/Shared/notify';

const props = defineProps({
    jobs: { type: Object, required: true },
    statusFilter: { type: String, default: null },
    statusOptions: { type: Array, default: () => [] },
});

usePollWhilePending(toRef(props, 'jobs'));

const BADGE_STYLES = {
    yellow: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/50 dark:text-yellow-300',
    purple: 'bg-purple-100 text-purple-700 dark:bg-purple-900/50 dark:text-purple-300',
    green: 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300',
    red: 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300',
};

const filterByStatus = (status) => {
    router.get('/admin/impressoes/lista', status ? { status } : {}, { preserveState: true, preserveScroll: true });
};

const destroy = async (job) => {
    if (await confirmDelete({ title: `Remover a impressão #${job.id}?` })) {
        router.delete(`/admin/impressoes/${job.id}`, { preserveScroll: true, preserveState: true });
    }
};
</script>

<template>
    <Head title="Todas as Impressões" />

    <AdminLayout>
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="mb-1 text-2xl font-bold">Todas as Impressões</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Histórico completo dos jobs de impressão.</p>
            </div>
            <Link href="/admin/impressoes" class="text-sm text-primary hover:underline">
                &larr; Voltar pros cards
            </Link>
        </div>

        <div class="mb-4 flex items-center gap-2">
            <button type="button"
                class="rounded-full px-3 py-1.5 text-xs font-medium"
                :class="!props.statusFilter ? 'bg-primary text-white' : 'bg-[var(--surface-muted)] text-slate-500 hover:bg-[var(--surface-border)]/40'"
                @click="filterByStatus(null)">
                Todos
            </button>
            <button v-for="option in props.statusOptions" :key="option.value" type="button"
                class="rounded-full px-3 py-1.5 text-xs font-medium"
                :class="props.statusFilter === option.value ? 'bg-primary text-white' : 'bg-[var(--surface-muted)] text-slate-500 hover:bg-[var(--surface-border)]/40'"
                @click="filterByStatus(option.value)">
                {{ option.label }}
            </button>
        </div>

        <div class="overflow-x-auto rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-[var(--surface-border)] text-xs uppercase text-slate-400">
                    <tr>
                        <th class="px-4 py-3">Canal / Pedido</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Criado / Capturado / Impresso</th>
                        <th class="px-4 py-3">Detalhes</th>
                        <th class="px-4 py-3 text-right">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--surface-border)]">
                    <tr v-for="job in props.jobs.data" :key="job.id" class="hover:bg-[var(--surface-muted)]/50">
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center gap-1.5 font-medium">
                                <i :class="job.channelIcon" class="text-slate-400"></i>
                                {{ job.channel ?? '—' }}
                            </span>
                            <div class="text-xs text-slate-400">
                                <span v-if="job.orderId">Pedido #{{ job.orderId }}</span>
                                <span v-else>Sem pedido vinculado</span>
                                <span v-if="job.saleId"> · ID venda: {{ job.saleId }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium" :class="BADGE_STYLES[job.statusColor]">
                                {{ job.statusLabel }}
                            </span>
                        </td>
                        <td class="space-y-0.5 px-4 py-3 text-xs text-slate-500">
                            <div>Criado: {{ job.createdAt ?? '—' }}</div>
                            <div>Capturado: {{ job.claimedAt ?? '—' }}</div>
                            <div>Impresso: {{ job.printedAt ?? '—' }}</div>
                        </td>
                        <td class="px-4 py-3 text-slate-500">
                            <span v-if="job.errorMessage" class="text-error" :title="job.errorMessage">
                                <i class="fas fa-circle-exclamation"></i> {{ job.errorMessage }}
                            </span>
                            <span v-else-if="job.claimedBy" class="text-xs">Agente: {{ job.claimedBy }}</span>
                            <span v-else>—</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <ActionIcon icon="fa-eye" label="Visualizar" color="slate" :href="`/admin/impressoes/${job.id}`" />
                                <ActionIcon icon="fa-trash" label="Excluir" color="red" @click="destroy(job)" />
                            </div>
                        </td>
                    </tr>

                    <tr v-if="props.jobs.data.length === 0">
                        <td colspan="5" class="px-4 py-10 text-center text-slate-400">Nenhuma impressão encontrada.</td>
                    </tr>
                </tbody>
            </table>

            <div v-if="props.jobs.links.length > 3" class="flex flex-wrap items-center justify-center gap-1 border-t border-[var(--surface-border)] px-4 py-3">
                <template v-for="(link, index) in props.jobs.links" :key="index">
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
