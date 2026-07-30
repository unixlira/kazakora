<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { confirmDelete } from '@/Shared/notify';

const props = defineProps({
    banners: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const bannerError = computed(() => page.props.errors?.banner);
const { can } = usePermissions();

const destroy = async (banner) => {
    if (await confirmDelete({ title: `Remover o banner "${banner.title || banner.id}"?` })) {
        router.delete(`/admin/banners/${banner.id}`);
    }
};

const move = (banner, direction) => {
    router.patch(`/admin/banners/${banner.id}/${direction === 'up' ? 'subir' : 'descer'}`, {}, { preserveScroll: true });
};
</script>

<template>
    <Head title="Banners" />

    <AdminLayout>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-bold">Banners</h1>
            <Link v-if="can('cadastros.create')" href="/admin/banners/criar"
                class="rounded bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">
                Novo banner
            </Link>
        </div>

        <InputError :message="bannerError" class="mb-4" />

        <p v-if="banners.length === 0" class="rounded border border-dashed border-gray-300 py-16 text-center text-sm text-gray-500">
            Nenhum banner cadastrado.
        </p>

        <div v-else class="space-y-3">
            <div v-for="(banner, index) in banners" :key="banner.id"
                class="flex items-center gap-4 rounded border border-gray-200 p-3">
                <img :src="banner.image_url" :alt="banner.title || 'Banner'" class="h-16 w-28 rounded object-cover">

                <div class="min-w-0 flex-1">
                    <p class="truncate font-medium">{{ banner.title || '(sem título)' }}</p>
                    <p class="truncate text-xs text-gray-500">{{ banner.link_url || 'Sem link' }}</p>
                    <span class="mt-1 inline-block rounded px-2 py-0.5 text-xs font-medium"
                        :class="banner.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500'">
                        {{ banner.is_active ? 'Ativo' : 'Inativo' }}
                    </span>
                </div>

                <div class="flex flex-col gap-1">
                    <button type="button" :disabled="index === 0" class="text-sm text-gray-400 hover:text-gray-900 disabled:cursor-not-allowed disabled:opacity-30"
                        aria-label="Mover para cima" @click="move(banner, 'up')">
                        <i class="fas fa-chevron-up"></i>
                    </button>
                    <button type="button" :disabled="index === banners.length - 1" class="text-sm text-gray-400 hover:text-gray-900 disabled:cursor-not-allowed disabled:opacity-30"
                        aria-label="Mover para baixo" @click="move(banner, 'down')">
                        <i class="fas fa-chevron-down"></i>
                    </button>
                </div>

                <div class="flex items-center gap-3">
                    <Link v-if="can('cadastros.edit')" :href="`/admin/banners/${banner.id}/editar`" class="text-sm hover:text-primary hover:underline">
                        Editar
                    </Link>
                    <button v-if="can('cadastros.delete')" type="button" class="text-sm text-error hover:underline" @click="destroy(banner)">
                        Remover
                    </button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
