<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    logs: { type: Object, required: true },
    channels: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const STATUS_META = {
    received: { label: 'Recebido', color: 'bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-300' },
    processed: { label: 'Processado', color: 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300' },
    ignored: { label: 'Ignorado', color: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300' },
    rejected: { label: 'Rejeitado', color: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/50 dark:text-yellow-300' },
    failed: { label: 'Falhou', color: 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300' },
};

const channelFilter = ref(props.filters.channel ?? '');
const statusFilter = ref(props.filters.status ?? '');

const applyFilters = () => {
    router.get('/admin/integracoes/webhooks', {
        channel: channelFilter.value || undefined,
        status: statusFilter.value || undefined,
    }, { preserveState: true, replace: true });
};

const expandedId = ref(null);
const toggleExpand = (id) => {
    expandedId.value = expandedId.value === id ? null : id;
};
</script>

<template>
    <Head title="Logs de Webhook" />

    <AdminLayout>
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="mb-1 text-2xl font-bold">Logs de Webhook</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Todo webhook recebido de cada marketplace, com o que aconteceu depois (processado, ignorado, falhou).
                </p>
            </div>
            <Link href="/admin/integracoes" class="text-sm text-primary hover:underline">← Voltar para Integrações</Link>
        </div>

        <div class="mb-4 flex flex-wrap gap-3">
            <select v-model="channelFilter" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm" @change="applyFilters">
                <option value="">Todos os canais</option>
                <option v-for="channel in channels" :key="channel.value" :value="channel.value">{{ channel.label }}</option>
            </select>
            <select v-model="statusFilter" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm" @change="applyFilters">
                <option value="">Todos os status</option>
                <option v-for="status in statuses" :key="status.value" :value="status.value">{{ status.label }}</option>
            </select>
        </div>

        <div class="overflow-x-auto rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-[var(--surface-border)] text-xs uppercase text-slate-400">
                    <tr>
                        <th class="px-4 py-3">Recebido em</th>
                        <th class="px-4 py-3">Canal</th>
                        <th class="px-4 py-3">Tipo</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Detalhe</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--surface-border)]">
                    <template v-for="log in props.logs.data" :key="log.id">
                        <tr class="cursor-pointer hover:bg-[var(--surface-muted)]/50" @click="toggleExpand(log.id)">
                            <td class="whitespace-nowrap px-4 py-3 text-slate-500">{{ log.createdAt }}</td>
                            <td class="px-4 py-3 font-medium">{{ log.channel }}</td>
                            <td class="px-4 py-3">{{ log.eventType ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium"
                                    :class="STATUS_META[log.status]?.color ?? 'bg-slate-100 text-slate-700'">
                                    {{ STATUS_META[log.status]?.label ?? log.status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs text-primary">{{ expandedId === log.id ? 'Ocultar' : 'Ver payload' }}</td>
                        </tr>
                        <tr v-if="expandedId === log.id">
                            <td colspan="5" class="bg-[var(--surface-muted)]/30 px-4 py-4">
                                <p v-if="log.errorMessage" class="mb-2 text-sm text-error">{{ log.errorMessage }}</p>
                                <pre class="max-h-96 overflow-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100">{{ JSON.stringify(log.payload, null, 2) }}</pre>
                            </td>
                        </tr>
                    </template>

                    <tr v-if="props.logs.data.length === 0">
                        <td colspan="5" class="px-4 py-10 text-center text-slate-400">Nenhum webhook recebido ainda.</td>
                    </tr>
                </tbody>
            </table>

            <div v-if="props.logs.links.length > 3" class="flex flex-wrap items-center justify-center gap-1 border-t border-[var(--surface-border)] px-4 py-3">
                <template v-for="(link, index) in props.logs.links" :key="index">
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
