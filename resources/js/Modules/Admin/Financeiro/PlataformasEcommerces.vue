<script setup>
/**
 * Financeiro › Plataformas e E-commerces (pedido do Lira em 16/11/2026):
 * o resultado dos marketplaces separado do resultado dos sites próprios.
 * Os números vêm prontos de ContributionMargin::porCanal() (ver
 * PlataformasEcommercesController e GruposDeCanais); a tela só mostra.
 */
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    filtros: { type: Object, required: true },
    resultado: { type: Object, required: true },
    semAdsIntegrado: { type: Array, default: () => [] },
});

const formatPrice = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v ?? 0);
const formatPercent = (v) => (v === null || v === undefined ? '—' : `${v.toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`);
const formatNumber = (v) => (v ?? 0).toLocaleString('pt-BR');
const formatDate = (iso) => iso.split('-').reverse().join('/');
const lucroClasse = (v) => (v < 0 ? 'text-error' : v > 0 ? 'text-success' : '');

// Abas: Plataformas e E-commerces sempre; Outros só quando tem pedido fora dos dois.
const abas = computed(() => Object.values(props.resultado.grupos));
const aba = ref(props.resultado.grupos[props.filtros.aba] ? props.filtros.aba : 'plataformas');
const grupo = computed(() => props.resultado.grupos[aba.value]);
const trocarAba = (chave) => {
    aba.value = chave;
    // Guarda a aba na URL (voltar e recarregar mantêm), sem ir ao servidor.
    const url = new URL(window.location.href);
    url.searchParams.set('aba', chave);
    window.history.replaceState(window.history.state, '', url);
};

// Período: atalhos e datas livres, no fuso da loja.
const hojeSp = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Sao_Paulo' }).format(new Date());
const somarDias = (iso, dias) => {
    const d = new Date(`${iso}T12:00:00Z`);
    d.setUTCDate(d.getUTCDate() + dias);
    return d.toISOString().slice(0, 10);
};
const atalhos = computed(() => {
    const hoje = hojeSp();
    const inicioMes = `${hoje.slice(0, 8)}01`;
    const fimMesPassado = somarDias(inicioMes, -1);
    const ano = hoje.slice(0, 4);
    return [
        { label: 'Este mês', de: inicioMes, ate: hoje },
        { label: 'Mês passado', de: `${fimMesPassado.slice(0, 8)}01`, ate: fimMesPassado },
        { label: '90 dias', de: somarDias(hoje, -89), ate: hoje },
        { label: 'Este ano', de: `${ano}-01-01`, ate: hoje },
    ];
});
const periodo = reactive({ de: props.filtros.de, ate: props.filtros.ate });
const carregando = ref(false);
const aplicar = (de = periodo.de, ate = periodo.ate) => {
    router.get('/admin/financeiro/plataformas-e-ecommerces', { de, ate, aba: aba.value }, {
        preserveScroll: true,
        onStart: () => { carregando.value = true; },
        onFinish: () => { carregando.value = false; },
    });
};
const atalhoAtivo = (a) => a.de === props.filtros.de && a.ate === props.filtros.ate;

const cartoes = computed(() => {
    const t = grupo.value.total;
    return [
        { titulo: formatPrice(t.receita), legenda: 'Receita', icone: 'fas fa-sack-dollar', variante: 'primary' },
        { titulo: formatPrice(t.ads), legenda: 'ADS', icone: 'fas fa-bullhorn', variante: 'warning' },
        { titulo: formatPercent(t.ads_pct), legenda: 'ADS % da receita', icone: 'fas fa-percent', variante: 'warning' },
        { titulo: formatPrice(t.lucro), legenda: 'Lucro líquido', icone: 'fas fa-coins', variante: t.lucro < 0 ? 'error' : 'success', classe: lucroClasse(t.lucro) },
        { titulo: formatPercent(t.margem_pct), legenda: 'Margem (lucro / receita)', icone: 'fas fa-chart-pie', variante: t.lucro < 0 ? 'error' : 'success', classe: lucroClasse(t.lucro) },
        { titulo: formatNumber(t.pedidos), legenda: 'Pedidos', icone: 'fas fa-receipt', variante: 'info' },
    ];
});

// Participação de cada grupo no total (barra no topo).
const participacao = computed(() => abas.value.map((g) => ({
    chave: g.chave,
    nome: g.nome,
    receita: g.total.receita,
    lucro: g.total.lucro,
    pct: props.resultado.total.receita > 0 ? (g.total.receita / props.resultado.total.receita) * 100 : 0,
})));

// Aviso de ADS sem integração só para canal do grupo aberto.
const canaisSemAds = computed(() => grupo.value.canais.filter((c) => c.sem_ads_integrado));

// Tabela por mês: só linha de canal que teve movimento (canal zerado polui).
const linhasDoMes = (mes) => mes.canais.filter((c) => c.pedidos > 0 || c.receita !== 0 || c.ads !== 0 || c.lucro !== 0);
</script>

<template>
    <Head title="Plataformas e E-commerces" />

    <AdminLayout>
        <div class="mb-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-[var(--texto-suave)]">Financeiro</p>
            <h1 class="text-2xl font-bold">Plataformas e E-commerces</h1>
            <p class="mt-1 text-sm text-[var(--texto-suave)]">
                Vendas efetivas de {{ formatDate(filtros.de) }} a {{ formatDate(filtros.ate) }} · lucro líquido pela margem de contribuição, a mesma conta de Resultado e margem
            </p>
        </div>

        <!-- Período -->
        <form class="mb-6 flex flex-wrap items-end gap-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" @submit.prevent="aplicar()">
            <div class="flex flex-wrap gap-2" role="group" aria-label="Atalhos de período">
                <button v-for="a in atalhos" :key="a.label" type="button"
                    class="rounded-full border px-3 py-1.5 text-sm transition"
                    :class="atalhoAtivo(a) ? 'border-transparent bg-[var(--primary-solido)] text-white' : 'border-[var(--surface-border)] hover:bg-[var(--surface-muted)]'"
                    :aria-pressed="atalhoAtivo(a)"
                    @click="periodo.de = a.de; periodo.ate = a.ate; aplicar(a.de, a.ate)">
                    {{ a.label }}
                </button>
            </div>
            <div class="ml-auto flex flex-wrap items-end gap-2">
                <label class="text-xs text-[var(--texto-suave)]">De
                    <input v-model="periodo.de" type="date" name="de" required class="mt-1 block rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm">
                </label>
                <label class="text-xs text-[var(--texto-suave)]">Até
                    <input v-model="periodo.ate" type="date" name="ate" required class="mt-1 block rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm">
                </label>
                <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50" :disabled="carregando">
                    {{ carregando ? 'Carregando…' : 'Aplicar' }}
                </button>
            </div>
        </form>

        <!-- Abas = grupos, com o resumo de cada um -->
        <div class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-2" :class="abas.length > 2 ? 'xl:grid-cols-3' : ''" role="tablist" aria-label="Grupos de canais">
            <button v-for="p in participacao" :key="p.chave" type="button" role="tab"
                class="rounded-xl border bg-[var(--surface)] p-4 text-left shadow-sm transition hover:shadow-md"
                :class="aba === p.chave ? 'border-[var(--destaque)] ring-2 ring-[var(--destaque)]' : 'border-[var(--surface-border)]'"
                :aria-selected="aba === p.chave"
                @click="trocarAba(p.chave)">
                <div class="flex items-baseline justify-between gap-3">
                    <span class="text-lg font-bold">{{ p.nome }}</span>
                    <span class="text-sm tabular-nums text-[var(--texto-suave)]">{{ formatPercent(p.pct) }} da receita</span>
                </div>
                <p class="text-xs text-[var(--texto-suave)]">{{ resultado.grupos[p.chave].descricao }}</p>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-[var(--surface-muted)]">
                    <div class="h-full rounded-full bg-[var(--primary-solido)]" :style="{ width: `${Math.min(100, p.pct)}%` }"></div>
                </div>
                <dl class="mt-2 flex gap-6 text-sm tabular-nums">
                    <div><dt class="text-xs text-[var(--texto-suave)]">Receita</dt><dd class="font-semibold">{{ formatPrice(p.receita) }}</dd></div>
                    <div><dt class="text-xs text-[var(--texto-suave)]">Lucro</dt><dd class="font-semibold" :class="lucroClasse(p.lucro)">{{ formatPrice(p.lucro) }}</dd></div>
                </dl>
            </button>
        </div>

        <section role="tabpanel" :aria-label="grupo.nome">
            <!-- Métricas do grupo -->
            <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                <CardStats v-for="c in cartoes" :key="c.legenda" :stat-title="c.titulo" :stat-subtitle="c.legenda" :stat-icon-name="c.icone" :variant="c.variante" :title-class="c.classe ?? ''" compact vivid />
            </div>

            <p v-if="canaisSemAds.length" class="mb-6 rounded-lg border border-[var(--color-warning)] bg-lightwarning px-4 py-2 text-sm">
                <i class="fas fa-triangle-exclamation mr-1 text-warning"></i>
                {{ canaisSemAds.map((c) => c.nome).join(', ') }}: sem integração de ADS. O gasto de anúncio aparece zerado e o lucro desses canais fica maior que o real.
            </p>
            <p v-else-if="aba === 'ecommerces'" class="mb-6 text-xs text-[var(--texto-suave)]">
                ADS dos sites = gasto da Meta (conta da KazaKora no canal kazakora.com, conta da FerraKora na FerraKora).
            </p>

            <!-- Por canal no período -->
            <section class="mb-6 overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm" style="border-top: 3px solid var(--faixa-card)">
                <h2 class="border-b border-[var(--surface-border)] px-4 py-3 text-base font-semibold">{{ grupo.nome }} por canal</h2>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-left text-sm">
                        <thead class="border-b border-[var(--surface-border)] text-xs uppercase text-[var(--texto-suave)]">
                            <tr>
                                <th scope="col" class="px-4 py-2">Canal</th>
                                <th scope="col" class="py-2 pr-3 text-right">Receita</th>
                                <th scope="col" class="py-2 pr-3 text-right">ADS</th>
                                <th scope="col" class="py-2 pr-3 text-right">ADS %</th>
                                <th scope="col" class="py-2 pr-3 text-right">Lucro</th>
                                <th scope="col" class="py-2 pr-3 text-right">Margem</th>
                                <th scope="col" class="py-2 pr-4 text-right">Pedidos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in grupo.canais" :key="c.canal" class="border-b border-[var(--surface-border)] hover:bg-[var(--surface-muted)]">
                                <td class="px-4 py-2 font-medium">{{ c.nome }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ formatPrice(c.total.receita) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">
                                    <span v-if="c.sem_ads_integrado" class="text-[var(--texto-suave)]" title="Canal sem integração de ADS">sem integração</span>
                                    <span v-else>{{ formatPrice(c.total.ads) }}</span>
                                </td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ c.sem_ads_integrado ? '—' : formatPercent(c.total.ads_pct) }}</td>
                                <td class="py-2 pr-3 text-right font-semibold tabular-nums" :class="lucroClasse(c.total.lucro)">{{ formatPrice(c.total.lucro) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ formatPercent(c.total.margem_pct) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ formatNumber(c.total.pedidos) }}</td>
                            </tr>
                        </tbody>
                        <tfoot class="font-semibold">
                            <tr>
                                <td class="px-4 py-2">Total {{ grupo.nome }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ formatPrice(grupo.total.receita) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ formatPrice(grupo.total.ads) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ formatPercent(grupo.total.ads_pct) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums" :class="lucroClasse(grupo.total.lucro)">{{ formatPrice(grupo.total.lucro) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ formatPercent(grupo.total.margem_pct) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ formatNumber(grupo.total.pedidos) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <!-- Por mês e canal -->
            <section class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm" style="border-top: 3px solid var(--faixa-card)">
                <h2 class="border-b border-[var(--surface-border)] px-4 py-3 text-base font-semibold">{{ grupo.nome }} mês a mês</h2>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-left text-sm">
                        <thead class="border-b border-[var(--surface-border)] text-xs uppercase text-[var(--texto-suave)]">
                            <tr>
                                <th scope="col" class="px-4 py-2">Mês / canal</th>
                                <th scope="col" class="py-2 pr-3 text-right">Receita</th>
                                <th scope="col" class="py-2 pr-3 text-right">ADS</th>
                                <th scope="col" class="py-2 pr-3 text-right">ADS %</th>
                                <th scope="col" class="py-2 pr-3 text-right">Lucro</th>
                                <th scope="col" class="py-2 pr-3 text-right">Margem</th>
                                <th scope="col" class="py-2 pr-4 text-right">Pedidos</th>
                            </tr>
                        </thead>
                        <tbody v-for="m in [...grupo.meses].reverse()" :key="m.mes">
                            <tr class="border-b border-[var(--surface-border)] bg-[var(--surface-muted)] font-semibold">
                                <th scope="row" class="px-4 py-2 text-left">
                                    {{ m.rotulo }}
                                    <span v-if="m.de.slice(8) !== '01' || m.ate !== filtros.ate" class="ml-1 text-xs font-normal text-[var(--texto-suave)]">{{ formatDate(m.de) }} a {{ formatDate(m.ate) }}</span>
                                </th>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ formatPrice(m.total.receita) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ formatPrice(m.total.ads) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ formatPercent(m.total.ads_pct) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums" :class="lucroClasse(m.total.lucro)">{{ formatPrice(m.total.lucro) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ formatPercent(m.total.margem_pct) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ formatNumber(m.total.pedidos) }}</td>
                            </tr>
                            <tr v-for="c in linhasDoMes(m)" :key="`${m.mes}-${c.canal}`" class="border-b border-[var(--surface-border)] hover:bg-[var(--surface-muted)]">
                                <td class="py-2 pl-8 pr-3">{{ c.nome }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ formatPrice(c.receita) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ formatPrice(c.ads) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ formatPercent(c.ads_pct) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums" :class="lucroClasse(c.lucro)">{{ formatPrice(c.lucro) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ formatPercent(c.margem_pct) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ formatNumber(c.pedidos) }}</td>
                            </tr>
                            <tr v-if="linhasDoMes(m).length === 0">
                                <td colspan="7" class="px-8 py-2 text-xs text-[var(--texto-suave)]">Sem venda nem gasto neste mês.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="border-t border-[var(--surface-border)] px-4 py-3 text-xs text-[var(--texto-suave)]">
                    Lucro líquido = receita − custo dos produtos − taxas do canal − frete pago pela loja − insumos − ADS − imposto (Simples).
                    Mesma conta de Resultado e margem e do e-mail diário; somando os grupos dá o total da loja ({{ formatPrice(resultado.total.lucro) }} no período).
                </p>
            </section>
        </section>
    </AdminLayout>
</template>
