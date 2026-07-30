<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable } from '@/Shared/Components/DataTable';
import { Head, router } from '@inertiajs/vue3';
import { computed, h, ref } from 'vue';

const props = defineProps({
    products: { type: Array, default: () => [] },
    selectedProduct: { type: Object, default: null },
    results: { type: Array, default: () => [] },
    error: { type: Object, default: null },
});

const LISTING_TYPE_LABELS = {
    gold_special: 'Clássico',
    gold_pro: 'Premium',
    gold_premium: 'Premium',
    free: 'Grátis',
};

const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const filterText = ref('');
const selectedProductId = ref(props.selectedProduct?.id ?? '');
const loading = ref(false);

const filteredProducts = computed(() => {
    const term = filterText.value.trim().toLowerCase();
    if (!term) return props.products;
    return props.products.filter((p) => p.name.toLowerCase().includes(term) || (p.sku ?? '').toLowerCase().includes(term));
});

const search = () => {
    if (!selectedProductId.value) return;

    router.get('/admin/concorrencia', { product_id: selectedProductId.value }, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => { loading.value = true; },
        onFinish: () => { loading.value = false; },
    });
};

const columns = [
    {
        accessorKey: 'title',
        header: 'Anúncio',
        cell: ({ row }) => h('a', {
            href: row.original.permalink,
            target: '_blank',
            rel: 'noopener noreferrer',
            class: 'text-sm text-primary hover:underline',
        }, row.original.title),
    },
    {
        accessorKey: 'price',
        header: 'Preço',
        cell: ({ row }) => formatPrice(row.original.price),
    },
    {
        id: 'seller',
        header: 'Vendedor',
        accessorFn: (row) => row.seller_nickname ?? (row.seller_id ? `#${row.seller_id}` : '—'),
    },
    {
        id: 'fee',
        header: 'Comissão estimada',
        cell: ({ row }) => {
            if (row.original.estimated_fee_amount == null) {
                return h('span', { class: 'text-slate-400' }, '—');
            }

            const label = LISTING_TYPE_LABELS[row.original.estimated_fee_listing_type] ?? row.original.estimated_fee_listing_type;
            const pct = row.original.estimated_fee_percentage != null ? ` (${row.original.estimated_fee_percentage}%)` : '';

            return h('div', [
                h('span', formatPrice(row.original.estimated_fee_amount) + pct),
                h('span', { class: 'ml-1 text-xs text-slate-400' }, `· ${label}`),
            ]);
        },
    },
];
</script>

<template>
    <Head title="Análise de Concorrentes" />

    <AdminLayout>
        <h1 class="mb-1 text-2xl font-bold">Análise de Concorrentes</h1>
        <p class="mb-6 text-sm text-gray-500">Consulte o Mercado Livre para ver quem mais vende um produto parecido com o seu, por quanto, e a comissão estimada.</p>

        <div class="mb-6 flex flex-wrap items-end gap-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
            <div class="min-w-[220px]">
                <label class="block text-xs font-medium text-slate-400">Filtrar produtos</label>
                <input v-model="filterText" type="text" placeholder="Nome ou SKU..."
                    class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
            </div>
            <div class="min-w-[260px] flex-1">
                <label class="block text-xs font-medium text-slate-400">Produto</label>
                <select v-model="selectedProductId" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
                    <option value="" disabled>Selecione um produto</option>
                    <option v-for="product in filteredProducts" :key="product.id" :value="product.id">
                        {{ product.name }} ({{ product.sku }})
                    </option>
                </select>
            </div>
            <button type="button" :disabled="!selectedProductId || loading"
                class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50"
                @click="search">
                {{ loading ? 'Buscando...' : 'Buscar concorrentes' }}
            </button>
        </div>

        <p v-if="!selectedProduct" class="rounded border border-dashed border-gray-300 py-16 text-center text-sm text-gray-500">
            Selecione um produto para consultar a concorrência.
        </p>

        <template v-else>
            <div v-if="error" class="mb-4 rounded-lg border px-4 py-3 text-sm"
                :class="error.type === 'rate_limit' ? 'border-amber-300 bg-amber-50 text-amber-700' : 'border-red-300 bg-red-50 text-red-700'">
                <i class="fas fa-triangle-exclamation mr-2"></i>{{ error.message }}
            </div>

            <template v-else>
                <p v-if="results.length === 0" class="rounded border border-dashed border-gray-300 py-16 text-center text-sm text-gray-500">
                    Nenhum concorrente encontrado para "{{ selectedProduct.name }}" no Mercado Livre.
                </p>

                <DataTable v-else :columns="columns" :data="results"
                    search-placeholder="Buscar nos resultados..." empty-message="Nenhum concorrente encontrado." />
            </template>
        </template>
    </AdminLayout>
</template>
