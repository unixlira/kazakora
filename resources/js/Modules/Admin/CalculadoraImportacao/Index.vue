<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { IMPOSTO_FEDERAL_PCT, calcularImportacao, simularLucro, validarImportacao } from '@/Shared/custoImportacao';

const props = defineProps({
    cotacao: { type: Object, default: null },
});

const form = ref({
    produtosUsd: '',
    freteUsd: '',
    dolar: props.cotacao ? props.cotacao.valor.toFixed(2) : '',
    icmsPct: 18,
    taxaAdmPct: 3,
    quantidade: '',
});

const cotacaoInfo = ref(props.cotacao);
const atualizandoDolar = ref(false);
const erroDolar = ref('');

const erro = ref('');
const resultado = ref(null);

const precoVenda = ref('');
const vendasPorDia = ref('');

const brl = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v || 0);
const usd = (v) => 'US$ ' + new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v || 0);
const pct = (v) => new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 2 }).format(v || 0) + '%';
const num = (v, casas = 2) => new Intl.NumberFormat('pt-BR', { minimumFractionDigits: casas, maximumFractionDigits: casas }).format(v || 0);

const quandoCotacao = computed(() => {
    if (!cotacaoInfo.value?.atualizado_em) return '';
    const d = new Date(cotacaoInfo.value.atualizado_em);
    const data = d.toLocaleDateString('pt-BR', { timeZone: 'America/Sao_Paulo' });
    const hora = d.toLocaleTimeString('pt-BR', { timeZone: 'America/Sao_Paulo', hour: '2-digit', minute: '2-digit' });
    return `${data} às ${hora}`;
});

const atualizarDolar = async () => {
    atualizandoDolar.value = true;
    erroDolar.value = '';
    try {
        const r = await fetch('/admin/calculadora-importacao/cotacao', { headers: { Accept: 'application/json' } });
        const dados = await r.json();
        if (!r.ok) throw new Error(dados.message || 'Não foi possível buscar a cotação.');
        cotacaoInfo.value = dados;
        form.value.dolar = Number(dados.valor).toFixed(2);
    } catch (e) {
        erroDolar.value = e.message || 'Não foi possível buscar a cotação. Digite o valor à mão.';
    } finally {
        atualizandoDolar.value = false;
    }
};

const calcular = () => {
    erro.value = validarImportacao(form.value) ?? '';
    resultado.value = erro.value ? null : calcularImportacao(form.value);
};

const simulacao = computed(() =>
    resultado.value ? simularLucro(resultado.value.custoPorPeca, precoVenda.value, vendasPorDia.value) : null,
);

const passos = computed(() => {
    const r = resultado.value;
    if (!r) {
        return [
            { titulo: 'Produto + frete, em reais', conta: 'produto + frete (USD) × dólar do dia', nota: 'O que você gastou lá fora, somando mercadoria, frete e seguro, convertido pela cotação.' },
            { titulo: `Imposto federal (${IMPOSTO_FEDERAL_PCT}%)`, conta: `produto + frete × ${IMPOSTO_FEDERAL_PCT}%`, nota: 'O imposto de importação incide sobre o produto mais o frete.' },
            { titulo: 'Base ICMS', conta: 'produto + frete + imposto federal', nota: 'Soma do que você gastou com o imposto federal.' },
            { titulo: `ICMS (${pct(form.value.icmsPct)})`, conta: `base ICMS ÷ ${num(1 - (Number(form.value.icmsPct) || 0) / 100)} − base ICMS`, nota: `Aqui não é multiplicar pela alíquota. O ICMS entra na própria base de cálculo, então a conta é dividir por ${num(1 - (Number(form.value.icmsPct) || 0) / 100)}, que é 1 menos a alíquota.` },
            { titulo: `Taxa administrativa (${pct(form.value.taxaAdmPct)})`, conta: `total com impostos × ${pct(form.value.taxaAdmPct)}`, nota: 'Cobrada por último, sobre produto + frete + imposto federal + ICMS.' },
            { titulo: 'Custo total', conta: 'total com impostos + taxa administrativa', nota: 'Quanto a importação custa de verdade, na sua mão.' },
        ];
    }

    const lista = [
        { titulo: 'Produto + frete, em reais', conta: `${usd(r.totalUsd)} × ${brl(r.cotacao)} =`, valor: r.produtoFrete, nota: 'O que você gastou lá fora, somando mercadoria, frete e seguro, convertido pela cotação.' },
        { titulo: `Imposto federal (${IMPOSTO_FEDERAL_PCT}%)`, conta: `${brl(r.produtoFrete)} × ${IMPOSTO_FEDERAL_PCT}% =`, valor: r.impostoFederal, nota: 'O imposto de importação incide sobre o produto mais o frete.' },
        { titulo: 'Base ICMS', conta: `${brl(r.produtoFrete)} + ${brl(r.impostoFederal)} =`, valor: r.baseIcms, nota: 'Soma do que você gastou com o imposto federal.' },
        {
            titulo: `ICMS (${pct(r.icmsPct)})`,
            conta: `${brl(r.baseIcms)} ÷ ${num(r.divisorIcms)} − ${brl(r.baseIcms)} =`,
            valor: r.icmsValor,
            nota: `Aqui não é multiplicar por ${pct(r.icmsPct)}. O ICMS entra na própria base de cálculo, então a conta é dividir por ${num(r.divisorIcms)}, que é 1 menos a alíquota.`,
            extra: r.icmsPct > 0
                ? `Se fosse ${pct(r.icmsPct)} direto sobre ${brl(r.baseIcms)}, daria ${brl(r.icmsSimples)}, e o imposto acabaria valendo menos que a alíquota sobre o total da operação. Dividindo, ele fica sendo exatamente ${pct(r.icmsPct)} do valor final: ${brl(r.baseAdministrativa)}.`
                : null,
        },
        { titulo: `Taxa administrativa (${pct(r.taxaAdmPct)})`, conta: `${brl(r.baseAdministrativa)} × ${pct(r.taxaAdmPct)} =`, valor: r.taxaAdministrativa, nota: 'Cobrada por último, sobre produto + frete + imposto federal + ICMS.' },
        { titulo: 'Custo total', conta: `${brl(r.baseAdministrativa)} + ${brl(r.taxaAdministrativa)} =`, valor: r.custoTotal, nota: 'Quanto a importação custa de verdade, na sua mão.' },
    ];

    if (r.custoPorPeca !== null) {
        lista.push({ titulo: 'Custo por peça', conta: `${brl(r.custoTotal)} ÷ ${r.quantidade} =`, valor: r.custoPorPeca, nota: 'É esse número que precisa caber no seu preço de venda.' });
    }

    return lista;
});

const linhasResultado = computed(() => {
    const r = resultado.value;
    if (!r) return [];
    const linhas = [
        { rotulo: 'Produto + frete', valor: r.produtoFrete },
        { rotulo: `Imposto federal (${IMPOSTO_FEDERAL_PCT}%)`, valor: r.impostoFederal },
        { rotulo: 'Base ICMS', valor: r.baseIcms },
        { rotulo: `ICMS (${pct(r.icmsPct)})`, valor: r.icmsValor },
        { rotulo: 'Base administrativa', valor: r.baseAdministrativa },
        { rotulo: `Taxa administrativa (${pct(r.taxaAdmPct)})`, valor: r.taxaAdministrativa },
        { rotulo: 'Custo total', valor: r.custoTotal, destaque: true },
    ];
    if (r.custoPorPeca !== null) linhas.push({ rotulo: 'Custo por peça', valor: r.custoPorPeca, destaque: true });
    return linhas;
});

const campo = 'w-full rounded-lg border border-slate-300 px-3 py-2.5 font-semibold dark:border-slate-600 dark:bg-slate-900';
const rotulo = 'block text-sm font-semibold text-slate-600 dark:text-slate-300';
const ajuda = 'mt-1 text-xs text-slate-400';
</script>

<template>
    <Head title="Calculadora de importação" />

    <AdminLayout>
        <div>
            <h1 class="text-2xl font-bold text-slate-700 dark:text-slate-200">Calculadora de importação</h1>
            <p class="mt-1 text-sm text-slate-500">
                Descubra quanto a importação custa de verdade, com impostos e taxas, e quanto sobra na revenda.
            </p>
        </div>

        <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
            <div class="space-y-6">
                <!-- Dados da remessa -->
                <form class="rounded-2xl bg-white p-6 shadow-lg dark:bg-slate-800" @submit.prevent="calcular">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <label for="imp-produtos" :class="rotulo">Valor total dos produtos (USD)</label>
                            <input id="imp-produtos" v-model="form.produtosUsd" type="number" step="0.01" min="0" placeholder="Ex: 1000" :class="[campo, 'mt-1']">
                        </div>
                        <div>
                            <label for="imp-frete" :class="rotulo">Valor do frete + seguro (USD)</label>
                            <input id="imp-frete" v-model="form.freteUsd" type="number" step="0.01" min="0" placeholder="Ex: 100" :class="[campo, 'mt-1']">
                        </div>
                        <div>
                            <label for="imp-dolar" :class="rotulo">Dólar do dia (R$)</label>
                            <div class="mt-1 flex gap-2">
                                <input id="imp-dolar" v-model="form.dolar" type="number" step="0.01" min="0" placeholder="Ex: 5,40" :class="campo">
                                <button
                                    type="button"
                                    class="shrink-0 rounded-lg border border-slate-300 px-3 text-sm font-medium text-slate-600 hover:border-primary hover:text-primary disabled:opacity-50 dark:border-slate-600 dark:text-slate-300"
                                    :disabled="atualizandoDolar"
                                    @click="atualizarDolar"
                                >
                                    <i class="fas fa-rotate mr-1" :class="{ 'fa-spin': atualizandoDolar }"></i>Atualizar
                                </button>
                            </div>
                            <p v-if="erroDolar" class="mt-1 text-xs text-red-500">{{ erroDolar }}</p>
                            <p v-else-if="quandoCotacao" :class="ajuda">Cotação de {{ quandoCotacao }} ({{ cotacaoInfo.fonte }}). Você pode ajustar à mão.</p>
                            <p v-else :class="ajuda">Cotação automática indisponível. Digite o valor à mão.</p>
                        </div>
                        <div>
                            <label for="imp-icms" :class="rotulo">ICMS do seu estado (%)</label>
                            <input id="imp-icms" v-model="form.icmsPct" type="number" step="0.01" min="0" max="99" placeholder="Ex: 18" :class="[campo, 'mt-1']">
                            <p :class="ajuda">A alíquota muda de estado para estado. O padrão é 18%.</p>
                        </div>
                        <div>
                            <label for="imp-taxa" :class="rotulo">Taxa administrativa (%)</label>
                            <input id="imp-taxa" v-model="form.taxaAdmPct" type="number" step="0.01" min="0" placeholder="Ex: 3" :class="[campo, 'mt-1']">
                            <p :class="ajuda">Cobrada sobre produto + frete + imposto federal + ICMS. O padrão é 3%.</p>
                        </div>
                        <div>
                            <label for="imp-qtd" :class="rotulo">Quantidade de peças</label>
                            <input id="imp-qtd" v-model="form.quantidade" type="number" step="1" min="1" placeholder="Ex: 10" :class="[campo, 'mt-1']">
                            <p :class="ajuda">Opcional. Informe para ver o custo por peça e simular o lucro.</p>
                        </div>
                    </div>

                    <p v-if="erro" role="alert" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600 dark:bg-red-900/30 dark:text-red-300">{{ erro }}</p>

                    <button type="submit" class="mt-5 w-full rounded-lg bg-primary px-4 py-3 font-semibold text-white hover:opacity-90">
                        <i class="fas fa-calculator mr-1"></i>Calcular custo
                    </button>
                </form>

                <!-- Resultado -->
                <div v-if="resultado" class="rounded-2xl bg-white p-6 shadow-lg dark:bg-slate-800">
                    <h2 class="text-lg font-bold text-slate-700 dark:text-slate-200">Resultado</h2>
                    <dl class="mt-3 divide-y divide-slate-100 dark:divide-slate-700">
                        <div v-for="linha in linhasResultado" :key="linha.rotulo" class="flex items-center justify-between py-2">
                            <dt class="text-sm" :class="linha.destaque ? 'font-bold text-slate-700 dark:text-slate-200' : 'text-slate-500'">{{ linha.rotulo }}</dt>
                            <dd class="tabular-nums" :class="linha.destaque ? 'text-lg font-extrabold text-primary' : 'font-semibold text-slate-700 dark:text-slate-200'">{{ brl(linha.valor) }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Simulação de lucro -->
                <div v-if="resultado && resultado.custoPorPeca !== null" class="rounded-2xl bg-white p-6 shadow-lg dark:bg-slate-800">
                    <h2 class="text-lg font-bold text-slate-700 dark:text-slate-200">Simulação de lucro</h2>
                    <p class="mt-1 text-sm text-slate-500">Partindo do custo de {{ brl(resultado.custoPorPeca) }} por peça.</p>

                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="imp-preco" :class="rotulo">Preço de venda por peça (R$)</label>
                            <input id="imp-preco" v-model="precoVenda" type="number" step="0.01" min="0" placeholder="Ex: 199,90" :class="[campo, 'mt-1']">
                        </div>
                        <div>
                            <label for="imp-vendas" :class="rotulo">Vendas por dia</label>
                            <input id="imp-vendas" v-model="vendasPorDia" type="number" step="1" min="0" placeholder="Ex: 5" :class="[campo, 'mt-1']">
                        </div>
                    </div>

                    <template v-if="simulacao">
                        <p v-if="simulacao.prejuizo" role="alert" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600 dark:bg-red-900/30 dark:text-red-300">
                            Nesse preço você não cobre o custo da peça. Ajuste o preço de venda ou o custo da importação.
                        </p>
                        <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-3">
                            <div v-for="card in [
                                { rotulo: 'Lucro por peça', valor: brl(simulacao.lucroPeca), negativo: simulacao.lucroPeca < 0 },
                                { rotulo: 'Margem', valor: num(simulacao.margemPct, 1) + '%', negativo: simulacao.margemPct < 0 },
                                { rotulo: 'Faturamento diário', valor: brl(simulacao.faturamentoDia) },
                                { rotulo: 'Lucro diário', valor: brl(simulacao.lucroDia), negativo: simulacao.lucroDia < 0 },
                                { rotulo: 'Faturamento mensal (30 dias)', valor: brl(simulacao.faturamentoMes) },
                                { rotulo: 'Lucro mensal (30 dias)', valor: brl(simulacao.lucroMes), negativo: simulacao.lucroMes < 0 },
                            ]" :key="card.rotulo" class="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                                <p class="text-xs text-slate-500">{{ card.rotulo }}</p>
                                <p class="mt-1 text-lg font-bold tabular-nums" :class="card.negativo ? 'text-red-500' : 'text-slate-700 dark:text-slate-200'">{{ card.valor }}</p>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Como a conta é feita -->
            <div class="h-fit rounded-2xl bg-white p-6 shadow-lg dark:bg-slate-800">
                <h2 class="text-lg font-bold text-slate-700 dark:text-slate-200">Como a conta é feita</h2>
                <p class="mt-1 text-sm text-slate-500">
                    {{ resultado ? 'Passo a passo com os seus números. Confira cada linha na calculadora do celular.' : 'Preencha os campos e clique em calcular para ver o passo a passo com os seus números.' }}
                </p>
                <ol class="mt-4 space-y-4">
                    <li v-for="(passo, i) in passos" :key="passo.titulo" class="flex gap-3">
                        <span aria-hidden="true" class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-bold text-primary">{{ i + 1 }}</span>
                        <div class="min-w-0">
                            <p class="font-semibold text-slate-700 dark:text-slate-200">{{ passo.titulo }}</p>
                            <p class="mt-0.5 font-mono text-sm text-slate-600 dark:text-slate-300">
                                {{ passo.conta }}
                                <strong v-if="passo.valor !== undefined" class="text-slate-800 dark:text-white">{{ brl(passo.valor) }}</strong>
                            </p>
                            <p class="mt-1 text-xs text-slate-400">{{ passo.nota }}</p>
                            <p v-if="passo.extra" class="mt-1 text-xs text-slate-400">{{ passo.extra }}</p>
                        </div>
                    </li>
                </ol>
            </div>
        </div>

        <p class="mt-6 text-xs text-slate-400">
            Simulação com imposto federal de {{ IMPOSTO_FEDERAL_PCT }}% e o ICMS informado. Os valores são uma estimativa para planejamento e podem variar conforme o regime aplicado à remessa.
        </p>
    </AdminLayout>
</template>
