<script setup>
import InputError from '@/Shared/Components/InputError.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    product: {
        type: Object,
        required: true,
    },
    fiscalData: {
        type: Object,
        default: null,
    },
});

const form = useForm({
    peso_bruto: props.fiscalData?.peso_bruto ?? '',
    peso_liquido: props.fiscalData?.peso_liquido ?? '',
    altura_cm: props.fiscalData?.altura_cm ?? '',
    largura_cm: props.fiscalData?.largura_cm ?? '',
    profundidade_cm: props.fiscalData?.profundidade_cm ?? '',
});

const submit = () => {
    form.put(`/admin/products/${props.product.id}/logistics`, { preserveScroll: true });
};
</script>

<template>
    <form class="space-y-4" @submit.prevent="submit">
        <h3 class="text-sm font-semibold uppercase text-slate-500">Peso e dimensões</h3>
        <p class="text-xs text-slate-500">Usados para calcular frete e para a nota fiscal.</p>

        <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
            <div>
                <label class="block text-sm font-medium text-slate-600">Peso bruto (kg)</label>
                <input v-model="form.peso_bruto" type="number" step="0.001" min="0" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                <InputError :message="form.errors.peso_bruto" />
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600">Peso líquido (kg)</label>
                <input v-model="form.peso_liquido" type="number" step="0.001" min="0" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                <InputError :message="form.errors.peso_liquido" />
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600">Altura (cm)</label>
                <input v-model="form.altura_cm" type="number" step="0.01" min="0" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                <InputError :message="form.errors.altura_cm" />
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600">Largura (cm)</label>
                <input v-model="form.largura_cm" type="number" step="0.01" min="0" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                <InputError :message="form.errors.largura_cm" />
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600">Profundidade (cm)</label>
                <input v-model="form.profundidade_cm" type="number" step="0.01" min="0" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                <InputError :message="form.errors.profundidade_cm" />
            </div>
        </div>

        <button type="submit" :disabled="form.processing"
            class="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-slate-300">
            Salvar logística
        </button>
    </form>
</template>
