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
import VariationsManager from '@/Modules/Admin/Products/VariationsManager.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    product: {
        type: Object,
        required: true,
    },
    // Conteúdo do anúncio (benefícios + descrição em blocos) — pedido 2026-10-09.
    adContent: {
        type: Object,
        default: null,
    },
    // Valores do Pix (preço da loja sem o +5%) — pedido 2026-10-09.
    precoPix: {
        type: Object,
        default: null,
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
    variations: {
        type: Object,
        default: () => ({ parent: null, siblings: [] }),
    },
    linkableOrphans: {
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
    price: props.precoPix?.price ?? props.product.price,
    cost_price: props.product.cost_price ?? '',
    discount_percentage: props.product.discount_percentage,
    discount_amount: props.precoPix ? props.precoPix.discount_amount : props.product.discount_amount,
    // stock aqui é só EXIBIÇÃO ("Estoque atual: X" no ProductForm) — quem
    // muda o estoque de verdade é stock_adjustment (0 = não mexe), ver
    // BUG REAL 2026-08-17 no ProductController::update().
    stock: props.product.stock,
    stock_adjustment: 0,
    is_active: props.product.is_active,
    is_featured: props.product.is_featured,
    is_new_release: props.product.is_new_release,
});

const submit = () => {
    form.put(`/admin/produtos/${props.product.id}`);
};

const tabs = [
    { key: 'geral', label: 'Geral' },
    { key: 'variacoes', label: 'Variações' },
    { key: 'fiscal', label: 'Dados fiscais' },
    { key: 'logistica', label: 'Logística' },
    { key: 'desconto-quantidade', label: 'Desconto por quantidade' },
    { key: 'midia', label: 'Fotos e vídeo' },
    { key: 'anuncio', label: 'Conteúdo do anúncio' },
    { key: 'canais', label: 'Canais de venda' },
    { key: 'estoque', label: 'Histórico de estoque' },
];

const activeTab = ref('geral');

const gerandoAnuncio = ref(false);
const gerarAnuncio = () => {
    gerandoAnuncio.value = true;
    router.post(`/admin/produtos/${props.product.id}/conteudo-anuncio`, {}, {
        preserveScroll: true,
        onFinish: () => { gerandoAnuncio.value = false; },
    });
};
const formatarData = (valor) => (valor ? new Date(valor).toLocaleString('pt-BR') : '—');
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
                    <span v-if="tab.key === 'variacoes' && variations.siblings.length"
                        class="ml-1 rounded-full bg-white/20 px-1.5 text-xs" :class="activeTab === tab.key ? '' : 'bg-emerald-100 text-emerald-700'">
                        {{ variations.siblings.length }}
                    </span>
                </button>
            </div>

            <div class="mt-6">
                <ProductForm v-if="activeTab === 'geral'" :form="form" :categories="categories" :is-edit="true"
                    submit-label="Salvar alterações" @submit="submit" />

                <VariationsManager v-else-if="activeTab === 'variacoes'" :product="product" :variations="variations"
                    :linkable-orphans="linkableOrphans" />

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

                <div v-else-if="activeTab === 'anuncio'" class="space-y-5">
                    <p class="text-sm text-slate-500">
                        Benefícios (abaixo das avaliações) e a descrição em blocos com imagem da página do produto.
                        São gerados pela IA (Gemini) a partir da descrição e das fotos sempre que elas mudam; se a IA
                        falhar, o sistema monta tudo pela própria descrição.
                    </p>
                    <div class="flex flex-wrap items-center gap-3">
                        <span v-if="adContent" class="rounded px-2 py-1 text-xs font-semibold"
                            :class="adContent.fonte === 'gemini' ? 'bg-violet-100 text-violet-700' : 'bg-slate-100 text-slate-600'">
                            {{ adContent.fonte === 'gemini' ? 'Gerado com IA' : 'Montado pela regra automática' }} · {{ formatarData(adContent.gerado_em) }}
                        </span>
                        <span v-else class="rounded bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-700">Ainda não gerado — a página usa a descrição como está.</span>
                        <button type="button" :disabled="gerandoAnuncio" class="rounded bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50" @click="gerarAnuncio">
                            <i class="fas mr-1" :class="gerandoAnuncio ? 'fa-spinner animate-spin' : 'fa-wand-magic-sparkles'"></i>
                            {{ gerandoAnuncio ? 'Gerando...' : (adContent ? 'Gerar de novo' : 'Gerar agora') }}
                        </button>
                    </div>
                    <div v-if="adContent?.destaques?.length">
                        <h3 class="mb-2 text-sm font-semibold uppercase text-slate-500">Benefícios</h3>
                        <ul class="list-inside list-disc text-sm text-slate-700">
                            <li v-for="(item, index) in adContent.destaques" :key="index">{{ item }}</li>
                        </ul>
                    </div>
                    <div v-if="adContent?.blocos?.length">
                        <h3 class="mb-2 text-sm font-semibold uppercase text-slate-500">Blocos da descrição</h3>
                        <ol class="list-inside list-decimal space-y-1 text-sm text-slate-700">
                            <li v-for="(bloco, index) in adContent.blocos" :key="index">
                                <strong>{{ bloco.titulo }}</strong>
                                <span v-if="bloco.imagem !== null" class="text-slate-400"> · foto {{ bloco.imagem + 1 }}</span>
                            </li>
                        </ol>
                    </div>
                </div>

                <ChannelsManager v-else-if="activeTab === 'canais'" :product="product" :channels="channels"
                    :channel-listings="channelListings" />

                <StockHistory v-else-if="activeTab === 'estoque'" :stock-movements="stockMovements" />
            </div>
        </div>
    </AdminLayout>
</template>
