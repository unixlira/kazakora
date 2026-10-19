<script setup>
/**
 * Fluxo de Caixa (aprovado pelo Lira em 2026-10-19, substitui o
 * Gerenciador em /admin). Casca com três abas; filtros na URL (de, ate,
 * canal, comparar, agrupar, aba). O servidor calcula só a aba aberta
 * (CashFlowController). Todo número do Resumo vem da conta oficial
 * (ContributionMargin), a mesma do painel e do e-mail das 23h50.
 */
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { usePermissions } from '@/Shared/usePermissions';
import { formatPercent, formatPrice } from '@/Shared/graficos';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AbaLancamentos from './AbaLancamentos.vue';
import AbaVendas from './AbaVendas.vue';
import AlertaAds from '../Financeiro/AlertaAds.vue';
import AlertasFinanceiros from './AlertasFinanceiros.vue';
import CascataResultado from './CascataResultado.vue';
import DeCadaCem from './DeCadaCem.vue';
import EvolucaoPeriodo from './EvolucaoPeriodo.vue';
import FiltrosPeriodo from './FiltrosPeriodo.vue';
import KpiResultado from './KpiResultado.vue';
import LucroPorCanal from './LucroPorCanal.vue';
import ProximosDias from './ProximosDias.vue';
import QualidadeDados from './QualidadeDados.vue';

const props = defineProps({
    filtro: { type: Object, required: true },
    canaisFiltro: { type: Array, default: () => [] },
    costCenters: { type: Array, default: () => [] },
    // Resumo
    resumo: { type: Object, default: null },
    radarAds: { type: Object, default: null },
    canais: { type: Array, default: null },
    serie: { type: Array, default: null },
    previsao: { type: Object, default: null },
    qualidade: { type: Object, default: null },
    alertas: { type: Array, default: null },
    // Vendas
    sales: { type: Object, default: null },
    salesTotals: { type: Object, default: null },
    salesFilter: { type: Object, default: null },
    // Lançamentos
    entries: { type: Object, default: null },
    summary: { type: Object, default: null },
    entriesFilter: { type: Object, default: null },
});

const { can } = usePermissions();
const URL_BASE = '/admin/fluxo-de-caixa';

// ---- Navegação: filtros na URL --------------------------------------------
const filtroBase = (aba = props.filtro.aba) => {
    const f = props.filtro;
    const p = { aba, de: f.de, ate: f.ate };
    if (f.canal) p.canal = f.canal;
    if (f.comparar !== 'mes_passado') p.comparar = f.comparar;
    if (f.agruparFixo) p.agrupar = f.agrupar;
    return p;
};
const hrefAba = (aba) => `${URL_BASE}?${new URLSearchParams(filtroBase(aba)).toString()}`;

const visitar = (mudancas = {}) => {
    const atuais = Object.fromEntries(new URLSearchParams(window.location.search).entries());
    delete atuais.page;
    delete atuais.entries_page;
    delete atuais.start;
    delete atuais.end;
    delete atuais.platform;
    const params = { ...atuais, ...filtroBase(), ...mudancas };
    if (mudancas.agrupar === null) delete params.agrupar;
    const limpos = Object.fromEntries(Object.entries(params).filter(([, v]) => v !== null && v !== undefined && String(v).trim() !== ''));
    router.get(URL_BASE, limpos, { preserveState: true, preserveScroll: true, replace: true });
};

// "Calculando…" enquanto o servidor refaz a conta.
const calculando = ref(false);
let pararInicio = null;
let pararFim = null;
onMounted(() => {
    pararInicio = router.on('start', (e) => { if (e.detail.visit.url.pathname === URL_BASE) calculando.value = true; });
    pararFim = router.on('finish', () => { calculando.value = false; });
});
onBeforeUnmount(() => { pararInicio?.(); pararFim?.(); });

// ---- Novo lançamento ------------------------------------------------------
const abaLancamentos = ref(null);
const abrirForm = ref(false);
const novoLancamento = () => {
    if (props.filtro.aba === 'lancamentos') {
        abaLancamentos.value?.abrirNovo();
        return;
    }
    router.get(URL_BASE, { ...filtroBase('lancamentos') }, { preserveScroll: true, onSuccess: () => { abrirForm.value = true; } });
};

// ---- Resumo -----------------------------------------------------------------
const ABAS = [
    { chave: 'resumo', rotulo: 'Resumo' },
    { chave: 'vendas', rotulo: 'Vendas (custo e comissão)' },
    { chave: 'lancamentos', rotulo: 'Lançamentos' },
];

const atual = computed(() => props.resumo?.atual ?? null);
const comp = computed(() => props.resumo?.comparacao ?? null);
const rotuloComp = computed(() => (comp.value ? props.filtro.rotuloComparacao : null));
const vazio = computed(() => atual.value && atual.value.pedidos === 0 && atual.value.receita === 0);
const pctDe = (parte, todo) => (todo ? formatPercent((parte / todo) * 100) : '0,0%');

// Vencimento do DAS da competência do fim do período: dia 20 do mês seguinte,
// antecipado para sexta se cair no fim de semana.
const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
const das = computed(() => {
    const [ano, mes] = props.filtro.ate.split('-').map(Number);
    const vence = new Date(Date.UTC(mes === 12 ? ano + 1 : ano, mes === 12 ? 0 : mes, 20, 12));
    while ([0, 6].includes(vence.getUTCDay())) vence.setUTCDate(vence.getUTCDate() - 1);
    const dd = String(vence.getUTCDate()).padStart(2, '0');
    const mm = String(vence.getUTCMonth() + 1).padStart(2, '0');
    return { mes: MESES[mes - 1], vence: `${dd}/${mm}` };
});

const adsPct = computed(() => (atual.value?.receita ? (atual.value.ads / atual.value.receita) * 100 : 0));
const adsDaMargem = computed(() => {
    const antes = (atual.value?.margem ?? 0) + (atual.value?.ads ?? 0);
    return antes > 0 ? formatPercent((atual.value.ads / antes) * 100) : '—';
});
const escalaAds = computed(() => Math.max((props.resumo?.adsLimite ?? 6) * 2, adsPct.value * 1.1, 1));
const aliquota = computed(() => (props.resumo?.aliquota ?? 4.5).toLocaleString('pt-BR'));

const AJUDA = {
    lucro: 'Receita menos custo dos produtos, taxas dos canais, frete pago pela loja, ADS e imposto do Simples, mais o bônus do Flex.',
    vendas: 'O que os clientes pagaram, com frete.',
    nota: 'Soma das notas fiscais de venda. É a base do DAS.',
    receita: 'Valor dos produtos depois dos descontos da loja, sem o frete que o comprador paga ao canal (na Amazon, com frete). É a base do lucro e a primeira barra de "Para onde foi o dinheiro".',
    ads: 'Gasto real com anúncio no período. Recarga de carteira não entra.',
    imposto: '4,5% das notas de venda. Estimativa: o valor oficial é a guia do contador.',
};

const linkSemCusto = computed(() => `${URL_BASE}?${new URLSearchParams({ ...filtroBase('vendas'), sem_custo: 1 }).toString()}`);
</script>

<template>
    <Head title="Fluxo de Caixa" />

    <AdminLayout>
        <header class="mb-3 flex flex-wrap items-end justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-2xl font-bold">Fluxo de Caixa</h1>
                <p class="text-sm text-pretty text-[var(--texto-suave)]">Quanto entrou, quanto sobrou e para onde foi o dinheiro. Conta oficial do lucro (a mesma do painel e do e-mail das 23h50).</p>
            </div>
            <button v-if="can('financeiro.create')" type="button"
                class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-emphasis"
                @click="novoLancamento">
                <i class="fas fa-plus text-xs" aria-hidden="true"></i> Novo lançamento
            </button>
        </header>

        <nav class="mb-4 flex gap-1 overflow-x-auto border-b border-[var(--surface-border)]" aria-label="Abas do Fluxo de Caixa">
            <Link v-for="a in ABAS" :key="a.chave" :href="hrefAba(a.chave)" preserve-scroll
                class="shrink-0 border-b-2 px-3 py-2 text-sm font-semibold whitespace-nowrap transition-colors"
                :class="filtro.aba === a.chave ? 'border-[var(--color-primary)] text-[var(--surface-foreground)]' : 'border-transparent text-[var(--texto-suave)] hover:text-[var(--surface-foreground)]'"
                :aria-current="filtro.aba === a.chave ? 'page' : undefined">{{ a.rotulo }}</Link>
        </nav>

        <FiltrosPeriodo :filtro="filtro" :canais="canaisFiltro" @mudar="visitar" />
        <p class="sr-only" aria-live="polite">{{ calculando ? 'Calculando…' : '' }}</p>
        <div v-if="calculando" class="mb-3 inline-flex items-center gap-2 rounded-full bg-[var(--surface)] px-3 py-1 text-xs font-semibold text-[var(--texto-suave)] shadow-sm" aria-hidden="true">
            <i class="fas fa-circle-notch fa-spin"></i> Calculando…
        </div>

        <!-- ================= Resumo ================= -->
        <div v-if="filtro.aba === 'resumo' && atual" :class="{ 'opacity-60 transition-opacity': calculando }">
            <AlertaAds :radar="radarAds" />
            <AlertasFinanceiros :alertas="alertas ?? []" />

            <p v-if="vazio" class="mb-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-4 py-3 text-sm shadow-sm">Nenhuma venda paga neste período.</p>

            <section class="kpis mb-4 grid gap-3 sm:gap-4" aria-label="Números do período">
                <KpiResultado class="kpi-lucro" hero rotulo="Lucro líquido" :valor="formatPrice(atual.lucro)"
                    :apoio="[`${pctDe(atual.lucro, atual.receita)} da receita · antes do imposto: ${formatPrice(atual.margem)}`]"
                    :ajuda="AJUDA.lucro" :atual="atual.lucro" :anterior="comp?.lucro ?? null" :rotulo-comparacao="rotuloComp" />
                <KpiResultado rotulo="Vendas brutas" :valor="formatPrice(atual.vendas_brutas)"
                    :apoio="[
                        { texto: `Faturamento em nota: ${formatPrice(atual.faturamento_nota)}`, ajuda: AJUDA.nota, rotulo: 'Faturamento em nota' },
                        { texto: `Receita: ${formatPrice(atual.receita)}`, ajuda: AJUDA.receita, rotulo: 'Receita' },
                    ]"
                    :ajuda="AJUDA.vendas" :atual="atual.vendas_brutas" :anterior="comp?.vendas_brutas ?? null" :rotulo-comparacao="rotuloComp" />
                <KpiResultado rotulo="Pedidos" :valor="String(atual.pedidos)"
                    :apoio="[
                        `Ticket médio ${formatPrice(atual.pedidos ? atual.receita / atual.pedidos : 0)}`,
                        ...(atual.pedidos_site !== null ? [`KazaKora: ${atual.pedidos_site} ${atual.pedidos_site === 1 ? 'pedido pago' : 'pedidos pagos'}`] : []),
                    ]"
                    :atual="atual.pedidos" :anterior="comp?.pedidos ?? null" :rotulo-comparacao="rotuloComp" />
                <KpiResultado rotulo="ADS" :valor="formatPrice(atual.ads)"
                    :apoio="[`${formatPercent(adsPct)} da receita · ${adsDaMargem} da margem antes do ADS`]"
                    :ajuda="AJUDA.ads" :atual="atual.ads" :anterior="comp?.ads ?? null" bom="baixo" :rotulo-comparacao="rotuloComp">
                    <div class="medidor relative mt-2 h-1.5 rounded-full bg-[var(--surface-muted)]" role="img"
                        :aria-label="`ADS em ${formatPercent(adsPct)} da receita; meta ${resumo.adsMeta}%`">
                        <i class="absolute inset-y-0 left-0 rounded-full" :class="adsPct > resumo.adsLimite ? 'bg-error' : adsPct > resumo.adsMeta ? 'bg-warning' : 'bg-success'"
                            :style="{ width: `${Math.min(100, (adsPct / escalaAds) * 100)}%` }"></i>
                        <b class="absolute -top-1 -bottom-1 w-0.5 bg-[var(--surface-foreground)]" :style="{ left: `${(resumo.adsMeta / escalaAds) * 100}%` }"></b>
                    </div>
                    <p class="mt-1 text-[11px] text-[var(--texto-suave)]">meta {{ resumo.adsMeta.toLocaleString('pt-BR') }}% da receita</p>
                </KpiResultado>
                <KpiResultado rotulo="Imposto estimado" :valor="formatPrice(atual.imposto)"
                    :apoio="[`Simples ${aliquota}% · DAS de ${das.mes} vence ${das.vence}`]"
                    :ajuda="AJUDA.imposto" :atual="atual.imposto" :anterior="comp?.imposto ?? null" bom="baixo" :rotulo-comparacao="rotuloComp" />
            </section>

            <div class="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <CascataResultado class="lg:col-span-2" :conta="atual" />
                <DeCadaCem class="order-first lg:order-none" :conta="atual" />
            </div>

            <div class="mb-4">
                <LucroPorCanal :canais="canais ?? []" />
            </div>

            <div class="mb-4">
                <EvolucaoPeriodo :serie="serie ?? []" :agrupar="filtro.agrupar" :rotulo-comparacao="filtro.rotuloComparacao" @agrupar="visitar({ agrupar: $event })" />
            </div>

            <div class="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <ProximosDias :previsao="previsao ?? { itens: [] }" :hoje="filtro.hoje" />
                <QualidadeDados :qualidade="qualidade ?? { canais: [], pendencias: {} }" :link-sem-custo="linkSemCusto" />
            </div>

            <p class="text-xs text-[var(--texto-suave)]">
                Lucro = receita − custo dos produtos − taxas − frete pago pela loja − ADS + bônus do Flex − imposto do Simples ({{ aliquota }}% das notas).
                Lançamentos manuais não entram no lucro. Comparação: {{ rotuloComp ? `${filtro.rotuloComparacao} (${filtro.compDe.split('-').reverse().slice(0, 2).join('/')} a ${filtro.compAte.split('-').reverse().slice(0, 2).join('/')})` : 'desligada' }}.
            </p>
        </div>

        <!-- ================= Vendas ================= -->
        <AbaVendas v-else-if="filtro.aba === 'vendas' && sales" :class="{ 'opacity-60 transition-opacity': calculando }"
            :sales="sales" :sales-totals="salesTotals ?? {}" :sales-filter="salesFilter ?? {}" @visitar="visitar" />

        <!-- ================= Lançamentos ================= -->
        <AbaLancamentos v-else-if="filtro.aba === 'lancamentos' && entries" ref="abaLancamentos" :class="{ 'opacity-60 transition-opacity': calculando }"
            :entries="entries" :summary="summary ?? { balance: 0, income: 0, expense: 0 }" :entries-filter="entriesFilter ?? {}"
            :cost-centers="costCenters" :abrir-formulario="abrirForm" @visitar="visitar" />
    </AdminLayout>
</template>

<style scoped>
.kpis { grid-template-columns: 1.4fr repeat(4, minmax(0, 1fr)); }
@media (max-width: 1279px) {
    .kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .kpis :deep(.kpi-lucro) { grid-column: 1 / -1; }
}
</style>
