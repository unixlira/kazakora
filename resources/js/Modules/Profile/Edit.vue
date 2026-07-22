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
</script>

<template>
    <Head title="Meu Perfil" />

    <AppLayout>
        <div class="container-fluid py-5">
            <div class="container" style="max-width: 760px;">
                <h1 class="display-6 mb-4">Meu Perfil</h1>

                <!-- Card de resumo -->
                <div class="bg-white rounded shadow-sm p-4 mb-4">
                    <AvatarManager :profile-user="profileUser" />
                    <hr class="my-4">
                    <p class="mb-1"><strong>{{ profileUser.name }}</strong></p>
                    <p class="mb-0 text-muted">{{ profileUser.email }}</p>
                </div>

                <!-- Dados pessoais -->
                <div class="bg-white rounded shadow-sm p-4 mb-4">
                    <h2 class="h5 mb-4">Dados pessoais</h2>

                    <form @submit.prevent="submitProfile">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Nome completo</label>
                                <input v-model="profileForm.name" type="text" class="form-control" required>
                                <div v-if="profileForm.errors.name" class="text-danger small mt-1">{{ profileForm.errors.name }}</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">E-mail</label>
                                <input v-model="profileForm.email" type="email" class="form-control" required>
                                <div v-if="profileForm.errors.email" class="text-danger small mt-1">{{ profileForm.errors.email }}</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Telefone</label>
                                <input v-model="profileForm.phone" type="text" class="form-control" placeholder="(11) 91234-5678">
                                <div v-if="profileForm.errors.phone" class="text-danger small mt-1">{{ profileForm.errors.phone }}</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">CPF</label>
                                <input v-model="profileForm.cpf" type="text" class="form-control" placeholder="000.000.000-00">
                                <div v-if="profileForm.errors.cpf" class="text-danger small mt-1">{{ profileForm.errors.cpf }}</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Data de nascimento</label>
                                <input v-model="profileForm.birth_date" type="date" class="form-control">
                                <div v-if="profileForm.errors.birth_date" class="text-danger small mt-1">{{ profileForm.errors.birth_date }}</div>
                            </div>
                        </div>

                        <button type="submit" :disabled="profileForm.processing"
                            class="btn btn-primary rounded-pill px-4 py-2 mt-4">
                            Salvar alterações
                        </button>
                    </form>
                </div>

                <!-- Senha -->
                <div class="bg-white rounded shadow-sm p-4">
                    <h2 class="h5 mb-1">Alterar senha</h2>
                    <p class="text-muted small mb-4">Escolha uma nova senha com pelo menos 8 caracteres.</p>

                    <form @submit.prevent="submitPassword">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Nova senha</label>
                                <input v-model="passwordForm.password" type="password" class="form-control" required minlength="8">
                                <div v-if="passwordForm.errors.password" class="text-danger small mt-1">{{ passwordForm.errors.password }}</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Confirmar nova senha</label>
                                <input v-model="passwordForm.password_confirmation" type="password" class="form-control" required minlength="8">
                            </div>
                        </div>

                        <button type="submit" :disabled="passwordForm.processing"
                            class="btn btn-outline-primary rounded-pill px-4 py-2 mt-4">
                            Alterar senha
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
