<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import ActionIcon from '@/Shared/Components/ActionIcon.vue';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, h, ref } from 'vue';
import { confirmDelete } from '@/Shared/notify';

const props = defineProps({
    shippingMethods: { type: Array, default: () => [] },
    provedorAtivo: { type: String, default: 'correios' },
    provedores: { type: Array, default: () => [] },
    tabela: { type: Object, default: () => ({ cep: null, linhas: [] }) },
    preenchimento: { type: Object, default: () => ({}) },
});

const { can } = usePermissions();
const showForm = ref(false);
const conectando = ref(null);

// Só um provedor conectado (pedido do Lira em 2026-11-03): conectar um
// desconecta o outro e troca o frete do site inteiro na hora.
const conectar = async (provedor) => {
    const outro = props.provedores.find((p) => p.chave !== provedor.chave);
    const ok = await confirmDelete({
        icon: 'question',
        title: `Conectar ${provedor.nome}?`,
        text: `${outro?.nome ?? 'O outro'} será desconectado. O frete somado ao preço do site, as opções do checkout e as etiquetas dos pedidos novos da KazaKora passam a sair de ${provedor.nome}.`,
        confirmButtonText: `Conectar ${provedor.nome}`,
        confirmButtonColor: '#0d6efd',
    });
    if (!ok) return;

    conectando.value = provedor.chave;
    router.post(`/admin/logistica/provedor/${provedor.chave}/conectar`, {}, {
        preserveScroll: true,
        onFinish: () => { conectando.value = null; },
    });
};

const nomeAtivo = computed(() => props.provedores.find((p) => p.chave === props.provedorAtivo)?.nome ?? '');
const cepFormatado = computed(() => (props.tabela.cep ? `${props.tabela.cep.slice(0, 5)}-${props.tabela.cep.slice(5)}` : ''));
const formatPrazo = (dias) => (dias ? `${dias} ${dias === 1 ? 'dia útil' : 'dias úteis'}` : '');
const formatData = (valor) => (valor ? new Date(valor.replace(' ', 'T')).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : 'nunca');
const preenchendo = computed(() => ['cotacao', 'localidades'].includes(props.preenchimento?.fase));

const form = useForm({
    name: '',
    estimated_days: 3,
    price: 0,
    is_active: true,
});

const submit = () => {
    form.post('/admin/logistica', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            showForm.value = false;
        },
    });
};

const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const destroy = async (method) => {
    if (await confirmDelete({ title: `Remover o método "${method.name}"?` })) {
        router.delete(`/admin/logistica/${method.id}`);
    }
};

const columns = [
    { accessorKey: 'name', header: 'Nome' },
    { accessorKey: 'estimated_days', header: 'Prazo (dias)' },
    { accessorKey: 'price', header: 'Preço', cell: ({ row }) => formatPrice(row.original.price) },
    {
        id: 'status',
        header: 'Status',
        accessorFn: (row) => (row.is_active ? 'Ativo' : 'Inativo'),
        cell: ({ row }) => h(StatusBadge, { status: row.original.is_active ? 'active' : 'inactive', label: row.original.is_active ? 'Ativo' : 'Inativo' }),
    },
    {
        id: 'actions',
        header: 'Ações',
        enableSorting: false,
        cell: ({ row }) => (can('operacional.delete')
            ? h('div', { class: 'flex justify-end' }, h(ActionIcon, { icon: 'fa-trash', label: 'Remover', color: 'red', onClick: () => destroy(row.original) }))
            : null),
    },
];
</script>

<template>
    <Head title="Logística" />

    <AdminLayout>
        <div class="mb-4 flex items-center justify-between">
            <h1 class="text-2xl font-bold">Logística — Métodos de envio</h1>
            <button v-if="can('operacional.create')" type="button"
                class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis"
                @click="showForm = !showForm">
                <i class="fas fa-plus text-xs"></i> Novo método
            </button>
        </div>

        <div class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-2">
            <div v-for="provedor in provedores" :key="provedor.chave"
                class="flex flex-col justify-between gap-3 rounded-xl border bg-[var(--surface)] p-4 shadow-sm"
                :class="provedor.conectado ? 'border-primary' : 'border-[var(--surface-border)]'">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-sm font-semibold">{{ provedor.nome }}</h2>
                        <StatusBadge :tone="provedor.conectado ? 'green' : 'gray'"
                            :label="provedor.conectado ? 'Conectado' : 'Desconectado'" />
                    </div>
                    <p class="mt-1 text-xs text-slate-400">{{ provedor.descricao }}</p>
                    <p v-if="provedor.conta" class="mt-1 text-xs text-slate-500">Conta: {{ provedor.conta }}</p>
                    <p class="mt-1 text-xs text-slate-500">
                        Tabela de frete: {{ provedor.cobertura.cotados.toLocaleString('pt-BR') }} de
                        {{ provedor.cobertura.ceps.toLocaleString('pt-BR') }} CEPs cotados
                        (última cotação {{ formatData(provedor.cobertura.ultima) }})
                    </p>
                    <p v-if="provedor.aviso" class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">{{ provedor.aviso }}</p>
                </div>
                <div>
                    <span v-if="provedor.conectado" class="inline-flex items-center gap-2 text-sm font-medium text-success">
                        <i class="fas fa-check-circle text-xs"></i> Em uso no site
                    </span>
                    <button v-else-if="can('operacional.edit')" type="button"
                        :disabled="conectando !== null || (provedor.chave === 'correios' && !provedor.disponivel)"
                        class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50"
                        @click="conectar(provedor)">
                        <i class="fas text-xs" :class="conectando === provedor.chave ? 'fa-spinner fa-spin' : 'fa-plug'"></i>
                        Conectar {{ provedor.nome }}
                    </button>
                </div>
            </div>
        </div>

        <div class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
            <h2 class="text-sm font-semibold">Frete em uso: {{ nomeAtivo }}</h2>
            <p class="mt-0.5 text-xs text-slate-400">
                Valor somado ao preço dos produtos por categoria de tamanho, cotado para o CEP de referência
                {{ cepFormatado }}. Mais barato vai no preço; o mais rápido é a opção paga do checkout.
            </p>
            <p v-if="preenchendo" class="mt-2 text-xs text-amber-600">
                <i class="fas fa-spinner fa-spin"></i> Preenchendo a tabela de frete por localidade em segundo plano.
            </p>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs text-slate-400">
                        <tr>
                            <th class="py-1.5 pr-4 font-medium">Categoria</th>
                            <th class="py-1.5 pr-4 font-medium">Caixa</th>
                            <th class="py-1.5 pr-4 font-medium">Mais barato</th>
                            <th class="py-1.5 font-medium">Mais rápido</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="linha in tabela.linhas" :key="linha.categoria" class="border-t border-[var(--surface-border)]">
                            <td class="py-1.5 pr-4">{{ linha.nome }}</td>
                            <td class="py-1.5 pr-4 text-xs text-slate-500">{{ linha.caixa }}</td>
                            <td class="py-1.5 pr-4">
                                <template v-if="linha.barato !== null">
                                    {{ formatPrice(linha.barato) }}
                                    <span class="block text-xs text-slate-500">{{ [linha.barato_servico ?? (provedorAtivo === 'correios' ? 'PAC' : null), formatPrazo(linha.barato_prazo)].filter(Boolean).join(' · ') }}</span>
                                </template>
                                <span v-else class="text-xs text-slate-400">{{ linha.erro ?? 'Sem cotação ainda' }}</span>
                            </td>
                            <td class="py-1.5">
                                <template v-if="linha.rapido !== null">
                                    {{ formatPrice(linha.rapido) }}
                                    <span class="block text-xs text-slate-500">{{ [linha.rapido_servico ?? (provedorAtivo === 'correios' ? 'SEDEX' : null), formatPrazo(linha.rapido_prazo)].filter(Boolean).join(' · ') }}</span>
                                </template>
                                <span v-else class="text-xs text-slate-400">{{ linha.erro ?? 'Sem cotação ainda' }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <form v-if="showForm" class="mb-6 grid grid-cols-1 gap-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:grid-cols-4" @submit.prevent="submit">
            <div class="sm:col-span-2">
                <label class="block text-xs font-medium text-slate-400">Nome</label>
                <input v-model="form.name" type="text" required class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-400">Prazo (dias)</label>
                <input v-model.number="form.estimated_days" type="number" min="0" required class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-400">Preço (R$)</label>
                <input v-model.number="form.price" type="number" step="0.01" min="0" required class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-2 py-1.5 text-sm">
            </div>
            <div class="sm:col-span-4">
                <button type="submit" :disabled="form.processing" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50">
                    Salvar método
                </button>
            </div>
        </form>

        <DataTable
            :columns="columns"
            :data="props.shippingMethods"
            search-placeholder="Buscar método..."
            empty-message="Nenhum método de envio cadastrado."
        />
    </AdminLayout>
</template>
