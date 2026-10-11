<script setup>
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { confirmDelete, notifyError } from '@/Shared/notify';

const props = defineProps({
    profileUser: {
        type: Object,
        required: true,
    },
    editable: {
        type: Boolean,
        default: true,
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
    <div class="relative h-20 w-20 shrink-0">
        <img v-if="previewUrl || profileUser.avatar_url" :src="previewUrl || profileUser.avatar_url"
            class="h-20 w-20 rounded-full border border-store-border object-cover" alt="">
        <span v-else class="flex h-20 w-20 items-center justify-center rounded-full border border-store-border bg-store-accent-soft text-xl font-semibold text-store-accent-strong">
            {{ profileUser.initials }}
        </span>

        <template v-if="editable">
            <button type="button" class="absolute -bottom-1 -right-1 flex h-7 w-7 items-center justify-center rounded-full border border-store-border-strong bg-store-bg-raised text-store-fg-muted shadow-sm hover:text-store-accent disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="form.processing" aria-label="Trocar foto" title="JPG, PNG ou WEBP, até 2MB" @click="fileInput.click()">
                <i class="fas fa-camera text-xs"></i>
            </button>
            <button v-if="profileUser.avatar_url" type="button" class="absolute -top-1 -right-1 flex h-6 w-6 items-center justify-center rounded-full border border-store-border-strong bg-store-bg-raised text-red-500 shadow-sm hover:bg-red-50"
                aria-label="Remover foto" @click="removeAvatar">
                <i class="fas fa-xmark text-[10px]"></i>
            </button>
            <input ref="fileInput" type="file" accept="image/jpeg,image/jpg,image/png,image/webp" class="hidden" @change="onFileSelect">
        </template>
    </div>
</template>
