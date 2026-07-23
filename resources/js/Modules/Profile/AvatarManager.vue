<script setup>
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { confirmDelete, notifyError } from '@/Shared/notify';

const props = defineProps({
    profileUser: {
        type: Object,
        required: true,
    },
});

const form = useForm({ avatar: null });
const fileInput = ref(null);
const previewUrl = ref(null);

const onFileSelect = (event) => {
    const file = event.target.files[0];
    if (!file) return;

    if (!['image/jpeg', 'image/jpg', 'image/png', 'image/webp'].includes(file.type)) {
        notifyError('Envie uma imagem JPG, JPEG, PNG ou WEBP.');
        event.target.value = '';
        return;
    }

    if (file.size > 2 * 1024 * 1024) {
        notifyError('A imagem precisa ter no máximo 2MB.');
        event.target.value = '';
        return;
    }

    previewUrl.value = URL.createObjectURL(file);

    form.transform((data) => ({ ...data, avatar: file })).post('/perfil/avatar', {
        preserveScroll: true,
        onFinish: () => {
            previewUrl.value = null;
            event.target.value = '';
        },
    });
};

const removeAvatar = async () => {
    if (await confirmDelete({ text: 'A foto de perfil será removida.' })) {
        router.delete('/perfil/avatar', { preserveScroll: true });
    }
};
</script>

<template>
    <div class="flex items-center gap-4">
        <img v-if="previewUrl || profileUser.avatar_url" :src="previewUrl || profileUser.avatar_url"
            class="h-22 w-22 rounded-full object-cover" alt="">
        <span v-else class="flex h-22 w-22 items-center justify-center rounded-full bg-store-accent-soft text-2xl font-semibold text-store-accent-strong">
            {{ profileUser.initials }}
        </span>

        <div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="rounded-full border border-store-border-strong px-4 py-1.5 text-sm font-medium text-store-fg hover:bg-store-bg-sunken disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="form.processing" @click="fileInput.click()">
                    <i class="fas fa-camera mr-2"></i>{{ form.processing ? 'Enviando...' : 'Trocar foto' }}
                </button>
                <button v-if="profileUser.avatar_url" type="button" class="rounded-full border border-store-border-strong px-4 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50"
                    @click="removeAvatar">
                    Remover
                </button>
            </div>
            <p class="mt-2 text-sm text-store-fg-muted">JPG, JPEG, PNG ou WEBP, até 2MB.</p>
            <input ref="fileInput" type="file" accept="image/jpeg,image/jpg,image/png,image/webp" class="hidden" @change="onFileSelect">
        </div>
    </div>
</template>
