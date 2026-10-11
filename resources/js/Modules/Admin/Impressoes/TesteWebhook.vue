<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    channels: { type: Array, default: () => [] },
});

const form = useForm({
    channel: props.channels[0]?.value ?? '',
});

const selectedChannel = computed(() => props.channels.find((c) => c.value === form.channel));

const dispararTeste = () => {
    form.post('/admin/impressoes/teste-webhook');
};
</script>

<template>
    <Head title="Teste Webhook Marketplaces" />

    <AdminLayout>
        <div class="mb-6">
            <h1 class="mb-1 text-2xl font-bold">Teste Webhook Marketplaces</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Simula uma venda chegando de um marketplace — cria um pedido de teste de verdade (débita estoque, gera etiqueta,
                aparece no KoraSync e imprime), sem precisar esperar uma venda real.
            </p>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                <h2 class="font-semibold">1. Escolha o marketplace</h2>

                <form class="mt-4 space-y-4" @submit.prevent="dispararTeste">
                    <div>
                        <label for="channel" class="block text-sm font-medium">Marketplace</label>
                        <select
                            id="channel"
                            v-model="form.channel"
                            class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm"
                        >
                            <option v-for="channel in channels" :key="channel.value" :value="channel.value">
                                {{ channel.label }}
                            </option>
                        </select>
                        <p v-if="form.errors.channel" class="mt-1 text-xs text-error">{{ form.errors.channel }}</p>
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full rounded-lg bg-primary px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{ form.processing ? 'Disparando...' : 'Disparar teste' }}
                    </button>
                </form>

                <div class="mt-6 rounded-lg bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                    <strong>Como funciona:</strong> o pedido, itens e débito de estoque são reais (mesmo caminho de código do
                    webhook de verdade). A confirmação de envio é simulada — o pedido fake não existe de verdade no
                    marketplace, então a etiqueta é gerada diretamente em vez de consultar a API real do canal.
                </div>
            </div>

            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                <h2 class="font-semibold">2. Payload que será usado</h2>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Formato realista de como a API do canal devolve o pedido (não o webhook em si, que só traz um ID — ver
                    aviso no código). Um `external_order_id` único é gerado a cada disparo.
                </p>
                <pre
                    v-if="selectedChannel"
                    class="mt-3 max-h-[500px] overflow-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100"
                >{{ JSON.stringify(selectedChannel.payload, null, 2) }}</pre>
            </div>
        </div>
    </AdminLayout>
</template>
