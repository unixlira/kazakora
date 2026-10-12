<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import { usePermissions } from '@/Shared/usePermissions';
import { confirmDelete } from '@/Shared/notify';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    pesquisa: { type: Object, required: true },
    aba: { type: String, required: true },
    opcoes: { type: Object, required: true },
});

const { can } = usePermissions();

const moeda = (valor) => (valor === null || valor === undefined
    ? '—'
    : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(valor));
const dataBr = (iso) => (iso ? new Date(`${iso.slice(0, 10)}T12:00:00`).toLocaleDateString('pt-BR') : '—');

const precos = computed(() => props.pesquisa.itens.map((i) => i.preco).filter((p) => p !== null && p !== undefined));
const faixa = computed(() => (precos.value.length
    ? { min: Math.min(...precos.value), max: Math.max(...precos.value), media: precos.value.reduce((a, b) => a + b, 0) / precos.value.length }
    : null));

const remover = async () => {
    if (await confirmDelete({ title: `Remover a pesquisa "${props.pesquisa.titulo}"?`, text: 'Ela vai para a lixeira.' })) {
        router.delete(`/admin/pesquisas/${props.pesquisa.id}`);
    }
};
</script>

<template>
    <Head :title="props.pesquisa.titulo" />

    <AdminLayout>
        <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <Link :href="`/admin/pesquisas?aba=${props.aba}`" class="text-sm text-slate-500 hover:text-primary">
                    <i class="fas fa-arrow-left mr-1 text-xs" aria-hidden="true"></i> Pesquisas
                </Link>
                <h1 class="mt-1 break-words text-2xl font-bold">{{ props.pesquisa.titulo }}</h1>
                <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-500">
                    <span><i class="fas fa-store mr-1 text-xs" aria-hidden="true"></i>{{ props.pesquisa.plataforma_label }}</span>
                    <span class="tabular-nums"><i class="far fa-calendar mr-1 text-xs" aria-hidden="true"></i>{{ dataBr(props.pesquisa.data_pesquisa) }}</span>
                    <span><i class="fas fa-user mr-1 text-xs" aria-hidden="true"></i>{{ props.pesquisa.autor?.name ?? '—' }}</span>
                    <StatusBadge :tone="props.pesquisa.status === 'concluida' ? 'green' : 'yellow'"
                        :label="props.opcoes.status[props.pesquisa.status] ?? props.pesquisa.status" />
                </div>
            </div>
            <div class="flex items-center gap-2">
                <Link v-if="can('cadastros.edit')" :href="`/admin/pesquisas/${props.pesquisa.id}/editar`"
                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis">
                    <i class="fas fa-pen text-xs" aria-hidden="true"></i> Editar
                </Link>
                <button v-if="can('cadastros.delete')" type="button"
                    class="inline-flex items-center gap-2 rounded-lg border border-red-200 px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50"
                    @click="remover">
                    <i class="fas fa-trash text-xs" aria-hidden="true"></i> Remover
                </button>
            </div>
        </div>

        <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-slate-500">Produtos</p>
                <p class="mt-1 text-xl font-bold tabular-nums">{{ props.pesquisa.itens.length }}</p>
            </div>
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-slate-500">Menor preço</p>
                <p class="mt-1 text-xl font-bold tabular-nums">{{ faixa ? moeda(faixa.min) : '—' }}</p>
            </div>
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-slate-500">Preço médio</p>
                <p class="mt-1 text-xl font-bold tabular-nums">{{ faixa ? moeda(faixa.media) : '—' }}</p>
            </div>
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-slate-500">Maior preço</p>
                <p class="mt-1 text-xl font-bold tabular-nums">{{ faixa ? moeda(faixa.max) : '—' }}</p>
            </div>
        </div>

        <section class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-6 shadow-sm" aria-labelledby="titulo-resumo">
            <h2 id="titulo-resumo" class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Resumo e conclusão</h2>
            <p v-if="props.pesquisa.resumo" class="whitespace-pre-line break-words leading-relaxed">{{ props.pesquisa.resumo }}</p>
            <p v-else class="text-sm text-slate-500">Sem resumo ainda.</p>
        </section>

        <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm" aria-labelledby="titulo-produtos">
            <h2 id="titulo-produtos" class="border-b border-[var(--surface-border)] px-6 py-4 text-sm font-semibold uppercase tracking-wide text-slate-500">
                Produtos observados
            </h2>
            <div v-if="props.pesquisa.itens.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-[var(--surface-border)] text-left text-xs uppercase tracking-wide text-slate-500">
                            <th scope="col" class="px-4 py-3 font-semibold">#</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Produto</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Preço</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Vendas</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Avaliações</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Vendedor</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Nosso produto</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Nota</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(item, indice) in props.pesquisa.itens" :key="item.id"
                            class="border-b border-[var(--surface-border)] align-top last:border-0">
                            <td class="px-4 py-3 tabular-nums text-slate-500">{{ indice + 1 }}</td>
                            <td class="max-w-xs px-4 py-3">
                                <a v-if="item.link" :href="item.link" target="_blank" rel="noopener noreferrer"
                                    class="font-medium break-words hover:text-primary hover:underline">
                                    {{ item.produto }} <i class="fas fa-arrow-up-right-from-square ml-0.5 text-[10px]" aria-hidden="true"></i>
                                    <span class="sr-only">(abre em nova aba)</span>
                                </a>
                                <span v-else class="font-medium break-words">{{ item.produto }}</span>
                                <p v-if="item.observacoes" class="mt-1 whitespace-pre-line break-words text-xs text-slate-500">{{ item.observacoes }}</p>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ moeda(item.preco) }}</td>
                            <td class="px-4 py-3">{{ item.vendas || '—' }}</td>
                            <td class="px-4 py-3">{{ item.avaliacoes || '—' }}</td>
                            <td class="px-4 py-3">{{ item.vendedor || '—' }}</td>
                            <td class="px-4 py-3">
                                <Link v-if="item.product" :href="`/admin/produtos/${item.product.id}/editar`" class="hover:text-primary hover:underline">
                                    {{ item.product.name }}
                                </Link>
                                <span v-else class="text-slate-500">—</span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ item.nota === null || item.nota === undefined ? '—' : item.nota.toLocaleString('pt-BR') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="px-6 py-10 text-center text-sm text-slate-500">Nenhum produto registrado nesta pesquisa.</p>
        </section>
    </AdminLayout>
</template>
