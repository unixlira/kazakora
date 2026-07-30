<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import ProductForm from '@/Modules/Admin/Products/ProductForm.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    categories: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    name: '',
    sku: '',
    category_id: null,
    brand: '',
    model: '',
    color: '',
    variation: '',
    description: '',
    price: '',
    discount_percentage: null,
    discount_amount: null,
    stock: 0,
    is_active: true,
    is_featured: false,
    is_new_release: false,
});

const submit = () => {
    form.post('/admin/produtos');
};
</script>

<template>
    <Head title="Novo produto" />

    <AdminLayout>
        <h1 class="text-2xl font-bold">Novo produto</h1>

        <div class="mt-6">
            <ProductForm :form="form" :categories="categories" submit-label="Criar produto" @submit="submit" />
        </div>
    </AdminLayout>
</template>
