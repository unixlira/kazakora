<script setup>
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
    <div class="rounded-2xl border border-store-border bg-store-bg-raised p-6">
        <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <h4 class="mb-4 text-lg font-semibold">Segurança</h4>
                <div>
                    <p class="mb-1 text-xs text-store-fg-muted">Senha</p>
                    <p class="font-store-mono text-sm font-medium">••••••••</p>
                </div>
            </div>

            <button type="button" class="w-full shrink-0 rounded-full border border-store-border-strong px-4 py-2.5 text-sm font-medium text-store-fg hover:bg-store-bg-sunken lg:w-auto"
                @click="openModal">
                <i class="fas fa-pen mr-2 text-xs"></i>Alterar senha
            </button>
        </div>
    </div>

    <Modal :open="isOpen" @close="closeModal">
        <h4 class="mb-1 text-xl font-semibold">Alterar senha</h4>
        <p class="mb-6 text-sm text-store-fg-muted">Escolha uma nova senha com pelo menos 8 caracteres.</p>

        <form class="grid grid-cols-1 gap-4" @submit.prevent="submit">
            <div>
                <label class="text-sm font-medium">Nova senha</label>
                <input v-model="form.password" type="password" required minlength="8" :class="inputClass">
                <p v-if="form.errors.password" class="mt-1 text-xs text-red-600">{{ form.errors.password }}</p>
            </div>
            <div>
                <label class="text-sm font-medium">Confirmar nova senha</label>
                <input v-model="form.password_confirmation" type="password" required minlength="8" :class="inputClass">
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
