<script setup>
import CampoSenha from '@/Shared/Components/CampoSenha.vue';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Modal from '@/Shared/Modal.vue';

const isOpen = ref(false);
const openModal = () => (isOpen.value = true);
const closeModal = () => (isOpen.value = false);

const form = useForm({
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.put('/perfil/senha', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            closeModal();
        },
    });
};

const inputClass = 'mt-1 w-full rounded-lg border border-store-border-strong bg-store-bg-raised px-3 py-2 text-sm focus:border-store-accent focus:outline-none focus:ring-1 focus:ring-store-accent';
</script>

<template>
    <div>
        <div class="flex flex-col gap-6 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="mb-1 text-lg font-semibold">Segurança</h2>
                <p class="mb-4 text-sm text-store-fg-muted">Troque a senha sempre que achar que alguém pode ter acesso a ela.</p>
                <div>
                    <p class="mb-1 text-xs text-store-fg-muted">Senha</p>
                    <p class="font-store-mono text-sm font-medium">••••••••</p>
                </div>
            </div>

            <button type="button" class="w-full shrink-0 rounded-full border border-store-border-strong px-4 py-2.5 text-sm font-medium text-store-fg hover:bg-store-bg-sunken sm:w-auto"
                @click="openModal">
                <i class="fas fa-pen mr-2 text-xs" aria-hidden="true"></i>Alterar senha
            </button>
        </div>
    </div>

    <Modal :open="isOpen" @close="closeModal">
        <h2 class="mb-1 text-xl font-semibold">Alterar senha</h2>
        <p class="mb-6 text-sm text-store-fg-muted">Escolha uma nova senha com pelo menos 8 caracteres.</p>

        <form class="grid grid-cols-1 gap-4" @submit.prevent="submit">
            <div>
                <label for="senha-nova" class="text-sm font-medium">Nova senha</label>
                <CampoSenha id="senha-nova" v-model="form.password" required minlength="8" autocomplete="new-password" :class="inputClass" />
                <p v-if="form.errors.password" class="mt-1 text-xs text-red-600">{{ form.errors.password }}</p>
            </div>
            <div>
                <label for="senha-confirmar" class="text-sm font-medium">Confirmar nova senha</label>
                <CampoSenha id="senha-confirmar" v-model="form.password_confirmation" required minlength="8" autocomplete="new-password" :class="inputClass" />
                <p v-if="form.errors.password_confirmation" class="mt-1 text-xs text-red-600">{{ form.errors.password_confirmation }}</p>
            </div>

            <div class="flex items-center justify-end gap-3">
                <button type="button" class="rounded-full border border-store-border-strong px-5 py-2 text-sm font-medium hover:bg-store-bg-sunken" @click="closeModal">Cancelar</button>
                <button type="submit" :disabled="form.processing"
                    class="rounded-full bg-store-accent px-5 py-2 text-sm font-semibold text-store-accent-contrast hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50">
                    Alterar senha
                </button>
            </div>
        </form>
    </Modal>
</template>
