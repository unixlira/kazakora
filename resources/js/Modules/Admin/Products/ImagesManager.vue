<script setup>
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { confirmDelete, notifyError } from '@/Shared/notify';

const props = defineProps({
    product: {
        type: Object,
        required: true,
    },
    images: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({ image: null });
const isDragging = ref(false);
const uploadingCount = ref(0);
const fileInput = ref(null);

const uploadFiles = (files) => {
    const list = Array.from(files).filter((file) => file.type.startsWith('image/'));

    if (list.length === 0) {
        notifyError('Selecione apenas arquivos de imagem.');
        return;
    }

    uploadNext(list, 0);
};

const uploadNext = (files, index) => {
    if (index >= files.length) return;

    uploadingCount.value += 1;
    const file = files[index];

    form.transform((data) => ({ ...data, image: file })).post(
        `/admin/produtos/${props.product.id}/imagens`,
        {
            preserveScroll: true,
            onError: (errors) => {
                notifyError(errors.image ?? `Falha ao enviar "${file.name}". Tente novamente.`);
            },
            onFinish: () => {
                uploadingCount.value -= 1;
                uploadNext(files, index + 1);
            },
        },
    );
};

const onDrop = (event) => {
    isDragging.value = false;
    uploadFiles(event.dataTransfer.files);
};

const onFileSelect = (event) => {
    uploadFiles(event.target.files);
    event.target.value = '';
};

const remove = async (image) => {
    if (await confirmDelete({ text: 'Essa foto será removida permanentemente.' })) {
        router.delete(`/admin/produtos/${props.product.id}/imagens/${image.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap gap-4">
            <div v-for="image in images" :key="image.id" class="relative h-28 w-28 overflow-hidden rounded border border-slate-200">
                <img :src="image.url" class="h-full w-full object-cover" :alt="product.name">
                <span v-if="image.is_primary" class="absolute left-1 top-1 rounded bg-emerald-600 px-1.5 py-0.5 text-[10px] font-semibold text-white">
                    Principal
                </span>
                <button type="button" class="absolute right-1 top-1 flex h-6 w-6 items-center justify-center rounded-full bg-white/90 text-red-500 shadow"
                    @click="remove(image)">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>

        <div class="flex cursor-pointer flex-col items-center justify-center rounded border-2 border-dashed px-6 py-10 text-center transition-colors"
            :class="isDragging ? 'border-emerald-500 bg-emerald-50' : 'border-slate-300 hover:border-emerald-400'"
            @click="fileInput.click()"
            @dragover.prevent="isDragging = true"
            @dragleave.prevent="isDragging = false"
            @drop.prevent="onDrop">
            <i class="fas fa-cloud-upload-alt mb-2 text-2xl text-slate-400"></i>
            <p class="text-sm text-slate-500">
                <span v-if="uploadingCount > 0">Enviando {{ uploadingCount }} foto(s)...</span>
                <span v-else>Arraste fotos aqui ou clique para selecionar (múltiplas ao mesmo tempo)</span>
            </p>
            <input ref="fileInput" type="file" accept="image/*" multiple class="hidden" @change="onFileSelect">
        </div>
    </div>
</template>
