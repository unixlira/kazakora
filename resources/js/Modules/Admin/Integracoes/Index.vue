<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import { Head, Link, router } from '@inertiajs/vue3';
import { confirmDelete } from '@/Shared/notify';

const props = defineProps({
    integrations: { type: Array, default: () => [] },
});

const disconnect = async (integration) => {
    if (await confirmDelete({
        title: `Desconectar ${integration.name}?`,
        text: 'A loja vai parar de sincronizar produtos, estoque e pedidos com esse canal.',
        confirmButtonText: 'Sim, desconectar',
    })) {
        router.delete(`/admin/integracoes/${integration.channel}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Integrações" />

    <AdminLayout>
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="mb-1 text-2xl font-bold">Integrações</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Marketplaces conectados à loja.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Link href="/admin/integracoes/webhooks"
                    class="rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:bg-lightprimary">
                    Logs de webhook
                </Link>
                <Link href="/admin/integracoes/teste-impressao"
                    class="rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:bg-lightprimary">
                    Testar impressão de etiquetas
                </Link>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <div v-for="integration in props.integrations" :key="integration.channel"
                class="flex flex-col rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm transition-shadow hover:shadow-md">
                <div class="flex items-start justify-between">
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-lightprimary text-primary">
                        <i :class="integration.icon" class="text-xl"></i>
                    </div>
                    <StatusBadge :status="integration.connected ? 'active' : 'inactive'"
                        :label="integration.connected ? 'Conectado' : 'Não conectado'" />
                </div>

                <h3 class="mt-4 text-lg font-semibold">{{ integration.name }}</h3>
                <p class="mt-1 flex-1 text-sm text-slate-500 dark:text-slate-400">{{ integration.description }}</p>

                <p v-if="integration.connected && integration.accountLabel" class="mt-3 text-xs text-slate-400">
                    Conta: {{ integration.accountLabel }}
                </p>

                <div class="mt-4">
                    <a v-if="!integration.connected && integration.available" :href="integration.connectHref"
                        class="inline-flex w-full items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis">
                        Conectar
                    </a>
                    <button v-else-if="integration.connected" type="button"
                        class="w-full rounded-lg border border-error px-4 py-2 text-sm font-medium text-error hover:bg-error/10"
                        @click="disconnect(integration)">
                        Desconectar
                    </button>
                    <span v-else class="block w-full rounded-lg border border-[var(--surface-border)] px-4 py-2 text-center text-sm text-slate-400">
                        Em breve
                    </span>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
