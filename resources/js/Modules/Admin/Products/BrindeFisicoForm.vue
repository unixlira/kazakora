<script setup>
// Brinde físico da oferta (pedido do Lira em 21/11/2026): o que vai de graça
// na caixa. Preenchido, toda venda do produto sai com duas NF-e: a de venda e
// a de bonificação do brinde (CFOP 5910/6910, R$ 0,00), e o cliente recebe as
// duas por e-mail. Nome vazio desliga.
import InputError from '@/Shared/Components/InputError.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    product: { type: Object, required: true },
});

const brinde = props.product.brinde_fisico ?? {};
const form = useForm({
    nome: brinde.nome ?? '',
    quantidade: brinde.quantidade ?? 1,
    ncm: brinde.ncm ?? '95030099',
    origem: brinde.origem ?? '',
});

const submit = () => {
    form.put(`/admin/produtos/${props.product.id}/brinde-fisico`, { preserveScroll: true });
};
</script>

<template>
    <form class="space-y-4 border-t border-slate-200 pt-6" @submit.prevent="submit">
        <h3 class="text-sm font-semibold uppercase text-slate-500">Brinde físico (nota de bonificação)</h3>
        <p class="text-xs text-slate-500">
            O que vai de graça na caixa junto com este produto. Preenchido, cada venda sai com duas notas: a de venda e a de bonificação
            do brinde (CFOP 5910/6910, valor zero), e o cliente recebe as duas por e-mail. Deixe o nome em branco para desligar.
            Variações usam o brinde do produto principal.
        </p>
        <div class="grid gap-4 md:grid-cols-4">
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-slate-600">Nome do brinde na nota</label>
                <input v-model="form.nome" type="text" maxlength="120" placeholder="Ex.: Lousa Mágica LED com canetinhas" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                <InputError :message="form.errors.nome" />
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600">Quantidade por unidade vendida</label>
                <input v-model="form.quantidade" type="number" min="1" max="20" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                <InputError :message="form.errors.quantidade" />
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-600">NCM do brinde</label>
                <input v-model="form.ncm" type="text" placeholder="9503.00.99" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                <InputError :message="form.errors.ncm" />
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-600">Origem da mercadoria (em branco: a mesma do produto)</label>
            <select v-model="form.origem" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 md:w-96">
                <option value="">A mesma do produto</option>
                <option :value="0">0 - Nacional</option>
                <option :value="1">1 - Estrangeira, importação direta</option>
                <option :value="2">2 - Estrangeira, adquirida no mercado interno</option>
            </select>
            <InputError :message="form.errors.origem" />
        </div>
        <button type="submit" :disabled="form.processing" class="rounded bg-emerald-600 px-4 py-2 font-semibold text-white disabled:opacity-50">Salvar brinde físico</button>
    </form>
</template>
