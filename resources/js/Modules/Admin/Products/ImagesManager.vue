<script setup>
import { router, useForm } from '@inertiajs/vue3';

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

const form = useForm({
    image: null,
});

const upload = (event) => {
    form.image = event.target.files[0];
    if (!form.image) return;

    form.post(`/admin/products/${props.product.id}/images`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            event.target.value = '';
        },
    });
};

const remove = (image) => {
    if (confirm('Remover essa foto?')) {
        router.delete(`/admin/products/${props.product.id}/images/${image.id}`, { preserveScroll: true });
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

        <label class="inline-flex cursor-pointer items-center gap-2 rounded border border-dashed border-slate-300 px-4 py-3 text-sm text-slate-500 hover:border-emerald-500 hover:text-emerald-600">
            <i class="fas fa-upload"></i>
            {{ form.processing ? 'Enviando...' : 'Adicionar foto' }}
            <input type="file" accept="image/*" class="hidden" :disabled="form.processing" @change="upload">
        </label>
    </div>
</template>
