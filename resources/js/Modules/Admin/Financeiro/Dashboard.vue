<script setup>
/**
 * Resultado e margem (reestruturado em 2026-10-19, proposta do Jonathan
 * aprovada pelo Lira). Ordem: período, alerta de ADS, "Quanto sobrou",
 * "Para onde foi o dinheiro", lucro por canal, anúncios, dinheiro para
 * receber e, recolhida, a conferência com os extratos. Todo número de lucro
 * vem da conta oficial (FinancialDashboardController / ContributionMargin).
 * Estilo do Fluxo de Caixa: cartões lisos, rótulos sem caixa alta, vermelho
 * só para prejuízo e margem baixa.
 */
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import ChartCanvas from '@/Shared/Components/ChartCanvas.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { diaMes, formatPercent, formatPrice, useTemaEscuro } from '@/Shared/graficos';
import { computed, ref, watch } from 'vue';
import AlertaAds from './AlertaAds.vue';

const props = defineProps({
    filtro: { type: Object, required: true },
    conta: { type: Object, required: true },
    confianca: { type: Object, required: true },
    canais: { type: Object, default: () => ({ linhas: [], semVendas: [] }) },
    anuncios: { type: Object, default: () => ({ linhas: [], serie: [] }) },
    receber: { type: Object, default: () => ({ disponivel: [], aLiberar: [] }) },
    conferencia: { type: Object, default: () => ({ channels: [], totals: {} }) },
    radarAds: { type: Object, default: null },
});

const URL_BASE = '/admin/dashboard-financeiro';
const MARGEM_BAIXA = 5;

// ---- Período ----------------------------------------------------------------
const PERIODOS = [
    { chave: 'mes', rotulo: 'Este mês' },
    { chave: 'mes_passado', rotulo: 'Mês passado' },
    { chave: 'inicio', rotulo: 'Desde o início' },
];
const escolherDatas = ref(props.filtro.periodo === 'datas');
const de = ref(props.filtro.de);
const ate = ref(props.filtro.ate);
watch(() => [props.filtro.de, props.filtro.ate, props.filtro.periodo], ([d, a, p]) => {
    de.value = d;
    ate.value = a;
    escolherDatas.value = p === 'datas';
});

const visitar = (params) => router.get(URL_BASE, params, { preserveScroll: true, replace: true });
const escolher = (chave) => {
    escolherDatas.value = false;
    visitar(chave === 'mes' ? {} : { periodo: chave });
};
const aplicarDatas = () => {
    if (!de.value || !ate.value) return;
    visitar({ periodo: 'datas', de: de.value, ate: ate.value });
};

const dataCurta = (iso) => {
    const [a, m, d] = iso.split('-');
    return props.filtro.de.slice(0, 4) !== props.filtro.ate.slice(0, 4) ? `${d}/${m}/${a}` : `${d}/${m}`;
};

// ---- Números ----------------------------------------------------------------
const c = computed(() => props.conta);
const pctVenda = (valor) => (c.value.receita ? formatPercent((valor / c.value.receita) * 100) : '—');
const lucroNegativo = computed(() => c.value.lucro < 0);

const EXTRATO = computed(() => [
    { rotulo: 'Vendas', valor: c.value.receita, tipo: 'base' },
    { rotulo: '(−) Custo dos produtos', valor: -c.value.custo_produtos },
    { rotulo: '(−) Taxas dos canais', valor: -c.value.taxas },
    { rotulo: '(−) Anúncios', valor: -c.value.ads },
    { rotulo: '(−) Frete pago pela loja', valor: -c.value.frete },
    { rotulo: '(+) Bônus do Flex', valor: c.value.bonificacoes },
    { rotulo: '(=) Margem antes do imposto', valor: c.value.margem, tipo: 'subtotal' },
    { rotulo: `(−) Imposto do Simples (${(c.value.aliquota ?? 0).toLocaleString('pt-BR')}% das notas)`, valor: -c.value.imposto },
    { rotulo: '(=) Lucro líquido', valor: c.value.lucro, tipo: 'total' },
]);

const temAviso = computed(() => props.confianca.itensSemCusto > 0 || props.confianca.taxaEstimada.pedidos > 0);

// ---- Anúncios ---------------------------------------------------------------
const { channelColor } = useTemaEscuro();
const verGastoPorDia = ref(false);
const serie = computed(() => props.anuncios?.serie ?? []);
const totalSerie = computed(() => serie.value.reduce((s, d) => s + d.shopee + d.mercado_livre, 0));
const dadosSerie = computed(() => ({
    labels: serie.value.map((d) => diaMes(d.date)),
    datasets: [
        { label: 'Shopee Ads', data: serie.value.map((d) => d.shopee), borderColor: channelColor('shopee'), backgroundColor: channelColor('shopee'), tension: 0.3 },
        { label: 'Mercado Ads', data: serie.value.map((d) => d.mercado_livre), borderColor: channelColor('mercado_livre'), backgroundColor: channelColor('mercado_livre'), tension: 0.3 },
    ],
}));
const formatRoas = (v) => v.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

// ---- Receber ----------------------------------------------------------------
const horaCurta = (iso) => {
    if (!iso) return '';
    const [data, hora = ''] = iso.split(' ');
    return `${diaMes(data)} às ${hora.slice(0, 5)}`;
};
</script>

<template>
    <Head title="Resultado e margem" />

    <AdminLayout>
        <header class="mb-3">
            <h1 class="text-2xl font-bold">Resultado e margem</h1>
        </header>

        <!-- Período -->
        <div class="mb-2 flex flex-wrap items-center gap-2 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2.5 shadow-sm" role="group" aria-label="Período">
            <button v-for="p in PERIODOS" :key="p.chave" type="button" class="botao-filtro"
                :aria-pressed="!escolherDatas && filtro.periodo === p.chave" @click="escolher(p.chave)">{{ p.rotulo }}</button>
            <button type="button" class="botao-filtro" :aria-pressed="escolherDatas" :aria-expanded="escolherDatas" @click="escolherDatas = !escolherDatas">Escolher datas</button>
            <template v-if="escolherDatas">
                <label for="rm-de" class="flex items-center gap-1.5 text-xs text-[var(--texto-suave)]">
                    De <input id="rm-de" v-model="de" name="de" autocomplete="off" type="date" :max="ate || filtro.hoje" class="campo-filtro" @change="aplicarDatas">
                </label>
                <label for="rm-ate" class="flex items-center gap-1.5 text-xs text-[var(--texto-suave)]">
                    Até <input id="rm-ate" v-model="ate" name="ate" autocomplete="off" type="date" :min="de" :max="filtro.hoje" class="campo-filtro" @change="aplicarDatas">
                </label>
            </template>
        </div>
        <p class="mb-4 text-sm text-[var(--texto-suave)]">
            De {{ dataCurta(filtro.de) }} a {{ dataCurta(filtro.ate) }}. Mesma conta do painel, do KoraSync e do e-mail das 23h50.
        </p>

        <AlertaAds :radar="radarAds" />

        <!-- 1. Quanto sobrou -->
        <section class="mb-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm" aria-labelledby="rm-sobrou">
            <h2 id="rm-sobrou" class="text-sm font-semibold text-[var(--texto-suave)]">Quanto sobrou</h2>
            <p class="mt-1 text-4xl font-bold tracking-tight tabular-nums" :class="lucroNegativo ? 'text-error' : ''">{{ formatPrice(conta.lucro) }}</p>
            <p class="mt-1 text-sm text-[var(--texto-suave)]">
                Lucro líquido: {{ pctVenda(conta.lucro) }} de tudo que você vendeu · {{ formatPrice(conta.receita) }} em vendas em {{ conta.pedidos }} {{ conta.pedidos === 1 ? 'pedido' : 'pedidos' }}
            </p>
            <ul v-if="temAviso" class="mt-3 grid gap-1.5 text-sm">
                <li v-if="confianca.itensSemCusto > 0" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800 dark:border-amber-900 dark:bg-amber-900/20 dark:text-amber-300">
                    <i class="fas fa-triangle-exclamation mt-0.5" aria-hidden="true"></i>
                    <span>
                        {{ confianca.itensSemCusto }} {{ confianca.itensSemCusto === 1 ? 'item vendido' : 'itens vendidos' }} no período sem custo cadastrado: o lucro está acima do real.
                        <template v-if="confianca.produtosAtivosSemCusto > 0">({{ confianca.produtosAtivosSemCusto }} de {{ confianca.produtosAtivos }} produtos ativos sem custo.)</template>
                        <Link :href="confianca.linkSemCusto" class="ms-1 font-semibold underline">Ver itens</Link>
                    </span>
                </li>
                <li v-if="confianca.taxaEstimada.pedidos > 0" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800 dark:border-amber-900 dark:bg-amber-900/20 dark:text-amber-300">
                    <i class="fas fa-circle-info mt-0.5" aria-hidden="true"></i>
                    <span>
                        {{ confianca.taxaEstimada.pedidos }} {{ confianca.taxaEstimada.pedidos === 1 ? 'pedido' : 'pedidos' }} ainda sem a taxa real do canal ({{ confianca.taxaEstimada.canais.join(', ') }})<template v-if="confianca.taxaEstimada.valor > 0">, com {{ formatPrice(confianca.taxaEstimada.valor) }} de taxa estimada</template>. O lucro muda quando o extrato chegar.
                    </span>
                </li>
            </ul>
        </section>

        <!-- 2. Para onde foi o dinheiro -->
        <section class="mb-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm" aria-labelledby="rm-extrato">
            <h2 id="rm-extrato" class="font-semibold">Para onde foi o dinheiro</h2>
            <p class="mb-2 text-xs text-[var(--texto-suave)]">Cada linha com o valor e quanto ela representa das vendas.</p>
            <table class="w-full max-w-2xl text-sm tabular-nums">
                <caption class="sr-only">Extrato do período, das vendas ao lucro líquido</caption>
                <thead class="sr-only">
                    <tr><th scope="col">Linha</th><th scope="col">Valor</th><th scope="col">% da venda</th></tr>
                </thead>
                <tbody>
                    <tr v-for="l in EXTRATO" :key="l.rotulo"
                        :class="l.tipo === 'subtotal' ? 'border-t border-[var(--surface-border)]' : l.tipo === 'total' ? 'border-t-2 border-[var(--surface-border)]' : ''">
                        <th scope="row" class="py-1.5 pr-3 text-left" :class="l.tipo ? 'font-semibold' : 'font-normal text-[var(--texto-suave)]'">{{ l.rotulo }}</th>
                        <td class="py-1.5 pr-3 text-right" :class="[l.tipo === 'total' ? 'text-base font-bold' : l.tipo ? 'font-semibold' : '', (l.tipo === 'total' || l.tipo === 'subtotal') && l.valor < 0 ? 'text-error' : '']">
                            {{ formatPrice(l.valor) }}
                        </td>
                        <td class="w-20 py-1.5 text-right text-[var(--texto-suave)]">{{ pctVenda(Math.abs(l.valor)) }}</td>
                    </tr>
                </tbody>
            </table>
            <Link v-if="conta.frete > 0 || conta.bonificacoes > 0" href="/admin/integracoes/mercado-livre/flex" class="mt-3 inline-block text-xs font-semibold text-[var(--destaque)] hover:underline">Ver o custo do Flex</Link>
        </section>

        <!-- 3. Lucro por canal -->
        <section class="mb-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm" aria-labelledby="rm-canal">
            <h2 id="rm-canal" class="font-semibold">Lucro por canal</h2>
            <p class="mb-2 text-xs text-[var(--texto-suave)]">Do maior lucro para o menor. Margem abaixo de {{ MARGEM_BAIXA }}% fica em destaque.</p>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[620px] text-sm tabular-nums">
                    <caption class="sr-only">Lucro por canal no período</caption>
                    <thead>
                        <tr class="border-b border-[var(--surface-border)] text-[11px] text-[var(--texto-suave)]">
                            <th scope="col" class="py-1.5 pr-2 text-left font-semibold">Canal</th>
                            <th scope="col" class="px-2 py-1.5 text-right font-semibold">Pedidos</th>
                            <th scope="col" class="px-2 py-1.5 text-right font-semibold">Vendas</th>
                            <th scope="col" class="px-2 py-1.5 text-right font-semibold">Anúncios</th>
                            <th scope="col" class="px-2 py-1.5 text-right font-semibold">Lucro</th>
                            <th scope="col" class="py-1.5 pl-2 text-right font-semibold">Margem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="l in canais.linhas" :key="l.canal" class="border-b border-[var(--surface-muted)] last:border-0">
                            <th scope="row" class="py-1.5 pr-2 text-left font-medium whitespace-nowrap">
                                <span class="inline-flex items-center gap-1.5">
                                    <i class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: channelColor(l.canal) }" aria-hidden="true"></i>{{ l.nome }}
                                </span>
                                <span v-if="l.pedidosTaxaEstimada > 0" class="etiqueta ms-2" :title="`${l.pedidosTaxaEstimada} ${l.pedidosTaxaEstimada === 1 ? 'pedido' : 'pedidos'} ainda sem a taxa real do canal`">taxa estimada</span>
                            </th>
                            <td class="px-2 py-1.5 text-right">{{ l.pedidos }}</td>
                            <td class="px-2 py-1.5 text-right">{{ formatPrice(l.receita) }}</td>
                            <td class="px-2 py-1.5 text-right">{{ formatPrice(l.ads) }}</td>
                            <td class="px-2 py-1.5 text-right font-semibold" :class="l.lucro < 0 ? 'text-error' : ''">{{ formatPrice(l.lucro) }}</td>
                            <td class="py-1.5 pl-2 text-right" :class="l.margemPct !== null && l.margemPct < MARGEM_BAIXA ? 'font-semibold text-error' : ''">
                                {{ l.margemPct === null ? '—' : formatPercent(l.margemPct) }}
                            </td>
                        </tr>
                        <tr v-if="!canais.linhas.length">
                            <td colspan="6" class="py-4 text-center text-[var(--texto-suave)]">Nenhuma venda paga neste período.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="canais.semVendas.length" class="mt-2 text-xs text-[var(--texto-suave)]">Sem vendas no período: {{ canais.semVendas.join(', ') }}.</p>
        </section>

        <!-- 4. Anúncios -->
        <section class="mb-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm" aria-labelledby="rm-ads">
            <h2 id="rm-ads" class="font-semibold">Anúncios: quanto custou e o que trouxe</h2>
            <p class="mb-2 text-xs text-[var(--texto-suave)]">Gasto do período, vendas que o próprio canal atribui ao anúncio, ROAS (vendas atribuídas ÷ gasto) e o lucro do canal já com o anúncio descontado.</p>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[620px] text-sm tabular-nums">
                    <caption class="sr-only">Gasto com anúncio por canal</caption>
                    <thead>
                        <tr class="border-b border-[var(--surface-border)] text-[11px] text-[var(--texto-suave)]">
                            <th scope="col" class="py-1.5 pr-2 text-left font-semibold">Canal</th>
                            <th scope="col" class="px-2 py-1.5 text-right font-semibold">Gasto</th>
                            <th scope="col" class="px-2 py-1.5 text-right font-semibold">Vendas atribuídas</th>
                            <th scope="col" class="px-2 py-1.5 text-right font-semibold">ROAS</th>
                            <th scope="col" class="py-1.5 pl-2 text-right font-semibold">Lucro do canal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="l in anuncios.linhas" :key="l.canal" class="border-b border-[var(--surface-muted)] last:border-0">
                            <th scope="row" class="py-1.5 pr-2 text-left font-medium">
                                <Link :href="l.link.href" class="hover:underline">{{ l.nome }}</Link>
                                <span v-if="l.nota" class="block text-[11px] font-normal text-[var(--texto-suave)]">{{ l.nota }}</span>
                            </th>
                            <td class="px-2 py-1.5 text-right">{{ l.gasto === null ? 'sem dado' : formatPrice(l.gasto) }}</td>
                            <td class="px-2 py-1.5 text-right" :class="l.vendas === null ? 'text-[var(--texto-suave)]' : ''">{{ l.vendas === null ? 'sem dado' : formatPrice(l.vendas) }}</td>
                            <td class="px-2 py-1.5 text-right" :class="l.roas === null ? 'text-[var(--texto-suave)]' : ''">{{ l.roas === null ? 'sem dado' : formatRoas(l.roas) }}</td>
                            <td class="py-1.5 pl-2 text-right" :class="l.lucro === null ? 'text-[var(--texto-suave)]' : l.lucro < 0 ? 'font-semibold text-error' : ''">{{ l.lucro === null ? 'sem vendas' : formatPrice(l.lucro) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <button type="button" class="mt-3 text-sm font-semibold text-[var(--destaque)] hover:underline" :aria-expanded="verGastoPorDia" @click="verGastoPorDia = !verGastoPorDia">
                {{ verGastoPorDia ? 'Esconder gasto por dia' : 'Ver gasto por dia' }}
            </button>
            <div v-if="verGastoPorDia" class="mt-2">
                <p class="text-xs text-[var(--texto-suave)]">Últimos 14 dias, Shopee Ads e Mercado Ads (os que informam o gasto dia a dia). Total: {{ formatPrice(totalSerie) }}.</p>
                <ChartCanvas type="line" :data="dadosSerie" label="Gasto com anúncio por dia nos últimos 14 dias" />
            </div>
        </section>

        <!-- 5. Dinheiro para receber -->
        <section class="mb-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm" aria-labelledby="rm-receber">
            <h2 id="rm-receber" class="font-semibold">Dinheiro para receber</h2>
            <div class="mt-2 grid grid-cols-1 gap-5 lg:grid-cols-2">
                <div>
                    <h3 class="mb-1 text-sm font-semibold">Disponível para saque agora</h3>
                    <ul class="divide-y divide-[var(--surface-muted)] text-sm">
                        <li v-for="d in receber.disponivel" :key="d.canal" class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 py-1.5">
                            <span>{{ d.nome }}</span>
                            <span v-if="d.valor !== null" class="font-semibold tabular-nums">{{ formatPrice(d.valor) }}</span>
                            <span v-else class="text-[var(--texto-suave)]">indisponível</span>
                            <span class="w-full text-[11px] text-[var(--texto-suave)]">
                                <template v-if="d.valor !== null && d.quando">consultado em {{ horaCurta(d.quando) }}</template>
                                <template v-else-if="d.motivo">{{ d.motivo }}</template>
                                <Link v-if="d.atualizar" :href="`${URL_BASE}?refresh=1`" :data="filtro.periodo === 'mes' ? {} : { periodo: filtro.periodo, de: filtro.de, ate: filtro.ate }" preserve-scroll class="ms-2 font-semibold text-[var(--destaque)] hover:underline">Atualizar</Link>
                            </span>
                        </li>
                    </ul>
                </div>
                <div>
                    <h3 class="mb-1 text-sm font-semibold">A liberar</h3>
                    <p class="mb-1 text-[11px] text-[var(--texto-suave)]">Pelos extratos importados, só o que tem data de hoje em diante.</p>
                    <ul class="divide-y divide-[var(--surface-muted)] text-sm">
                        <li v-for="l in receber.aLiberar" :key="l.canal" class="py-1.5">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                                <span>{{ l.nome }}</span>
                                <span v-if="!l.temExtrato" class="text-[var(--texto-suave)]">sem previsão, falta extrato</span>
                                <span v-else-if="l.datas.length" class="font-semibold tabular-nums">{{ formatPrice(l.total) }}</span>
                                <span v-else class="text-[var(--texto-suave)]">nada com data a liberar</span>
                            </div>
                            <p v-if="l.datas.length" class="text-[11px] text-[var(--texto-suave)] tabular-nums">
                                {{ l.datas.slice(0, 6).map((d) => `${diaMes(d.data)}: ${formatPrice(d.valor)}`).join(' · ') }}<template v-if="l.datas.length > 6"> · e mais {{ l.datas.length - 6 }} datas</template>
                            </p>
                            <p v-if="l.retido > 0" class="text-[11px] text-[var(--texto-suave)]">
                                Retido até a entrega: {{ formatPrice(l.retido) }} ({{ l.retidoPedidos }} {{ l.retidoPedidos === 1 ? 'pedido' : 'pedidos' }}), sem data de liberação no extrato.
                            </p>
                        </li>
                    </ul>
                </div>
            </div>
        </section>

        <!-- Conferência (recolhida) -->
        <details class="mb-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <summary class="cursor-pointer px-5 py-3 font-semibold">Conferência com os extratos dos canais</summary>
            <div class="border-t border-[var(--surface-border)] px-5 py-4">
                <p class="mb-2 text-xs text-[var(--texto-suave)]">
                    O que cada canal liquidou nos extratos importados, para pedidos feitos no período. Serve para conferir valores; o lucro é sempre o de cima.
                </p>
                <div v-if="conferencia.channels.length" class="overflow-x-auto">
                    <table class="w-full min-w-[480px] text-sm tabular-nums">
                        <caption class="sr-only">Conferência com os extratos</caption>
                        <thead>
                            <tr class="border-b border-[var(--surface-border)] text-[11px] text-[var(--texto-suave)]">
                                <th scope="col" class="py-1.5 pr-2 text-left font-semibold">Linha</th>
                                <th v-for="ch in conferencia.channels" :key="ch.channel" scope="col" class="px-2 py-1.5 text-right font-semibold">{{ ch.label }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="linha in [
                                ['Pedidos no extrato (achados no KazaKora)', (ch) => `${ch.uniqueOrders} (${ch.matchedOrders})`],
                                ['Subtotal anunciado', (ch) => formatPrice(ch.itemSubtotalBeforeDiscounts)],
                                ['Vendas líquidas dos produtos', (ch) => formatPrice(ch.productNetSales)],
                                ['Taxas da plataforma', (ch) => formatPrice(ch.platformFeesTaxes)],
                                ['Descontos do vendedor', (ch) => formatPrice(ch.sellerDiscounts)],
                                ['Desconto pago pela plataforma', (ch) => formatPrice(ch.platformDiscounts)],
                                ['Frete líquido', (ch) => formatPrice(ch.netShippingImpact)],
                                ['Ajustes', (ch) => formatPrice(ch.adjustmentAmount)],
                                ['Total liquidável', (ch) => formatPrice(ch.payoutAmount)],
                                ['Já pago', (ch) => formatPrice(ch.paidPayoutAmount)],
                                ['Ainda não pago', (ch) => formatPrice(ch.pendingPayoutAmount)],
                            ]" :key="linha[0]" class="border-b border-[var(--surface-muted)] last:border-0">
                                <th scope="row" class="py-1.5 pr-2 text-left font-normal text-[var(--texto-suave)]">{{ linha[0] }}</th>
                                <td v-for="ch in conferencia.channels" :key="ch.channel" class="px-2 py-1.5 text-right">{{ linha[1](ch) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-if="conferencia.channels.some((ch) => ch.missingOrders > 0)" class="mt-2 text-xs text-[var(--texto-suave)]">
                        Pedidos do extrato que não existem no KazaKora: {{ conferencia.channels.filter((ch) => ch.missingOrders > 0).map((ch) => `${ch.label} ${ch.missingOrders}`).join(', ') }}.
                    </p>
                </div>
                <p v-else class="text-sm text-[var(--texto-suave)]">Nenhum extrato importado para pedidos deste período.</p>
            </div>
        </details>

        <p class="text-xs text-[var(--texto-suave)]">
            Lucro = vendas − custo dos produtos − taxas dos canais − anúncios − frete pago pela loja + bônus do Flex − imposto do Simples. Lançamentos manuais do Fluxo de Caixa não entram.
        </p>
    </AdminLayout>
</template>

<style scoped>
.botao-filtro {
    border: 1px solid var(--surface-border);
    background: var(--surface);
    color: var(--surface-foreground);
    border-radius: 8px;
    padding: 6px 11px;
    font-size: 13px;
    font-weight: 500;
    touch-action: manipulation;
    white-space: nowrap;
}
.botao-filtro:hover { background: var(--surface-muted); }
.botao-filtro[aria-pressed="true"] {
    background: var(--surface-foreground);
    border-color: var(--surface-foreground);
    color: var(--surface);
}
.campo-filtro {
    background: var(--surface);
    color: var(--surface-foreground);
    border: 1px solid var(--surface-border);
    border-radius: 8px;
    padding: 5px 8px;
    font-size: 13px;
    color-scheme: light dark;
}
.botao-filtro:focus-visible, .campo-filtro:focus-visible { outline: 2px solid var(--foco, #9a3412); outline-offset: 2px; }
.etiqueta {
    display: inline-block;
    border-radius: 9999px;
    padding: 1px 7px;
    font-size: 10px;
    font-weight: 600;
    color: #b45309;
    background: rgba(245, 158, 11, 0.12);
}
:global(.dark) .etiqueta { color: #fbbf24; }
</style>
