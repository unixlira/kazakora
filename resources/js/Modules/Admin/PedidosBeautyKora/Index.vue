<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import ServerPagination from '@/Shared/Components/ServerPagination.vue';
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

// Pedidos Beauty Kora (2026-10-12): o que os clientes compraram no site
// Beauty Kora, um item por linha, com troca de status.
const props = defineProps({
    pedidos: { type: Object, default: () => ({ data: [], links: [] }) },
    totais: { type: Object, default: () => ({ itens: 0, valor: 0, clientes: 0 }) },
    filtros: { type: Object, default: () => ({}) },
    statusOpcoes: { type: Array, default: () => [] },
});

const NOMES = { pending: 'Aguardando pagamento', paid: 'Pago', shipped: 'Enviado', delivered: 'Entregue', canceled: 'Cancelado' };
const CORES = {
    pending: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    paid: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    shipped: 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
    delivered: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    canceled: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
};

const filtros = reactive({ status: props.filtros.status ?? '', busca: props.filtros.busca ?? '' });

const buscar = () => {
    router.get('/admin/pedidos-beautykora', {
        status: filtros.status || undefined,
        busca: filtros.busca || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

const mudarStatus = (pedido, status) => {
    if (status === pedido.status) return;
    router.patch(`/admin/pedidos-beautykora/${pedido.id}/status`, { status }, { preserveScroll: true });
};

const numero = (valor) => Number(valor ?? 0).toLocaleString('pt-BR');
const reais = (valor) => Number(valor ?? 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
</script>

<template>
    <Head title="Pedidos Beauty Kora" />

    <AdminLayout>
        <div class="mb-6 max-w-3xl">
            <h1 class="mb-1 text-2xl font-bold">Pedidos Beauty Kora</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Compras feitas no site Beauty Kora, um produto por linha. Ficam fora dos pedidos e da margem da KazaKora.
                O pedido nasce "Aguardando pagamento" quando o cliente vai para o pagamento. Horário de Brasília.
            </p>
        </div>

        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-3">
            <CardStats stat-subtitle="ITENS" :stat-title="numero(props.totais.itens)" stat-icon-name="fas fa-bag-shopping" variant="primary" compact />
            <CardStats stat-subtitle="VALOR" :stat-title="reais(props.totais.valor)" stat-icon-name="fas fa-brazilian-real-sign" variant="info" compact />
            <CardStats stat-subtitle="CLIENTES" :stat-title="numero(props.totais.clientes)" stat-icon-name="fas fa-users" variant="info" compact />
        </div>

        <form class="mb-4 grid grid-cols-1 gap-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:grid-cols-3" @submit.prevent="buscar">
            <div>
                <label for="bk-status" class="block text-xs font-medium text-slate-500 dark:text-slate-400">Status</label>
                <select id="bk-status" v-model="filtros.status" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-2 text-sm" @change="buscar()">
                    <option value="">Todos</option>
                    <option v-for="s in props.statusOpcoes" :key="s" :value="s">{{ NOMES[s] ?? s }}</option>
                </select>
            </div>
            <div class="sm:col-span-2">
                <label for="bk-busca" class="block text-xs font-medium text-slate-500 dark:text-slate-400">Produto, cliente ou e-mail</label>
                <div class="mt-1 flex gap-2">
                    <input id="bk-busca" v-model="filtros.busca" type="search" autocomplete="off"
                        class="w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm">
                    <button type="submit" class="whitespace-nowrap rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis">
                        <i class="fas fa-magnifying-glass mr-1" aria-hidden="true"></i> Buscar
                    </button>
                </div>
            </div>
        </form>

        <div class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-[var(--surface-border)] text-xs uppercase text-slate-500 dark:text-slate-400">
                        <tr>
                            <th scope="col" class="px-4 py-3">#</th>
                            <th scope="col" class="px-4 py-3">Data/Hora</th>
                            <th scope="col" class="px-4 py-3">Cliente</th>
                            <th scope="col" class="px-4 py-3">Produto</th>
                            <th scope="col" class="px-4 py-3 text-right">Valor</th>
                            <th scope="col" class="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--surface-border)]">
                        <tr v-for="pedido in props.pedidos.data" :key="pedido.id" class="hover:bg-[var(--surface-muted)]/50">
                            <td class="whitespace-nowrap px-4 py-2.5 tabular-nums text-slate-500">{{ pedido.id }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 tabular-nums text-slate-500 dark:text-slate-400">{{ pedido.quando }}</td>
                            <td class="px-4 py-2.5">
                                <div class="font-medium">{{ pedido.cliente ?? '—' }}</div>
                                <div class="text-xs text-slate-500">{{ pedido.email }}<span v-if="pedido.cidade"> · {{ pedido.cidade }}</span></div>
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="flex items-center gap-2">
                                    <img v-if="pedido.imagem" :src="pedido.imagem" alt="" class="h-9 w-9 rounded object-cover" loading="lazy">
                                    <span>{{ pedido.quantidade }}x {{ pedido.produto }}</span>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums">{{ reais(pedido.valor) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5">
                                <select :value="pedido.status" :aria-label="`Status do pedido ${pedido.id}`"
                                    :class="['rounded-full border-0 px-2.5 py-1 text-xs font-medium', CORES[pedido.status]]"
                                    @change="mudarStatus(pedido, $event.target.value)">
                                    <option v-for="s in props.statusOpcoes" :key="s" :value="s">{{ NOMES[s] ?? s }}</option>
                                </select>
                            </td>
                        </tr>
                        <tr v-if="props.pedidos.data.length === 0">
                            <td colspan="6" class="px-4 py-10 text-center text-slate-500 dark:text-slate-400">Nenhum pedido do Beauty Kora ainda.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <ServerPagination :paginator="props.pedidos" embedded />
        </div>
    </AdminLayout>
</template>
