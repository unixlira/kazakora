<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Modal from '@/Shared/Modal.vue';

const props = defineProps({
    profileUser: {
        type: Object,
        required: true,
    },
    isOwnProfile: {
        type: Boolean,
        required: true,
    },
});

const isOpen = ref(false);
const openModal = () => (isOpen.value = true);
const closeModal = () => (isOpen.value = false);

const formatDate = (value) => (value ? new Date(`${value}T00:00:00`).toLocaleDateString('pt-BR') : '—');

const fields = computed(() => [
    { label: 'Nome completo', value: props.profileUser.name },
    { label: 'E-mail', value: props.profileUser.email },
    { label: 'Telefone', value: props.profileUser.phone || '—' },
    { label: 'CPF', value: props.profileUser.cpf || '—' },
    { label: 'Data de nascimento', value: formatDate(props.profileUser.birth_date) },
]);

const form = useForm({
    name: props.profileUser.name,
    email: props.profileUser.email,
    phone: props.profileUser.phone ?? '',
    cpf: props.profileUser.cpf ?? '',
    birth_date: props.profileUser.birth_date ?? '',
});

const editUrl = computed(() => (props.isOwnProfile ? '/perfil' : `/perfil/usuario/${props.profileUser.id}`));

const submit = () => {
    form.put(editUrl.value, { preserveScroll: true, onSuccess: closeModal });
};

const inputClass = 'mt-1 w-full rounded-lg border border-store-border-strong bg-store-bg-raised px-3 py-2 text-sm focus:border-store-accent focus:outline-none focus:ring-1 focus:ring-store-accent';
</script>

<template>
    <div class="rounded-2xl border border-store-border bg-store-bg-raised p-6">
        <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <h4 class="mb-4 text-lg font-semibold">Dados pessoais</h4>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:gap-x-16">
                    <div v-for="field in fields" :key="field.label">
                        <p class="mb-1 text-xs text-store-fg-muted">{{ field.label }}</p>
                        <p class="text-sm font-medium">{{ field.value }}</p>
                    </div>
                </div>
            </div>

            <button type="button" class="w-full shrink-0 rounded-full border border-store-border-strong px-4 py-2.5 text-sm font-medium text-store-fg hover:bg-store-bg-sunken lg:w-auto"
                @click="openModal">
                <i class="fas fa-pen mr-2 text-xs"></i>Editar
            </button>
        </div>
    </div>

    <Modal :open="isOpen" @close="closeModal">
        <h4 class="mb-1 text-xl font-semibold">Editar dados pessoais</h4>
        <p class="mb-6 text-sm text-store-fg-muted">Atualize as informações para manter o perfil em dia.</p>

        <form class="grid grid-cols-1 gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <div class="sm:col-span-2">
                <label class="text-sm font-medium">Nome completo</label>
                <input v-model="form.name" type="text" required :class="inputClass">
                <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
            </div>
            <div class="sm:col-span-2">
                <label class="text-sm font-medium">E-mail</label>
                <input v-model="form.email" type="email" required :class="inputClass">
                <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p>
            </div>
            <div>
                <label class="text-sm font-medium">Telefone</label>
                <input v-model="form.phone" type="text" placeholder="(11) 91234-5678" :class="inputClass">
                <p v-if="form.errors.phone" class="mt-1 text-xs text-red-600">{{ form.errors.phone }}</p>
            </div>
            <div>
                <label class="text-sm font-medium">CPF</label>
                <input v-model="form.cpf" type="text" placeholder="000.000.000-00" :class="inputClass">
                <p v-if="form.errors.cpf" class="mt-1 text-xs text-red-600">{{ form.errors.cpf }}</p>
            </div>
            <div class="sm:col-span-2">
                <label class="text-sm font-medium">Data de nascimento</label>
                <input v-model="form.birth_date" type="date" :class="inputClass">
                <p v-if="form.errors.birth_date" class="mt-1 text-xs text-red-600">{{ form.errors.birth_date }}</p>
            </div>

            <div class="flex items-center justify-end gap-3 sm:col-span-2">
                <button type="button" class="rounded-full border border-store-border-strong px-5 py-2 text-sm font-medium hover:bg-store-bg-sunken" @click="closeModal">Cancelar</button>
                <button type="submit" :disabled="form.processing"
                    class="rounded-full bg-store-accent px-5 py-2 text-sm font-semibold text-store-accent-contrast hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50">
                    Salvar alterações
                </button>
            </div>
        </form>
    </Modal>
</template>
