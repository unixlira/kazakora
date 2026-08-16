<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    review: {
        type: Object,
        required: true,
    },
});

const { can } = usePermissions();

const channelBadge = {
    mercado_livre: { color: 'pending', label: 'Mercado Livre' },
    shopee: { color: 'processing', label: 'Shopee' },
    tiktok_shop: { color: 'completed', label: 'TikTok Shop' },
    amazon: { color: 'shipped', label: 'Amazon' },
};

const originLabel = props.review.channel ? (channelBadge[props.review.channel]?.label ?? props.review.channel) : 'Site';
const originColor = props.review.channel ? (channelBadge[props.review.channel]?.color ?? props.review.channel) : 'active';

const formatDate = (value) => (value ? new Date(value).toLocaleString('pt-BR') : '—');
</script>

<template>
    <Head :title="`Avaliação #${review.id}`" />

    <AdminLayout>
        <div class="mb-4 flex items-center justify-between">
            <h1 class="text-2xl font-bold">Avaliação #{{ review.id }}</h1>
            <div class="flex items-center gap-3">
                <Link v-if="can('cadastros.edit')" :href="`/admin/avaliacoes/${review.id}/editar`"
                    class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:opacity-90">
                    Editar
                </Link>
                <Link href="/admin/avaliacoes" class="text-sm text-primary hover:underline">
                    <i class="fas fa-arrow-left mr-1"></i> Voltar
                </Link>
            </div>
        </div>

        <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-6 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-medium uppercase text-slate-400">Produto</p>
                    <Link v-if="review.product" :href="`/admin/produtos/${review.product.id}/editar`"
                        class="text-lg font-semibold hover:text-primary hover:underline">
                        {{ review.product.name }}
                    </Link>
                    <p v-else class="text-lg font-semibold text-slate-400">Produto removido</p>
                </div>
                <StatusBadge :status="originColor" :label="originLabel" />
            </div>

            <div class="mt-6 grid grid-cols-1 gap-6 sm:grid-cols-3">
                <div>
                    <p class="text-xs font-medium uppercase text-slate-400">Cliente</p>
                    <p class="mt-1 text-sm">{{ review.reviewer_display_name }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase text-slate-400">Nota</p>
                    <div class="mt-1 flex items-center gap-0.5 text-amber-400">
                        <i v-for="star in 5" :key="star" class="text-sm"
                            :class="star <= review.rating ? 'fas fa-star' : 'far fa-star text-slate-300 dark:text-slate-600'"></i>
                    </div>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase text-slate-400">Data</p>
                    <p class="mt-1 text-sm">{{ formatDate(review.created_at) }}</p>
                </div>
            </div>

            <div class="mt-6">
                <p class="text-xs font-medium uppercase text-slate-400">Comentário</p>
                <p class="mt-1 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ review.comment ?? 'Sem comentário.' }}</p>
            </div>

            <div v-if="review.images?.length" class="mt-6">
                <p class="mb-2 text-xs font-medium uppercase text-slate-400">Fotos enviadas pelo cliente</p>
                <div class="flex flex-wrap gap-3">
                    <a v-for="image in review.images" :key="image.id" :href="image.image_url" target="_blank" rel="noopener">
                        <img :src="image.image_url" alt="" class="h-24 w-24 rounded-lg border border-[var(--surface-border)] object-cover">
                    </a>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
