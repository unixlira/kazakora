<script setup>
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { confirmDelete, notifyError } from '@/Shared/notify';

const props = defineProps({
    product: {
        type: Object,
        required: true,
    },
});

const MAX_DURATION_SECONDS = 60;
const MAX_BYTES = 100 * 1024 * 1024;

const form = useForm({ video: null, duration_seconds: null });
const fileInput = ref(null);
const isDragging = ref(false);

const readVideoDuration = (file) => new Promise((resolve, reject) => {
    const video = document.createElement('video');
    video.preload = 'metadata';
    video.onloadedmetadata = () => {
        URL.revokeObjectURL(video.src);
        resolve(Math.round(video.duration));
    };
    video.onerror = () => reject(new Error('Não foi possível ler o vídeo.'));
    video.src = URL.createObjectURL(file);
});

const handleFile = async (file) => {
    if (!file) return;

    if (!file.type.startsWith('video/')) {
        notifyError('Selecione um arquivo de vídeo.');
        return;
    }

    if (file.size > MAX_BYTES) {
        notifyError('O vídeo precisa ter no máximo 100MB.');
        return;
    }

    let duration;
    try {
        duration = await readVideoDuration(file);
    } catch (e) {
        notifyError('Não foi possível ler a duração do vídeo.');
        return;
    }

    if (duration > MAX_DURATION_SECONDS) {
        notifyError(`O vídeo precisa ter no máximo ${MAX_DURATION_SECONDS} segundos (esse tem ${duration}s).`);
        return;
    }

    form.transform((data) => ({ ...data, video: file, duration_seconds: duration })).post(
        `/admin/products/${props.product.id}/video`,
        { preserveScroll: true },
    );
};

const onDrop = (event) => {
    isDragging.value = false;
    handleFile(event.dataTransfer.files[0]);
};

const onFileSelect = (event) => {
    handleFile(event.target.files[0]);
    event.target.value = '';
};

const remove = async () => {
    if (await confirmDelete({ text: 'O vídeo do produto será removido permanentemente.' })) {
        router.delete(`/admin/products/${props.product.id}/video`, { preserveScroll: true });
    }
};
</script>

<template>
    <div class="space-y-4">
        <p class="text-xs text-slate-500">Máximo de 100MB e 60 segundos de duração — usado nos anúncios de marketplace.</p>

        <div v-if="product.video_url" class="flex items-start gap-4">
            <video :src="product.video_url" controls class="h-40 w-72 rounded border border-slate-200 bg-black" />
            <div class="text-sm text-slate-500">
                <p>Duração: {{ product.video_duration_seconds }}s</p>
                <button type="button" class="mt-2 text-red-500 hover:underline" @click="remove">Remover vídeo</button>
            </div>
        </div>

        <div v-else class="flex cursor-pointer flex-col items-center justify-center rounded border-2 border-dashed px-6 py-10 text-center transition-colors"
            :class="isDragging ? 'border-emerald-500 bg-emerald-50' : 'border-slate-300 hover:border-emerald-400'"
            @click="fileInput.click()"
            @dragover.prevent="isDragging = true"
            @dragleave.prevent="isDragging = false"
            @drop.prevent="onDrop">
            <i class="fas fa-video mb-2 text-2xl text-slate-400"></i>
            <p class="text-sm text-slate-500">
                <span v-if="form.processing">Enviando vídeo...</span>
                <span v-else>Arraste um vídeo aqui ou clique para selecionar</span>
            </p>
            <input ref="fileInput" type="file" accept="video/*" class="hidden" @change="onFileSelect">
        </div>
    </div>
</template>
