<script setup>
/**
 * Aba "Produtos" de Indicadores (2026-10-19): mais vendidos (30 dias) e
 * curva A/B (90 dias) do antigo Gerenciador, mesma conta
 * (PainelGerencial::produtos(), curva pela CurvaAbc/porItem).
 */
import { Link } from '@inertiajs/vue3';
import { formatPercent, formatPrice } from '@/Shared/graficos';
import { computed } from 'vue';

const props = defineProps({
    produtos: { type: Object, default: () => ({ topProducts: [], abcCurve: { total: 0, a: [], b: [] } }) },
});

const abcCards = computed(() => [
    { key: 'a', description: 'Somam 80% do faturamento', icon: 'fas fa-star', color: 'var(--color-success)', items: props.produtos?.abcCurve?.a ?? [] },
    { key: 'b', description: 'Os próximos 15% do faturamento', icon: 'fas fa-layer-group', color: 'var(--color-info)', items: props.produtos?.abcCurve?.b ?? [] },
]);
const topMax = computed(() => Math.max(1, ...(props.produtos?.topProducts ?? []).map((p) => p.quantity)));
</script>

<template>
    <div>
        <h2 class="sr-only">Produtos</h2>
        <!-- Produtos: cada card com a sua cor, ranking e a barra de peso. -->
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <section class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm transition duration-200 hover:shadow-md"
                style="border-top: 3px solid var(--faixa-card)">
                <div class="flex items-center gap-3 border-b border-[var(--surface-border)] px-4 py-4"
                    style="background: linear-gradient(135deg, color-mix(in oklab, var(--primary-solido) 10%, var(--surface)) 0%, var(--surface) 80%)">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-white" style="background: var(--primary-solido)"><i class="fas fa-fire" aria-hidden="true"></i></span>
                    <div>
                        <h3 class="text-base font-semibold">Produtos mais vendidos</h3>
                        <p class="text-xs text-[var(--texto-suave)]">Unidades vendidas nos últimos 30 dias</p>
                    </div>
                </div>
                <div class="p-4">
                    <p v-if="produtos.topProducts.length === 0" class="text-sm text-[var(--texto-suave)]">Nenhuma venda nos últimos 30 dias.</p>
                    <ol v-else class="space-y-3 text-sm">
                        <li v-for="(product, index) in produtos.topProducts" :key="`${product.id}-${product.name}`">
                            <div class="flex items-center justify-between gap-3">
                                <span class="flex min-w-0 items-center gap-2">
                                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                                        :class="index < 3 ? 'text-white' : 'bg-lightprimary text-primary'"
                                        :style="index < 3 ? { background: 'var(--primary-solido)' } : {}">{{ index + 1 }}</span>
                                    <span class="min-w-0 truncate">
                                        <Link v-if="product.id" :href="`/admin/produtos/${product.id}/editar`" class="hover:text-primary hover:underline">{{ product.name }}</Link>
                                        <span v-else>{{ product.name }}</span>
                                        <span v-if="product.color" class="block text-xs font-semibold text-[var(--texto-suave)]">{{ product.color }}</span>
                                    </span>
                                </span>
                                <span class="shrink-0 text-right">
                                    <span class="block font-semibold">{{ product.quantity }} un.</span>
                                    <span class="block text-xs text-[var(--texto-suave)]">{{ formatPrice(product.revenue) }}</span>
                                </span>
                            </div>
                            <div class="mt-1.5 ml-8 h-1.5 rounded-full bg-lightprimary">
                                <div class="h-1.5 rounded-full" style="background: var(--primary-solido)" :style="{ width: `${(product.quantity / topMax) * 100}%` }"></div>
                            </div>
                        </li>
                    </ol>
                </div>
            </section>

            <section v-for="curva in abcCards" :key="curva.key"
                class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm transition duration-200 hover:shadow-md"
                style="border-top: 3px solid var(--faixa-card)">
                <div class="flex items-center gap-3 border-b border-[var(--surface-border)] px-4 py-4"
                    :style="{ background: `linear-gradient(135deg, color-mix(in oklab, ${curva.color} 10%, var(--surface)) 0%, var(--surface) 80%)` }">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-white" :style="{ background: curva.color }"><i :class="curva.icon" aria-hidden="true"></i></span>
                    <div>
                        <h3 class="text-base font-semibold">Produtos curva {{ curva.key.toUpperCase() }}</h3>
                        <p class="text-xs text-[var(--texto-suave)]">{{ curva.description }} · últimos 90 dias</p>
                    </div>
                </div>
                <div class="p-4">
                    <p v-if="curva.items.length === 0" class="text-sm text-[var(--texto-suave)]">Nenhum produto nesta curva.</p>
                    <ul v-else class="space-y-3 text-sm">
                        <li v-for="product in curva.items.slice(0, 10)" :key="`${product.id}-${product.name}`">
                            <div class="flex items-center justify-between gap-3">
                                <span class="min-w-0 truncate">
                                    <Link v-if="product.id" :href="`/admin/produtos/${product.id}/editar`" class="hover:text-primary hover:underline">{{ product.name }}</Link>
                                    <span v-else>{{ product.name }}</span>
                                    <span v-if="product.color" class="block text-xs font-semibold text-[var(--texto-suave)]">{{ product.color }}</span>
                                </span>
                                <span class="shrink-0 text-right">
                                    <span class="block font-semibold">{{ formatPrice(product.revenue) }}</span>
                                    <span class="block text-xs text-[var(--texto-suave)]">{{ formatPercent(product.share) }} do faturamento</span>
                                </span>
                            </div>
                            <div class="mt-1.5 h-1.5 rounded-full" :style="{ background: `color-mix(in oklab, ${curva.color} 15%, transparent)` }">
                                <div class="h-1.5 rounded-full" :style="{ width: `${(product.share / (curva.items[0]?.share || 1)) * 100}%`, background: curva.color }"></div>
                            </div>
                        </li>
                    </ul>
                    <p v-if="curva.items.length > 10" class="mt-3 text-xs text-[var(--texto-suave)]">+ {{ curva.items.length - 10 }} produto(s) nesta curva</p>
                </div>
            </section>
        </div>
    </div>
</template>
