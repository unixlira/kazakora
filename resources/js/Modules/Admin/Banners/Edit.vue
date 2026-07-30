<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    banner: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    title: props.banner.title ?? '',
    link_url: props.banner.link_url ?? '',
    is_active: props.banner.is_active,
    image: null,
});

const preview = ref(null);
const fileInput = ref(null);

const onFileSelect = (event) => {
    const file = event.target.files[0] ?? null;
    form.image = file;
    preview.value = file ? URL.createObjectURL(file) : null;
};

const submit = () => {
    form.put(`/admin/banners/${props.banner.id}`);
};
</script>

<template>
    <Head title="Editar banner" />

    <AdminLayout>
        <h1 class="text-2xl font-bold">Editar banner</h1>

        <form class="mt-6 max-w-xl space-y-4" enctype="multipart/form-data" @submit.prevent="submit">
            <div>
                <label for="title" class="block text-sm font-medium">Título (opcional)</label>
                <input id="title" v-model="form.title" type="text" class="mt-1 w-full rounded border border-gray-300 px-3 py-2">
                <InputError :message="form.errors.title" />
            </div>

            <div>
                <label for="link_url" class="block text-sm font-medium">Link ao clicar (opcional)</label>
                <input id="link_url" v-model="form.link_url" type="text" placeholder="/produtos ou https://..."
                    class="mt-1 w-full rounded border border-gray-300 px-3 py-2">
                <InputError :message="form.errors.link_url" />
            </div>

            <div>
                <span class="block text-sm font-medium">Imagem</span>
                <div class="mt-1 flex cursor-pointer flex-col items-center justify-center rounded border-2 border-dashed border-gray-300 px-6 py-10 text-center hover:border-gray-400"
                    @click="fileInput.click()">
                    <img :src="preview ?? banner.image_url" class="mb-2 h-24 rounded object-cover">
                    <p class="text-sm text-gray-500">Clique para substituir a imagem do banner</p>
                    <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="onFileSelect">
                </div>
                <InputError :message="form.errors.image" />
            </div>

            <label class="flex items-center gap-2 text-sm font-medium">
                <input v-model="form.is_active" type="checkbox" class="rounded border-gray-300">
                Ativo
            </label>

            <button type="submit" :disabled="form.processing"
                class="rounded bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:cursor-not-allowed disabled:bg-gray-300">
                Salvar alterações
            </button>
        </form>
    </AdminLayout>
</template>
