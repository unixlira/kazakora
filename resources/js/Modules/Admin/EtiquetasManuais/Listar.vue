<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import ActionIcon from '@/Shared/Components/ActionIcon.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { confirmDelete } from '@/Shared/notify';

const props = defineProps({
    jobs: { type: Object, required: true },
});

const STATUS_META = {
    queued: { label: 'Na fila', color: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/50 dark:text-yellow-300' },
    claimed: { label: 'Imprimindo', color: 'bg-purple-100 text-purple-700 dark:bg-purple-900/50 dark:text-purple-300' },
    printed: { label: 'Concluída', color: 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300' },
    failed: { label: 'Falhou', color: 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300' },
};

const destroy = async (job) => {
    if (await confirmDelete({ title: `Remover a etiqueta #${job.id}?` })) {
        router.delete(`/admin/etiquetas-manuais/${job.id}`);
    }
};
</script>

<template>
    <Head title="Etiquetas Manuais" />

    <AdminLayout>
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="mb-1 text-2xl font-bold">Etiquetas Manuais</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Etiquetas geradas manualmente por esta tela.</p>
            </div>
            <Link href="/admin/etiquetas-manuais/nova" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis">
                + Gerar etiqueta
            </Link>
        </div>

        <div class="overflow-x-auto rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-[var(--surface-border)] text-xs uppercase text-slate-400">
                    <tr>
                        <th class="px-4 py-3">Etiqueta</th>
                        <th class="px-4 py-3">Canal</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Criada em</th>
                        <th class="px-4 py-3">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--surface-border)]">
                    <tr v-for="job in props.jobs.data" :key="job.id" class="hover:bg-[var(--surface-muted)]/50">
                        <td class="px-4 py-3 font-medium">#{{ job.id }}</td>
                        <td class="px-4 py-3">{{ job.channel }}</td>
                        <td class="px-4 py-3">
                            <span class="whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium"
                                :class="STATUS_META[job.status]?.color ?? 'bg-slate-100 text-slate-700'">
                                {{ STATUS_META[job.status]?.label ?? job.status }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-slate-500">{{ job.createdAt ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <ActionIcon icon="fa-eye" label="Ver" color="slate" :href="`/admin/etiquetas-manuais/${job.id}`" />
                                <ActionIcon icon="fa-trash" label="Remover" color="red" @click="destroy(job)" />
                            </div>
                        </td>
                    </tr>

                    <tr v-if="props.jobs.data.length === 0">
                        <td colspan="5" class="px-4 py-10 text-center text-slate-400">Nenhuma etiqueta manual gerada ainda.</td>
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
