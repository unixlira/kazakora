<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    notifications: { type: Object, required: true },
});

// Clicar no corpo do aviso marca como lida (some da sineta a partir daí,
// ver HandleInertiaRequests::notificationsFor()) — só dispara se ainda não
// estava lida, reclicar numa já lida não faz nada (idempotente de qualquer
// forma, markRead() no backend não se importa, mas evita um POST à toa).
const openNotification = (notification) => {
    if (!notification.read) {
        router.post(`/notificacoes/${notification.id}/lida`, {}, { preserveScroll: true, preserveState: true });
    }

    if (notification.link) {
        router.visit(notification.link);
    }
};

const remove = (notification) => {
    router.delete(`/notificacoes/${notification.id}`, { preserveScroll: true, preserveState: true });
};

const markAllRead = () => router.post('/notificacoes/ler-todas', {}, { preserveScroll: true, preserveState: true });
</script>

<template>
    <Head title="Notificações" />

    <AdminLayout>
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="mb-1 text-2xl font-bold">Notificações</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Todo aviso que já chegou pra você — lido e não lido. A sineta no topo só mostra o que ainda não foi visto.
                </p>
            </div>
            <button type="button" class="rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:bg-[var(--surface-muted)]"
                @click="markAllRead">
                Marcar todas como lidas
            </button>
        </div>

        <div class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)]">
            <p v-if="props.notifications.data.length === 0" class="px-4 py-10 text-center text-sm text-slate-400">
                Nenhuma notificação por aqui ainda.
            </p>

            <ul v-else class="divide-y divide-[var(--surface-border)]">
                <li v-for="notification in props.notifications.data" :key="notification.id"
                    class="flex items-start gap-3 px-4 py-3" :class="{ 'bg-lightprimary': !notification.read }">
                    <span class="mt-2 h-2 w-2 shrink-0 rounded-full" :class="notification.read ? 'bg-transparent' : 'bg-primary'"></span>

                    <button type="button" class="min-w-0 flex-1 text-left" @click="openNotification(notification)">
                        <p class="text-sm text-slate-700 dark:text-slate-200">{{ notification.message }}</p>
                        <p v-if="notification.body" class="mt-0.5 text-sm text-slate-500">{{ notification.body }}</p>
                        <p class="mt-1 text-xs text-slate-400">{{ notification.createdAt }}</p>
                    </button>

                    <button type="button" title="Excluir" class="shrink-0 rounded-full p-2 text-slate-400 hover:bg-error/10 hover:text-error"
                        @click="remove(notification)">
                        <i class="fas fa-trash text-xs"></i>
                    </button>
                </li>
            </ul>

            <div v-if="props.notifications.links.length > 3" class="flex flex-wrap items-center justify-center gap-1 border-t border-[var(--surface-border)] px-4 py-3">
                <template v-for="(link, index) in props.notifications.links" :key="index">
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
