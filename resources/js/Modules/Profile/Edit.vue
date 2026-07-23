<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import AvatarManager from '@/Modules/Profile/AvatarManager.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    profileUser: {
        type: Object,
        required: true,
    },
});

const profileForm = useForm({
    name: props.profileUser.name,
    email: props.profileUser.email,
    phone: props.profileUser.phone ?? '',
    cpf: props.profileUser.cpf ?? '',
    birth_date: props.profileUser.birth_date ?? '',
});

const passwordForm = useForm({
    password: '',
    password_confirmation: '',
});

const submitProfile = () => {
    profileForm.put('/perfil', { preserveScroll: true });
};

const submitPassword = () => {
    passwordForm.put('/perfil/senha', {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
    });
};

const inputClass = 'mt-1 w-full rounded-lg border border-store-border-strong bg-store-bg-raised px-3 py-2 text-sm focus:border-store-accent focus:outline-none focus:ring-1 focus:ring-store-accent';
</script>

<template>
    <Head title="Meu Perfil" />

    <AppLayout>
        <div class="mx-auto max-w-[760px] px-4 py-12 md:px-6">
            <h1 class="font-display text-3xl font-semibold">Meu Perfil</h1>

            <!-- Card de resumo -->
            <div class="mt-8 rounded-2xl border border-store-border bg-store-bg-raised p-6">
                <AvatarManager :profile-user="profileUser" />
                <hr class="my-5 border-store-border">
                <p class="font-medium">{{ profileUser.name }}</p>
                <p class="text-sm text-store-fg-muted">{{ profileUser.email }}</p>
            </div>

            <!-- Dados pessoais -->
            <div class="mt-6 rounded-2xl border border-store-border bg-store-bg-raised p-6">
                <h2 class="mb-4 text-lg font-semibold">Dados pessoais</h2>

                <form class="grid grid-cols-1 gap-4 sm:grid-cols-2" @submit.prevent="submitProfile">
                    <div>
                        <label class="text-sm font-medium">Nome completo</label>
                        <input v-model="profileForm.name" type="text" required :class="inputClass">
                        <p v-if="profileForm.errors.name" class="mt-1 text-xs text-red-600">{{ profileForm.errors.name }}</p>
                    </div>
                    <div>
                        <label class="text-sm font-medium">E-mail</label>
                        <input v-model="profileForm.email" type="email" required :class="inputClass">
                        <p v-if="profileForm.errors.email" class="mt-1 text-xs text-red-600">{{ profileForm.errors.email }}</p>
                    </div>
                    <div>
                        <label class="text-sm font-medium">Telefone</label>
                        <input v-model="profileForm.phone" type="text" placeholder="(11) 91234-5678" :class="inputClass">
                        <p v-if="profileForm.errors.phone" class="mt-1 text-xs text-red-600">{{ profileForm.errors.phone }}</p>
                    </div>
                    <div>
                        <label class="text-sm font-medium">CPF</label>
                        <input v-model="profileForm.cpf" type="text" placeholder="000.000.000-00" :class="inputClass">
                        <p v-if="profileForm.errors.cpf" class="mt-1 text-xs text-red-600">{{ profileForm.errors.cpf }}</p>
                    </div>
                    <div>
                        <label class="text-sm font-medium">Data de nascimento</label>
                        <input v-model="profileForm.birth_date" type="date" :class="inputClass">
                        <p v-if="profileForm.errors.birth_date" class="mt-1 text-xs text-red-600">{{ profileForm.errors.birth_date }}</p>
                    </div>

                    <button type="submit" :disabled="profileForm.processing"
                        class="mt-2 rounded-lg bg-store-accent px-6 py-3 text-sm font-semibold text-store-accent-contrast hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50 sm:col-span-2 sm:w-fit">
                        Salvar alterações
                    </button>
                </form>
            </div>

            <!-- Senha -->
            <div class="mt-6 rounded-2xl border border-store-border bg-store-bg-raised p-6">
                <h2 class="text-lg font-semibold">Alterar senha</h2>
                <p class="mb-4 text-sm text-store-fg-muted">Escolha uma nova senha com pelo menos 8 caracteres.</p>

                <form class="grid grid-cols-1 gap-4 sm:grid-cols-2" @submit.prevent="submitPassword">
                    <div>
                        <label class="text-sm font-medium">Nova senha</label>
                        <input v-model="passwordForm.password" type="password" required minlength="8" :class="inputClass">
                        <p v-if="passwordForm.errors.password" class="mt-1 text-xs text-red-600">{{ passwordForm.errors.password }}</p>
                    </div>
                    <div>
                        <label class="text-sm font-medium">Confirmar nova senha</label>
                        <input v-model="passwordForm.password_confirmation" type="password" required minlength="8" :class="inputClass">
                    </div>

                    <button type="submit" :disabled="passwordForm.processing"
                        class="mt-2 rounded-lg border border-store-border-strong px-6 py-3 text-sm font-semibold text-store-fg hover:bg-store-bg-sunken disabled:cursor-not-allowed disabled:opacity-50 sm:col-span-2 sm:w-fit">
                        Alterar senha
                    </button>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
