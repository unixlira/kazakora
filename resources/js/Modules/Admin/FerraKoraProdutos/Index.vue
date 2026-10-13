<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import ServerPagination from '@/Shared/Components/ServerPagination.vue';
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

// Produtos FerraKora (2026-10-13): catálogo do site FerraKora, paginado no
// servidor. Categoria e "ativo" mudam na própria linha; desativar só tira o
// produto do site, ele continua no KazaKora.
const props = defineProps({
    produtos: { type: Object, default: () => ({ data: [], links: [] }) },
    filtros: { type: Object, default: () => ({}) },
    categorias: { type: Array, default: () => [] },
    totais: { type: Object, default: () => ({ todos: 0, ativos: 0, sem_categoria: 0 }) },
});

const filtros = reactive({
    q: props.filtros.q ?? '',
    categoria: props.filtros.categoria ?? '',
    ativo: props.filtros.ativo ?? '',
});

const buscar = () => {
    router.get('/admin/produtos-ferrakora', {
        q: filtros.q || undefined,
        categoria: filtros.categoria || undefined,
        ativo: filtros.ativo || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

const limpar = () => {
    Object.assign(filtros, { q: '', categoria: '', ativo: '' });
    buscar();
};

const numero = (valor) => Number(valor ?? 0).toLocaleString('pt-BR');

const salvar = (produto, campos) => {
    router.patch(`/admin/produtos-ferrakora/${produto.id}`, campos, { preserveScroll: true, preserveState: true });
};

const mudarCategoria = (produto, evento) => salvar(produto, { categoria: evento.target.value || null });
</script>

<template>
    <Head title="Produtos FerraKora" />

    <AdminLayout>
        <div class="mb-6 max-w-3xl">
            <h1 class="mb-1 text-2xl font-bold">Produtos FerraKora</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Produtos que aparecem no site FerraKora. Desativar tira o produto do site; ele continua cadastrado no KazaKora.
            </p>
        </div>

        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-3">
            <CardStats stat-subtitle="PRODUTOS" :stat-title="numero(props.totais.todos)" stat-icon-name="fas fa-boxes-stacked" variant="primary" compact />
            <CardStats stat-subtitle="ATIVOS NO SITE" :stat-title="numero(props.totais.ativos)" stat-icon-name="fas fa-eye" variant="info" compact />
            <CardStats stat-subtitle="SEM CATEGORIA" :stat-title="numero(props.totais.sem_categoria)" stat-icon-name="fas fa-tag" variant="info" compact />
        </div>

        <form class="mb-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" @submit.prevent="buscar">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div>
                    <label for="fk-q" class="block text-xs font-medium text-slate-500 dark:text-slate-400">Busca</label>
                    <input id="fk-q" v-model="filtros.q" type="search" autocomplete="off" placeholder="Nome ou SKU…"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="fk-categoria" class="block text-xs font-medium text-slate-500 dark:text-slate-400">Categoria</label>
                    <select id="fk-categoria" v-model="filtros.categoria"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-2 text-sm"
                        @change="buscar()">
                        <option value="">Todas</option>
                        <option value="__sem">Sem categoria</option>
                        <option v-for="categoria in props.categorias" :key="categoria" :value="categoria">{{ categoria }}</option>
                    </select>
                </div>
                <div>
                    <label for="fk-ativo" class="block text-xs font-medium text-slate-500 dark:text-slate-400">Situação</label>
                    <div class="mt-1 flex gap-2">
                        <select id="fk-ativo" v-model="filtros.ativo"
                            class="w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-2 text-sm"
                            @change="buscar()">
                            <option value="">Todas</option>
                            <option value="1">Ativos</option>
                            <option value="0">Desativados</option>
                        </select>
                        <button type="submit"
                            class="whitespace-nowrap rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis">
                            <i class="fas fa-magnifying-glass mr-1" aria-hidden="true"></i> Buscar
                        </button>
                    </div>
                </div>
            </div>
            <div class="mt-3 text-right">
                <button type="button" class="text-xs text-primary hover:underline" @click="limpar">Limpar filtros</button>
            </div>
        </form>

        <div class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-[var(--surface-border)] text-xs uppercase text-slate-500 dark:text-slate-400">
                        <tr>
                            <th scope="col" class="px-4 py-3">SKU</th>
                            <th scope="col" class="px-4 py-3">Produto</th>
                            <th scope="col" class="px-4 py-3">Categoria</th>
                            <th scope="col" class="px-4 py-3 text-center">No site</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--surface-border)]">
                        <tr v-for="produto in props.produtos.data" :key="produto.id" class="hover:bg-[var(--surface-muted)]/50"
                            :class="{ 'opacity-60': !produto.ativo }">
                            <td class="whitespace-nowrap px-4 py-2.5 font-mono text-xs text-slate-500">{{ produto.sku ?? '—' }}</td>
                            <td class="px-4 py-2.5">{{ produto.nome }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5">
                                <select :value="produto.categoria ?? ''" :aria-label="`Categoria de ${produto.nome}`"
                                    class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1 text-sm"
                                    @change="mudarCategoria(produto, $event)">
                                    <option value="">Sem categoria</option>
                                    <option v-for="categoria in props.categorias" :key="categoria" :value="categoria">{{ categoria }}</option>
                                </select>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-center">
                                <input type="checkbox" :checked="produto.ativo" :aria-label="`${produto.nome} ativo no site`"
                                    @change="salvar(produto, { ativo: $event.target.checked })">
                            </td>
                        </tr>
                        <tr v-if="props.produtos.data.length === 0">
                            <td colspan="4" class="px-4 py-10 text-center text-slate-500 dark:text-slate-400">
                                Nenhum produto encontrado com esses filtros.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <ServerPagination :paginator="props.produtos" embedded />
        </div>
    </AdminLayout>
</template>
