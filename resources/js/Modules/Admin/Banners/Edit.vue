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
    image_mobile: null,
});

const desktopPreview = ref(null);
const mobilePreview = ref(null);
const desktopInput = ref(null);
const mobileInput = ref(null);

const onSelectDesktop = (event) => {
    const file = event.target.files[0] ?? null;
    form.image = file;
    desktopPreview.value = file ? URL.createObjectURL(file) : null;
};

const onSelectMobile = (event) => {
    const file = event.target.files[0] ?? null;
    form.image_mobile = file;
    mobilePreview.value = file ? URL.createObjectURL(file) : null;
};

const submit = () => {
    form.put(`/admin/banners/${props.banner.id}`);
};
</script>

<template>
    <Head title="Editar banner" />

    <AdminLayout>
        <h1 class="text-2xl font-bold">Editar banner</h1>

        <form class="mt-6 max-w-2xl space-y-4" enctype="multipart/form-data" @submit.prevent="submit">
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

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <span class="block text-sm font-medium">Imagem do site (desktop)</span>
                    <p class="mb-1 text-xs text-gray-500">Imagem larga (paisagem) usada em telas de computador.</p>
                    <div class="mt-1 flex aspect-[21/9] cursor-pointer flex-col items-center justify-center rounded border-2 border-dashed border-gray-300 px-4 py-6 text-center hover:border-gray-400"
                        @click="desktopInput.click()">
                        <img :src="desktopPreview ?? banner.image_url" class="h-full w-full rounded object-cover">
                        <input ref="desktopInput" type="file" accept="image/*" class="hidden" @change="onSelectDesktop">
                    </div>
                    <InputError :message="form.errors.image" />
                </div>

                <div>
                    <span class="block text-sm font-medium">Imagem mobile (opcional)</span>
                    <p class="mb-1 text-xs text-gray-500">Imagem em pé (retrato/quadrada) usada em celular. Sem ela, usa a do site.</p>
                    <div class="mt-1 flex aspect-[21/9] cursor-pointer flex-col items-center justify-center rounded border-2 border-dashed border-gray-300 px-4 py-6 text-center hover:border-gray-400"
                        @click="mobileInput.click()">
                        <img v-if="mobilePreview ?? banner.image_url_mobile" :src="mobilePreview ?? banner.image_url_mobile" class="h-full w-full rounded object-cover">
                        <p v-else class="text-sm text-gray-500">Clique para adicionar</p>
                        <input ref="mobileInput" type="file" accept="image/*" class="hidden" @change="onSelectMobile">
                    </div>
                    <InputError :message="form.errors.image_mobile" />
                </div>
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
