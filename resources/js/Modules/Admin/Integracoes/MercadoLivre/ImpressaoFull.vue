<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import SubNav from './SubNav.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    codes: '',
});

const submit = () => {
    form.post('/admin/integracoes/mercado-livre/impressao-full', {
        onSuccess: () => form.reset('codes'),
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
                Cole o(s) código(s) de envio do Full — busca direto na API do Mercado Livre e gera uma única
                etiqueta com todas em sequência, pronta pro KoraSync imprimir.
            </p>
        </div>

        <div class="max-w-xl rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
            <form class="space-y-4" @submit.prevent="submit">
                <div>
                    <label class="text-sm font-medium">Código(s) do envio</label>
                    <textarea v-model="form.codes" rows="4"
                        placeholder="Cole aqui 1 ou mais códigos — separados por vírgula, espaço ou uma linha por código"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 font-mono text-sm"></textarea>
                    <p v-if="form.errors.codes" class="mt-1 text-xs text-error">{{ form.errors.codes }}</p>
                    <p class="mt-1 text-xs text-slate-400">
                        Ainda estamos ajustando qual formato de código o Mercado Livre espera aqui — se der erro,
                        me manda a mensagem exata que aparecer.
                    </p>
                </div>

                <button type="submit"
                    :disabled="form.processing || !form.codes"
                    class="w-full rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50">
                    {{ form.processing ? 'Buscando no Mercado Livre...' : 'Gerar etiquetas em lote' }}
                </button>
            </form>
        </div>
    </AdminLayout>
</template>
