<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    company: {
        type: Object,
        default: null,
    },
    regimes: {
        type: Array,
        default: () => [],
    },
});

const regimeLabels = {
    mei: 'MEI',
    simples_nacional: 'Simples Nacional',
    lucro_presumido: 'Lucro Presumido',
    lucro_real: 'Lucro Real',
};

const form = useForm({
    razao_social: props.company?.razao_social ?? '',
    nome_fantasia: props.company?.nome_fantasia ?? '',
    cnpj: props.company?.cnpj ?? '',
    inscricao_estadual: props.company?.inscricao_estadual ?? '',
    inscricao_municipal: props.company?.inscricao_municipal ?? '',
    regime_tributario: props.company?.regime_tributario ?? 'simples_nacional',
    cnae: props.company?.cnae ?? '',
    phone: props.company?.phone ?? '',
    email: props.company?.email ?? '',
    zip: props.company?.zip ?? '',
    street: props.company?.street ?? '',
    number: props.company?.number ?? '',
    complement: props.company?.complement ?? '',
    neighborhood: props.company?.neighborhood ?? '',
    city: props.company?.city ?? '',
    state: props.company?.state ?? '',
});

const submit = () => {
    form.put('/admin/empresa');
};
</script>

<template>
    <Head title="Dados da empresa" />

    <AdminLayout>
        <h1 class="text-2xl font-bold text-slate-700">Dados da empresa</h1>
        <p class="mt-1 text-sm text-slate-500">
            Usados na emissão de notas fiscais e no cadastro em marketplaces.
        </p>

        <form class="mt-6 max-w-3xl space-y-6 rounded bg-white p-6 shadow-lg" @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-slate-600">Razão social</label>
                    <input v-model="form.razao_social" type="text" class="mt-1 w-full rounded border border-slate-300 px-3 py-2" required>
                    <InputError :message="form.errors.razao_social" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600">Nome fantasia</label>
                    <input v-model="form.nome_fantasia" type="text" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    <InputError :message="form.errors.nome_fantasia" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600">CNPJ</label>
                    <input v-model="form.cnpj" type="text" class="mt-1 w-full rounded border border-slate-300 px-3 py-2" required>
                    <InputError :message="form.errors.cnpj" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600">Regime tributário</label>
                    <select v-model="form.regime_tributario" class="mt-1 w-full rounded border border-slate-300 px-3 py-2" required>
                        <option v-for="regime in regimes" :key="regime" :value="regime">{{ regimeLabels[regime] ?? regime }}</option>
                    </select>
                    <InputError :message="form.errors.regime_tributario" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600">Inscrição estadual</label>
                    <input v-model="form.inscricao_estadual" type="text" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    <InputError :message="form.errors.inscricao_estadual" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600">Inscrição municipal</label>
                    <input v-model="form.inscricao_municipal" type="text" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    <InputError :message="form.errors.inscricao_municipal" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600">CNAE</label>
                    <input v-model="form.cnae" type="text" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    <InputError :message="form.errors.cnae" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600">Telefone</label>
                    <input v-model="form.phone" type="text" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600">E-mail</label>
                    <input v-model="form.email" type="email" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    <InputError :message="form.errors.email" />
                </div>
            </div>

            <hr class="border-slate-200">

            <h2 class="text-sm font-semibold uppercase text-slate-500">Endereço fiscal</h2>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div>
                    <label class="block text-sm font-medium text-slate-600">CEP</label>
                    <input v-model="form.zip" type="text" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-slate-600">Rua</label>
                    <input v-model="form.street" type="text" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600">Número</label>
                    <input v-model="form.number" type="text" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600">Complemento</label>
                    <input v-model="form.complement" type="text" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600">Bairro</label>
                    <input v-model="form.neighborhood" type="text" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600">Cidade</label>
                    <input v-model="form.city" type="text" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600">UF</label>
                    <input v-model="form.state" type="text" maxlength="2" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 uppercase">
                </div>
            </div>

            <button type="submit" :disabled="form.processing"
                class="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-slate-300">
                Salvar dados da empresa
            </button>
        </form>
    </AdminLayout>
</template>
