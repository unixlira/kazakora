<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import ProductForm from '@/Modules/Admin/Products/ProductForm.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    categories: {
        type: Array,
        default: () => [],
    },
    // Presente quando a URL veio com ?parent_product_id= — pedido
    // explícito 2026-08-17 (variações de produto): "+ Nova variação" no
    // VariationsManager.vue reusa esta tela inteira em vez de duplicar
    // formulário/preview de SKU/validação, só muda o aviso no topo e o
    // campo escondido enviado no submit.
    parent: {
        type: Object,
        default: null,
    },
});

const form = useForm({
    name: '',
    sku: '',
    category_id: props.parent?.category_id ?? null,
    brand: props.parent?.brand ?? '',
    model: props.parent?.model ?? '',
    color: '',
    variation: '',
    description: props.parent?.description ?? '',
    price: '',
    cost_price: '',
    discount_percentage: null,
    discount_amount: null,
    stock: 0,
    parent_product_id: props.parent?.id ?? null,
    is_active: true,
    is_featured: false,
    is_new_release: false,
});

const submit = () => {
    form.post('/admin/produtos');
};
</script>

<template>
    <Head :title="parent ? `Nova variação de ${parent.name}` : 'Novo produto'" />

    <AdminLayout>
        <h1 class="text-2xl font-bold">{{ parent ? 'Nova variação' : 'Novo produto' }}</h1>

        <div v-if="parent" class="mt-2 rounded border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            Cadastrando uma variação de <strong>{{ parent.name }}</strong> — preencha o nome e o campo
            "Variação" (ex: "10 Polegadas") pra diferenciar. SKU, fotos, estoque e dados fiscais são
            próprios desta variação, iguais a qualquer outro produto.
        </div>

        <div class="mt-6">
            <ProductForm :form="form" :categories="categories" submit-label="Criar produto" @submit="submit" />
        </div>
    </AdminLayout>
</template>
