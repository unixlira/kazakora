<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    suppliers: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
});

const form = useForm({
    supplier_id: '',
    expected_date: '',
    notes: '',
    items: [{ product_id: '', quantity: 1, unit_cost: 0 }],
});

const addItem = () => form.items.push({ product_id: '', quantity: 1, unit_cost: 0 });
const removeItem = (index) => form.items.splice(index, 1);

const submit = () => {
    form.post('/admin/pedidos-de-compra');
};
</script>

<template>
    <Head title="Novo pedido de compra" />

    <AdminLayout>
        <h1 class="mb-6 text-2xl font-bold">Novo pedido de compra</h1>

        <form class="max-w-4xl space-y-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-6 shadow-sm" @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium">Fornecedor</label>
                    <select v-model="form.supplier_id" required class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2">
                        <option value="" disabled>Selecione...</option>
                        <option v-for="supplier in props.suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option>
                    </select>
                    <InputError :message="form.errors.supplier_id" />
                </div>
                <div>
                    <label class="block text-sm font-medium">Previsão de entrega</label>
                    <input v-model="form.expected_date" type="date" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium">Observações</label>
                <textarea v-model="form.notes" rows="2" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2" />
            </div>

            <div>
                <div class="mb-2 flex items-center justify-between">
                    <h3 class="text-sm font-semibold uppercase text-slate-400">Itens</h3>
                    <button type="button" class="text-sm text-primary hover:underline" @click="addItem">+ Adicionar item</button>
                </div>

                <div v-for="(item, index) in form.items" :key="index" class="mb-2 grid grid-cols-1 gap-2 sm:grid-cols-8 sm:items-end">
                    <div class="sm:col-span-4">
                        <label class="block text-xs font-medium text-slate-400">Produto</label>
                        <select v-model="item.product_id" required class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
                            <option value="" disabled>Selecione...</option>
                            <option v-for="product in props.products" :key="product.id" :value="product.id">{{ product.name }} ({{ product.sku }})</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-slate-400">Quantidade</label>
                        <input v-model.number="item.quantity" type="number" min="1" required class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
                    </div>
                    <div class="sm:col-span-1">
                        <label class="block text-xs font-medium text-slate-400">Custo un.</label>
                        <input v-model.number="item.unit_cost" type="number" step="0.01" min="0" required class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
                    </div>
                    <div class="sm:col-span-1">
                        <button v-if="form.items.length > 1" type="button" class="text-error" @click="removeItem(index)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                <InputError :message="form.errors.items" />
            </div>

            <button type="submit" :disabled="form.processing"
                class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50">
                Criar pedido de compra
            </button>
        </form>
    </AdminLayout>
</template>
