<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import FieldTooltip from '@/Shared/Components/FieldTooltip.vue';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { MARKETPLACES, computePricing } from '@/Shared/marketplaceFees';

const props = defineProps({
    products: {
        type: Array,
        default: () => [],
    },
});

const cost = ref('');
const marginPct = ref(30);
const showAdvanced = ref(false);
const taxPct = ref(0);
const freight = ref(0);
const overrides = ref({});
const expandedKey = ref(null);

const marginPresets = [15, 20, 30, 40, 50];

const productQuery = ref('');
const showSuggestions = ref(false);

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value || 0);

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
    productQuery.value = `${product.name} — ${product.sku}`;
    showSuggestions.value = false;
};

const costNum = computed(() => Number(cost.value) || 0);
const marginNum = computed(() => Number(marginPct.value) || 0);
const taxNum = computed(() => Number(taxPct.value) || 0);
const freightNum = computed(() => Number(freight.value) || 0);
const hasCost = computed(() => costNum.value > 0);

const results = computed(() =>
    MARKETPLACES.map((marketplace) => ({
        marketplace,
        result: computePricing(marketplace, {
            cost: costNum.value,
            marginPct: marginNum.value,
            taxPct: taxNum.value,
            freight: freightNum.value,
            commissionPctOverride: overrides.value[marketplace.key] ?? null,
        }),
    })),
);

const bestKey = computed(() => {
    if (!hasCost.value) return null;
    const valid = results.value.filter((r) => !r.result.invalid);
    if (!valid.length) return null;
    return valid.reduce((best, cur) => (cur.result.profit > best.result.profit ? cur : best)).marketplace.key;
});

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
                    Informe o custo e a margem desejada — a calculadora mostra o preço de venda ideal em cada canal, já descontando as taxas reais de cada marketplace.
                </p>
            </div>
        </div>

        <!-- Inputs principais -->
        <div class="mt-6 rounded-2xl bg-white p-6 shadow-lg dark:bg-slate-800">
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
                            placeholder="ou busque um produto do catálogo para preencher o custo..."
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

                <div>
                    <label for="margin" class="block text-sm font-semibold text-slate-600 dark:text-slate-300">Margem de lucro desejada</label>
                    <div class="relative mt-1">
                        <input
                            id="margin"
                            v-model="marginPct"
                            type="number"
                            step="1"
                            min="0"
                            max="95"
                            class="w-full rounded-lg border border-slate-300 py-3 pl-3 pr-10 text-lg font-semibold dark:border-slate-600 dark:bg-slate-900"
                        >
                        <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400">%</span>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button
                            v-for="preset in marginPresets"
                            :key="preset"
                            type="button"
                            class="rounded-full border px-3 py-1 text-xs font-medium transition-colors"
                            :class="Number(marginPct) === preset
                                ? 'border-primary bg-primary text-white'
                                : 'border-slate-300 text-slate-500 hover:border-primary hover:text-primary dark:border-slate-600'"
                            @click="marginPct = preset"
                        >
                            {{ preset }}%
                        </button>
                    </div>
                </div>
            </div>

            <button
                type="button"
                class="mt-4 flex items-center gap-1 text-xs font-medium text-slate-400 hover:text-primary"
                @click="showAdvanced = !showAdvanced"
            >
                <i class="fas" :class="showAdvanced ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                Mais opções (frete e imposto)
            </button>

            <div v-if="showAdvanced" class="mt-3 grid grid-cols-1 gap-4 border-t border-slate-100 pt-4 sm:grid-cols-2 dark:border-slate-700">
                <div>
                    <label for="freight" class="block text-xs font-medium uppercase text-slate-400">Frete que você paga (R$)</label>
                    <input
                        id="freight" v-model="freight" type="number" step="0.01" min="0" placeholder="0,00"
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600 dark:bg-slate-900"
                    >
                </div>
                <div>
                    <label for="tax" class="block text-xs font-medium uppercase text-slate-400">
                        Imposto sobre a venda (%)
                        <FieldTooltip text="Ex.: alíquota efetiva do Simples Nacional. Deixe em 0 se não quiser considerar imposto no cálculo." />
                    </label>
                    <input
                        id="tax" v-model="taxPct" type="number" step="0.01" min="0" max="50" placeholder="0"
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-600 dark:bg-slate-900"
                    >
                </div>
            </div>
        </div>

        <p v-if="!hasCost" class="mt-6 text-center text-sm text-slate-400">
            Informe o preço de custo acima — os cards abaixo calculam o preço sugerido em cada marketplace assim que você digitar.
        </p>

        <!-- Cards por marketplace -->
        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <div
                v-for="{ marketplace, result } in results"
                :key="marketplace.key"
                class="relative flex flex-col overflow-hidden rounded-2xl shadow-lg transition-transform hover:-translate-y-0.5"
                :style="{ background: marketplace.theme.cardBg, color: marketplace.theme.fg }"
            >
                <span
                    v-if="bestKey === marketplace.key"
                    class="absolute right-3 top-3 rounded-full bg-white/90 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-700 shadow"
                >
                    <i class="fas fa-trophy mr-1 text-amber-500"></i>Melhor margem
                </span>

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

                    <span
                        v-if="marketplace.estimated"
                        class="mt-2 inline-block rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                        :style="{ background: marketplace.theme.chipBg }"
                    >
                        Estimativa
                    </span>

                    <template v-if="!hasCost">
                        <p class="mt-5 text-[11px] uppercase tracking-wide opacity-70">Venda por</p>
                        <p class="text-3xl font-extrabold leading-tight opacity-40">{{ formatPrice(0) }}</p>
                        <p class="mt-4 text-xs opacity-60">Informe o custo acima pra calcular este canal.</p>
                    </template>

                    <template v-else-if="result.invalid">
                        <p class="mt-6 text-sm font-medium opacity-90">
                            Margem + taxas ultrapassam 100% do preço — reduza a margem desejada para calcular este canal.
                        </p>
                    </template>

                    <template v-else>
                        <p class="mt-5 text-[11px] uppercase tracking-wide opacity-70">Venda por</p>
                        <p class="text-3xl font-extrabold leading-tight">{{ formatPrice(result.price) }}</p>

                        <div class="mt-4 space-y-1.5 border-t pt-3 text-xs" :style="{ borderColor: marketplace.theme.chipBg }">
                            <div class="flex items-center justify-between opacity-90">
                                <span>{{ marketplace.commissionLabel }} ({{ result.pct.toFixed(1) }}%)</span>
                                <span>− {{ formatPrice(result.commissionValue) }}</span>
                            </div>
                            <div v-if="result.fixed > 0" class="flex items-center justify-between opacity-90">
                                <span>Taxa fixa</span>
                                <span>− {{ formatPrice(result.fixed) }}</span>
                            </div>
                            <div v-if="taxNum > 0" class="flex items-center justify-between opacity-90">
                                <span>Imposto ({{ taxNum }}%)</span>
                                <span>− {{ formatPrice(result.taxValue) }}</span>
                            </div>
                            <div v-if="freightNum > 0" class="flex items-center justify-between opacity-90">
                                <span>Frete</span>
                                <span>− {{ formatPrice(freightNum) }}</span>
                            </div>
                        </div>

                        <div class="mt-3 flex items-center justify-between border-t pt-3" :style="{ borderColor: marketplace.theme.chipBg }">
                            <span class="text-xs font-medium opacity-80">Lucro líquido</span>
                            <span class="text-lg font-bold" :style="{ color: marketplace.theme.accent }">
                                {{ formatPrice(result.profit) }}
                            </span>
                        </div>
                        <p class="text-right text-[11px] opacity-70">margem real {{ result.realMarginPct.toFixed(1) }}%</p>
                    </template>
                </div>

                <button
                    type="button"
                    class="mt-auto flex items-center justify-between px-5 py-2.5 text-[11px] font-medium"
                    :style="{ background: marketplace.theme.chipBg }"
                    @click="toggleExpanded(marketplace.key)"
                >
                    <span><i class="fas fa-sliders mr-1"></i>Ajustar comissão</span>
                    <i class="fas" :class="expandedKey === marketplace.key ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                </button>

                <div v-if="expandedKey === marketplace.key" class="space-y-2 px-5 py-3" :style="{ background: marketplace.theme.chipBg }">
                    <label class="block text-[11px] font-medium opacity-80">
                        Comissão desta categoria (%)
                    </label>
                    <input
                        type="number" step="0.1" min="0" max="90"
                        :value="overrides[marketplace.key] ?? marketplace.defaultCommissionPct"
                        class="w-full rounded-md border-0 bg-white/90 px-2 py-1.5 text-sm text-slate-700"
                        @input="setOverride(marketplace.key, $event.target.value)"
                    >
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
