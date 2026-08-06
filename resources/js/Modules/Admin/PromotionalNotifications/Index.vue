<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    campaigns: {
        type: Array,
        default: () => [],
    },
});

const { can } = usePermissions();

const form = useForm({
    title: '',
    message: '',
    link: '',
});

const submit = () => {
    form.post('/admin/notificacoes-promocionais', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <Head title="Notificações Promocionais" />

    <AdminLayout>
        <div class="mb-6">
            <h1 class="mb-1 text-2xl font-bold">Notificações Promocionais</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Manda um aviso (promoção, cupom, etc.) pra sineta de todos os clientes do site — nunca aparece pra admins.
            </p>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <form v-if="can('cadastros.create')" @submit.prevent="submit"
                class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm xl:col-span-1">
                <h2 class="mb-4 text-lg font-semibold">Nova notificação</h2>

                <div class="mb-4">
                    <label class="mb-1 block text-sm font-medium">Título *</label>
                    <input v-model="form.title" type="text" maxlength="100"
                        class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm"
                        placeholder="Ex: Cupom de 10% só hoje!" />
                    <InputError :message="form.errors.title" />
                </div>

                <div class="mb-4">
                    <label class="mb-1 block text-sm font-medium">Mensagem *</label>
                    <textarea v-model="form.message" rows="4" maxlength="500"
                        class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm"
                        placeholder="Ex: Use o cupom VOLTA10 até domingo em toda a loja."></textarea>
                    <InputError :message="form.errors.message" />
                </div>

                <div class="mb-4">
                    <label class="mb-1 block text-sm font-medium">Link (opcional)</label>
                    <input v-model="form.link" type="text"
                        class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm"
                        placeholder="/catalogo?tipo=destaque" />
                    <p class="mt-1 text-xs text-slate-400">Pra onde o cliente vai ao clicar na notificação. Deixe em branco se for só um aviso.</p>
                    <InputError :message="form.errors.link" />
                </div>

                <button type="submit" :disabled="form.processing"
                    class="w-full rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50">
                    {{ form.processing ? 'Enviando...' : 'Enviar pra todos os clientes' }}
                </button>
            </form>

            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm xl:col-span-2">
                <h2 class="p-5 pb-0 text-lg font-semibold">Histórico</h2>
                <div class="overflow-x-auto p-5">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-[var(--surface-border)] text-left text-xs uppercase text-slate-400">
                                <th class="pb-2 pr-4">Título</th>
                                <th class="pb-2 pr-4">Destinatários</th>
                                <th class="pb-2 pr-4">Enviado por</th>
                                <th class="pb-2">Quando</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="campaigns.length === 0">
                                <td colspan="4" class="py-6 text-center text-slate-400">Nenhuma notificação enviada ainda.</td>
                            </tr>
                            <tr v-for="campaign in campaigns" :key="campaign.id" class="border-b border-[var(--surface-border)] last:border-0">
                                <td class="py-2 pr-4">
                                    <div class="font-medium">{{ campaign.title }}</div>
                                    <div class="text-xs text-slate-400">{{ campaign.message }}</div>
                                </td>
                                <td class="py-2 pr-4">
                                    <span v-if="campaign.sent_at">{{ campaign.recipients_count }}</span>
                                    <span v-else class="text-xs text-warning">Enviando...</span>
                                </td>
                                <td class="py-2 pr-4">{{ campaign.creator?.name ?? '—' }}</td>
                                <td class="py-2 text-xs text-slate-400">{{ campaign.created_at }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
