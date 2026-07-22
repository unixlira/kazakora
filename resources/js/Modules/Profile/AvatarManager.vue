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
    <div class="d-flex align-items-center gap-4">
        <img v-if="previewUrl || profileUser.avatar_url" :src="previewUrl || profileUser.avatar_url"
            class="rounded-circle" style="width:88px;height:88px;object-fit:cover;" alt="">
        <span v-else class="rounded-circle bg-secondary text-white d-inline-flex align-items-center justify-content-center"
            style="width:88px;height:88px;font-size:1.75rem;">
            {{ profileUser.initials }}
        </span>

        <div>
            <button type="button" class="btn btn-outline-primary btn-sm rounded-pill" :disabled="form.processing"
                @click="fileInput.click()">
                <i class="fas fa-camera me-2"></i>{{ form.processing ? 'Enviando...' : 'Trocar foto' }}
            </button>
            <button v-if="profileUser.avatar_url" type="button" class="btn btn-outline-danger btn-sm rounded-pill ms-2"
                @click="removeAvatar">
                Remover
            </button>
            <p class="mt-2 mb-0 text-muted small">JPG, JPEG, PNG ou WEBP, até 2MB.</p>
            <input ref="fileInput" type="file" accept="image/jpeg,image/jpg,image/png,image/webp" class="d-none" @change="onFileSelect">
        </div>
    </div>
</template>
