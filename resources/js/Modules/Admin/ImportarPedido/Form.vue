<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    channels: { type: Array, default: () => [] },
});

const form = useForm({
    channel: props.channels[0]?.value ?? '',
    external_order_id: '',
});

const submit = () => {
    form.post('/admin/importar-pedido', {
        onSuccess: () => form.reset('external_order_id'),
    });
};
</script>

<template>
    <Head title="Importar Pedido" />

    <AdminLayout>
        <div class="mb-6">
            <h1 class="mb-1 text-2xl font-bold">Importar Pedido</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Quando um pedido existe de verdade no marketplace mas nunca chegou aqui (webhook perdido/atrasado) —
                busca ao vivo na API do canal pelo número do pedido, cria o pedido local e já dispara envio/etiqueta,
                exatamente como se o webhook tivesse chegado normalmente.
            </p>
        </div>

        <div class="max-w-xl rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
            <form class="space-y-4" @submit.prevent="submit">
                <div>
                    <label class="text-sm font-medium">Canal</label>
                    <select v-model="form.channel"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm">
                        <option v-for="channel in props.channels" :key="channel.value" :value="channel.value">
                            {{ channel.label }}
                        </option>
                    </select>
                    <p v-if="form.errors.channel" class="mt-1 text-xs text-error">{{ form.errors.channel }}</p>
                </div>

                <div>
                    <label class="text-sm font-medium">Número do pedido no canal</label>
                    <input v-model="form.external_order_id" type="text" placeholder="Ex: 2000012345678901"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm font-mono">
                    <p v-if="form.errors.external_order_id" class="mt-1 text-xs text-error">{{ form.errors.external_order_id }}</p>
                </div>

                <button type="submit"
                    :disabled="form.processing || !form.channel || !form.external_order_id"
                    class="w-full rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50">
                    {{ form.processing ? 'Buscando no canal...' : 'Buscar e importar pedido' }}
                </button>
            </form>
        </div>
    </AdminLayout>
</template>
