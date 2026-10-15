<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Modal from '@/Shared/Modal.vue';
import { maskCpf } from '@/Shared/useMasks';

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

// profileUser.birth_date comes from Laravel's `date` cast serialized as a
// full ISO string ("1990-05-15T00:00:00.000000Z") — work off the YYYY-MM-DD
// slice directly instead of re-parsing it, which sidesteps both invalid-date
// and UTC/local timezone-shift bugs.
const toDateInputValue = (value) => (value ? value.slice(0, 10) : '');

const formatDate = (value) => {
    if (!value) return null;
    const [year, month, day] = toDateInputValue(value).split('-');
    return `${day}/${month}/${year}`;
};

// Nome e e-mail já aparecem no topo do card; aqui ficam só os demais dados.
// O e-mail continua editável no modal.
const fields = computed(() => [
    { label: 'Telefone', value: props.profileUser.phone || null },
    { label: 'CPF', value: props.profileUser.cpf ? maskCpf(props.profileUser.cpf) : null },
    { label: 'Data de nascimento', value: formatDate(props.profileUser.birth_date), wide: true },
]);

const form = useForm({
    name: props.profileUser.name,
    email: props.profileUser.email,
    phone: props.profileUser.phone ?? '',
    cpf: maskCpf(props.profileUser.cpf ?? ''),
    birth_date: toDateInputValue(props.profileUser.birth_date),
});

const onCpfInput = (event) => {
    form.cpf = maskCpf(event.target.value);
};

const editUrl = computed(() => (props.isOwnProfile ? '/perfil' : `/perfil/usuario/${props.profileUser.id}`));

const submit = () => {
    form.put(editUrl.value, { preserveScroll: true, onSuccess: closeModal });
};

const inputClass = 'mt-1 w-full rounded-lg border border-store-border-strong bg-store-bg-raised px-3 py-2 text-sm focus:border-store-accent focus:outline-none focus:ring-1 focus:ring-store-accent';
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
            <h2 class="text-lg font-semibold">Dados pessoais</h2>
            <button type="button" class="shrink-0 rounded-full border border-store-border-strong px-4 py-2 text-sm font-medium text-store-fg hover:bg-store-bg-sunken"
                @click="openModal">
                <i class="fas fa-pen mr-2 text-xs" aria-hidden="true"></i>Editar
            </button>
        </div>
        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div v-for="field in fields" :key="field.label" :class="{ 'sm:col-span-2': field.wide }">
                <dt class="mb-1 text-xs text-store-fg-muted">{{ field.label }}</dt>
                <dd v-if="field.value" class="break-words text-sm font-medium">{{ field.value }}</dd>
                <dd v-else class="text-sm text-store-fg-muted">Não informado</dd>
            </div>
        </dl>
    </div>

    <Modal :open="isOpen" @close="closeModal">
        <h2 class="mb-1 text-xl font-semibold">Editar dados pessoais</h2>
        <p class="mb-6 text-sm text-store-fg-muted">Atualize as informações para manter o perfil em dia.</p>

        <form class="grid grid-cols-1 gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <div class="sm:col-span-2">
                <label for="perfil-nome" class="text-sm font-medium">Nome completo</label>
                <input id="perfil-nome" v-model="form.name" type="text" required autocomplete="name" :class="inputClass">
                <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
            </div>
            <div class="sm:col-span-2">
                <label for="perfil-email" class="text-sm font-medium">E-mail</label>
                <input id="perfil-email" v-model="form.email" type="email" required autocomplete="email" :class="inputClass">
                <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p>
            </div>
            <div>
                <label for="perfil-telefone" class="text-sm font-medium">Telefone</label>
                <input id="perfil-telefone" v-model="form.phone" type="tel" autocomplete="tel" placeholder="(11) 91234-5678" :class="inputClass">
                <p v-if="form.errors.phone" class="mt-1 text-xs text-red-600">{{ form.errors.phone }}</p>
            </div>
            <div>
                <label for="perfil-cpf" class="text-sm font-medium">CPF</label>
                <input id="perfil-cpf" :value="form.cpf" type="text" inputmode="numeric" maxlength="14" autocomplete="off" placeholder="000.000.000-00" :class="inputClass" @input="onCpfInput">
                <p v-if="form.errors.cpf" class="mt-1 text-xs text-red-600">{{ form.errors.cpf }}</p>
            </div>
            <div class="sm:col-span-2">
                <label for="perfil-nascimento" class="text-sm font-medium">Data de nascimento</label>
                <input id="perfil-nascimento" v-model="form.birth_date" type="date" autocomplete="bday" :class="inputClass">
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
