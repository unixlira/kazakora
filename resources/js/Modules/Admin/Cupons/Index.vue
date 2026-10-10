<script setup>
// Cupons de desconto (pedido 2026-10-10): criar/editar com as regras e ir
// para o disparo em lote de cada cupom.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import InputError from '@/Shared/Components/InputError.vue';
import { usePermissions } from '@/Shared/usePermissions';
import { confirmDelete, notifySuccess } from '@/Shared/notify';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineProps({
    cupons: { type: Array, default: () => [] },
});

const { can } = usePermissions();
const page = usePage();
const erroCupom = computed(() => page.props.errors?.cupom);

const vazio = () => ({
    code: '',
    name: '',
    discount_type: 'percentage',
    discount_value: '',
    min_order_value: '',
    max_uses: '',
    one_per_customer: false,
    starts_at: '',
    expires_at: '',
    is_active: true,
});

const form = useForm(vazio());
const editandoId = ref(null);

const gerarCodigo = () => {
    const letras = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    form.code = 'KAZA' + Array.from({ length: 6 }, () => letras[Math.floor(Math.random() * letras.length)]).join('');
};

const editar = (cupom) => {
    editandoId.value = cupom.id;
    form.defaults({
        code: cupom.code,
        name: cupom.name ?? '',
        discount_type: cupom.discount_type,
        discount_value: cupom.discount_value,
        min_order_value: cupom.min_order_value ?? '',
        max_uses: cupom.max_uses ?? '',
        one_per_customer: cupom.one_per_customer,
        starts_at: cupom.starts_at ?? '',
        expires_at: cupom.expires_at ?? '',
        is_active: cupom.is_active,
    });
    form.reset();
    form.clearErrors();
    window.scrollTo({ top: 0, behavior: 'smooth' });
};

const cancelar = () => {
    editandoId.value = null;
    form.defaults(vazio());
    form.reset();
    form.clearErrors();
};

const salvar = () => {
    const opcoes = { preserveScroll: true, onSuccess: cancelar };
    const dados = (data) => ({
        ...data,
        min_order_value: data.min_order_value === '' ? null : data.min_order_value,
        max_uses: data.max_uses === '' ? null : data.max_uses,
        starts_at: data.starts_at || null,
        expires_at: data.expires_at || null,
    });
    if (editandoId.value) {
        form.transform(dados).put(`/admin/cupons/${editandoId.value}`, opcoes);
    } else {
        form.transform(dados).post('/admin/cupons', opcoes);
    }
};

const alternar = (cupom) => router.patch(`/admin/cupons/${cupom.id}/ativo`, {}, { preserveScroll: true });

const excluir = async (cupom) => {
    if (await confirmDelete({ title: `Excluir o cupom ${cupom.code}?` })) {
        router.delete(`/admin/cupons/${cupom.id}`, { preserveScroll: true });
    }
};

const copiarLink = async (cupom) => {
    try {
        await navigator.clipboard.writeText(cupom.link);
        notifySuccess('Link copiado! Quem abrir já entra com o cupom aplicado.');
    } catch {
        // sem permissão de área de transferência
    }
};

const SITUACAO = {
    ativo: { label: 'Ativo', tone: 'green' },
    inativo: { label: 'Inativo', tone: 'gray' },
    expirado: { label: 'Expirado', tone: 'red' },
    agendado: { label: 'Agendado', tone: 'blue' },
    esgotado: { label: 'Esgotado', tone: 'yellow' },
};

const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
const formatDate = (value) => (value ? value.split('-').reverse().join('/') : null);
const campo = 'w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm';
</script>

<template>
    <Head title="Cupons" />

    <AdminLayout>
        <div class="mb-6">
            <h1 class="mb-1 text-2xl font-bold">Cupons de desconto</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Crie cupons para a loja, defina as regras e dispare em lote para carrinhos abandonados, clientes e datas especiais.
            </p>
        </div>

        <p v-if="erroCupom" class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ erroCupom }}</p>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <form v-if="can(editandoId ? 'cadastros.edit' : 'cadastros.create')" @submit.prevent="salvar"
                class="h-fit rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm xl:col-span-1">
                <h2 class="mb-4 text-lg font-semibold">{{ editandoId ? `Editar cupom ${form.code}` : 'Novo cupom' }}</h2>

                <div class="mb-4">
                    <label class="mb-1 block text-sm font-medium">Código *</label>
                    <div class="flex gap-2">
                        <input v-model="form.code" type="text" maxlength="40" class="uppercase" :class="campo" placeholder="Ex: VOLTA10" />
                        <button type="button" class="shrink-0 rounded-lg border border-[var(--surface-border)] px-3 text-sm hover:bg-slate-50 dark:hover:bg-slate-800" @click="gerarCodigo">
                            <i class="fas fa-shuffle mr-1"></i> Gerar
                        </button>
                    </div>
                    <p class="mt-1 text-xs text-slate-400">O cliente pode digitar em maiúsculas ou minúsculas.</p>
                    <InputError :message="form.errors.code" />
                </div>

                <div class="mb-4">
                    <label class="mb-1 block text-sm font-medium">Nome interno</label>
                    <input v-model="form.name" type="text" maxlength="120" :class="campo" placeholder="Ex: Black Friday 2026" />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="mb-4 grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium">Tipo *</label>
                        <select v-model="form.discount_type" :class="campo">
                            <option value="percentage">Porcentagem (%)</option>
                            <option value="fixed">Valor fixo (R$)</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ form.discount_type === 'percentage' ? 'Desconto (%) *' : 'Desconto (R$) *' }}</label>
                        <input v-model="form.discount_value" type="number" step="0.01" min="0" :class="campo" :placeholder="form.discount_type === 'percentage' ? '10' : '20,00'" />
                        <InputError :message="form.errors.discount_value" />
                    </div>
                </div>

                <div class="mb-4 grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium">Pedido mínimo (R$)</label>
                        <input v-model="form.min_order_value" type="number" step="0.01" min="0" :class="campo" placeholder="Sem mínimo" />
                        <InputError :message="form.errors.min_order_value" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Limite de usos</label>
                        <input v-model="form.max_uses" type="number" min="1" :class="campo" placeholder="Ilimitado" />
                        <InputError :message="form.errors.max_uses" />
                    </div>
                </div>

                <div class="mb-4 grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium">Vale a partir de</label>
                        <input v-model="form.starts_at" type="date" :class="campo" />
                        <InputError :message="form.errors.starts_at" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Vale até</label>
                        <input v-model="form.expires_at" type="date" :class="campo" />
                        <InputError :message="form.errors.expires_at" />
                    </div>
                </div>

                <label class="mb-2 flex items-center gap-2 text-sm">
                    <input v-model="form.one_per_customer" type="checkbox" class="h-4 w-4"> Cada cliente usa só 1 vez
                </label>
                <label class="mb-5 flex items-center gap-2 text-sm">
                    <input v-model="form.is_active" type="checkbox" class="h-4 w-4"> Cupom ativo
                </label>

                <div class="flex gap-2">
                    <button type="submit" :disabled="form.processing"
                        class="flex-1 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50">
                        <i class="fas mr-1" :class="form.processing ? 'fa-spinner animate-spin' : 'fa-check'"></i>
                        {{ editandoId ? 'Salvar alterações' : 'Criar cupom' }}
                    </button>
                    <button v-if="editandoId" type="button" class="rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm" @click="cancelar">Cancelar</button>
                </div>
            </form>

            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm xl:col-span-2">
                <h2 class="p-5 pb-0 text-lg font-semibold">Cupons cadastrados</h2>
                <div class="overflow-x-auto p-5">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-[var(--surface-border)] text-left text-xs uppercase text-slate-400">
                                <th class="pb-2 pr-4">Cupom</th>
                                <th class="pb-2 pr-4">Desconto</th>
                                <th class="pb-2 pr-4">Regras</th>
                                <th class="pb-2 pr-4">Usos</th>
                                <th class="pb-2 pr-4">Situação</th>
                                <th class="pb-2 text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="cupons.length === 0">
                                <td colspan="6" class="py-8 text-center text-slate-400">Nenhum cupom ainda. Crie o primeiro ao lado.</td>
                            </tr>
                            <tr v-for="cupom in cupons" :key="cupom.id" class="border-b border-[var(--surface-border)] align-top last:border-0">
                                <td class="py-3 pr-4">
                                    <div class="font-mono font-bold tracking-wide">{{ cupom.code }}</div>
                                    <div v-if="cupom.name" class="text-xs text-slate-400">{{ cupom.name }}</div>
                                </td>
                                <td class="py-3 pr-4 font-medium">{{ cupom.descricao }}</td>
                                <td class="py-3 pr-4 text-xs text-slate-500">
                                    <div v-if="cupom.min_order_value">Mínimo {{ formatPrice(cupom.min_order_value) }}</div>
                                    <div v-if="cupom.starts_at || cupom.expires_at">
                                        {{ cupom.starts_at ? `De ${formatDate(cupom.starts_at)}` : '' }}
                                        {{ cupom.expires_at ? `até ${formatDate(cupom.expires_at)}` : '' }}
                                    </div>
                                    <div v-if="cupom.one_per_customer">1 por cliente</div>
                                    <div v-if="!cupom.min_order_value && !cupom.starts_at && !cupom.expires_at && !cupom.one_per_customer">Sem restrições</div>
                                </td>
                                <td class="py-3 pr-4">{{ cupom.usos }}<span v-if="cupom.max_uses" class="text-slate-400"> / {{ cupom.max_uses }}</span></td>
                                <td class="py-3 pr-4">
                                    <StatusBadge :tone="SITUACAO[cupom.situacao].tone" :label="SITUACAO[cupom.situacao].label" />
                                </td>
                                <td class="py-3">
                                    <div class="flex flex-wrap justify-end gap-1">
                                        <Link :href="`/admin/cupons/${cupom.id}/disparo`" class="rounded-lg bg-primary px-2.5 py-1 text-xs font-medium text-white hover:bg-primary-emphasis" title="Disparar em lote">
                                            <i class="fas fa-paper-plane mr-1"></i>Disparar
                                        </Link>
                                        <button type="button" class="rounded-lg border border-[var(--surface-border)] px-2 py-1 text-xs" title="Copiar link com o cupom" @click="copiarLink(cupom)">
                                            <i class="fas fa-link"></i>
                                        </button>
                                        <button v-if="can('cadastros.edit')" type="button" class="rounded-lg border border-[var(--surface-border)] px-2 py-1 text-xs" title="Editar" @click="editar(cupom)">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                        <button v-if="can('cadastros.edit')" type="button" class="rounded-lg border border-[var(--surface-border)] px-2 py-1 text-xs" :title="cupom.is_active ? 'Desativar' : 'Ativar'" @click="alternar(cupom)">
                                            <i class="fas" :class="cupom.is_active ? 'fa-toggle-on text-emerald-600' : 'fa-toggle-off text-slate-400'"></i>
                                        </button>
                                        <button v-if="can('cadastros.delete')" type="button" class="rounded-lg border border-[var(--surface-border)] px-2 py-1 text-xs text-red-600" title="Excluir" @click="excluir(cupom)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
