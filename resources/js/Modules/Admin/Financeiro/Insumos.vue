<script setup>
/**
 * Insumos (pedido do Lira em 12/11/2026): material de escritório e do galpão
 * que entra no custo. Cada lançamento sai do lucro pela data dele; a
 * embalagem por pedido (R$ fixo da config) aparece ao lado só de referência.
 */
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { confirmDelete } from '@/Shared/notify';

const props = defineProps({
    mes: { type: String, required: true },
    lancamentos: { type: Array, default: () => [] },
    categorias: { type: Object, required: true },
    porCategoria: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
    embalagemPorPedido: { type: Object, required: true },
});

const formatPrice = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v ?? 0);
const formatData = (d) => new Date(`${d}T00:00:00`).toLocaleDateString('pt-BR');
const nomeMes = computed(() => {
    const [a, m] = props.mes.split('-').map(Number);
    return new Date(a, m - 1, 1).toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
});

const hoje = new Date().toISOString().slice(0, 10);
const editando = ref(null);
const form = useForm({ data: hoje, descricao: '', categoria: 'escritorio', valor: null, observacao: '' });

const limpar = () => {
    editando.value = null;
    form.reset();
    form.clearErrors();
};

const editar = (l) => {
    editando.value = l.id;
    form.data = l.data;
    form.descricao = l.descricao;
    form.categoria = l.categoria;
    form.valor = l.valor;
    form.observacao = l.observacao ?? '';
};

const salvar = () => {
    const opcoes = { preserveScroll: true, onSuccess: limpar };
    if (editando.value) {
        form.put(`/admin/insumos/${editando.value}`, opcoes);
    } else {
        form.post('/admin/insumos', opcoes);
    }
};

const excluir = async (l) => {
    if (await confirmDelete({ title: `Excluir "${l.descricao}" (${formatPrice(l.valor)})?` })) {
        router.delete(`/admin/insumos/${l.id}`, { preserveScroll: true });
    }
};

const trocarMes = (mes) => router.get('/admin/insumos', { mes }, { preserveState: true, preserveScroll: true });
</script>

<template>
    <Head title="Insumos" />

    <AdminLayout>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-bold">Insumos</h1>
            <label class="flex items-center gap-2 text-sm">
                <span class="text-[var(--texto-suave)]">Mês</span>
                <input type="month" :value="mes" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm"
                    @change="trocarMes($event.target.value)">
            </label>
        </div>

        <p class="mb-4 text-sm text-[var(--texto-suave)]">
            Material de escritório, do galpão e de embalagem comprado. Cada lançamento entra no custo (lucro líquido do Fluxo de Caixa,
            do Financeiro e do e-mail diário) pela data dele. Além disso, a embalagem de cada pedido entra sozinha a
            {{ formatPrice(embalagemPorPedido.valor) }} por pedido embalado pela loja (o Full do Mercado Livre fica de fora).
        </p>

        <div class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-6">
            <CardStats :stat-subtitle="`Lançado em ${nomeMes}`" :stat-title="formatPrice(total)" stat-icon-name="fas fa-box-open" variant="primary" compact />
            <CardStats v-for="c in porCategoria" :key="c.categoria" :stat-subtitle="c.rotulo" :stat-title="formatPrice(c.total)" stat-icon-name="fas fa-tag" variant="neutral" compact />
            <CardStats :stat-subtitle="`Embalagem: ${embalagemPorPedido.pedidos} pedidos`" :stat-title="formatPrice(embalagemPorPedido.total)" stat-icon-name="fas fa-box" variant="neutral" compact />
        </div>

        <form class="mb-6 grid grid-cols-1 gap-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:grid-cols-6" @submit.prevent="salvar">
            <h2 class="font-semibold sm:col-span-6">{{ editando ? 'Editar lançamento' : 'Novo lançamento' }}</h2>
            <div>
                <label for="ins-data" class="block text-xs font-medium text-[var(--texto-suave)]">Data</label>
                <input id="ins-data" v-model="form.data" type="date" required class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
                <p v-if="form.errors.data" class="mt-1 text-xs text-error">{{ form.errors.data }}</p>
            </div>
            <div class="sm:col-span-2">
                <label for="ins-desc" class="block text-xs font-medium text-[var(--texto-suave)]">Descrição</label>
                <input id="ins-desc" v-model="form.descricao" type="text" maxlength="200" required placeholder="Ex.: fita adesiva, papel A4, lâmpada do galpão"
                    class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
                <p v-if="form.errors.descricao" class="mt-1 text-xs text-error">{{ form.errors.descricao }}</p>
            </div>
            <div>
                <label for="ins-cat" class="block text-xs font-medium text-[var(--texto-suave)]">Categoria</label>
                <select id="ins-cat" v-model="form.categoria" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
                    <option v-for="(rotulo, chave) in categorias" :key="chave" :value="chave">{{ rotulo }}</option>
                </select>
            </div>
            <div>
                <label for="ins-valor" class="block text-xs font-medium text-[var(--texto-suave)]">Valor (R$)</label>
                <input id="ins-valor" v-model.number="form.valor" type="number" step="0.01" min="0.01" required class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
                <p v-if="form.errors.valor" class="mt-1 text-xs text-error">{{ form.errors.valor }}</p>
            </div>
            <div>
                <label for="ins-obs" class="block text-xs font-medium text-[var(--texto-suave)]">Observação</label>
                <input id="ins-obs" v-model="form.observacao" type="text" maxlength="500" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
            </div>
            <div class="flex gap-2 sm:col-span-6">
                <button type="submit" :disabled="form.processing" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50">
                    {{ editando ? 'Salvar alteração' : 'Lançar insumo' }}
                </button>
                <button v-if="editando" type="button" class="rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm" @click="limpar">Cancelar</button>
            </div>
        </form>

        <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
            <h2 class="mb-2 font-semibold">Lançamentos de {{ nomeMes }}</h2>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-sm tabular-nums">
                    <thead>
                        <tr class="border-b border-[var(--surface-border)] text-left text-[11px] text-[var(--texto-suave)]">
                            <th scope="col" class="py-1.5 pr-2 font-semibold">Data</th>
                            <th scope="col" class="px-2 py-1.5 font-semibold">Descrição</th>
                            <th scope="col" class="px-2 py-1.5 font-semibold">Categoria</th>
                            <th scope="col" class="px-2 py-1.5 text-right font-semibold">Valor</th>
                            <th scope="col" class="px-2 py-1.5 font-semibold">Observação</th>
                            <th scope="col" class="px-2 py-1.5 font-semibold">Lançado por</th>
                            <th scope="col" class="px-2 py-1.5 text-right font-semibold">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="l in lancamentos" :key="l.id" class="border-b border-[var(--surface-muted)]">
                            <td class="py-1.5 pr-2 whitespace-nowrap">{{ formatData(l.data) }}</td>
                            <td class="px-2 py-1.5">{{ l.descricao }}</td>
                            <td class="px-2 py-1.5">{{ categorias[l.categoria] ?? l.categoria }}</td>
                            <td class="px-2 py-1.5 text-right font-semibold">{{ formatPrice(l.valor) }}</td>
                            <td class="px-2 py-1.5 text-[var(--texto-suave)]">{{ l.observacao ?? '—' }}</td>
                            <td class="px-2 py-1.5 text-[var(--texto-suave)]">{{ l.autor ?? '—' }}</td>
                            <td class="px-2 py-1.5 text-right whitespace-nowrap">
                                <button type="button" class="mr-3 text-primary hover:underline" @click="editar(l)">Editar</button>
                                <button type="button" class="text-error hover:underline" @click="excluir(l)">Excluir</button>
                            </td>
                        </tr>
                        <tr v-if="!lancamentos.length">
                            <td colspan="7" class="py-6 text-center text-[var(--texto-suave)]">Nenhum insumo lançado neste mês.</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="lancamentos.length">
                        <tr class="border-t border-[var(--surface-border)] font-bold">
                            <td colspan="3" class="py-1.5 pr-2">Total do mês</td>
                            <td class="px-2 py-1.5 text-right">{{ formatPrice(total) }}</td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>
    </AdminLayout>
</template>
