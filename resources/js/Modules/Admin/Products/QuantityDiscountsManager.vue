<script setup>
import InputError from '@/Shared/Components/InputError.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    product: {
        type: Object,
        required: true,
    },
    quantityDiscounts: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    discounts: props.quantityDiscounts.length
        ? props.quantityDiscounts.map((row) => ({ min_quantity: row.min_quantity, discount_percentage: row.discount_percentage }))
        : [],
});

const addRow = () => {
    form.discounts.push({ min_quantity: '', discount_percentage: '' });
};

const removeRow = (index) => {
    form.discounts.splice(index, 1);
};

const submit = () => {
    form.put(`/admin/produtos/${props.product.id}/descontos-quantidade`, { preserveScroll: true });
};
</script>

<template>
    <form class="space-y-4" @submit.prevent="submit">
        <h3 class="text-sm font-semibold uppercase text-slate-500">Desconto por quantidade</h3>
        <p class="text-xs text-slate-500">
            Cadastre faixas de "a partir de X unidades, Y% de desconto". O desconto é aplicado sobre o preço atual do
            produto (já considerando promoção normal, se houver) e vale de verdade no carrinho e no pedido, não é só
            visual. Ex: a partir de 5 unidades, 10% de desconto.
        </p>

        <div v-if="form.errors.discounts" class="rounded bg-red-50 px-3 py-2 text-sm text-red-600">
            {{ form.errors.discounts }}
        </div>

        <div v-if="form.discounts.length" class="flex flex-col gap-3">
            <div v-for="(row, index) in form.discounts" :key="index" class="flex items-end gap-3">
                <div class="flex-1">
                    <label class="block text-sm font-medium text-slate-600">A partir de (unidades)</label>
                    <input v-model.number="row.min_quantity" type="number" min="2" step="1"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    <InputError :message="form.errors[`discounts.${index}.min_quantity`]" />
                </div>
                <div class="flex-1">
                    <label class="block text-sm font-medium text-slate-600">Desconto (%)</label>
                    <input v-model.number="row.discount_percentage" type="number" min="0.01" max="100" step="0.01"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    <InputError :message="form.errors[`discounts.${index}.discount_percentage`]" />
                </div>
                <button type="button" class="mb-1 flex h-10 w-10 items-center justify-center rounded text-red-600 hover:bg-red-50"
                    aria-label="Remover faixa" @click="removeRow(index)">
                    <i class="fas fa-trash text-sm"></i>
                </button>
            </div>
        </div>
        <p v-else class="text-sm text-slate-500">Nenhuma faixa de desconto cadastrada ainda.</p>

        <button type="button" class="rounded border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
            @click="addRow">
            <i class="fas fa-plus mr-1.5 text-xs"></i>
            Adicionar faixa
        </button>

        <div>
            <button type="submit" :disabled="form.processing"
                class="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-slate-300">
                Salvar descontos por quantidade
            </button>
        </div>
    </form>
</template>
