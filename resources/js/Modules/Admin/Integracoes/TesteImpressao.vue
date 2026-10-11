<script setup>
import { computed, ref } from 'vue';
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    channels: { type: Array, default: () => [] },
    orders: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
});

const form = useForm({
    channel: props.channels[0]?.value ?? '',
    order_id: '',
    product_ids: [],
    file: null,
});

const fileInput = ref(null);

const isShopee = computed(() => form.channel === 'shopee');
const acceptedExtension = computed(() => (isShopee.value ? '.txt' : '.pdf'));

const onChannelChange = () => {
    form.file = null;
    if (fileInput.value) fileInput.value.value = '';
};

const onFileSelect = (event) => {
    form.file = event.target.files[0] ?? null;
};

const submit = () => {
    form.post('/admin/integracoes/teste-impressao', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.file = null;
            if (fileInput.value) fileInput.value.value = '';
        },
    });
};
</script>

<template>
    <Head title="Teste de Impressão de Etiquetas" />

    <AdminLayout>
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="mb-1 text-2xl font-bold">Teste de Impressão de Etiquetas</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Tela exclusiva de teste — valida o processamento manual da etiqueta (conversão ZPL e inserção da
                    lista de produtos) antes de enviar pro agente de impressão (KoraSync).
                </p>
            </div>
            <Link href="/admin/integracoes" class="text-sm text-primary hover:underline">
                &larr; Voltar pra Integrações
            </Link>
        </div>

        <div class="max-w-xl rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
            <form class="space-y-4" @submit.prevent="submit">
                <div>
                    <label class="text-sm font-medium">Canal</label>
                    <select v-model="form.channel"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm"
                        @change="onChannelChange">
                        <option v-for="channel in props.channels" :key="channel.value" :value="channel.value">
                            {{ channel.label }}
                        </option>
                    </select>
                    <p v-if="form.errors.channel" class="mt-1 text-xs text-error">{{ form.errors.channel }}</p>
                </div>

                <div>
                    <label class="text-sm font-medium">Pedido (usado só pra vincular o job de impressão)</label>
                    <select v-model="form.order_id"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm">
                        <option value="" disabled>Selecione um pedido</option>
                        <option v-for="order in props.orders" :key="order.id" :value="order.id">
                            {{ order.label }}
                        </option>
                    </select>
                    <p v-if="form.errors.order_id" class="mt-1 text-xs text-error">{{ form.errors.order_id }}</p>
                </div>

                <div>
                    <label class="text-sm font-medium">Produtos que devem aparecer em destaque na etiqueta</label>
                    <select v-model="form.product_ids" multiple size="6"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm">
                        <option v-for="product in props.products" :key="product.id" :value="product.id">
                            {{ product.label }}
                        </option>
                    </select>
                    <p class="mt-1 text-xs text-slate-400">Segure Ctrl (ou Cmd) pra selecionar mais de um produto.</p>
                    <p v-if="form.errors.product_ids" class="mt-1 text-xs text-error">{{ form.errors.product_ids }}</p>
                </div>

                <div>
                    <label class="text-sm font-medium">
                        Arquivo da etiqueta ({{ isShopee ? 'TXT com ZPL' : 'PDF' }})
                    </label>
                    <input ref="fileInput" type="file" :accept="acceptedExtension"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm"
                        @change="onFileSelect">
                    <p v-if="form.errors.file" class="mt-1 text-xs text-error">{{ form.errors.file }}</p>
                </div>

                <button type="submit"
                    :disabled="form.processing || !form.file || !form.order_id || form.product_ids.length === 0"
                    class="w-full rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50">
                    {{ form.processing ? 'Processando...' : 'Processar e enfileirar impressão' }}
                </button>
            </form>
        </div>
    </AdminLayout>
</template>
