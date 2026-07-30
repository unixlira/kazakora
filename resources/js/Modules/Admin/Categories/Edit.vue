<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    category: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    name: props.category.name,
    description: props.category.description ?? '',
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
    form.put(`/admin/categorias/${props.category.id}`);
};
</script>

<template>
    <Head title="Editar categoria" />

    <AdminLayout>
        <h1 class="text-2xl font-bold">Editar categoria</h1>

        <form class="mt-6 max-w-xl space-y-4" enctype="multipart/form-data" @submit.prevent="submit">
            <div>
                <label for="name" class="block text-sm font-medium">Nome</label>
                <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    required
                    class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
                >
                <InputError :message="form.errors.name" />
            </div>

            <div>
                <label for="description" class="block text-sm font-medium">Descrição</label>
                <textarea
                    id="description"
                    v-model="form.description"
                    rows="4"
                    class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
                />
                <InputError :message="form.errors.description" />
            </div>

            <div>
                <span class="block text-sm font-medium">Imagem (opcional)</span>
                <p class="mb-1 text-xs text-gray-500">Aparece em formato circular na home da loja.</p>
                <div class="mt-1 flex h-28 w-28 cursor-pointer items-center justify-center overflow-hidden rounded-full border-2 border-dashed border-gray-300 text-center hover:border-gray-400"
                    @click="fileInput.click()">
                    <img v-if="preview ?? category.image_url" :src="preview ?? category.image_url" class="h-full w-full object-cover">
                    <i v-else class="fas fa-image text-2xl text-gray-400"></i>
                    <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="onFileSelect">
                </div>
                <InputError :message="form.errors.image" />
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="rounded bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:cursor-not-allowed disabled:bg-gray-300"
            >
                Salvar alterações
            </button>
        </form>
    </AdminLayout>
</template>
