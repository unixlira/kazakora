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
    ncm: props.fiscalData?.ncm ?? '',
    cest: props.fiscalData?.cest ?? '',
    cfop: props.fiscalData?.cfop ?? '',
    origem: props.fiscalData?.origem ?? 0,
    gtin: props.fiscalData?.gtin ?? '',
    unidade_tributavel: props.fiscalData?.unidade_tributavel ?? 'UN',
    icms_situacao_tributaria: props.fiscalData?.icms_situacao_tributaria ?? '',
    icms_aliquota: props.fiscalData?.icms_aliquota ?? '',
    ipi_situacao_tributaria: props.fiscalData?.ipi_situacao_tributaria ?? '',
    ipi_aliquota: props.fiscalData?.ipi_aliquota ?? '',
    pis_situacao_tributaria: props.fiscalData?.pis_situacao_tributaria ?? '',
    pis_aliquota: props.fiscalData?.pis_aliquota ?? '',
    cofins_situacao_tributaria: props.fiscalData?.cofins_situacao_tributaria ?? '',
    cofins_aliquota: props.fiscalData?.cofins_aliquota ?? '',
    peso_bruto: props.fiscalData?.peso_bruto ?? '',
    peso_liquido: props.fiscalData?.peso_liquido ?? '',
    altura_cm: props.fiscalData?.altura_cm ?? '',
    largura_cm: props.fiscalData?.largura_cm ?? '',
    profundidade_cm: props.fiscalData?.profundidade_cm ?? '',
});

const submit = () => {
    form.put(`/admin/products/${props.product.id}/fiscal`, { preserveScroll: true });
};
</script>

<template>
    <form class="space-y-4" @submit.prevent="submit">
        <h3 class="text-sm font-semibold uppercase text-slate-500">Classificação fiscal</h3>
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            <div>
                <label class="block text-sm font-medium text-slate-600">NCM</label>
                <input v-model="form.ncm" type="text" maxlength="8" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                <InputError :message="form.errors.ncm" />
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600">CEST</label>
                <input v-model="form.cest" type="text" maxlength="7" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600">CFOP</label>
                <input v-model="form.cfop" type="text" maxlength="4" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600">GTIN/EAN</label>
                <input v-model="form.gtin" type="text" maxlength="14" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600">Origem</label>
                <select v-model.number="form.origem" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    <option :value="0">0 — Nacional</option>
                    <option :value="1">1 — Estrangeira (importação direta)</option>
                    <option :value="2">2 — Estrangeira (mercado interno)</option>
                </select>
                <InputError :message="form.errors.origem" />
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600">Unidade tributável</label>
                <input v-model="form.unidade_tributavel" type="text" maxlength="6" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                <InputError :message="form.errors.unidade_tributavel" />
            </div>
        </div>

        <h3 class="pt-2 text-sm font-semibold uppercase text-slate-500">Tributação</h3>
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            <div>
                <label class="block text-sm font-medium text-slate-600">CST/CSOSN ICMS</label>
                <input v-model="form.icms_situacao_tributaria" type="text" maxlength="3" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600">Alíquota ICMS (%)</label>
                <input v-model="form.icms_aliquota" type="number" step="0.01" min="0" max="100" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600">CST IPI</label>
                <input v-model="form.ipi_situacao_tributaria" type="text" maxlength="3" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600">Alíquota IPI (%)</label>
                <input v-model="form.ipi_aliquota" type="number" step="0.01" min="0" max="100" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600">CST PIS</label>
                <input v-model="form.pis_situacao_tributaria" type="text" maxlength="3" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600">Alíquota PIS (%)</label>
                <input v-model="form.pis_aliquota" type="number" step="0.01" min="0" max="100" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600">CST COFINS</label>
                <input v-model="form.cofins_situacao_tributaria" type="text" maxlength="3" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600">Alíquota COFINS (%)</label>
                <input v-model="form.cofins_aliquota" type="number" step="0.01" min="0" max="100" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            </div>
        </div>

        <h3 class="pt-2 text-sm font-semibold uppercase text-slate-500">Peso e dimensões (nota fiscal e frete)</h3>
        <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
            <div>
                <label class="block text-sm font-medium text-slate-600">Peso bruto (kg)</label>
                <input v-model="form.peso_bruto" type="number" step="0.001" min="0" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600">Peso líquido (kg)</label>
                <input v-model="form.peso_liquido" type="number" step="0.001" min="0" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600">Altura (cm)</label>
                <input v-model="form.altura_cm" type="number" step="0.01" min="0" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600">Largura (cm)</label>
                <input v-model="form.largura_cm" type="number" step="0.01" min="0" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600">Profundidade (cm)</label>
                <input v-model="form.profundidade_cm" type="number" step="0.01" min="0" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            </div>
        </div>

        <button type="submit" :disabled="form.processing"
            class="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-slate-300">
            Salvar dados fiscais
        </button>
    </form>
</template>
