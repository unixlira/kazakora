<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import FieldTooltip from '@/Shared/Components/FieldTooltip.vue';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { MARKETPLACES, REGRA, computePricing } from '@/Shared/marketplaceFees';

const props = defineProps({
    products: {
        type: Array,
        default: () => [],
    },
    // Tamanhos de pacote da tabela de frete (Pequena, Média, Grande, Extra Grande).
    categorias: {
        type: Array,
        default: () => [],
    },
    // Frete Correios por tamanho: { pequena: { sp, brasil, pior }, ... }.
    fretes: {
        type: Object,
        default: () => ({}),
    },
});

const regioes = [
    { key: 'brasil', label: 'Média Brasil' },
    { key: 'sp', label: 'Média SP' },
    { key: 'pior', label: 'Pior caso' },
];

const cost = ref('');
const categoria = ref(props.categorias[0]?.chave ?? 'pequena');
const regiao = ref('brasil');
const freteMl = ref('');
const overrides = ref({});
const expandedKey = ref(null);

const productQuery = ref('');
const showSuggestions = ref(false);

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value || 0);
const formatPct = (value) => `${Number(value).toLocaleString('pt-BR', { maximumFractionDigits: 2 })}%`;

const filteredProducts = computed(() => {
    const q = productQuery.value.trim().toLowerCase();
    if (q.length < 2) return [];
    return props.products
        .filter((p) => p.name.toLowerCase().includes(q) || (p.sku ?? '').toLowerCase().includes(q))
        .slice(0, 6);
});

const pickProduct = (product) => {
    if (product.cost_price != null) {
        cost.value = product.cost_price;
    }
    if (product.categoria_frete) {
        categoria.value = product.categoria_frete;
    }
    productQuery.value = `${product.name} — ${product.sku}`;
    showSuggestions.value = false;
};

const costNum = computed(() => Number(cost.value) || 0);
const hasCost = computed(() => costNum.value > 0);
const freteCorreios = computed(() => props.fretes[categoria.value]?.[regiao.value] ?? null);
const freteMlNum = computed(() => (freteMl.value === '' || freteMl.value === null ? null : Number(freteMl.value)));

// Frete que a loja paga em cada canal: na Shopee e no TikTok já está na taxa;
// no ML é o que o ML cobra no anúncio; na Amazon e nas lojas próprias, Correios.
const freteDe = (marketplace) => {
    if (marketplace.frete === 'incluido') return 0;
    if (marketplace.frete === 'mercado_livre') return freteMlNum.value;
    return freteCorreios.value;
};

const results = computed(() =>
    MARKETPLACES.map((marketplace) => {
        const frete = freteDe(marketplace);
        return {
            marketplace,
            frete,
            result: frete === null
                ? null
                : computePricing(marketplace, {
                    cost: costNum.value,
                    frete,
                    commissionPctOverride: overrides.value[marketplace.key] ?? null,
                }),
        };
    }),
);

const toggleExpanded = (key) => {
    expandedKey.value = expandedKey.value === key ? null : key;
};

const setOverride = (key, value) => {
    if (value === '' || value === null) {
        const next = { ...overrides.value };
        delete next[key];
        overrides.value = next;
        return;
    }
    overrides.value = { ...overrides.value, [key]: Number(value) };
};
</script>

<template>
    <Head title="Calculadora de Preços" />

    <AdminLayout>
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h1 class="text-2xl font-bold text-slate-700 dark:text-slate-200">Calculadora de Preços</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Informe o custo do produto: cada card mostra o preço para cadastrar no canal, já com as taxas reais, o imposto, o ADS e o lucro da regra.
                </p>
            </div>
        </div>

        <!-- Regra usada -->
        <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300">
            <p class="font-semibold">Regra de preço (28/10/2026)</p>
            <p class="mt-1 font-mono">
                preço = (custo + frete + insumos {{ formatPrice(REGRA.insumos) }} + tarifa fixa) ÷ (1 − (imposto {{ formatPct(REGRA.impostoPct) }} + comissão + ADS {{ formatPct(REGRA.adsPct) }} + lucro))
            </p>
            <p class="mt-1">
                Lucro de {{ formatPct(REGRA.lucroMarketplacePct) }} do preço nos marketplaces e {{ formatPct(REGRA.lucroLojaPropriaPct) }} nas lojas próprias. O preço sobe até o próximo final ,90.
            </p>
        </div>

        <!-- Entradas -->
        <div class="mt-4 rounded-2xl bg-white p-6 shadow-lg dark:bg-slate-800">
            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <div>
                    <label for="cost" class="block text-sm font-semibold text-slate-600 dark:text-slate-300">Preço de custo (R$)</label>
                    <div class="relative mt-1">
                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">R$</span>
                        <input
                            id="cost"
                            v-model="cost"
                            type="number"
                            step="0.01"
                            min="0"
                            placeholder="0,00"
                            class="w-full rounded-lg border border-slate-300 py-3 pl-10 pr-3 text-lg font-semibold dark:border-slate-600 dark:bg-slate-900"
                        >
                    </div>

                    <!-- Preenchimento rápido a partir de um produto real -->
                    <div class="relative mt-2">
                        <input
                            v-model="productQuery"
                            type="text"
                            placeholder="ou busque um produto do catálogo (preenche o custo e o tamanho)..."
                            class="w-full rounded-lg border border-dashed border-slate-300 px-3 py-2 text-sm text-slate-500 dark:border-slate-600 dark:bg-slate-900"
                            @focus="showSuggestions = true"
                            @input="showSuggestions = true"
                            @blur="() => setTimeout(() => (showSuggestions = false), 150)"
                        >
                        <ul
                            v-if="showSuggestions && filteredProducts.length"
                            class="absolute z-20 mt-1 w-full overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-800"
                        >
                            <li v-for="product in filteredProducts" :key="product.id">
                                <button
                                    type="button"
                                    class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-slate-50 dark:hover:bg-slate-700"
                                    @mousedown.prevent="pickProduct(product)"
                                >
                                    <span class="truncate">{{ product.name }}</span>
                                    <span class="ml-2 shrink-0 text-xs text-slate-400">
                                        {{ product.cost_price ? formatPrice(product.cost_price) : 'sem custo cadastrado' }}
                                    </span>
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="categoria" class="block text-xs font-medium uppercase text-slate-400">
                                Tamanho do pacote
                                <FieldTooltip text="Define o frete Correios (Amazon e lojas próprias). Vem do produto quando você escolhe um do catálogo." />
                            </label>
                            <select
                                id="categoria" v-model="categoria"
                                class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600 dark:bg-slate-900"
                            >
                                <option v-for="c in categorias" :key="c.chave" :value="c.chave">{{ c.nome }}</option>
                            </select>
                        </div>
                        <div>
                            <label for="regiao" class="block text-xs font-medium uppercase text-slate-400">
                                Frete Correios
                                <FieldTooltip text="Menor preço entre PAC e SEDEX na tabela de frete da loja: média dos destinos em SP, média do Brasil ou o destino mais caro." />
                            </label>
                            <select
                                id="regiao" v-model="regiao"
                                class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600 dark:bg-slate-900"
                            >
                                <option v-for="r in regioes" :key="r.key" :value="r.key">{{ r.label }}</option>
                            </select>
                        </div>
                    </div>
                    <p class="text-xs text-slate-500">
                        Frete Correios usado:
                        <strong>{{ freteCorreios === null ? 'sem cotação para esse tamanho' : formatPrice(freteCorreios) }}</strong>
                    </p>
                    <div>
                        <label for="frete-ml" class="block text-xs font-medium uppercase text-slate-400">
                            Frete do Mercado Livre (R$)
                            <FieldTooltip text="O que o ML cobra da loja pelo envio desse anúncio (aparece no anúncio e no simulador do ML). Muda com o peso e com o preço acima ou abaixo de R$ 79." />
                        </label>
                        <input
                            id="frete-ml" v-model="freteMl" type="number" step="0.01" min="0" placeholder="informe para calcular o ML"
                            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600 dark:bg-slate-900"
                        >
                    </div>
                </div>
            </div>
        </div>

        <p v-if="!hasCost" class="mt-6 text-center text-sm text-slate-400">
            Informe o preço de custo acima — os cards abaixo mostram o preço de cadastro em cada canal assim que você digitar.
        </p>

        <!-- Cards por canal -->
        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <div
                v-for="{ marketplace, frete, result } in results"
                :key="marketplace.key"
                class="relative flex flex-col overflow-hidden rounded-2xl shadow-lg transition-transform hover:-translate-y-0.5"
                :style="{ background: marketplace.theme.cardBg, color: marketplace.theme.fg }"
            >
                <div class="p-5">
                    <div class="flex items-center gap-2">
                        <span class="flex h-9 w-9 items-center justify-center rounded-full text-base" :style="{ background: marketplace.theme.chipBg }">
                            <svg
                                v-if="marketplace.logoSvg"
                                :viewBox="marketplace.logoSvg.viewBox"
                                class="h-4 w-4"
                                fill="currentColor"
                                xmlns="http://www.w3.org/2000/svg"
                            ><path :d="marketplace.logoSvg.path" /></svg>
                            <i v-else :class="marketplace.icon"></i>
                        </span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-bold">{{ marketplace.label }}</p>
                            <p class="truncate text-[11px] opacity-70">{{ marketplace.sublabel }}</p>
                        </div>
                    </div>

                    <template v-if="!hasCost">
                        <p class="mt-5 text-[11px] uppercase tracking-wide opacity-70">Cadastre por</p>
                        <p class="text-3xl font-extrabold leading-tight opacity-40">{{ formatPrice(0) }}</p>
                        <p class="mt-4 text-xs opacity-60">Informe o custo acima pra calcular este canal.</p>
                    </template>

                    <template v-else-if="frete === null">
                        <p class="mt-6 text-sm font-medium opacity-90">
                            {{ marketplace.frete === 'mercado_livre'
                                ? 'Informe o frete do Mercado Livre acima para calcular este canal.'
                                : 'Sem cotação de frete Correios para esse tamanho na tabela da loja.' }}
                        </p>
                    </template>

                    <template v-else-if="result.invalid">
                        <p class="mt-6 text-sm font-medium opacity-90">
                            Imposto + comissão + ADS + lucro passam de 100% do preço — não existe preço que feche a regra neste canal.
                        </p>
                    </template>

                    <template v-else>
                        <p class="mt-5 text-[11px] uppercase tracking-wide opacity-70">Cadastre por</p>
                        <p class="text-3xl font-extrabold leading-tight">{{ formatPrice(result.price) }}</p>
                        <p class="text-[11px] opacity-70">mínimo exato {{ formatPrice(result.minimo) }}</p>

                        <div class="mt-4 space-y-1.5 border-t pt-3 text-xs" :style="{ borderColor: marketplace.theme.chipBg }">
                            <div class="flex items-center justify-between opacity-90">
                                <span>Custo do produto</span>
                                <span>− {{ formatPrice(costNum) }}</span>
                            </div>
                            <div v-if="result.pct > 0" class="flex items-center justify-between opacity-90">
                                <span>Comissão ({{ formatPct(result.pct) }})</span>
                                <span>− {{ formatPrice(result.commissionValue) }}</span>
                            </div>
                            <div v-if="result.fixed > 0" class="flex items-center justify-between opacity-90">
                                <span>Tarifa fixa{{ marketplace.key === 'tiktok_shop' ? ' + frete médio' : '' }}</span>
                                <span>− {{ formatPrice(result.fixed) }}</span>
                            </div>
                            <div v-if="result.frete > 0" class="flex items-center justify-between opacity-90">
                                <span>Frete</span>
                                <span>− {{ formatPrice(result.frete) }}</span>
                            </div>
                            <div class="flex items-center justify-between opacity-90">
                                <span>Insumos</span>
                                <span>− {{ formatPrice(result.insumos) }}</span>
                            </div>
                            <div class="flex items-center justify-between opacity-90">
                                <span>Imposto ({{ formatPct(REGRA.impostoPct) }})</span>
                                <span>− {{ formatPrice(result.taxValue) }}</span>
                            </div>
                            <div class="flex items-center justify-between opacity-90">
                                <span>ADS ({{ formatPct(REGRA.adsPct) }})</span>
                                <span>− {{ formatPrice(result.adsValue) }}</span>
                            </div>
                        </div>

                        <div class="mt-3 flex items-center justify-between border-t pt-3" :style="{ borderColor: marketplace.theme.chipBg }">
                            <span class="text-xs font-medium opacity-80">Lucro</span>
                            <span class="text-lg font-bold" :style="{ color: marketplace.theme.accent }">
                                {{ formatPrice(result.profit) }}
                            </span>
                        </div>
                        <p class="text-right text-[11px] opacity-70">
                            {{ result.realMarginPct.toFixed(1) }}% do preço (regra: {{ formatPct(result.lucroPct) }})
                        </p>
                    </template>
                </div>

                <button
                    type="button"
                    class="mt-auto flex items-center justify-between px-5 py-2.5 text-[11px] font-medium"
                    :style="{ background: marketplace.theme.chipBg }"
                    @click="toggleExpanded(marketplace.key)"
                >
                    <span><i class="fas fa-circle-info mr-1"></i>{{ marketplace.defaultCommissionPct !== null ? 'Taxas e comissão da categoria' : 'Taxas usadas' }}</span>
                    <i class="fas" :class="expandedKey === marketplace.key ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                </button>

                <div v-if="expandedKey === marketplace.key" class="space-y-2 px-5 py-3" :style="{ background: marketplace.theme.chipBg }">
                    <template v-if="marketplace.defaultCommissionPct !== null">
                        <label class="block text-[11px] font-medium opacity-80">
                            Comissão desta categoria (%)
                        </label>
                        <input
                            type="number" step="0.1" min="0" max="90"
                            :value="overrides[marketplace.key] ?? marketplace.defaultCommissionPct"
                            class="w-full rounded-md border-0 bg-white/90 px-2 py-1.5 text-sm text-slate-700"
                            @input="setOverride(marketplace.key, $event.target.value)"
                        >
                    </template>
                    <p class="text-[11px] leading-snug opacity-80">{{ marketplace.note }}</p>
                    <p class="flex items-center justify-between text-[10px] opacity-60">
                        <span>Fonte: {{ marketplace.source }}</span>
                        <button
                            v-if="overrides[marketplace.key] !== undefined"
                            type="button"
                            class="underline"
                            @click="setOverride(marketplace.key, null)"
                        >
                            usar padrão
                        </button>
                    </p>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
