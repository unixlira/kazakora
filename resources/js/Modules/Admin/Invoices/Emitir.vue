<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import FieldTooltip from '@/Shared/Components/FieldTooltip.vue';
import { maskCep, useCep } from '@/Shared/useCep';
import { maskCpfCnpj, maskPhone } from '@/Shared/useMasks';
import { ORIGEM_OPTIONS, CSOSN_OPTIONS, PIS_COFINS_CST_OPTIONS, UNIDADE_MEDIDA_OPTIONS } from '@/Shared/fiscalOptions';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    products: { type: Array, default: () => [] },
});

const form = useForm({
    buyer_document: '',
    buyer_name: '',
    buyer_phone: '',
    buyer_email: '',
    shipping_zip: '',
    shipping_street: '',
    shipping_number: '',
    shipping_complement: '',
    shipping_neighborhood: '',
    shipping_city: '',
    shipping_state: '',
    items: [],
});

const { loading: cepLoading, error: cepError, lookup: lookupCep } = useCep();

const onCepInput = async (event) => {
    form.shipping_zip = maskCep(event.target.value);

    if (form.shipping_zip.replace(/\D/g, '').length !== 8) {
        return;
    }

    const result = await lookupCep(form.shipping_zip);

    if (result) {
        form.shipping_street = result.street;
        form.shipping_neighborhood = result.neighborhood;
        form.shipping_city = result.city;
        form.shipping_state = result.state;
    }
};

const emptyFreeformItem = () => ({
    product_id: null,
    description: '',
    item_type: 'servico',
    quantity: 1,
    unit_price: 0,
    ncm: '',
    cest: '',
    cfop: '',
    cfop_outros_estados: '',
    origem_mercadoria: 0,
    gtin: '',
    unidade_tributavel: 'UN',
    icms_situacao_tributaria: '',
    pis_situacao_tributaria: '',
    pis_aliquota: 0,
    cofins_situacao_tributaria: '',
    cofins_aliquota: 0,
    percentual_aproximado_tributos: 0,
});

const itemFilters = ref([]);

const filteredProducts = (index) => {
    const term = (itemFilters.value[index] ?? '').trim().toLowerCase();

    if (!term) {
        return props.products;
    }

    return props.products.filter((product) =>
        product.name.toLowerCase().includes(term) || (product.sku ?? '').toLowerCase().includes(term));
};

const addItem = () => {
    form.items.push(emptyFreeformItem());
    itemFilters.value.push('');
};

const removeItem = (index) => {
    form.items.splice(index, 1);
    itemFilters.value.splice(index, 1);
};

// Alterna entre "produto do catálogo" e "item digitado na hora" — limpa os
// campos do modo anterior pra não mandar lixo junto (ex: product_id de um
// item que virou avulso).
const setItemMode = (index, mode) => {
    const current = form.items[index];

    if (mode === 'catalog') {
        Object.assign(current, { product_id: null, description: '' });
    } else {
        current.product_id = null;
    }
};

const isCatalogItem = (item) => item.product_id !== null;

const onProductSelected = (index) => {
    const product = props.products.find((item) => item.id === form.items[index].product_id);

    if (product) {
        form.items[index].unit_price = product.price;
    }
};

// PIS e COFINS usam o mesmo CST na prática (mesmo padrão já usado em
// Products/FiscalForm.vue) — um select só, grava nos dois campos.
const setPisCofins = (index, value) => {
    form.items[index].pis_situacao_tributaria = value;
    form.items[index].cofins_situacao_tributaria = value;
};

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value || 0);

const total = computed(() => form.items.reduce((sum, item) => sum + (item.quantity || 0) * (item.unit_price || 0), 0));

const submit = () => {
    form.post('/admin/notas-fiscais/emitir');
};
</script>

<template>
    <Head title="Emitir Nota Fiscal" />

    <AdminLayout>
        <h1 class="mb-1 text-2xl font-bold">Emitir Nota Fiscal</h1>
        <p class="mb-4 text-sm text-slate-500">
            Emissão manual — cada item pode ser um produto real do catálogo (dados fiscais já cadastrados) ou um item
            digitado na hora (produto fora do catálogo ou serviço avulso, preenchendo NCM/CFOP/CSOSN/CST manualmente).
        </p>

        <form class="max-w-5xl space-y-6" @submit.prevent="submit">
            <div class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-4">
                <h2 class="mb-3 font-semibold">Destinatário</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-slate-600">Nome</label>
                        <input v-model="form.buyer_name" type="text" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <InputError :message="form.errors.buyer_name" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-600">CPF/CNPJ</label>
                        <input :value="form.buyer_document" type="text" required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                            @input="form.buyer_document = maskCpfCnpj($event.target.value)">
                        <InputError :message="form.errors.buyer_document" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-600">Telefone (opcional)</label>
                        <input :value="form.buyer_phone" type="text"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                            @input="form.buyer_phone = maskPhone($event.target.value)">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-600">E-mail (opcional)</label>
                        <input v-model="form.buyer_email" type="email" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <InputError :message="form.errors.buyer_email" />
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-4">
                <h2 class="mb-3 font-semibold">Endereço</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-600">CEP</label>
                        <input :value="form.shipping_zip" type="text" required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm" @input="onCepInput">
                        <p v-if="cepLoading" class="mt-1 text-xs text-slate-400">Buscando...</p>
                        <p v-if="cepError" class="mt-1 text-xs text-red-600">{{ cepError }}</p>
                        <InputError :message="form.errors.shipping_zip" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-slate-600">Rua</label>
                        <input v-model="form.shipping_street" type="text" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <InputError :message="form.errors.shipping_street" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-600">Número</label>
                        <input v-model="form.shipping_number" type="text" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <InputError :message="form.errors.shipping_number" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-600">Complemento</label>
                        <input v-model="form.shipping_complement" type="text" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-600">Bairro</label>
                        <input v-model="form.shipping_neighborhood" type="text" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <InputError :message="form.errors.shipping_neighborhood" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-600">Cidade</label>
                        <input v-model="form.shipping_city" type="text" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <InputError :message="form.errors.shipping_city" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-600">UF</label>
                        <input v-model="form.shipping_state" type="text" maxlength="2" required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm uppercase">
                        <InputError :message="form.errors.shipping_state" />
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-4">
                <h2 class="mb-3 font-semibold">Itens</h2>
                <InputError :message="form.errors.items" />

                <div v-if="form.items.length" class="space-y-4">
                    <div v-for="(item, index) in form.items" :key="index" class="rounded border border-[var(--surface-border)] p-3">
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <div class="inline-flex rounded-full border border-[var(--surface-border)] p-0.5 text-xs">
                                <button type="button"
                                    class="rounded-full px-3 py-1 font-medium"
                                    :class="isCatalogItem(item) ? 'bg-primary text-white' : 'text-slate-500'"
                                    @click="setItemMode(index, 'catalog')">
                                    Produto do catálogo
                                </button>
                                <button type="button"
                                    class="rounded-full px-3 py-1 font-medium"
                                    :class="!isCatalogItem(item) ? 'bg-primary text-white' : 'text-slate-500'"
                                    @click="setItemMode(index, 'freeform')">
                                    Item avulso (produto ou serviço)
                                </button>
                            </div>
                            <button type="button" class="flex h-8 w-8 items-center justify-center rounded text-red-600 hover:bg-red-50"
                                aria-label="Remover item" @click="removeItem(index)">
                                <i class="fas fa-trash text-sm"></i>
                            </button>
                        </div>

                        <!-- Produto do catálogo: reaproveita fiscalData já cadastrado, só pede quantidade/preço -->
                        <div v-if="isCatalogItem(item)" class="grid grid-cols-1 gap-3 sm:grid-cols-[2fr_1fr_1fr]">
                            <div>
                                <label class="block text-xs font-medium text-slate-500">Produto</label>
                                <input v-model="itemFilters[index]" type="text" placeholder="Filtrar..."
                                    class="mt-1 w-full rounded border border-slate-300 px-3 py-1.5 text-xs">
                                <select v-model.number="item.product_id" required
                                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                                    @change="onProductSelected(index)">
                                    <option :value="null" disabled>Selecione um produto</option>
                                    <option v-for="product in filteredProducts(index)" :key="product.id" :value="product.id">
                                        {{ product.sku }} — {{ product.name }}
                                    </option>
                                </select>
                                <InputError :message="form.errors[`items.${index}.product_id`]" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-500">Quantidade</label>
                                <input v-model.number="item.quantity" type="number" min="1" step="1" required
                                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-500">Preço unitário</label>
                                <input v-model.number="item.unit_price" type="number" min="0" step="0.01" required
                                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                            </div>
                        </div>

                        <!-- Item avulso: sem produto de catálogo, todo dado fiscal digitado na hora -->
                        <div v-else class="space-y-3">
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-[2fr_1fr_1fr_1fr]">
                                <div>
                                    <label class="block text-xs font-medium text-slate-500">Descrição</label>
                                    <input v-model="item.description" type="text" required placeholder="Ex.: Consultoria técnica, produto avulso..."
                                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                                    <InputError :message="form.errors[`items.${index}.description`]" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500">Tipo</label>
                                    <select v-model="item.item_type" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                                        <option value="servico">Serviço</option>
                                        <option value="produto">Produto</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500">Quantidade</label>
                                    <input v-model.number="item.quantity" type="number" min="1" step="1" required
                                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500">Preço unitário</label>
                                    <input v-model.number="item.unit_price" type="number" min="0" step="0.01" required
                                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                                </div>
                            </div>

                            <p class="text-xs text-amber-600 dark:text-amber-400">
                                <i class="fas fa-triangle-exclamation mr-1"></i>
                                NF-e modelo 55 é formalmente pra mercadoria — emitir "serviço" nela é uma escolha de negócio sua, não uma
                                orientação fiscal. Se precisar de nota de serviço formal, isso normalmente é NFS-e (municipal), não implementada aqui.
                            </p>

                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
                                <div>
                                    <label class="block text-xs font-medium text-slate-500">NCM</label>
                                    <input v-model="item.ncm" type="text" required maxlength="10"
                                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                                    <InputError :message="form.errors[`items.${index}.ncm`]" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500">CEST (opcional)</label>
                                    <input v-model="item.cest" type="text" maxlength="10"
                                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500">CFOP (mesma UF)</label>
                                    <input v-model="item.cfop" type="text" required maxlength="10" placeholder="Ex.: 5102 ou 5933"
                                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                                    <InputError :message="form.errors[`items.${index}.cfop`]" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500">CFOP (outros estados)</label>
                                    <input v-model="item.cfop_outros_estados" type="text" maxlength="10" placeholder="Deixe em branco pra usar o mesmo"
                                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500">Origem da mercadoria</label>
                                    <select v-model.number="item.origem_mercadoria" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                                        <option v-for="option in ORIGEM_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500">Unidade</label>
                                    <select v-model="item.unidade_tributavel" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                                        <option v-for="option in UNIDADE_MEDIDA_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500">GTIN (opcional)</label>
                                    <input v-model="item.gtin" type="text" maxlength="20"
                                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500">
                                        CSOSN (ICMS)
                                        <FieldTooltip text="Código de Situação Operacional do Simples Nacional — determina como o ICMS é tratado nesse item." />
                                    </label>
                                    <select v-model="item.icms_situacao_tributaria" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                                        <option value="" disabled>Selecione</option>
                                        <option v-for="option in CSOSN_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
                                    </select>
                                    <InputError :message="form.errors[`items.${index}.icms_situacao_tributaria`]" />
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
                                <div class="sm:col-span-2">
                                    <label class="block text-xs font-medium text-slate-500">
                                        PIS e COFINS — CST
                                        <FieldTooltip text="Código de Situação Tributária — mesmo código pros dois tributos, padrão usado no cadastro de produto." />
                                    </label>
                                    <select
                                        :value="item.pis_situacao_tributaria"
                                        required
                                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                                        @change="setPisCofins(index, $event.target.value)"
                                    >
                                        <option value="" disabled>Selecione</option>
                                        <option v-for="option in PIS_COFINS_CST_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
                                    </select>
                                    <InputError :message="form.errors[`items.${index}.pis_situacao_tributaria`]" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500">Alíquota PIS (%)</label>
                                    <input v-model.number="item.pis_aliquota" type="number" min="0" max="100" step="0.01"
                                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500">Alíquota COFINS (%)</label>
                                    <input v-model.number="item.cofins_aliquota" type="number" min="0" max="100" step="0.01"
                                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500">
                                        Total de tributos aprox. (%)
                                        <FieldTooltip text="Percentual aproximado de tributos incidentes — informação obrigatória por lei (Lei da Transparência Fiscal)." />
                                    </label>
                                    <input v-model.number="item.percentual_aproximado_tributos" type="number" min="0" max="100" step="0.01"
                                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <p v-else class="text-sm text-slate-500">Nenhum item adicionado ainda.</p>

                <button type="button" class="mt-3 rounded border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                    @click="addItem">
                    <i class="fas fa-plus mr-1.5 text-xs"></i>
                    Adicionar item
                </button>
            </div>

            <div class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-4">
                <span class="block text-sm font-medium text-slate-600">Total da nota</span>
                <p class="mt-1 text-xl font-bold">{{ formatPrice(total) }}</p>
            </div>

            <div>
                <button type="submit" :disabled="form.processing || form.items.length === 0"
                    class="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-slate-300">
                    Emitir nota fiscal
                </button>
            </div>
        </form>
    </AdminLayout>
</template>
