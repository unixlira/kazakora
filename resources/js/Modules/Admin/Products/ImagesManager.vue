<script setup>
import { router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
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

// Ordem local da galeria: muda na hora enquanto arrasta e só vai pro
// servidor quando solta. A primeira foto vira a principal.
// Mesmo critério da loja (principal primeiro, depois posição), pra o selo
// "Principal" bater com o que o cliente vê em produto antigo.
const ordenar = (images) => [...images].sort((a, b) => Number(b.is_primary) - Number(a.is_primary) || a.position - b.position);
const ordem = ref(ordenar(props.images));
const arrastando = ref(null);
const salvandoOrdem = ref(false);
let ordemAntes = [];

watch(() => props.images, (images) => {
    ordem.value = ordenar(images);
});

const onImageDragStart = (event, image) => {
    arrastando.value = image.id;
    ordemAntes = [...ordem.value];
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', String(image.id));
};

const onImageDragEnter = (image) => {
    if (arrastando.value === null || arrastando.value === image.id) return;

    const lista = [...ordem.value];
    const de = lista.findIndex((item) => item.id === arrastando.value);
    const para = lista.findIndex((item) => item.id === image.id);
    lista.splice(para, 0, lista.splice(de, 1)[0]);
    ordem.value = lista;
};

const onImageDragEnd = () => {
    if (arrastando.value === null) return;
    arrastando.value = null;

    const ids = ordem.value.map((image) => image.id);
    if (ids.join(',') === ordemAntes.map((image) => image.id).join(',')) return;

    salvandoOrdem.value = true;
    router.put(`/admin/produtos/${props.product.id}/imagens/ordem`, { ids }, {
        preserveScroll: true,
        onError: () => {
            ordem.value = ordemAntes;
            notifyError('Não foi possível salvar a nova ordem das fotos. Tente novamente.');
        },
        onFinish: () => {
            salvandoOrdem.value = false;
        },
    });
};

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
    if (arrastando.value !== null) return;
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
        <p v-if="ordem.length > 1" class="text-xs text-slate-500">
            <span v-if="salvandoOrdem">Salvando a ordem...</span>
            <span v-else>Arraste as fotos para mudar a ordem. A primeira é a principal.</span>
        </p>
        <TransitionGroup tag="div" move-class="transition-transform duration-200" class="flex flex-wrap gap-4">
            <div v-for="(image, index) in ordem" :key="image.id"
                class="relative h-28 w-28 cursor-grab overflow-hidden rounded border border-slate-200 active:cursor-grabbing"
                :class="arrastando === image.id ? 'opacity-40 ring-2 ring-emerald-500' : ''"
                draggable="true"
                @dragstart="onImageDragStart($event, image)"
                @dragenter.prevent="onImageDragEnter(image)"
                @dragover.prevent
                @drop.prevent
                @dragend="onImageDragEnd">
                <img :src="image.url" class="pointer-events-none h-full w-full object-cover" :alt="product.name" draggable="false">
                <span v-if="index === 0" class="absolute left-1 top-1 rounded bg-emerald-600 px-1.5 py-0.5 text-[10px] font-semibold text-white">
                    Principal
                </span>
                <button type="button" class="absolute right-1 top-1 flex h-6 w-6 items-center justify-center rounded-full bg-white/90 text-red-500 shadow"
                    @click="remove(image)">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </TransitionGroup>

        <div class="flex cursor-pointer flex-col items-center justify-center rounded border-2 border-dashed px-6 py-10 text-center transition-colors"
            :class="isDragging ? 'border-emerald-500 bg-emerald-50' : 'border-slate-300 hover:border-emerald-400'"
            @click="fileInput.click()"
            @dragover.prevent="isDragging = arrastando === null"
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
