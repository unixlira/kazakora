<script setup>
import { ref } from 'vue';
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import SubNav from './SubNav.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    file: null,
    content: '',
});

const fileInput = ref(null);
const inputMode = ref('paste'); // 'paste' ou 'file'

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
    form.post('/admin/integracoes/mercado-livre/impressao-full', {
        forceFormData: true,
        onSuccess: () => {
            form.reset('file', 'content');
            if (fileInput.value) fileInput.value.value = '';
        },
    });
};
</script>

<template>
    <Head title="Impressão Full — Mercado Livre" />

    <AdminLayout>
        <SubNav />

        <div class="mb-6">
            <h1 class="mb-1 text-2xl font-bold">Impressão Full</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Cole (ou envie o arquivo .txt) o ZPL que você já baixa pronto no painel do Full do Mercado
                Livre — geralmente já vem com todos os volumes do envio em sequência. Sem chamada de API nenhuma
                aqui: é só converter pra PDF e mandar pra fila, igual à tela de Etiquetas Manuais.
            </p>
        </div>

        <div class="max-w-xl rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
            <form class="space-y-4" @submit.prevent="submit">
                <div>
                    <div class="mb-2 flex gap-2">
                        <button type="button"
                            class="rounded-full px-3 py-1.5 text-xs font-medium"
                            :class="inputMode === 'paste' ? 'bg-primary text-white' : 'bg-[var(--surface-muted)] text-slate-500'"
                            @click="setInputMode('paste')">
                            Colar conteúdo
                        </button>
                        <button type="button"
                            class="rounded-full px-3 py-1.5 text-xs font-medium"
                            :class="inputMode === 'file' ? 'bg-primary text-white' : 'bg-[var(--surface-muted)] text-slate-500'"
                            @click="setInputMode('file')">
                            Enviar arquivo
                        </button>
                    </div>

                    <textarea v-if="inputMode === 'paste'" v-model="form.content" rows="10"
                        placeholder="Cole aqui o ZPL baixado do painel do Full..."
                        class="w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 font-mono text-xs"></textarea>
                    <input v-else ref="fileInput" type="file" accept=".txt"
                        class="w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm"
                        @change="onFileSelect">

                    <p v-if="form.errors.file" class="mt-1 text-xs text-error">{{ form.errors.file }}</p>
                    <p v-if="form.errors.content" class="mt-1 text-xs text-error">{{ form.errors.content }}</p>
                </div>

                <button type="submit"
                    :disabled="form.processing || (inputMode === 'file' ? !form.file : !form.content)"
                    class="w-full rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50">
                    {{ form.processing ? 'Processando...' : 'Gerar e enfileirar impressão' }}
                </button>
            </form>
        </div>
    </AdminLayout>
</template>
