<script setup>
import { ref } from 'vue';
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { confirmDelete } from '@/Shared/notify';

const props = defineProps({
    job: { type: Object, required: true },
    channels: { type: Array, default: () => [] },
});

const form = useForm({
    channel: props.job.channel,
    file: null,
    content: '',
});

const fileInput = ref(null);
const inputMode = ref('file');

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
    form.put(`/admin/etiquetas-manuais/${props.job.id}`);
};

const destroy = async () => {
    if (await confirmDelete({ title: `Remover a etiqueta #${props.job.id}?` })) {
        router.delete(`/admin/etiquetas-manuais/${props.job.id}`);
    }
};
</script>

<template>
    <Head :title="`Etiqueta #${job.id}`" />

    <AdminLayout>
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="mb-1 text-2xl font-bold">Etiqueta #{{ job.id }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Status: <span class="font-medium">{{ job.status }}</span>
                    <span v-if="job.errorMessage" class="text-error"> — {{ job.errorMessage }}</span>
                </p>
            </div>
            <Link href="/admin/etiquetas-manuais" class="text-sm text-primary hover:underline">
                &larr; Voltar pra listagem
            </Link>
        </div>

        <div class="max-w-xl space-y-4">
            <a :href="`/admin/etiquetas-manuais/${job.id}/pdf`" target="_blank"
                class="inline-flex items-center gap-2 rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm hover:bg-[var(--surface-muted)]">
                <i class="fas fa-file-pdf"></i> Ver PDF atual
            </a>

            <div v-if="job.isThankYou" class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 text-sm text-slate-500 shadow-sm">
                Esta é a etiqueta fixa de agradecimento — usa sempre o mesmo arquivo, não é editável por aqui.
            </div>

            <form v-else class="space-y-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm" @submit.prevent="submit">
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
                    <label class="mb-2 block text-sm font-medium">Substituir conteúdo (opcional)</label>
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
                        placeholder="Cole aqui o novo conteúdo ZPL, ou deixe em branco pra manter o atual..."
                        class="w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 font-mono text-xs"></textarea>

                    <p class="mt-1 text-xs text-slate-400">Deixe em branco pra manter o PDF atual, mudando só o canal.</p>
                    <p v-if="form.errors.file" class="mt-1 text-xs text-error">{{ form.errors.file }}</p>
                    <p v-if="form.errors.content" class="mt-1 text-xs text-error">{{ form.errors.content }}</p>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" :disabled="form.processing"
                        class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50">
                        {{ form.processing ? 'Salvando...' : 'Salvar alterações' }}
                    </button>
                    <button type="button" class="text-sm text-error hover:underline" @click="destroy">
                        Excluir etiqueta
                    </button>
                </div>
            </form>

            <button v-if="job.isThankYou" type="button" class="text-sm text-error hover:underline" @click="destroy">
                Excluir etiqueta
            </button>
        </div>
    </AdminLayout>
</template>
