<script setup>
import InputError from '@/Shared/Components/InputError.vue';
import FieldTooltip from '@/Shared/Components/FieldTooltip.vue';
import { ORIGEM_OPTIONS, CSOSN_OPTIONS, PIS_COFINS_CST_OPTIONS, UNIDADE_MEDIDA_OPTIONS } from '@/Shared/fiscalOptions';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    product: {
        type: Object,
        required: true,
    },
    fiscalData: {
        type: Object,
        default: null,
    },
});

const form = useForm({
    ncm: props.fiscalData?.ncm ?? '',
    origem: props.fiscalData?.origem ?? 0,
    cfop: props.fiscalData?.cfop ?? '5102',
    icms_situacao_tributaria: props.fiscalData?.icms_situacao_tributaria ?? '400',
    cfop_outros_estados: props.fiscalData?.cfop_outros_estados ?? '6108',
    unidade_tributavel: props.fiscalData?.unidade_tributavel ?? 'UN',
    pis_situacao_tributaria: props.fiscalData?.pis_situacao_tributaria ?? '',
    cofins_situacao_tributaria: props.fiscalData?.cofins_situacao_tributaria ?? '',
    percentual_aproximado_tributos: props.fiscalData?.percentual_aproximado_tributos ?? '',
    cest: props.fiscalData?.cest ?? '',
    tipo_operacao: props.fiscalData?.tipo_operacao ?? 1,
    recopi_numero: props.fiscalData?.recopi_numero ?? '',
    ex_tipi: props.fiscalData?.ex_tipi ?? '',
    fci_numero: props.fiscalData?.fci_numero ?? '',
    informacoes_adicionais: props.fiscalData?.informacoes_adicionais ?? '',
    item_agrupavel: props.fiscalData?.item_agrupavel ?? false,
});

const pisCofinsCst = ref(form.pis_situacao_tributaria || '');

const setPisCofins = (value) => {
    pisCofinsCst.value = value;
    form.pis_situacao_tributaria = value;
    form.cofins_situacao_tributaria = value;
};

const unidadeFilter = ref('');
const filteredUnidades = computed(() => {
    const term = unidadeFilter.value.trim().toLowerCase();
    if (!term) return UNIDADE_MEDIDA_OPTIONS;
    return UNIDADE_MEDIDA_OPTIONS.filter((option) => option.label.toLowerCase().includes(term));
});

const submit = () => {
    form.put(`/admin/produtos/${props.product.id}/fiscal`, { preserveScroll: true });
};
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <h3 class="text-sm font-semibold uppercase text-slate-500">Informações Fiscais</h3>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label class="flex items-center text-sm font-medium text-slate-600">
                    NCM *
                    <FieldTooltip text="Nomenclatura Comum do Mercosul, é uma convenção entre os países membros do Mercosul para reconhecer facilmente bens, serviços e fatores produtivos negociados entre si. NCM deve conter 8 dígitos, OU se o item não possui um NCM informe o valor 00." />
                </label>
                <input v-model="form.ncm" type="text" maxlength="8" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                <InputError :message="form.errors.ncm" />
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-600">Origem *</label>
                <select v-model.number="form.origem" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    <option v-for="option in ORIGEM_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
                <InputError :message="form.errors.origem" />
            </div>

            <div>
                <label class="flex items-center text-sm font-medium text-slate-600">
                    CFOP Venda Mesmo Estado *
                    <FieldTooltip text="Código Fiscal das Operações. Se é uma venda no mesmo estado, geralmente tem o formato 5XXX, como 5102." />
                </label>
                <input v-model="form.cfop" type="text" maxlength="4" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                <InputError :message="form.errors.cfop" />
            </div>

            <div>
                <label class="flex items-center text-sm font-medium text-slate-600">
                    CSOSN *
                    <FieldTooltip text="O Código de Situação da Operação no Simples Nacional detalha a origem da mercadoria e situação tributária da operação, sendo essencial para o recolhimento correto dos tributos federais, estaduais e municipais." />
                </label>
                <select v-model="form.icms_situacao_tributaria" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    <option v-for="option in CSOSN_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
                <InputError :message="form.errors.icms_situacao_tributaria" />
            </div>

            <div>
                <label class="flex items-center text-sm font-medium text-slate-600">
                    CFOP Venda Diferentes Estados *
                    <FieldTooltip text="Código Fiscal das Operações. Se é uma venda em estados diferentes, geralmente tem o formato 6XXX, como 6108." />
                </label>
                <input v-model="form.cfop_outros_estados" type="text" maxlength="4" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                <InputError :message="form.errors.cfop_outros_estados" />
            </div>

            <div>
                <label class="flex items-center text-sm font-medium text-slate-600">
                    Unidade de Medida *
                    <FieldTooltip text="Unidade de Medida Comercial" />
                </label>
                <input v-model="unidadeFilter" type="text" placeholder="Filtrar..." class="mt-1 w-full rounded border border-slate-300 px-3 py-1.5 text-xs">
                <select v-model="form.unidade_tributavel" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    <option v-for="option in filteredUnidades" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
                <InputError :message="form.errors.unidade_tributavel" />
            </div>

            <div>
                <label class="flex items-center text-sm font-medium text-slate-600">
                    PIS e COFINS CST
                    <FieldTooltip text="CST (Código de Situação Tributária) é o código que indica a situação tributária de seu produto." />
                </label>
                <select :value="pisCofinsCst" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm" @change="setPisCofins($event.target.value)">
                    <option value="">Selecione</option>
                    <option v-for="option in PIS_COFINS_CST_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
            </div>

            <div>
                <label class="flex items-center text-sm font-medium text-slate-600">
                    % total de tributos federais, estaduais e municipais
                    <FieldTooltip text="Insira a porcentagem total da combinação de tributos federais, estaduais e municipais utilizando até dois décimos." />
                </label>
                <input v-model="form.percentual_aproximado_tributos" type="number" step="0.01" min="0" max="100" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                <InputError :message="form.errors.percentual_aproximado_tributos" />
            </div>

            <div>
                <label class="flex items-center text-sm font-medium text-slate-600">
                    CEST
                    <FieldTooltip text="Código Especificador da Substituição Tributária, para separar dentro da mesma NCM os produtos que possuem ou não substituição tributária de ICMS. CEST deve conter 7 dígitos, OU se o item não possui um CEST informe o valor 00." />
                </label>
                <input v-model="form.cest" type="text" maxlength="7" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                <InputError :message="form.errors.cest" />
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-600">Tipo de Operação</label>
                <select v-model.number="form.tipo_operacao" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    <option :value="1">1 — Revendedor</option>
                    <option :value="2">2 — Fabricante</option>
                </select>
            </div>

            <div>
                <label class="flex items-center text-sm font-medium text-slate-600">
                    Nr. RECOPI
                    <FieldTooltip text="O Sistema de Registro e Controle das Operações com Papel Imune provê o prévio reconhecimento da não incidência do imposto e o registro das operações realizadas com o papel destinado à impressão de livro, jornal ou periódico (papel imune)." />
                </label>
                <input v-model="form.recopi_numero" type="text" maxlength="30" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            </div>

            <div>
                <label class="flex items-center text-sm font-medium text-slate-600">
                    EX TIPI
                    <FieldTooltip text="A nota fiscal eletrônica (NF-e) possui o campo EXTIPI (Código de Exceção da Tabela de IPI). Ele serve para informar se existe uma exceção na alíquota do Imposto sobre Produtos Industrializados (IPI) para um determinado produto." />
                </label>
                <input v-model="form.ex_tipi" type="text" maxlength="10" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            </div>

            <div>
                <label class="flex items-center text-sm font-medium text-slate-600">
                    Nr. de controle da FCI
                    <FieldTooltip text="O Número de Controle da FCI (Ficha de Conteúdo de Importação) é um identificador único para cada FCI de importação, exigido na NF-e para garantir o cumprimento das leis tributárias brasileiras." />
                </label>
                <input v-model="form.fci_numero" type="text" maxlength="40" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            </div>
        </div>

        <div>
            <label class="flex items-center text-sm font-medium text-slate-600">
                Informações adicionais do produto
                <FieldTooltip text="Inclua informações relevantes para constar na Nota Fiscal." />
            </label>
            <textarea v-model="form.informacoes_adicionais" rows="3" maxlength="1000" class="mt-1 w-full rounded border border-slate-300 px-3 py-2"></textarea>
        </div>

        <div>
            <span class="block text-sm font-medium text-slate-600">Produto é um item agrupável</span>
            <div class="mt-1 flex gap-4">
                <label class="inline-flex items-center gap-2 text-sm text-slate-600">
                    <input v-model="form.item_agrupavel" :value="false" type="radio" name="item_agrupavel">
                    Não
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-slate-600">
                    <input v-model="form.item_agrupavel" :value="true" type="radio" name="item_agrupavel">
                    Sim
                </label>
            </div>
        </div>

        <button type="submit" :disabled="form.processing"
            class="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-slate-300">
            Salvar dados fiscais
        </button>
    </form>
</template>
