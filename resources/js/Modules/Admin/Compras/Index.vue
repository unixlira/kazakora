<script setup>
/**
 * Compras (pedido do Lira em 12/11/2026): o custo do produto sai do que a
 * loja pagou de verdade. Lista do mês, filtro por fornecedor, importar XML.
 */
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    mes: { type: String, required: true },
    fornecedor: { type: [Number, null], default: null },
    compras: { type: Array, default: () => [] },
    fornecedores: { type: Array, default: () => [] },
    totais: { type: Object, required: true },
});

const formatPrice = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v ?? 0);
const formatData = (d) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('pt-BR') : '—');
const nomeMes = computed(() => {
    const [a, m] = props.mes.split('-').map(Number);
    return new Date(a, m - 1, 1).toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
});

const filtrar = (mudanca) => router.get('/admin/compras', { mes: props.mes, fornecedor: props.fornecedor || undefined, ...mudanca }, { preserveState: true, preserveScroll: true });

const xml = useForm({ xml: null });
const importar = (e) => {
    xml.xml = e.target.files?.[0] ?? null;
    if (xml.xml) xml.post('/admin/compras/importar-xml', { forceFormData: true });
};
</script>

<template>
    <Head title="Compras" />

    <AdminLayout>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-bold">Compras</h1>
            <div class="flex flex-wrap items-center gap-2">
                <label class="cursor-pointer rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:bg-[var(--surface-muted)]">
                    <i class="fas fa-file-code mr-1 text-xs" aria-hidden="true"></i> Importar XML da NF-e
                    <input type="file" accept=".xml,text/xml,application/xml" class="sr-only" @change="importar">
                </label>
                <Link href="/admin/compras/nova" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis">
                    <i class="fas fa-plus text-xs" aria-hidden="true"></i> Lançar compra
                </Link>
            </div>
        </div>
        <p v-if="xml.errors.xml" class="mb-3 text-sm text-error">{{ xml.errors.xml }}</p>

        <p class="mb-4 text-sm text-[var(--texto-suave)]">
            Compra com ou sem nota. O frete e os outros custos (taxa, imposto de importação) são rateados entre os itens pelo valor de cada um;
            o custo do produto no cadastro só muda quando você clica em <strong>Atualizar custo do produto</strong> na compra.
        </p>

        <div class="mb-4 flex flex-wrap items-center gap-3 text-sm">
            <label class="flex items-center gap-2">
                <span class="text-[var(--texto-suave)]">Mês</span>
                <input type="month" :value="mes" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5" @change="filtrar({ mes: $event.target.value })">
            </label>
            <label class="flex items-center gap-2">
                <span class="text-[var(--texto-suave)]">Fornecedor</span>
                <select :value="fornecedor ?? ''" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5" @change="filtrar({ fornecedor: $event.target.value || undefined })">
                    <option value="">Todos</option>
                    <option v-for="f in fornecedores" :key="f.id" :value="f.id">{{ f.name }}</option>
                </select>
            </label>
        </div>

        <div class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            <CardStats :stat-subtitle="`Total em ${nomeMes}`" :stat-title="formatPrice(totais.total)" stat-icon-name="fas fa-basket-shopping" variant="primary" compact />
            <CardStats stat-subtitle="Produtos" :stat-title="formatPrice(totais.produtos)" stat-icon-name="fas fa-boxes-stacked" variant="neutral" compact />
            <CardStats stat-subtitle="Frete" :stat-title="formatPrice(totais.frete)" stat-icon-name="fas fa-truck" variant="neutral" compact />
            <CardStats stat-subtitle="Outros custos" :stat-title="formatPrice(totais.outros)" stat-icon-name="fas fa-percent" variant="neutral" compact />
        </div>

        <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-sm tabular-nums">
                    <thead>
                        <tr class="border-b border-[var(--surface-border)] text-left text-[11px] text-[var(--texto-suave)]">
                            <th scope="col" class="py-1.5 pr-2 font-semibold">Data</th>
                            <th scope="col" class="px-2 py-1.5 font-semibold">Fornecedor</th>
                            <th scope="col" class="px-2 py-1.5 text-right font-semibold">Itens</th>
                            <th scope="col" class="px-2 py-1.5 text-right font-semibold">Produtos</th>
                            <th scope="col" class="px-2 py-1.5 text-right font-semibold">Frete + outros</th>
                            <th scope="col" class="px-2 py-1.5 text-right font-semibold">Total</th>
                            <th scope="col" class="px-2 py-1.5 font-semibold">Situação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in compras" :key="c.id" class="border-b border-[var(--surface-muted)]">
                            <td class="py-1.5 pr-2 whitespace-nowrap"><Link :href="`/admin/compras/${c.id}`" class="font-medium text-primary hover:underline">{{ formatData(c.data) }}</Link></td>
                            <td class="px-2 py-1.5">{{ c.fornecedor ?? '—' }}</td>
                            <td class="px-2 py-1.5 text-right">{{ c.itens }} ({{ c.unidades }} un.)</td>
                            <td class="px-2 py-1.5 text-right">{{ formatPrice(c.produtos) }}</td>
                            <td class="px-2 py-1.5 text-right">{{ formatPrice(c.frete + c.outros) }}</td>
                            <td class="px-2 py-1.5 text-right font-semibold">{{ formatPrice(c.total) }}</td>
                            <td class="px-2 py-1.5 text-xs">
                                <span v-if="c.com_nota" class="mr-1 rounded-full bg-[var(--surface-muted)] px-2 py-0.5">NF-e</span>
                                <span v-if="c.recebida" class="rounded-full bg-[var(--surface-muted)] px-2 py-0.5">no estoque</span>
                            </td>
                        </tr>
                        <tr v-if="!compras.length">
                            <td colspan="7" class="py-6 text-center text-[var(--texto-suave)]">Nenhuma compra lançada neste mês.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AdminLayout>
</template>
