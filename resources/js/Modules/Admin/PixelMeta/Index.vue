<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    pixelId: { type: String, default: null },
    origem: { type: String, default: null },
    pixelEnv: { type: String, default: null },
});

const form = useForm({
    pixel_id: props.origem === 'admin' ? props.pixelId : '',
});

const submit = () => {
    form.put('/admin/pixel-meta', { preserveScroll: true });
};

const voltarAoPadrao = () => {
    form.pixel_id = '';
    submit();
};
</script>

<template>
    <Head title="Pixel da Meta" />

    <AdminLayout>
        <h1 class="text-2xl font-bold">Pixel da Meta</h1>
        <p class="mt-1 text-sm text-slate-500">
            O pixel mede visitas, produtos vistos, início de checkout e compras do site para os anúncios da Meta.
            Ele só carrega para o cliente que deu OK no aviso de cookies.
        </p>

        <div class="mt-6 max-w-2xl space-y-4">
            <div class="rounded-xl border border-[var(--surface-border)] p-4">
                <div class="flex items-center gap-2">
                    <span class="font-semibold">Pixel em uso</span>
                    <StatusBadge :tone="pixelId ? 'green' : 'yellow'" :label="pixelId ? 'Ativo no site' : 'Sem pixel'" />
                </div>
                <p class="mt-2 font-mono text-lg">{{ pixelId || 'Nenhum' }}</p>
                <p class="mt-1 text-sm text-slate-500">
                    <template v-if="origem === 'admin'">Definido nesta tela.</template>
                    <template v-else-if="origem === 'env'">Padrão do servidor (.env). Salve um ID abaixo para trocar.</template>
                    <template v-else>Nenhum ID configurado: o site não carrega o pixel.</template>
                </p>
            </div>

            <form class="space-y-3 rounded-xl border border-[var(--surface-border)] p-4" @submit.prevent="submit">
                <label for="pixel_id" class="block font-semibold">Novo ID do Pixel</label>
                <input id="pixel_id" v-model.trim="form.pixel_id" type="text" inputmode="numeric" autocomplete="off"
                    :placeholder="pixelEnv || 'Ex.: 1234567890123456'"
                    class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 font-mono">
                <p class="text-sm text-slate-500">
                    Só números, como aparece no Gerenciador de Eventos da Meta. Ao salvar, o site passa a usar o novo ID em até 1 minuto.
                </p>
                <p v-if="form.errors.pixel_id" class="text-sm text-red-600">{{ form.errors.pixel_id }}</p>

                <div class="flex flex-wrap gap-2">
                    <button type="submit" :disabled="form.processing || !form.pixel_id"
                        class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50">
                        Salvar
                    </button>
                    <button v-if="origem === 'admin'" type="button" :disabled="form.processing" @click="voltarAoPadrao"
                        class="rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:border-slate-300 disabled:opacity-50">
                        {{ pixelEnv ? `Voltar ao padrão do servidor (${pixelEnv})` : 'Remover pixel' }}
                    </button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
