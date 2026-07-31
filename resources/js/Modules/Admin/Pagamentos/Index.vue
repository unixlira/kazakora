<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    activeProvider: { type: String, required: true },
    gateways: { type: Object, required: true },
});

const form = useForm({
    provider: props.activeProvider,
});

const submit = () => {
    form.put('/admin/pagamentos');
};
</script>

<template>
    <Head title="Pagamentos" />

    <AdminLayout>
        <h1 class="text-2xl font-bold">Pagamentos</h1>
        <p class="mt-1 text-sm text-slate-500">
            Escolha qual gateway processa cartão, Pix e boleto no checkout da loja.
        </p>

        <form class="mt-6 max-w-2xl space-y-4" @submit.prevent="submit">
            <label v-for="(gateway, key) in gateways" :key="key"
                class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition-colors"
                :class="form.provider === key ? 'border-primary bg-primary/5' : 'border-[var(--surface-border)] hover:border-slate-300'">
                <input v-model="form.provider" type="radio" :value="key" class="mt-1 h-4 w-4 accent-primary">
                <div class="flex-1">
                    <div class="flex items-center gap-2">
                        <span class="font-semibold">{{ gateway.label }}</span>
                        <span v-if="gateway.configured" class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">
                            Configurado
                        </span>
                        <span v-else class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">
                            Sem credenciais no .env
                        </span>
                    </div>
                    <p class="mt-1 text-sm text-slate-500">{{ gateway.description }}</p>
                </div>
            </label>

            <p v-if="!gateways[form.provider]?.configured" class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-700">
                Esse gateway ainda não tem credenciais configuradas no servidor — o checkout vai falhar até isso ser resolvido.
            </p>

            <button type="submit" :disabled="form.processing"
                class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50">
                Salvar
            </button>
        </form>
    </AdminLayout>
</template>
