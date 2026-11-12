<script setup>
/**
 * Compra salva (pedido do Lira em 12/11/2026): custo do cadastro hoje x
 * custo efetivo da compra, por item, e o botão que grava o custo no produto.
 * Nada muda o custo do produto sem esse clique.
 */
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { confirmDelete } from '@/Shared/notify';

const props = defineProps({
    compra: { type: Object, required: true },
});

const formatPrice = (v) => (v === null || v === undefined ? '—' : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v));
const formatData = (d) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('pt-BR') : '—');
const diferente = (i) => i.custo_efetivo !== null && (i.custo_atual === null || Math.abs(i.custo_atual - i.custo_efetivo) >= 0.01);
const pendentes = computed(() => props.compra.linhas.filter(diferente).length);

const atualizar = (ids = null) => router.post(`/admin/compras/${props.compra.id}/atualizar-custo`, ids ? { item_ids: ids } : {}, { preserveScroll: true });
const excluir = async () => {
    if (await confirmDelete({ title: `Excluir a compra #${props.compra.id}?` })) {
        router.delete(`/admin/compras/${props.compra.id}`);
    }
};
</script>

<template>
    <Head :title="`Compra #${compra.id}`" />

    <AdminLayout>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-bold">Compra #{{ compra.id }} · {{ formatData(compra.data) }}</h1>
            <div class="flex flex-wrap gap-3 text-sm">
                <Link href="/admin/compras" class="text-primary hover:underline">Voltar</Link>
                <Link :href="`/admin/compras/${compra.id}/editar`" class="text-primary hover:underline">Editar</Link>
                <button v-if="!compra.recebida" type="button" class="text-error hover:underline" @click="excluir">Excluir</button>
            </div>
        </div>

        <section class="mb-6 grid grid-cols-1 gap-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 text-sm shadow-sm sm:grid-cols-3">
            <div><span class="text-[var(--texto-suave)]">Fornecedor</span><br><strong>{{ compra.fornecedor ?? '—' }}</strong></div>
            <div><span class="text-[var(--texto-suave)]">Pagamento</span><br>{{ compra.pagamento ?? '—' }}</div>
            <div><span class="text-[var(--texto-suave)]">Lançada por</span><br>{{ compra.criado_por ?? '—' }}</div>
            <div class="tabular-nums"><span class="text-[var(--texto-suave)]">Produtos</span><br>{{ formatPrice(compra.produtos) }}</div>
            <div class="tabular-nums"><span class="text-[var(--texto-suave)]">Frete + outros custos</span><br>{{ formatPrice(compra.frete) }} + {{ formatPrice(compra.outros) }}</div>
            <div class="tabular-nums"><span class="text-[var(--texto-suave)]">Total</span><br><strong>{{ formatPrice(compra.total) }}</strong></div>
            <div v-if="compra.invoice_key" class="sm:col-span-2"><span class="text-[var(--texto-suave)]">NF-e</span><br><span class="break-all font-mono text-xs">{{ compra.invoice_key }}</span></div>
            <div v-if="compra.tem_comprovante"><a :href="`/admin/compras/${compra.id}/comprovante`" target="_blank" rel="noopener" class="text-primary hover:underline">Ver comprovante</a></div>
            <div v-if="compra.notes" class="sm:col-span-3"><span class="text-[var(--texto-suave)]">Observação</span><br>{{ compra.notes }}</div>
            <div v-if="compra.recebida" class="sm:col-span-3 text-[var(--texto-suave)]">Essa compra deu entrada no estoque.</div>
        </section>

        <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-semibold">Custo de cada item</h2>
                <button v-if="pendentes > 0" type="button" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis" @click="atualizar()">
                    Atualizar custo de todos ({{ pendentes }})
                </button>
            </div>
            <p class="mb-3 text-xs text-[var(--texto-suave)]">Custo efetivo = valor pago + frete e outros custos rateados pelo valor do item. Produto pai leva o custo também para as variações.</p>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-sm tabular-nums">
                    <thead>
                        <tr class="border-b border-[var(--surface-border)] text-left text-[11px] text-[var(--texto-suave)]">
                            <th scope="col" class="py-1.5 pr-2 font-semibold">Produto</th>
                            <th scope="col" class="px-2 py-1.5 text-right font-semibold">Qtd.</th>
                            <th scope="col" class="px-2 py-1.5 text-right font-semibold">Pago/un.</th>
                            <th scope="col" class="px-2 py-1.5 text-right font-semibold">Custo efetivo/un.</th>
                            <th scope="col" class="px-2 py-1.5 text-right font-semibold">Custo no cadastro</th>
                            <th scope="col" class="px-2 py-1.5 text-right font-semibold">Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="i in compra.linhas" :key="i.id" class="border-b border-[var(--surface-muted)] align-top">
                            <td class="py-1.5 pr-2">
                                {{ i.produto }} <span v-if="i.sku" class="text-xs text-[var(--texto-suave)]">({{ i.sku }})</span>
                                <span v-if="i.variacoes" class="block text-[11px] text-[var(--texto-suave)]">pai de {{ i.variacoes }} variações</span>
                                <span v-if="i.aplicado_em" class="block text-[11px] text-[var(--texto-suave)]">
                                    custo aplicado em {{ i.aplicado_em }}{{ i.aplicado_por ? ` por ${i.aplicado_por}` : '' }} (antes {{ formatPrice(i.custo_anterior) }})
                                </span>
                            </td>
                            <td class="px-2 py-1.5 text-right">{{ i.quantity }}</td>
                            <td class="px-2 py-1.5 text-right">{{ formatPrice(i.unit_cost) }}</td>
                            <td class="px-2 py-1.5 text-right font-semibold">{{ formatPrice(i.custo_efetivo) }}</td>
                            <td class="px-2 py-1.5 text-right" :class="diferente(i) ? 'text-amber-700 dark:text-amber-400' : ''">{{ formatPrice(i.custo_atual) }}</td>
                            <td class="px-2 py-1.5 text-right">
                                <button v-if="diferente(i)" type="button" class="text-primary hover:underline" @click="atualizar([i.id])">Atualizar custo do produto</button>
                                <span v-else class="text-xs text-[var(--texto-suave)]">igual</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AdminLayout>
</template>
