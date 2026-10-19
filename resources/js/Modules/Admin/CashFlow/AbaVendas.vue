<script setup>
/**
 * Aba "Vendas (custo e comissão)": a tabela item a item de antes, agora
 * pela conta oficial (ContributionMargin::porItem, receita, taxa e frete do
 * pedido repartidos entre os itens). ADS, Flex e bônus do Flex são do canal
 * inteiro: entram só no total (soma das linhas − ADS − Flex + bônus = margem
 * do Resumo). Custo e comissão continuam editáveis com Enter; mudar o custo
 * de um produto pede confirmação, porque vale para todas as vendas dele.
 */
import CardStats from '@/Shared/Components/CardStats.vue';
import { DataTable } from '@/Shared/Components/DataTable';
import { usePermissions } from '@/Shared/usePermissions';
import { toTableSort } from '@/Shared/useServerTable';
import { formatPrice } from '@/Shared/graficos';
import { router } from '@inertiajs/vue3';
import Swal from 'sweetalert2';
import { computed, h, ref } from 'vue';

const props = defineProps({
    sales: { type: Object, default: () => ({ data: [] }) },
    salesTotals: { type: Object, default: () => ({}) },
    salesFilter: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['visitar']);

const { can } = usePermissions();
const podeEditar = computed(() => can('financeiro.edit'));
const linhas = computed(() => props.sales?.data ?? []);
const t = computed(() => props.salesTotals ?? {});

const salvandoTaxa = ref(null);
const salvandoCusto = ref(null);
const numero = (bruto) => parseFloat(String(bruto).replace(',', '.'));

const salvarTaxa = (linha, bruto) => {
    const valor = numero(bruto);
    if (Number.isNaN(valor) || valor < 0) return;
    salvandoTaxa.value = linha.order_id;
    router.put(`/admin/fluxo-de-caixa/vendas/${linha.order_id}/comissao`, { fee_amount: valor }, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { salvandoTaxa.value = null; },
    });
};

const salvarCusto = async (linha, bruto, campo) => {
    const valor = numero(bruto);
    if (Number.isNaN(valor) || valor < 0) return;
    if (linha.has_product) {
        const ok = await Swal.fire({
            icon: 'question',
            title: 'Mudar o custo do produto?',
            text: `Isso muda o custo do produto em todas as vendas. Confirmar? (${linha.product_name}: ${formatPrice(valor)} nesta linha, ${formatPrice(valor / Math.max(1, linha.quantity))} por unidade)`,
            showCancelButton: true,
            confirmButtonText: 'Confirmar',
            cancelButtonText: 'Cancelar',
            reverseButtons: true,
            focusCancel: true,
        });
        if (!ok.isConfirmed) {
            if (campo) campo.value = linha.product_cost;
            return;
        }
    }
    salvandoCusto.value = linha.item_id;
    router.put(`/admin/fluxo-de-caixa/vendas/item/${linha.item_id}/custo`, { product_cost: valor }, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { salvandoCusto.value = null; },
    });
};

const campoEditavel = ({ valor, rotulo, alerta, desabilitado, aoSalvar, titulo }) => h('input', {
    type: 'number',
    step: '0.01',
    min: '0',
    inputmode: 'decimal',
    'aria-label': rotulo,
    class: `w-24 rounded-lg border bg-[var(--surface)] px-2 py-1 text-sm tabular-nums ${alerta ? 'border-amber-500 text-amber-700 dark:text-amber-400' : 'border-[var(--surface-border)]'}`,
    value: valor,
    disabled: desabilitado,
    title: titulo,
    onKeydown: (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            aoSalvar(event.target.value, event.target);
            event.target.blur();
        }
    },
});

const dataBr = (iso) => `${iso.slice(8, 10)}/${iso.slice(5, 7)}/${iso.slice(0, 4)}`;

const colunas = computed(() => [
    { accessorKey: 'date', header: 'Data do pedido', cell: ({ row }) => h('span', { class: 'tabular-nums whitespace-nowrap' }, dataBr(row.original.date)) },
    {
        accessorKey: 'product_name',
        header: 'Produto',
        cell: ({ row }) => h('div', { class: 'min-w-[180px]' }, [
            h('span', {}, row.original.product_name),
            h('span', { class: 'block text-xs text-[var(--texto-suave)] tabular-nums' }, `Pedido #${row.original.order_id}${row.original.quantity > 1 ? ` · ${row.original.quantity} un.` : ''}`),
            !row.original.has_cost && row.original.item_id
                ? h('span', { class: 'text-xs font-semibold text-amber-700 dark:text-amber-400' }, 'sem custo: margem acima do real')
                : null,
        ]),
    },
    { accessorKey: 'platform', header: 'Canal' },
    { accessorKey: 'receita', header: 'Receita', cell: ({ row }) => h('span', { class: 'tabular-nums' }, formatPrice(row.original.receita)) },
    {
        accessorKey: 'product_cost',
        header: 'Pago ao fornecedor',
        cell: ({ row }) => (podeEditar.value && row.original.item_id
            ? campoEditavel({
                valor: row.original.product_cost,
                rotulo: `Pago ao fornecedor, ${row.original.product_name}, pedido ${row.original.order_id}`,
                alerta: !row.original.has_cost,
                desabilitado: salvandoCusto.value === row.original.item_id,
                titulo: row.original.has_cost ? 'Custo total da linha. Edite e aperte Enter.' : 'Sem custo cadastrado. Digite o custo total da linha e aperte Enter.',
                aoSalvar: (v, campo) => salvarCusto(row.original, v, campo),
            })
            : h('span', { class: 'tabular-nums' }, formatPrice(row.original.product_cost))),
    },
    {
        accessorKey: 'platform_fee',
        header: 'Comissão',
        cell: ({ row }) => h('div', { class: 'flex flex-col items-start gap-0.5' }, [
            podeEditar.value
                ? campoEditavel({
                    valor: row.original.platform_fee,
                    rotulo: `Comissão do pedido ${row.original.order_id}`,
                    alerta: !row.original.has_fee_data,
                    desabilitado: salvandoTaxa.value === row.original.order_id,
                    titulo: row.original.has_fee_data ? 'Comissão do pedido, repartida entre os itens. Edite e aperte Enter (vale para o pedido inteiro).' : 'O canal ainda não informou a taxa deste pedido. Digite a comissão do pedido e aperte Enter.',
                    aoSalvar: (v) => salvarTaxa(row.original, v),
                })
                : h('span', { class: 'tabular-nums' }, formatPrice(row.original.platform_fee)),
            row.original.fee_source === 'estimate' ? h('span', { class: 'text-[10px] font-semibold text-amber-700 dark:text-amber-400' }, 'estimada') : null,
            !row.original.has_fee_data ? h('span', { class: 'text-[10px] font-semibold text-amber-700 dark:text-amber-400' }, 'sem taxa') : null,
        ]),
    },
    { accessorKey: 'shipping_cost', header: 'Frete (loja)', cell: ({ row }) => h('span', { class: 'tabular-nums text-[var(--texto-suave)]' }, formatPrice(row.original.shipping_cost)) },
    {
        accessorKey: 'net_profit',
        header: 'Margem antes do ADS',
        cell: ({ row }) => h('span', { class: `tabular-nums font-semibold ${row.original.net_profit >= 0 ? 'text-success' : 'text-error'}` }, formatPrice(row.original.net_profit)),
    },
]);

const sortParams = (sort) => ({ sort: sort?.id ?? null, direction: sort ? (sort.desc ? 'desc' : 'asc') : null });
</script>

<template>
    <div>
        <div class="mb-4 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-5">
            <CardStats stat-subtitle="Receita" :stat-title="formatPrice(t.receita)" stat-icon-name="fas fa-sack-dollar" variant="neutral" compact />
            <CardStats stat-subtitle="Pago ao fornecedor" :stat-title="formatPrice(t.cost)" stat-icon-name="fas fa-truck-ramp-box" variant="neutral" compact />
            <CardStats stat-subtitle="Comissão dos canais" :stat-title="formatPrice(t.fee)" stat-icon-name="fas fa-percent" variant="neutral" compact />
            <CardStats stat-subtitle="Frete pago pela loja (Correios)" :stat-title="formatPrice(t.shipping)" stat-icon-name="fas fa-truck" variant="neutral" compact />
            <CardStats stat-subtitle="Margem antes do ADS" :stat-title="formatPrice(t.netProfit)" stat-icon-name="fas fa-scale-balanced" :variant="t.netProfit >= 0 ? 'success' : 'error'" compact />
        </div>

        <p v-if="t.margemOficial !== null && t.margemOficial !== undefined" class="mb-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-4 py-3 text-sm tabular-nums shadow-sm">
            Soma das linhas {{ formatPrice(t.netProfit) }} − ADS {{ formatPrice(t.ads) }} − Flex {{ formatPrice(t.flex) }} + bônus do Flex {{ formatPrice(t.bonificacoes) }}
            = <strong>margem de contribuição {{ formatPrice(t.margemOficial) }}</strong>, a mesma do Resumo.
        </p>
        <p v-else class="mb-4 text-sm text-[var(--texto-suave)]">Com busca ou filtro de linha, os totais somam só as linhas mostradas (sem ADS e Flex, que são do canal inteiro).</p>

        <div class="mb-3 flex flex-wrap items-center gap-2" role="group" aria-label="Filtrar linhas">
            <button type="button" class="botao-filtro" :aria-pressed="!!salesFilter.sem_custo" @click="emit('visitar', { sem_custo: salesFilter.sem_custo ? null : 1 })">
                Só sem custo <span class="tabular-nums">({{ t.semCusto ?? 0 }})</span>
            </button>
            <button type="button" class="botao-filtro" :aria-pressed="!!salesFilter.taxa_estimada" @click="emit('visitar', { taxa_estimada: salesFilter.taxa_estimada ? null : 1 })">
                Só taxa estimada <span class="tabular-nums">({{ t.taxaEstimada ?? 0 }})</span>
            </button>
        </div>

        <DataTable
            :columns="colunas"
            :data="linhas"
            :paginator="sales"
            :search="salesFilter.search ?? ''"
            :sort="toTableSort(salesFilter.sort, salesFilter.direction)"
            row-key="row_key"
            dense
            search-placeholder="Buscar produto, canal ou nº do pedido…"
            empty-message="Nenhuma venda paga neste período."
            @update:search="emit('visitar', { search: $event })"
            @update:sort="emit('visitar', sortParams($event))"
        />
    </div>
</template>

<style scoped>
.botao-filtro {
    border: 1px solid var(--surface-border);
    background: var(--surface);
    color: var(--surface-foreground);
    border-radius: 999px;
    padding: 5px 12px;
    font-size: 13px;
    font-weight: 500;
}
.botao-filtro:hover { background: var(--surface-muted); }
.botao-filtro[aria-pressed="true"] { background: var(--surface-foreground); border-color: var(--surface-foreground); color: var(--surface); }
.botao-filtro:focus-visible { outline: 2px solid var(--foco, #9a3412); outline-offset: 2px; }
</style>
