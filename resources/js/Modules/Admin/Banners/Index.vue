<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { confirmDelete, notifyError } from '@/Shared/notify';

const props = defineProps({
    banners: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const bannerError = computed(() => page.props.errors?.banner ?? page.props.errors?.image);
const { can } = usePermissions();

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

    form.transform((data) => ({ ...data, image: files[index] })).post('/admin/banners', {
        preserveScroll: true,
        onFinish: () => {
            uploadingCount.value -= 1;
            uploadNext(files, index + 1);
        },
    });
};

const onDrop = (event) => {
    isDragging.value = false;
    uploadFiles(event.dataTransfer.files);
};

const onFileSelect = (event) => {
    uploadFiles(event.target.files);
    event.target.value = '';
};

const destroy = async (banner) => {
    if (await confirmDelete({ title: `Remover o banner "${banner.title || banner.id}"?` })) {
        router.delete(`/admin/banners/${banner.id}`, { preserveScroll: true });
    }
};

const move = (banner, direction) => {
    router.patch(`/admin/banners/${banner.id}/${direction === 'up' ? 'subir' : 'descer'}`, {}, { preserveScroll: true });
};
</script>

<template>
    <Head title="Banners" />

    <AdminLayout>
        <h1 class="mb-1 text-2xl font-bold">Banners</h1>
        <p class="mb-6 text-sm text-gray-500">Imagens exibidas em rotação no topo da loja, logo abaixo do menu. Ideal para trocar em datas sazonais.</p>

        <InputError :message="bannerError" class="mb-4" />

        <div v-if="can('cadastros.create')"
            class="flex cursor-pointer flex-col items-center justify-center rounded border-2 border-dashed px-6 py-10 text-center transition-colors"
            :class="isDragging ? 'border-emerald-500 bg-emerald-50' : 'border-slate-300 hover:border-emerald-400'"
            @click="fileInput.click()"
            @dragover.prevent="isDragging = true"
            @dragleave.prevent="isDragging = false"
            @drop.prevent="onDrop">
            <i class="fas fa-cloud-upload-alt mb-2 text-2xl text-slate-400"></i>
            <p class="text-sm text-slate-500">
                <span v-if="uploadingCount > 0">Enviando {{ uploadingCount }} imagem(ns)...</span>
                <span v-else>Arraste imagens aqui ou clique para selecionar (múltiplas ao mesmo tempo)</span>
            </p>
            <input ref="fileInput" type="file" accept="image/*" multiple class="hidden" @change="onFileSelect">
        </div>

        <h2 class="mb-4 mt-8 text-sm font-semibold uppercase tracking-wide text-gray-500">Imagens no Storage</h2>

        <p v-if="banners.length === 0" class="rounded border border-dashed border-gray-300 py-16 text-center text-sm text-gray-500">
            Nenhum banner cadastrado.
        </p>

        <div v-else class="flex flex-wrap gap-4">
            <div v-for="(banner, index) in banners" :key="banner.id" class="group relative w-56">
                <div class="relative aspect-[21/9] overflow-hidden rounded border border-slate-200">
                    <img :src="banner.image_url" :alt="banner.title || 'Banner'" class="h-full w-full object-cover">

                    <button v-if="can('cadastros.delete')" type="button"
                        class="absolute right-1 top-1 flex h-6 w-6 items-center justify-center rounded-full bg-white/90 text-red-500 shadow"
                        aria-label="Remover banner" @click="destroy(banner)">
                        <i class="fas fa-times"></i>
                    </button>

                    <span v-if="!banner.is_active" class="absolute left-1 top-1 rounded bg-gray-900/70 px-1.5 py-0.5 text-[10px] font-semibold text-white">
                        Inativo
                    </span>
                </div>

                <div class="mt-1 flex items-center justify-between">
                    <p class="truncate text-xs text-gray-500">{{ banner.title || 'Sem título' }}</p>
                    <div class="flex items-center gap-2 opacity-0 transition-opacity group-hover:opacity-100">
                        <button v-if="can('cadastros.edit')" type="button" :disabled="index === 0"
                            class="text-xs text-gray-400 hover:text-gray-900 disabled:cursor-not-allowed disabled:opacity-30"
                            aria-label="Mover para cima" @click="move(banner, 'up')">
                            <i class="fas fa-chevron-up"></i>
                        </button>
                        <button v-if="can('cadastros.edit')" type="button" :disabled="index === banners.length - 1"
                            class="text-xs text-gray-400 hover:text-gray-900 disabled:cursor-not-allowed disabled:opacity-30"
                            aria-label="Mover para baixo" @click="move(banner, 'down')">
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <Link v-if="can('cadastros.edit')" :href="`/admin/banners/${banner.id}/editar`" class="text-xs hover:text-primary hover:underline">
                            Editar
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
