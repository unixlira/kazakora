<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import ActionIcon from '@/Shared/Components/ActionIcon.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { toRef } from 'vue';
import { confirmDelete } from '@/Shared/notify';
import { usePollWhilePending } from '@/Shared/usePollWhilePending';

const props = defineProps({
    jobs: { type: Object, required: true },
});

usePollWhilePending(toRef(props, 'jobs'));

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
                            <StatusBadge :status="job.status" context="print_job" />
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
