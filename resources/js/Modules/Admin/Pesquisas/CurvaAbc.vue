<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import Abas from './Abas.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Curva ABC por canal (pedido 2026-10-12): vendas efetivas do canal,
// variações somadas no produto pai. Ranking pelo faturamento; a margem por
// produto é antes de frete e ADS (que só existem por canal). O resultado
// oficial do canal vem de ContributionMargin::porCanal().
const props = defineProps({
    curva: { type: Object, required: true },
    filtros: { type: Object, required: true },
    periodo: { type: Object, required: true },
    canais: { type: Object, required: true },
    contagem: { type: Object, default: () => ({}) },
});

const classe = ref('todas');

const moeda = (valor) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(valor ?? 0);
const pct = (valor) => (valor === null || valor === undefined ? '—' : `${valor.toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`);
const dataBr = (iso) => new Date(`${iso}T12:00:00`).toLocaleDateString('pt-BR');

const filtrar = (mudanca) => {
    router.get('/admin/pesquisas/curva-abc', { ...props.filtros, ...mudanca }, { preserveScroll: true, replace: true });
};

const produtos = computed(() => (classe.value === 'todas'
    ? props.curva.produtos
    : props.curva.produtos.filter((p) => p.classe === classe.value)));

const oficial = computed(() => props.curva.canal_oficial);
const margemOficialPct = computed(() => (oficial.value.receita > 0 ? (oficial.value.margem / oficial.value.receita) * 100 : null));

const corClasse = {
    A: 'bg-emerald-100 text-emerald-700',
    B: 'bg-amber-100 text-amber-700',
    C: 'bg-slate-100 text-slate-600',
};
</script>

<template>
    <Head title="Curva ABC por canal" />

    <AdminLayout>
        <div class="mb-4">
            <h1 class="text-2xl font-bold">Curva ABC por canal</h1>
            <p class="mt-1 text-sm text-slate-500">
                Quais produtos fazem o faturamento de cada canal. A = até 80% do faturamento, B = até 95%, C = o resto.
            </p>
        </div>

        <Abas ativa="curva-abc" :contagem="props.contagem" />

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <div class="flex flex-wrap gap-2" role="group" aria-label="Canal">
                <button v-for="(rotulo, valor) in props.canais" :key="valor" type="button"
                    class="rounded-full px-3 py-1.5 text-sm font-medium transition-colors"
                    :class="props.filtros.canal === valor ? 'bg-primary text-white' : 'bg-[var(--surface-muted)] text-slate-600 hover:bg-[var(--surface-border)]'"
                    :aria-pressed="props.filtros.canal === valor"
                    @click="filtrar({ canal: valor })">
                    {{ rotulo }}
                </button>
            </div>
            <div class="ml-auto">
                <label for="dias-curva" class="sr-only">Período</label>
                <select id="dias-curva" :value="props.filtros.dias"
                    class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm"
                    @change="filtrar({ dias: Number($event.target.value) })">
                    <option :value="30">Últimos 30 dias</option>
                    <option :value="60">Últimos 60 dias</option>
                    <option :value="90">Últimos 90 dias</option>
                    <option :value="180">Últimos 180 dias</option>
                </select>
            </div>
        </div>

        <section class="mb-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm" aria-labelledby="titulo-oficial">
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="titulo-oficial" class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                    Resultado do canal ({{ props.canais[props.filtros.canal] }})
                </h2>
                <span class="text-xs tabular-nums text-slate-500">{{ dataBr(props.periodo.desde) }} a {{ dataBr(props.periodo.ate) }}</span>
            </div>
            <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-7">
                <div><dt class="text-xs text-slate-500">Pedidos</dt><dd class="text-lg font-bold tabular-nums">{{ oficial.pedidos }}</dd></div>
                <div><dt class="text-xs text-slate-500">Receita</dt><dd class="text-lg font-bold tabular-nums">{{ moeda(oficial.receita) }}</dd></div>
                <div><dt class="text-xs text-slate-500">Custo dos produtos</dt><dd class="text-lg font-bold tabular-nums">{{ moeda(oficial.custo_produtos) }}</dd></div>
                <div><dt class="text-xs text-slate-500">Taxas</dt><dd class="text-lg font-bold tabular-nums">{{ moeda(oficial.taxas) }}</dd></div>
                <div><dt class="text-xs text-slate-500">Frete da loja</dt><dd class="text-lg font-bold tabular-nums">{{ moeda(oficial.frete) }}</dd></div>
                <div><dt class="text-xs text-slate-500">ADS</dt><dd class="text-lg font-bold tabular-nums">{{ moeda(oficial.ads) }}</dd></div>
                <div>
                    <dt class="text-xs text-slate-500">Margem de contribuição</dt>
                    <dd class="text-lg font-bold tabular-nums" :class="oficial.margem < 0 ? 'text-red-600' : 'text-emerald-700'">
                        {{ moeda(oficial.margem) }} <span class="text-xs font-medium">{{ pct(margemOficialPct) }}</span>
                    </dd>
                </div>
            </dl>
            <p class="mt-3 text-xs text-slate-500">Mesma conta do painel e do Financeiro (margem de contribuição oficial).</p>
        </section>

        <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--surface-border)] p-4">
                <div class="flex flex-wrap gap-2" role="group" aria-label="Classe">
                    <button v-for="opcao in ['todas', 'A', 'B', 'C']" :key="opcao" type="button"
                        class="rounded-full px-3 py-1 text-xs font-medium transition-colors"
                        :class="classe === opcao ? 'bg-primary text-white' : 'bg-[var(--surface-muted)] text-slate-600 hover:bg-[var(--surface-border)]'"
                        :aria-pressed="classe === opcao"
                        @click="classe = opcao">
                        {{ opcao === 'todas' ? `Todas (${props.curva.produtos.length})` : `${opcao} (${props.curva.resumo[opcao.toLowerCase()]})` }}
                    </button>
                </div>
                <span class="text-sm text-slate-500">Faturamento dos produtos: <strong class="tabular-nums text-slate-700">{{ moeda(props.curva.total) }}</strong></span>
            </div>

            <div v-if="produtos.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-[var(--surface-border)] text-left text-xs uppercase tracking-wide text-slate-500">
                            <th scope="col" class="px-4 py-3 font-semibold">Classe</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Produto</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Unidades</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Faturamento</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Part.</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Acum.</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Custo</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Taxas</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold" title="Faturamento menos custo e taxas, antes de frete e ADS">Margem*</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="produto in produtos" :key="`${produto.id}-${produto.nome}`"
                            class="border-b border-[var(--surface-border)] last:border-0 hover:bg-[var(--surface-muted)]">
                            <td class="px-4 py-3">
                                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold" :class="corClasse[produto.classe]">{{ produto.classe }}</span>
                            </td>
                            <td class="max-w-sm px-4 py-3">
                                <Link v-if="produto.id" :href="`/admin/produtos/${produto.id}/editar`" class="break-words font-medium hover:text-primary hover:underline">{{ produto.nome }}</Link>
                                <span v-else class="break-words font-medium">{{ produto.nome }}</span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ produto.quantidade }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ moeda(produto.faturamento) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ pct(produto.participacao) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-500">{{ pct(produto.acumulado) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ moeda(produto.custo) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ moeda(produto.taxas) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums" :class="produto.margem < 0 ? 'text-red-600' : ''">
                                {{ moeda(produto.margem) }}
                                <span class="block text-xs text-slate-500">{{ pct(produto.margem_percentual) }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="px-6 py-12 text-center text-sm text-slate-500">Nenhuma venda efetiva neste canal no período.</p>

            <p class="border-t border-[var(--surface-border)] px-4 py-3 text-xs text-slate-500">
                * Margem por produto = faturamento − custo do cadastro − taxa real do pedido rateada pelo valor do item. Frete pago pela loja e ADS
                entram só no resultado do canal acima. Cores e variações somam no produto principal.
                <template v-if="props.filtros.canal === 'tiktok_shop'"> No TikTok o faturamento por produto é o preço cheio, antes do desconto que a loja financia; a receita real está no resultado do canal.</template>
            </p>
        </div>
    </AdminLayout>
</template>
