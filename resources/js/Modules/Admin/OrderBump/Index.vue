<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { Head, router, useForm } from '@inertiajs/vue3';

// Order bump do checkout (pedido do Lira 2026-11-24): configuração geral e os números
// para ver se aumenta o ticket. Regras de escolha e preço em app/Modules/Checkout/Support/OrderBump.php.
const props = defineProps({
    dias: { type: Number, default: 30 },
    periodos: { type: Array, default: () => [7, 30, 90] },
    resumo: { type: Object, required: true },
    config: { type: Object, required: true },
    regras: { type: Object, required: true },
    produtos: { type: Array, default: () => [] },
});

const dinheiro = (valor) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(valor) || 0);
const numero = (valor) => (Number(valor) || 0).toLocaleString('pt-BR');
const pct = (valor) => `${(Number(valor) || 0).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%`;

const form = useForm({
    ligado: props.config.ligado,
    produto_id: props.config.produto_id,
    desconto_pct: props.config.desconto_pct,
});
const salvar = () => form.put('/admin/order-bump', { preserveScroll: true });
const trocarPeriodo = (dias) => router.get('/admin/order-bump', { dias }, { preserveScroll: true, preserveState: true, replace: true });
</script>

<template>
    <Head title="Order bump" />
    <AdminLayout>
        <div class="mb-6 max-w-3xl">
            <h1 class="mb-1 text-2xl font-bold">Order bump</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Caixa de oferta antes do "Finalizar pedido" no checkout da KazaKora: um produto a mais, com desconto só nesta compra.
                Ordem da escolha: o produto marcado no cadastro de um item do carrinho, depois o produto geral abaixo, depois o automático
                (o mais vendido dos últimos {{ regras.dias_ranking }} dias que custa até {{ regras.teto_do_subtotal_pct }}% do carrinho, teto mínimo {{ dinheiro(regras.teto_minimo) }}).
                O preço nunca fica abaixo de custo + {{ regras.margem_minima_pct }}%. Cupom e desconto do Pix não valem no item do bump, e ele não muda o frete.
            </p>
        </div>

        <div class="mb-3 flex flex-wrap gap-2" role="group" aria-label="Período">
            <button v-for="opcao in periodos" :key="opcao" type="button" class="rounded-full border px-3 py-1 text-sm"
                :class="opcao === dias ? 'border-[var(--color-primary)] bg-[var(--color-primary)] text-white' : 'border-[var(--surface-border)]'"
                @click="trocarPeriodo(opcao)">Últimos {{ opcao }} dias</button>
        </div>

        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <CardStats stat-subtitle="EXIBIÇÕES" :stat-title="numero(resumo.exibicoes)" stat-icon-name="fas fa-eye" variant="info" compact stat-note="sessões que viram a caixa" />
            <CardStats stat-subtitle="ACEITES" :stat-title="numero(resumo.aceites)" stat-icon-name="fas fa-square-check" variant="success" compact :stat-note="`taxa ${pct(resumo.taxa_aceite)}`" />
            <CardStats stat-subtitle="PEDIDOS PAGOS COM BUMP" :stat-title="numero(resumo.pedidos_com_bump)" stat-icon-name="fas fa-box" variant="primary" compact :stat-note="`${pct(resumo.taxa_pedidos)} dos pedidos pagos`" />
            <CardStats stat-subtitle="RECEITA EXTRA" :stat-title="dinheiro(resumo.receita_extra)" stat-icon-name="fas fa-sack-dollar" variant="success" compact stat-note="itens do bump em pedidos pagos" />
            <CardStats stat-subtitle="TICKET COM BUMP" :stat-title="dinheiro(resumo.ticket_com_bump)" stat-icon-name="fas fa-arrow-trend-up" variant="primary" compact />
            <CardStats stat-subtitle="TICKET SEM BUMP" :stat-title="dinheiro(resumo.ticket_sem_bump)" stat-icon-name="fas fa-receipt" variant="neutral" compact />
            <CardStats stat-subtitle="TICKET GERAL" :stat-title="dinheiro(resumo.ticket_geral)" stat-icon-name="fas fa-chart-line" variant="info" compact :stat-note="`${numero(resumo.pedidos_com_bump + resumo.pedidos_sem_bump)} pedidos pagos`" />
        </div>

        <section class="mb-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
            <h2 class="mb-3 font-semibold">Configuração geral</h2>
            <form class="grid max-w-3xl gap-4 sm:grid-cols-[auto_1fr_10rem] sm:items-end" @submit.prevent="salvar">
                <label class="flex items-center gap-2 text-sm font-medium">
                    <input v-model="form.ligado" type="checkbox" class="h-5 w-5"> Caixa ligada no checkout
                </label>
                <div>
                    <label for="bump-produto" class="block text-xs font-medium text-slate-500">Produto geral (quando o carrinho não tem escolha no cadastro)</label>
                    <select id="bump-produto" v-model="form.produto_id" class="mt-1 w-full rounded border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm">
                        <option :value="null">Nenhum: usar o automático</option>
                        <option v-for="opcao in produtos" :key="opcao.id" :value="opcao.id">{{ opcao.nome }}</option>
                    </select>
                    <InputError :message="form.errors.produto_id" />
                </div>
                <div>
                    <label for="bump-desconto" class="block text-xs font-medium text-slate-500">Desconto padrão (%)</label>
                    <input id="bump-desconto" v-model="form.desconto_pct" type="number" min="0" max="90" step="0.5" class="mt-1 w-full rounded border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm">
                    <InputError :message="form.errors.desconto_pct" />
                </div>
                <button type="submit" :disabled="form.processing" class="rounded bg-[var(--color-primary)] px-4 py-2 text-sm font-semibold text-white sm:col-span-3 sm:w-fit">
                    {{ form.processing ? 'Salvando...' : 'Salvar' }}
                </button>
            </form>
        </section>

        <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
            <h2 class="mb-3 font-semibold">Produtos aceitos no bump (pedidos pagos)</h2>
            <p v-if="!resumo.por_produto.length" class="text-sm text-slate-500">Nenhum pedido pago com order bump no período.</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-slate-500">
                        <tr><th class="py-2 pr-3">Produto</th><th class="py-2 pr-3 text-right">Pedidos</th><th class="py-2 text-right">Receita</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="linha in resumo.por_produto" :key="`${linha.product_id}-${linha.nome}`" class="border-t border-[var(--surface-border)]">
                            <td class="py-2 pr-3">{{ linha.nome }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ numero(linha.pedidos) }}</td>
                            <td class="py-2 text-right tabular-nums">{{ dinheiro(linha.receita) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AdminLayout>
</template>
