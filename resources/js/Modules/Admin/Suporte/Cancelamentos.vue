<script setup>
// Atendimento > Cancelamentos (Lira, 2026-11-17): o cliente tocou em
// "Cancelar pedido" numa compra já postada. Nada foi cancelado: cada linha
// tem o chamado onde a equipe responde e o botão para marcar como resolvida.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    solicitacoes: { type: Array, default: () => [] },
    filtro: { type: String, default: 'pendente' },
    contagem: { type: Object, default: () => ({}) },
});

const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value) || 0);
const formatDateTime = (value) => (value ? new Date(value).toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo', dateStyle: 'short', timeStyle: 'short' }) : '—');

const abas = [
    { value: 'pendente', label: 'Pendentes' },
    { value: 'resolvido', label: 'Resolvidas' },
    { value: 'todos', label: 'Todas' },
];
const contar = (aba) => (aba === 'todos'
    ? Object.values(props.contagem).reduce((t, n) => t + Number(n), 0)
    : Number(props.contagem[aba] ?? 0));

const trocar = (status) => router.get('/admin/cancelamentos', { status }, { preserveState: true, preserveScroll: true });

const resolvendo = ref(null);
const resolver = (linha) => {
    if (!window.confirm(`Marcar a solicitação do pedido ${linha.pedido?.numero ?? ''} como resolvida?`)) return;
    resolvendo.value = linha.id;
    router.post(`/admin/cancelamentos/${linha.id}/resolver`, {}, {
        preserveScroll: true,
        onFinish: () => { resolvendo.value = null; },
    });
};
</script>

<template>
    <Head title="Cancelamentos" />

    <AdminLayout>
        <h1 class="mb-1 text-2xl font-bold">Cancelamentos</h1>
        <p class="mb-4 text-sm text-slate-500">
            Clientes que pediram para cancelar uma compra que já foi postada. O pedido <strong>não</strong> foi cancelado:
            responda pelo chamado e marque como resolvida quando terminar.
        </p>

        <div class="mb-4 flex gap-1 border-b border-[var(--surface-border)]">
            <button v-for="aba in abas" :key="aba.value" type="button"
                :class="['-mb-px border-b-2 px-4 py-2 text-sm font-semibold', aba.value === filtro ? 'border-sky-600 text-sky-700' : 'border-transparent text-slate-500 hover:text-slate-700']"
                @click="trocar(aba.value)">
                {{ aba.label }} <span class="ml-1 text-xs text-slate-400">{{ contar(aba.value) }}</span>
            </button>
        </div>

        <div v-if="solicitacoes.length" class="overflow-x-auto rounded-xl border border-[var(--surface-border)] bg-[var(--surface)]">
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs uppercase text-slate-400">
                    <tr>
                        <th class="px-3 py-2">Pedido</th>
                        <th class="px-3 py-2">Cliente</th>
                        <th class="px-3 py-2">Valor</th>
                        <th class="px-3 py-2">Postagem</th>
                        <th class="px-3 py-2">Motivo</th>
                        <th class="px-3 py-2">Pedido em</th>
                        <th class="px-3 py-2">Situação</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="linha in solicitacoes" :key="linha.id" class="border-t border-[var(--surface-border)] align-top">
                        <td class="px-3 py-2 whitespace-nowrap">
                            <Link v-if="linha.pedido" :href="`/admin/pedidos/${linha.pedido.id}`" class="font-mono font-semibold text-sky-700 hover:underline">#{{ linha.pedido.numero }}</Link>
                            <span v-else class="text-slate-400">—</span>
                        </td>
                        <td class="px-3 py-2">
                            <div class="font-medium">{{ linha.cliente?.nome || '—' }}</div>
                            <div v-if="linha.cliente?.email" class="text-xs text-slate-400 break-all">{{ linha.cliente.email }}</div>
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ linha.pedido ? formatPrice(linha.pedido.total) : '—' }}</td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            <div>{{ formatDateTime(linha.postado_em) }}</div>
                            <div v-if="linha.rastreio" class="font-mono text-xs">{{ linha.rastreio }}</div>
                            <div v-if="linha.postagem_fonte" class="text-xs text-slate-400">{{ linha.postagem_fonte }}</div>
                        </td>
                        <td class="px-3 py-2 max-w-xs">{{ linha.motivo }}</td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ formatDateTime(linha.solicitado_em) }}</td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            <span v-if="linha.status === 'pendente'" class="rounded-full bg-lightwarning px-2 py-0.5 text-xs font-semibold text-warning">Pendente</span>
                            <template v-else>
                                <span class="rounded-full bg-lightsuccess px-2 py-0.5 text-xs font-semibold text-success">Resolvido</span>
                                <div class="mt-1 text-xs text-slate-400">{{ formatDateTime(linha.resolvido_em) }}<span v-if="linha.resolvido_por"> · {{ linha.resolvido_por }}</span></div>
                            </template>
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex flex-col items-end gap-1.5 whitespace-nowrap">
                                <Link v-if="linha.chamado" :href="`/admin/suporte?c=${linha.chamado.id}`" class="text-xs font-semibold text-sky-700 hover:underline">
                                    <i class="fas fa-headset mr-1"></i>Chamado {{ linha.chamado.numero }}
                                </Link>
                                <button v-if="linha.status === 'pendente'" type="button" :disabled="resolvendo === linha.id"
                                    class="rounded-md border border-emerald-600/40 px-2 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-50 disabled:opacity-50"
                                    @click="resolver(linha)">
                                    <i class="fas fa-check mr-1"></i>Marcar como resolvida
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else class="rounded-xl border border-dashed border-[var(--surface-border)] p-6 text-center text-sm text-slate-400">
            Nenhuma solicitação de cancelamento {{ filtro === 'pendente' ? 'pendente' : 'aqui' }}.
        </p>
    </AdminLayout>
</template>
