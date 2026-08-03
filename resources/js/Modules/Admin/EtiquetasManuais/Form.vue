<script setup>
import { ref } from 'vue';
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    channels: { type: Array, default: () => [] },
});

const form = useForm({
    channel: props.channels[0]?.value ?? '',
    file: null,
    content: '',
    print_thank_you: false,
});

const fileInput = ref(null);
const inputMode = ref('file'); // 'file' ou 'paste'

const onFileSelect = (event) => {
    form.file = event.target.files[0] ?? null;
};

const setInputMode = (mode) => {
    inputMode.value = mode;
    if (mode === 'file') {
        form.content = '';
    } else {
        form.file = null;
        if (fileInput.value) fileInput.value.value = '';
    }
};

const submit = () => {
    form.post('/admin/etiquetas-manuais', {
        forceFormData: true,
        onSuccess: () => {
            form.reset('file', 'content', 'print_thank_you');
            if (fileInput.value) fileInput.value.value = '';
        },
    });
};
</script>

<template>
    <Head title="Gerar Etiqueta Manual" />

    <AdminLayout>
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="mb-1 text-2xl font-bold">Gerar Etiqueta Manual</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Envie ou cole o conteúdo ZPL da etiqueta — ela entra na mesma fila que o KoraSync já processa.
                </p>
            </div>
            <Link href="/admin/etiquetas-manuais" class="text-sm text-primary hover:underline">
                Ver etiquetas geradas &rarr;
            </Link>
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
                    <div class="mb-2 flex gap-2">
                        <button type="button"
                            class="rounded-full px-3 py-1.5 text-xs font-medium"
                            :class="inputMode === 'file' ? 'bg-primary text-white' : 'bg-[var(--surface-muted)] text-slate-500'"
                            @click="setInputMode('file')">
                            Enviar arquivo .txt
                        </button>
                        <button type="button"
                            class="rounded-full px-3 py-1.5 text-xs font-medium"
                            :class="inputMode === 'paste' ? 'bg-primary text-white' : 'bg-[var(--surface-muted)] text-slate-500'"
                            @click="setInputMode('paste')">
                            Colar conteúdo
                        </button>
                    </div>

                    <input v-if="inputMode === 'file'" ref="fileInput" type="file" accept=".txt"
                        class="w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm"
                        @change="onFileSelect">
                    <textarea v-else v-model="form.content" rows="8"
                        placeholder="Cole aqui o conteúdo ZPL da etiqueta..."
                        class="w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 font-mono text-xs"></textarea>

                    <p v-if="form.errors.file" class="mt-1 text-xs text-error">{{ form.errors.file }}</p>
                    <p v-if="form.errors.content" class="mt-1 text-xs text-error">{{ form.errors.content }}</p>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.print_thank_you" type="checkbox"
                        class="h-4 w-4 rounded border-[var(--surface-border)]">
                    Imprimir etiqueta de agradecimento
                </label>
                <p class="-mt-2 text-xs text-slate-400">
                    Se marcado, a etiqueta fixa de agradecimento é enfileirada logo depois desta.
                </p>

                <button type="submit"
                    :disabled="form.processing || !form.channel || (inputMode === 'file' ? !form.file : !form.content)"
                    class="w-full rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50">
                    {{ form.processing ? 'Processando...' : 'Gerar e enfileirar impressão' }}
                </button>
            </form>
        </div>
    </AdminLayout>
</template>
