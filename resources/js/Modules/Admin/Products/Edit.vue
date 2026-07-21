<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import ProductForm from '@/Modules/Admin/Products/ProductForm.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    product: {
        type: Object,
        required: true,
    },
    categories: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    name: props.product.name,
    sku: props.product.sku,
    category_id: props.product.category_id,
    description: props.product.description ?? '',
    price: props.product.price,
    stock: props.product.stock,
    is_active: props.product.is_active,
});

const submit = () => {
    form.put(`/admin/products/${props.product.id}`);
};
</script>

<template>
    <Head title="Editar produto" />

    <AdminLayout>
        <h1 class="text-2xl font-bold">Editar produto</h1>

        <div class="mt-6">
            <ProductForm :form="form" :categories="categories" submit-label="Salvar alterações" @submit="submit" />
        </div>
    </AdminLayout>
</template>
