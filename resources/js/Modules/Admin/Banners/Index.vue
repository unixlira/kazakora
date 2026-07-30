<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import ActionIcon from '@/Shared/Components/ActionIcon.vue';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { confirmDelete, notifyError } from '@/Shared/notify';

const props = defineProps({
    banners: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const bannerError = computed(() => page.props.errors?.banner ?? page.props.errors?.image ?? page.props.errors?.image_mobile);
const { can } = usePermissions();

const form = useForm({ image: null, image_mobile: null });
const desktopPreview = ref(null);
const mobilePreview = ref(null);
const desktopInput = ref(null);
const mobileInput = ref(null);
const draggingDesktop = ref(false);
const draggingMobile = ref(false);

const pickFile = (file, target) => {
    if (!file || !file.type.startsWith('image/')) {
        notifyError('Selecione um arquivo de imagem.');
        return;
    }

    if (target === 'desktop') {
        form.image = file;
        desktopPreview.value = URL.createObjectURL(file);
    } else {
        form.image_mobile = file;
        mobilePreview.value = URL.createObjectURL(file);
    }
};

const clearDesktop = () => {
    form.image = null;
    desktopPreview.value = null;
    desktopInput.value.value = '';
};

const clearMobile = () => {
    form.image_mobile = null;
    mobilePreview.value = null;
    mobileInput.value.value = '';
};

const submitNewBanner = () => {
    if (!form.image) {
        notifyError('Selecione ao menos a imagem do site (desktop).');
        return;
    }

    form.post('/admin/banners', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            desktopPreview.value = null;
            mobilePreview.value = null;
        },
    });
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

        <div v-if="can('cadastros.create')" class="rounded border border-gray-200 p-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <p class="mb-1 text-sm font-medium">Imagem do site (desktop)</p>
                    <p class="mb-2 text-xs text-gray-500">Obrigatória — use uma imagem larga (paisagem), essa é a que aparece em telas de computador.</p>
                    <div class="relative flex aspect-[21/9] cursor-pointer flex-col items-center justify-center rounded border-2 border-dashed px-4 py-6 text-center transition-colors"
                        :class="draggingDesktop ? 'border-emerald-500 bg-emerald-50' : 'border-slate-300 hover:border-emerald-400'"
                        @click="desktopInput.click()"
                        @dragover.prevent="draggingDesktop = true"
                        @dragleave.prevent="draggingDesktop = false"
                        @drop.prevent="draggingDesktop = false; pickFile(($event).dataTransfer.files[0], 'desktop')">
                        <img v-if="desktopPreview" :src="desktopPreview" class="absolute inset-0 h-full w-full rounded object-cover">
                        <template v-else>
                            <i class="fas fa-desktop mb-2 text-2xl text-slate-400"></i>
                            <p class="text-sm text-slate-500">Arraste ou clique para selecionar</p>
                        </template>
                        <button v-if="desktopPreview" type="button"
                            class="absolute right-1 top-1 flex h-6 w-6 items-center justify-center rounded-full bg-white/90 text-red-500 shadow"
                            aria-label="Remover imagem selecionada" @click.stop="clearDesktop">
                            <i class="fas fa-times"></i>
                        </button>
                        <input ref="desktopInput" type="file" accept="image/*" class="hidden" @change="pickFile($event.target.files[0], 'desktop')">
                    </div>
                </div>

                <div>
                    <p class="mb-1 text-sm font-medium">Imagem mobile (opcional)</p>
                    <p class="mb-2 text-xs text-gray-500">Use uma imagem em pé (retrato/quadrada) para celular. Se não enviar, a imagem do site será usada no mobile também.</p>
                    <div class="relative flex aspect-[21/9] cursor-pointer flex-col items-center justify-center rounded border-2 border-dashed px-4 py-6 text-center transition-colors"
                        :class="draggingMobile ? 'border-emerald-500 bg-emerald-50' : 'border-slate-300 hover:border-emerald-400'"
                        @click="mobileInput.click()"
                        @dragover.prevent="draggingMobile = true"
                        @dragleave.prevent="draggingMobile = false"
                        @drop.prevent="draggingMobile = false; pickFile(($event).dataTransfer.files[0], 'mobile')">
                        <img v-if="mobilePreview" :src="mobilePreview" class="absolute inset-0 h-full w-full rounded object-cover">
                        <template v-else>
                            <i class="fas fa-mobile-screen mb-2 text-2xl text-slate-400"></i>
                            <p class="text-sm text-slate-500">Arraste ou clique para selecionar</p>
                        </template>
                        <button v-if="mobilePreview" type="button"
                            class="absolute right-1 top-1 flex h-6 w-6 items-center justify-center rounded-full bg-white/90 text-red-500 shadow"
                            aria-label="Remover imagem selecionada" @click.stop="clearMobile">
                            <i class="fas fa-times"></i>
                        </button>
                        <input ref="mobileInput" type="file" accept="image/*" class="hidden" @change="pickFile($event.target.files[0], 'mobile')">
                    </div>
                </div>
            </div>

            <button type="button" :disabled="form.processing" class="mt-4 rounded bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:cursor-not-allowed disabled:bg-gray-300"
                @click="submitNewBanner">
                {{ form.processing ? 'Enviando...' : 'Adicionar banner' }}
            </button>
        </div>

        <h2 class="mb-4 mt-8 text-sm font-semibold uppercase tracking-wide text-gray-500">Imagens no Storage</h2>

        <p v-if="banners.length === 0" class="rounded border border-dashed border-gray-300 py-16 text-center text-sm text-gray-500">
            Nenhum banner cadastrado.
        </p>

        <div v-else class="flex flex-wrap gap-4">
            <div v-for="(banner, index) in banners" :key="banner.id" class="group relative w-56">
                <div class="relative aspect-[21/9] overflow-hidden rounded border border-slate-200">
                    <img :src="banner.image_url" :alt="banner.title || 'Banner'" class="h-full w-full object-cover">

                    <button v-if="can('cadastros.delete')" type="button" title="Remover banner"
                        class="absolute right-1 top-1 flex h-6 w-6 items-center justify-center rounded-full bg-white/90 text-red-500 shadow"
                        aria-label="Remover banner" @click="destroy(banner)">
                        <i class="fas fa-times"></i>
                    </button>

                    <span v-if="!banner.is_active" class="absolute left-1 top-1 rounded bg-gray-900/70 px-1.5 py-0.5 text-[10px] font-semibold text-white">
                        Inativo
                    </span>
                    <span v-if="banner.image_url_mobile" class="absolute bottom-1 left-1 rounded bg-gray-900/70 px-1.5 py-0.5 text-[10px] font-semibold text-white">
                        <i class="fas fa-mobile-screen"></i> mobile próprio
                    </span>
                </div>

                <div class="mt-1 flex items-center justify-between">
                    <p class="truncate text-xs text-gray-500">{{ banner.title || 'Sem título' }}</p>
                    <div class="flex items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100">
                        <button v-if="can('cadastros.edit')" type="button" :disabled="index === 0" title="Mover para cima"
                            class="flex h-7 w-7 items-center justify-center rounded-full text-gray-400 hover:bg-gray-100 hover:text-gray-900 disabled:cursor-not-allowed disabled:opacity-30"
                            aria-label="Mover para cima" @click="move(banner, 'up')">
                            <i class="fas fa-chevron-up text-xs"></i>
                        </button>
                        <button v-if="can('cadastros.edit')" type="button" :disabled="index === banners.length - 1" title="Mover para baixo"
                            class="flex h-7 w-7 items-center justify-center rounded-full text-gray-400 hover:bg-gray-100 hover:text-gray-900 disabled:cursor-not-allowed disabled:opacity-30"
                            aria-label="Mover para baixo" @click="move(banner, 'down')">
                            <i class="fas fa-chevron-down text-xs"></i>
                        </button>
                        <ActionIcon v-if="can('cadastros.edit')" icon="fa-pen" label="Editar" color="blue" :href="`/admin/banners/${banner.id}/editar`" />
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
