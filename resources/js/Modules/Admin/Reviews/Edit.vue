<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    review: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    rating: props.review.rating,
    comment: props.review.comment ?? '',
    reviewer_name: props.review.reviewer_name ?? '',
});

const submit = () => {
    form.put(`/admin/avaliacoes/${props.review.id}`);
};
</script>

<template>
    <Head title="Editar avaliação" />

    <AdminLayout>
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-2xl font-bold">Editar avaliação</h1>
            <Link :href="`/admin/avaliacoes/${review.id}`" class="text-sm text-primary hover:underline">
                <i class="fas fa-arrow-left mr-1"></i> Voltar
            </Link>
        </div>

        <form class="max-w-2xl space-y-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-6 shadow-sm" @submit.prevent="submit">
            <div>
                <p class="text-sm font-medium">Produto</p>
                <p class="mt-1 text-sm text-slate-500">{{ review.product?.name ?? 'Produto removido' }}</p>
            </div>

            <div>
                <label class="block text-sm font-medium">Nota</label>
                <div class="mt-1 flex items-center gap-1">
                    <button v-for="star in 5" :key="star" type="button" @click="form.rating = star">
                        <i class="text-2xl" :class="star <= form.rating ? 'fas fa-star text-amber-400' : 'far fa-star text-slate-300 dark:text-slate-600'"></i>
                    </button>
                </div>
                <InputError :message="form.errors.rating" />
            </div>

            <div v-if="!review.user">
                <label for="reviewer_name" class="block text-sm font-medium">Nome do cliente</label>
                <input id="reviewer_name" v-model="form.reviewer_name" type="text" maxlength="255"
                    class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2">
                <InputError :message="form.errors.reviewer_name" />
            </div>
            <div v-else>
                <p class="text-sm font-medium">Cliente</p>
                <p class="mt-1 text-sm text-slate-500">{{ review.user.name }} (usuário do site — nome não editável aqui)</p>
            </div>

            <div>
                <label for="comment" class="block text-sm font-medium">Comentário</label>
                <textarea id="comment" v-model="form.comment" rows="4" maxlength="1000"
                    class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2"></textarea>
                <InputError :message="form.errors.comment" />
            </div>

            <div v-if="review.images?.length">
                <p class="mb-2 text-sm font-medium">Fotos do cliente</p>
                <div class="flex flex-wrap gap-3">
                    <img v-for="image in review.images" :key="image.id" :src="image.image_url" alt=""
                        class="h-20 w-20 rounded-lg border border-[var(--surface-border)] object-cover">
                </div>
            </div>

            <button type="submit" :disabled="form.processing"
                class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50">
                Salvar alterações
            </button>
        </form>
    </AdminLayout>
</template>
