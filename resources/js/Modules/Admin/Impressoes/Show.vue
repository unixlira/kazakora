<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { confirmDelete } from '@/Shared/notify';

const props = defineProps({
    job: { type: Object, required: true },
});

const BADGE_STYLES = {
    yellow: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/50 dark:text-yellow-300',
    purple: 'bg-purple-100 text-purple-700 dark:bg-purple-900/50 dark:text-purple-300',
    green: 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300',
    red: 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300',
};

const destroy = async () => {
    if (await confirmDelete({ title: `Remover a impressão #${props.job.id}?` })) {
        router.delete(`/admin/impressoes/${props.job.id}`);
    }
};
</script>

<template>
    <Head :title="`Impressão #${job.id}`" />

    <AdminLayout>
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="mb-1 text-2xl font-bold">Impressão #{{ job.id }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Detalhe do job de impressão consumido pelo KoraSync.</p>
            </div>
            <Link href="/admin/impressoes/lista" class="text-sm text-primary hover:underline">
                &larr; Voltar pra listagem
            </Link>
        </div>

        <div class="max-w-2xl rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-6 shadow-sm">
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs uppercase text-slate-400">Status</dt>
                    <dd class="mt-1">
                        <span class="whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium" :class="BADGE_STYLES[job.statusColor]">
                            {{ job.statusLabel }}
                        </span>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-400">Canal</dt>
                    <dd class="mt-1 flex items-center gap-1.5">
                        <i :class="job.channelIcon" class="text-slate-400"></i>
                        {{ job.channel ?? '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-400">Pedido</dt>
                    <dd class="mt-1">
                        <Link v-if="job.orderId" :href="`/admin/pedidos/${job.orderId}`" class="text-primary hover:underline">
                            #{{ job.orderId }}
                        </Link>
                        <span v-else>—</span>
                        <div v-if="job.saleId" class="text-xs text-slate-400">ID venda: {{ job.saleId }}</div>
                        <div v-if="job.shippingName" class="text-xs text-slate-400">{{ job.shippingName }}</div>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-400">Agente que reivindicou</dt>
                    <dd class="mt-1">{{ job.claimedBy ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-400">Criado em</dt>
                    <dd class="mt-1">{{ job.createdAt ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-400">Capturado em</dt>
                    <dd class="mt-1">{{ job.claimedAt ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-400">Impresso em</dt>
                    <dd class="mt-1">{{ job.printedAt ?? '—' }}</dd>
                </div>
                <div v-if="job.errorMessage" class="sm:col-span-2">
                    <dt class="text-xs uppercase text-slate-400">Erro</dt>
                    <dd class="mt-1 text-error"><i class="fas fa-circle-exclamation"></i> {{ job.errorMessage }}</dd>
                </div>
            </dl>

            <div class="mt-6 flex flex-wrap gap-2 border-t border-[var(--surface-border)] pt-4">
                <a v-if="job.hasLabelFile" :href="`/admin/impressoes/${job.id}/pdf`" target="_blank" rel="noopener"
                    class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis">
                    <i class="fas fa-file-pdf"></i> Ver etiqueta (PDF)
                </a>
                <span v-else class="rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm text-slate-400">
                    Sem arquivo de etiqueta gravado
                </span>
                <button type="button"
                    class="rounded-lg border border-error px-4 py-2 text-sm font-medium text-error hover:bg-error/10"
                    @click="destroy">
                    <i class="fas fa-trash"></i> Excluir
                </button>
            </div>
        </div>
    </AdminLayout>
</template>
