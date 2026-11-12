<script setup>
/**
 * Lançar ou editar compra (pedido do Lira em 12/11/2026). Fornecedor da
 * lista ou novo (só o nome), itens do catálogo (busca por nome/SKU), frete
 * e outros custos rateados no custo de cada item, comprovante opcional.
 * Vindo do XML da NF-e, chega preenchido; item que não casou fica para
 * escolher o produto.
 */
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    compra: { type: Object, default: null },
    importado: { type: Object, default: null },
    fornecedores: { type: Array, default: () => [] },
    produtos: { type: Array, default: () => [] },
    pagamentos: { type: Object, required: true },
});

const editando = computed(() => !!props.compra);
const recebida = computed(() => !!props.compra?.recebida);
const base = props.compra ?? props.importado ?? {};
const hoje = new Date().toISOString().slice(0, 10);

const form = useForm({
    purchase_date: base.purchase_date ?? hoje,
    supplier_id: base.supplier_id ?? '',
    supplier_name: base.supplier_id ? '' : (base.supplier_name ?? ''),
    supplier_document: base.supplier_document ?? '',
    notes: base.notes ?? '',
    freight: base.freight ?? 0,
    other_costs: base.other_costs ?? 0,
    payment_method: base.payment_method ?? '',
    invoice_key: base.invoice_key ?? '',
    comprovante: null,
    entrada_estoque: false,
    items: (base.items ?? []).map((i) => ({ product_id: i.product_id ?? '', description: i.description ?? '', quantity: i.quantity, unit_cost: i.unit_cost, busca: '' })),
});
if (!form.items.length) form.items.push({ product_id: '', description: '', quantity: 1, unit_cost: 0, busca: '' });

const fornecedorNovo = ref(!base.supplier_id && !!base.supplier_name);

const porId = computed(() => Object.fromEntries(props.produtos.map((p) => [p.id, p])));
const opcoes = (item) => {
    const termo = (item.busca ?? '').trim().toLowerCase();
    const lista = termo
        ? props.produtos.filter((p) => p.name.toLowerCase().includes(termo) || (p.sku ?? '').toLowerCase().includes(termo))
        : props.produtos;
    const atual = porId.value[item.product_id];
    const corte = lista.slice(0, 60);
    return atual && !corte.includes(atual) ? [atual, ...corte] : corte;
};

const formatPrice = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v ?? 0);

// Mesmo rateio do servidor (CustoDaCompra::ratear), só para mostrar.
const custos = computed(() => {
    const extras = Number(form.freight || 0) + Number(form.other_costs || 0);
    const valor = form.items.reduce((s, i) => s + Number(i.quantity || 0) * Number(i.unit_cost || 0), 0);
    const qtd = form.items.reduce((s, i) => s + Number(i.quantity || 0), 0);
    return form.items.map((i) => {
        const q = Number(i.quantity || 0);
        const u = Number(i.unit_cost || 0);
        if (q <= 0) return u;
        const parte = valor > 0 ? (extras * q * u) / valor : (qtd > 0 ? (extras * q) / qtd : 0);
        return u + parte / q;
    });
});
const totalProdutos = computed(() => form.items.reduce((s, i) => s + Number(i.quantity || 0) * Number(i.unit_cost || 0), 0));

const adicionar = () => form.items.push({ product_id: '', description: '', quantity: 1, unit_cost: 0, busca: '' });
const remover = (n) => form.items.splice(n, 1);

const salvar = () => {
    form.transform((d) => ({
        ...d,
        supplier_id: fornecedorNovo.value ? null : (d.supplier_id || null),
        supplier_name: fornecedorNovo.value ? d.supplier_name : null,
        payment_method: d.payment_method || null,
        invoice_key: d.invoice_key || null,
        entrada_estoque: d.entrada_estoque ? 1 : 0,
        items: recebida.value ? [] : d.items.map(({ busca, ...i }) => i),
        ...(editando.value ? { _method: 'put' } : {}),
    }));
    form.post(editando.value ? `/admin/compras/${props.compra.id}` : '/admin/compras', { forceFormData: true, preserveScroll: true });
};

const campo = 'mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm';
const rotulo = 'block text-xs font-medium text-[var(--texto-suave)]';
</script>

<template>
    <Head :title="editando ? 'Editar compra' : 'Lançar compra'" />

    <AdminLayout>
        <div class="mb-4 flex items-center justify-between">
            <h1 class="text-2xl font-bold">{{ editando ? `Editar compra #${compra.id}` : 'Lançar compra' }}</h1>
            <Link href="/admin/compras" class="text-sm text-primary hover:underline">Voltar</Link>
        </div>
        <p v-if="importado" class="mb-4 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-4 py-3 text-sm">
            Preenchido pelo XML da NF-e. Confira os valores e escolha o produto do catálogo nos itens que ficaram sem produto.
        </p>

        <form class="space-y-6" @submit.prevent="salvar">
            <section class="grid grid-cols-1 gap-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:grid-cols-4">
                <div>
                    <label for="cp-data" :class="rotulo">Data da compra</label>
                    <input id="cp-data" v-model="form.purchase_date" type="date" required :class="campo">
                    <p v-if="form.errors.purchase_date" class="mt-1 text-xs text-error">{{ form.errors.purchase_date }}</p>
                </div>
                <div class="sm:col-span-2">
                    <div class="flex items-center justify-between">
                        <label :for="fornecedorNovo ? 'cp-forn-novo' : 'cp-forn'" :class="rotulo">Fornecedor</label>
                        <button type="button" class="text-xs text-primary hover:underline" @click="fornecedorNovo = !fornecedorNovo">
                            {{ fornecedorNovo ? 'Escolher da lista' : 'Novo fornecedor' }}
                        </button>
                    </div>
                    <input v-if="fornecedorNovo" id="cp-forn-novo" v-model="form.supplier_name" type="text" maxlength="255" placeholder="Nome do fornecedor" :class="campo">
                    <select v-else id="cp-forn" v-model="form.supplier_id" :class="campo">
                        <option value="">Selecione...</option>
                        <option v-for="f in fornecedores" :key="f.id" :value="f.id">{{ f.name }}</option>
                    </select>
                    <p v-if="form.errors.supplier_id || form.errors.supplier_name" class="mt-1 text-xs text-error">{{ form.errors.supplier_id || form.errors.supplier_name }}</p>
                </div>
                <div>
                    <label for="cp-pag" :class="rotulo">Forma de pagamento</label>
                    <select id="cp-pag" v-model="form.payment_method" :class="campo">
                        <option value="">—</option>
                        <option v-for="(nome, chave) in pagamentos" :key="chave" :value="chave">{{ nome }}</option>
                    </select>
                </div>
                <div>
                    <label for="cp-frete" :class="rotulo">Frete da compra (R$)</label>
                    <input id="cp-frete" v-model.number="form.freight" type="number" step="0.01" min="0" :class="campo">
                </div>
                <div>
                    <label for="cp-outros" :class="rotulo">Outros custos (taxa, imposto de importação)</label>
                    <input id="cp-outros" v-model.number="form.other_costs" type="number" step="0.01" min="0" :class="campo">
                </div>
                <div class="sm:col-span-2">
                    <label for="cp-chave" :class="rotulo">Chave da NF-e (se tiver)</label>
                    <input id="cp-chave" v-model="form.invoice_key" type="text" inputmode="numeric" maxlength="44" :class="campo">
                    <p v-if="form.errors.invoice_key" class="mt-1 text-xs text-error">{{ form.errors.invoice_key }}</p>
                </div>
                <div class="sm:col-span-2">
                    <label for="cp-obs" :class="rotulo">Observação</label>
                    <input id="cp-obs" v-model="form.notes" type="text" maxlength="2000" :class="campo">
                </div>
                <div class="sm:col-span-2">
                    <label for="cp-comp" :class="rotulo">Comprovante (imagem ou PDF){{ compra?.tem_comprovante ? ' — já tem um; enviar outro substitui' : '' }}</label>
                    <input id="cp-comp" type="file" accept="image/*,application/pdf" class="mt-1 w-full text-sm" @change="form.comprovante = $event.target.files?.[0] ?? null">
                    <p v-if="form.errors.comprovante" class="mt-1 text-xs text-error">{{ form.errors.comprovante }}</p>
                </div>
            </section>

            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="font-semibold">Itens</h2>
                    <button v-if="!recebida" type="button" class="text-sm text-primary hover:underline" @click="adicionar">+ Adicionar item</button>
                </div>
                <p v-if="recebida" class="mb-2 text-sm text-[var(--texto-suave)]">Essa compra já deu entrada no estoque: os itens não mudam. Frete e outros custos ainda recalculam o custo de cada item.</p>
                <p v-if="form.errors.items" class="mb-2 text-xs text-error">{{ form.errors.items }}</p>

                <div v-for="(item, n) in form.items" :key="n" class="mb-3 grid grid-cols-1 gap-2 border-b border-[var(--surface-muted)] pb-3 sm:grid-cols-12 sm:items-end">
                    <div class="sm:col-span-5">
                        <label :for="`cp-busca-${n}`" :class="rotulo">Produto (busque por nome ou SKU)</label>
                        <p v-if="item.description" class="text-[11px] text-[var(--texto-suave)]">Na nota: {{ item.description }}</p>
                        <input :id="`cp-busca-${n}`" v-model="item.busca" type="search" placeholder="Buscar..." :disabled="recebida" :class="campo">
                        <select v-model="item.product_id" :aria-label="`Produto do item ${n + 1}`" :disabled="recebida" :class="[campo, !item.product_id ? 'border-error' : '']">
                            <option value="">Escolha o produto...</option>
                            <option v-for="p in opcoes(item)" :key="p.id" :value="p.id">{{ p.name }}{{ p.sku ? ` (${p.sku})` : '' }}</option>
                        </select>
                        <p v-if="form.errors[`items.${n}.product_id`]" class="mt-1 text-xs text-error">{{ form.errors[`items.${n}.product_id`] }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label :for="`cp-q-${n}`" :class="rotulo">Quantidade</label>
                        <input :id="`cp-q-${n}`" v-model.number="item.quantity" type="number" min="1" step="1" required :disabled="recebida" :class="campo">
                    </div>
                    <div class="sm:col-span-2">
                        <label :for="`cp-u-${n}`" :class="rotulo">Valor unitário pago</label>
                        <input :id="`cp-u-${n}`" v-model.number="item.unit_cost" type="number" min="0" step="0.01" required :disabled="recebida" :class="campo">
                    </div>
                    <div class="text-sm sm:col-span-2">
                        <span :class="rotulo">Custo efetivo/un.</span>
                        <strong class="tabular-nums">{{ formatPrice(custos[n]) }}</strong>
                        <span v-if="porId[item.product_id]?.cost_price != null" class="block text-[11px] text-[var(--texto-suave)]">
                            cadastro hoje {{ formatPrice(porId[item.product_id].cost_price) }}
                        </span>
                    </div>
                    <div class="sm:col-span-1 sm:text-right">
                        <button v-if="!recebida && form.items.length > 1" type="button" class="text-sm text-error hover:underline" @click="remover(n)">Remover</button>
                    </div>
                </div>

                <p class="text-sm tabular-nums">
                    Produtos {{ formatPrice(totalProdutos) }} + frete {{ formatPrice(form.freight) }} + outros {{ formatPrice(form.other_costs) }}
                    = <strong>{{ formatPrice(totalProdutos + Number(form.freight || 0) + Number(form.other_costs || 0)) }}</strong>
                </p>
            </section>

            <label v-if="!editando" class="flex items-center gap-2 text-sm">
                <input v-model="form.entrada_estoque" type="checkbox">
                Dar entrada no estoque com essas quantidades (depois disso os itens não podem mais ser trocados)
            </label>

            <button type="submit" :disabled="form.processing" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50">
                {{ editando ? 'Salvar alterações' : 'Salvar compra' }}
            </button>
        </form>
    </AdminLayout>
</template>
