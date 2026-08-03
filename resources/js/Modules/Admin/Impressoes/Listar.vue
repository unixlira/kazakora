<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    jobs: { type: Object, required: true },
    statusFilter: { type: String, default: null },
    statusOptions: { type: Array, default: () => [] },
});

const BADGE_STYLES = {
    yellow: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/50 dark:text-yellow-300',
    purple: 'bg-purple-100 text-purple-700 dark:bg-purple-900/50 dark:text-purple-300',
    green: 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300',
    red: 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300',
};

const filterByStatus = (status) => {
    router.get('/admin/impressoes/lista', status ? { status } : {}, { preserveState: true, preserveScroll: true });
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
                        <th class="px-4 py-3">Job</th>
                        <th class="px-4 py-3">Canal</th>
                        <th class="px-4 py-3">Pedido</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Criado em</th>
                        <th class="px-4 py-3">Capturado em</th>
                        <th class="px-4 py-3">Impresso em</th>
                        <th class="px-4 py-3">Detalhes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--surface-border)]">
                    <tr v-for="job in props.jobs.data" :key="job.id" class="hover:bg-[var(--surface-muted)]/50">
                        <td class="px-4 py-3 font-medium">#{{ job.id }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center gap-1.5">
                                <i :class="job.channelIcon" class="text-slate-400"></i>
                                {{ job.channel ?? '—' }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div>Pedido #{{ job.orderId }}</div>
                            <div v-if="job.saleId" class="text-xs text-slate-400">ID venda: {{ job.saleId }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium" :class="BADGE_STYLES[job.statusColor]">
                                {{ job.statusLabel }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-slate-500">{{ job.createdAt ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ job.claimedAt ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ job.printedAt ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-500">
                            <span v-if="job.errorMessage" class="text-error" :title="job.errorMessage">
                                <i class="fas fa-circle-exclamation"></i> {{ job.errorMessage }}
                            </span>
                            <span v-else-if="job.claimedBy" class="text-xs">Agente: {{ job.claimedBy }}</span>
                            <span v-else>—</span>
                        </td>
                    </tr>

                    <tr v-if="props.jobs.data.length === 0">
                        <td colspan="8" class="px-4 py-10 text-center text-slate-400">Nenhuma impressão encontrada.</td>
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
