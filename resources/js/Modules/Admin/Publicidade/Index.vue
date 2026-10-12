<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    periodos: { type: Array, default: () => [] },
});

// Mesma cor de cada plataforma usada em Recargas de ADS e no Financeiro.
const CANAIS = {
    shopee: { label: 'Shopee Ads', color: '#EE4D2D' },
    mercado_livre: { label: 'Mercado Ads', color: '#2968C8' },
};

const dinheiro = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v ?? 0);
const numero = (v) => new Intl.NumberFormat('pt-BR').format(v ?? 0);
const data = (d) => new Date(`${d}T00:00:00`).toLocaleDateString('pt-BR');
const roas = (v) => (v === null || v === undefined ? '—' : `${v.toFixed(2).replace('.', ',')}x`);

const atual = computed(() => props.periodos[0] ?? { canais: [] });
const totalGasto = computed(() => atual.value.canais.reduce((s, c) => s + c.gasto, 0));
const totalVendas = computed(() => atual.value.canais.reduce((s, c) => s + c.vendas, 0));
const roasGeral = computed(() => (totalGasto.value > 0 ? totalVendas.value / totalGasto.value : null));

const atalhos = [
    { label: 'Recargas de ADS', href: '/admin/anuncios/recargas', icon: 'fas fa-coins', texto: 'Saldo da Shopee Ads e histórico de recargas.' },
    { label: 'Radar de criativos', href: '/admin/mkt-digital', icon: 'fas fa-globe', texto: 'Anúncios de concorrentes para inspirar criativos.' },
    { label: 'Páginas de conversão', href: '/admin/paginas-de-conversao', icon: 'fas fa-bullseye', texto: 'Páginas de venda que estão convertendo.' },
    { label: 'Resultado e margem', href: '/admin/dashboard-financeiro', icon: 'fas fa-chart-line', texto: 'Lucro real por canal, já descontando o ADS.' },
];
</script>

<template>
    <Head title="Publicidade" />

    <AdminLayout>
        <div class="mb-2 flex items-center justify-between">
            <h1 class="text-2xl font-bold">Publicidade</h1>
        </div>
        <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">
            Quanto foi gasto em anúncio na Shopee e no Mercado Livre e quanto as plataformas dizem que esse anúncio vendeu.
            ROAS é a venda atribuída dividida pelo gasto. O lucro de verdade fica em Resultado e margem.
        </p>

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <CardStats stat-subtitle="GASTO DE ADS NO MÊS" :stat-title="dinheiro(totalGasto)" stat-icon-name="fas fa-bullhorn" variant="warning" />
            <CardStats stat-subtitle="VENDA ATRIBUÍDA AO ADS" :stat-title="dinheiro(totalVendas)" stat-icon-name="fas fa-cart-shopping" variant="success" />
            <CardStats stat-subtitle="ROAS GERAL DO MÊS" :stat-title="roas(roasGeral)" stat-icon-name="fas fa-arrow-trend-up" variant="primary" />
        </div>

        <section
            v-for="periodo in periodos"
            :key="periodo.titulo"
            class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm"
        >
            <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-[var(--surface-border)] px-4 py-3">
                <h2 class="text-base font-semibold">{{ periodo.titulo }}</h2>
                <span class="text-xs text-slate-500 dark:text-slate-400">{{ data(periodo.desde) }} a {{ data(periodo.ate) }}</span>
            </div>

            <p v-if="!periodo.canais.length" class="px-4 py-6 text-sm text-slate-500 dark:text-slate-400">
                Nenhum gasto de ADS sincronizado neste período.
            </p>

            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            <th scope="col" class="px-4 py-2 font-semibold">Canal</th>
                            <th scope="col" class="px-4 py-2 text-right font-semibold">Gasto</th>
                            <th scope="col" class="px-4 py-2 text-right font-semibold">Impressões</th>
                            <th scope="col" class="px-4 py-2 text-right font-semibold">Cliques</th>
                            <th scope="col" class="px-4 py-2 text-right font-semibold">Pedidos</th>
                            <th scope="col" class="px-4 py-2 text-right font-semibold">Venda atribuída</th>
                            <th scope="col" class="px-4 py-2 text-right font-semibold">ROAS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in periodo.canais" :key="c.canal" class="border-t border-[var(--surface-border)]">
                            <td class="px-4 py-2">
                                <span class="inline-flex items-center gap-2 font-semibold">
                                    <span class="h-2.5 w-2.5 rounded-full" :style="{ background: CANAIS[c.canal]?.color ?? '#64748B' }"></span>
                                    {{ CANAIS[c.canal]?.label ?? c.canal }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ dinheiro(c.gasto) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ numero(c.impressoes) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ numero(c.cliques) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ numero(c.pedidos) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ dinheiro(c.vendas) }}</td>
                            <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ roas(c.roas) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <h2 class="mb-3 text-base font-semibold">Ferramentas de publicidade</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Link
                v-for="a in atalhos"
                :key="a.href"
                :href="a.href"
                class="group rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm transition hover:border-primary focus-visible:outline-2 focus-visible:outline-primary"
            >
                <div class="mb-1 flex items-center gap-2 font-semibold">
                    <i :class="[a.icon, 'text-primary']" aria-hidden="true"></i>
                    {{ a.label }}
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ a.texto }}</p>
            </Link>
        </div>
    </AdminLayout>
</template>
