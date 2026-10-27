<script setup>
/**
 * Campanha da Meta já excluída (pedido do Lira em 2026-10-27, botão
 * Visualizar do histórico em Gasto e resultados): os conjuntos e o
 * desempenho de cada anúncio, da foto tirada antes da exclusão.
 */
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    campanha: { type: Object, required: true },
    conjuntos: { type: Array, default: () => [] },
});

const dinheiro = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v ?? 0);
const numero = (v) => new Intl.NumberFormat('pt-BR').format(v ?? 0);
const data = (d) => new Date(`${d}T00:00:00`).toLocaleDateString('pt-BR');
const porcento = (v) => `${(v ?? 0).toFixed(2).replace('.', ',')}%`;
const STATUS = { PAUSED: 'Pausado', ARCHIVED: 'Arquivado', ACTIVE: 'Ativo', DELETED: 'Excluído' };
</script>

<template>
    <Head :title="campanha.nome" />

    <AdminLayout>
        <Link href="/admin/publicidade/meta-ads" class="mb-3 inline-flex items-center gap-2 text-sm font-medium text-primary hover:underline">
            <i class="fas fa-arrow-left text-xs" aria-hidden="true"></i> Gasto e resultados
        </Link>

        <h1 class="text-2xl font-bold">{{ campanha.nome }}</h1>
        <p class="mb-5 text-sm text-slate-500 dark:text-slate-400">
            {{ campanha.inicio ? data(campanha.inicio) : '—' }} a {{ campanha.fim ? data(campanha.fim) : 'sem fim' }}
            · orçamento
            <template v-if="campanha.orcamento_nivel === 'conjunto'">nos conjuntos</template>
            <template v-else>{{ dinheiro(campanha.orcamento_diario) }}/dia</template>
            · excluída em {{ data(campanha.excluida_em) }}.
            Números de toda a vida da campanha, guardados antes da exclusão.
        </p>

        <section class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6" aria-label="Totais da campanha">
            <div v-for="[rotulo, valor] in [
                ['Gasto', dinheiro(campanha.gasto)],
                ['Impressões', numero(campanha.impressoes)],
                ['Alcance', numero(campanha.alcance)],
                ['Cliques no link', numero(campanha.cliques_link)],
                ['CTR', porcento(campanha.ctr_link)],
                ['Compras', numero(campanha.compras)],
            ]" :key="rotulo" class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-3 shadow-sm">
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ rotulo }}</p>
                <p class="text-lg font-bold tabular-nums">{{ valor }}</p>
            </div>
        </section>

        <p v-if="!conjuntos.length" class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 text-sm text-slate-500" role="status">
            Sem conjuntos guardados para esta campanha.
        </p>

        <section v-for="(conjunto, i) in conjuntos" :key="conjunto.id"
            class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm"
            :aria-labelledby="`conjunto-${conjunto.id}`">
            <div class="border-b border-[var(--surface-border)] px-4 py-3">
                <h2 :id="`conjunto-${conjunto.id}`" class="text-base font-semibold">
                    Conjunto {{ i + 1 }} de {{ conjuntos.length }}: {{ conjunto.nome }}
                </h2>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                    {{ STATUS[conjunto.status] ?? conjunto.status }} antes de excluir
                    <template v-if="conjunto.orcamento_diario"> · {{ dinheiro(conjunto.orcamento_diario) }}/dia</template>
                    · {{ conjunto.anuncios.length }} {{ conjunto.anuncios.length === 1 ? 'anúncio' : 'anúncios' }}
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            <th scope="col" class="px-3 py-2 font-semibold">Anúncio</th>
                            <th scope="col" class="px-3 py-2 text-right font-semibold">Gasto</th>
                            <th scope="col" class="px-3 py-2 text-right font-semibold">Impressões</th>
                            <th scope="col" class="px-3 py-2 text-right font-semibold">Alcance</th>
                            <th scope="col" class="whitespace-nowrap px-3 py-2 text-right font-semibold">Cliques no link</th>
                            <th scope="col" class="px-3 py-2 text-right font-semibold">CTR</th>
                            <th scope="col" class="px-3 py-2 text-right font-semibold">Compras</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in conjunto.anuncios" :key="a.id" class="border-t border-[var(--surface-border)] align-top">
                            <td class="min-w-[16rem] px-3 py-2">
                                <span class="font-semibold">{{ a.nome }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ STATUS[a.status] ?? a.status }} antes de excluir</span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums">{{ dinheiro(a.metricas?.gasto) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ numero(a.metricas?.impressoes) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ numero(a.metricas?.alcance) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ numero(a.metricas?.cliques_link) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ porcento(a.metricas?.ctr_link) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ numero(a.metricas?.compras) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-[var(--surface-border)] font-semibold">
                            <td class="px-3 py-2">Total do conjunto</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ dinheiro(conjunto.metricas?.gasto) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ numero(conjunto.metricas?.impressoes) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ numero(conjunto.metricas?.alcance) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ numero(conjunto.metricas?.cliques_link) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ porcento(conjunto.metricas?.ctr_link) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ numero(conjunto.metricas?.compras) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>
    </AdminLayout>
</template>
