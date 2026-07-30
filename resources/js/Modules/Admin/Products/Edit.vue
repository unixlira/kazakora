<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import ProductForm from '@/Modules/Admin/Products/ProductForm.vue';
import FiscalForm from '@/Modules/Admin/Products/FiscalForm.vue';
import LogisticsForm from '@/Modules/Admin/Products/LogisticsForm.vue';
import QuantityDiscountsManager from '@/Modules/Admin/Products/QuantityDiscountsManager.vue';
import ImagesManager from '@/Modules/Admin/Products/ImagesManager.vue';
import VideoManager from '@/Modules/Admin/Products/VideoManager.vue';
import ChannelsManager from '@/Modules/Admin/Products/ChannelsManager.vue';
import StockHistory from '@/Modules/Admin/Products/StockHistory.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    product: {
        type: Object,
        required: true,
    },
    categories: {
        type: Array,
        default: () => [],
    },
    fiscalData: {
        type: Object,
        default: null,
    },
    images: {
        type: Array,
        default: () => [],
    },
    channels: {
        type: Array,
        default: () => [],
    },
    channelListings: {
        type: Array,
        default: () => [],
    },
    stockMovements: {
        type: Array,
        default: () => [],
    },
    quantityDiscounts: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    name: props.product.name,
    sku: props.product.sku,
    category_id: props.product.category_id,
    brand: props.product.brand ?? '',
    model: props.product.model ?? '',
    color: props.product.color ?? '',
    variation: props.product.variation ?? '',
    description: props.product.description ?? '',
    price: props.product.price,
    discount_percentage: props.product.discount_percentage,
    discount_amount: props.product.discount_amount,
    stock: props.product.stock,
    is_active: props.product.is_active,
    is_featured: props.product.is_featured,
    is_new_release: props.product.is_new_release,
});

const submit = () => {
    form.put(`/admin/produtos/${props.product.id}`);
};

const tabs = [
    { key: 'geral', label: 'Geral' },
    { key: 'fiscal', label: 'Dados fiscais' },
    { key: 'logistica', label: 'Logística' },
    { key: 'desconto-quantidade', label: 'Desconto por quantidade' },
    { key: 'midia', label: 'Fotos e vídeo' },
    { key: 'canais', label: 'Canais de venda' },
    { key: 'estoque', label: 'Histórico de estoque' },
];

const activeTab = ref('geral');
</script>

<template>
    <Head title="Editar produto" />

    <AdminLayout>
        <h1 class="text-2xl font-bold text-slate-700">Editar produto</h1>
        <p class="mt-1 text-sm text-slate-500">{{ product.name }} · SKU {{ product.sku }}</p>

        <div class="mt-6 rounded bg-white p-6 shadow-lg">
            <div class="flex flex-wrap gap-2 border-b border-slate-200 pb-4">
                <button v-for="tab in tabs" :key="tab.key" type="button"
                    class="rounded px-3 py-1.5 text-sm font-medium"
                    :class="activeTab === tab.key ? 'bg-emerald-600 text-white' : 'text-slate-500 hover:bg-slate-100'"
                    @click="activeTab = tab.key">
                    {{ tab.label }}
                </button>
            </div>

            <div class="mt-6">
                <ProductForm v-if="activeTab === 'geral'" :form="form" :categories="categories"
                    submit-label="Salvar alterações" @submit="submit" />

                <FiscalForm v-else-if="activeTab === 'fiscal'" :product="product" :fiscal-data="fiscalData" />

                <LogisticsForm v-else-if="activeTab === 'logistica'" :product="product" :fiscal-data="fiscalData" />

                <QuantityDiscountsManager v-else-if="activeTab === 'desconto-quantidade'" :product="product"
                    :quantity-discounts="quantityDiscounts" />

                <div v-else-if="activeTab === 'midia'" class="space-y-8">
                    <div>
                        <h3 class="mb-3 text-sm font-semibold uppercase text-slate-500">Fotos</h3>
                        <ImagesManager :product="product" :images="images" />
                    </div>
                    <div>
                        <h3 class="mb-3 text-sm font-semibold uppercase text-slate-500">Vídeo</h3>
                        <VideoManager :product="product" />
                    </div>
                </div>

                <ChannelsManager v-else-if="activeTab === 'canais'" :product="product" :channels="channels"
                    :channel-listings="channelListings" />

                <StockHistory v-else-if="activeTab === 'estoque'" :stock-movements="stockMovements" />
            </div>
        </div>
    </AdminLayout>
</template>
