<script setup>
import InputError from '@/Shared/Components/InputError.vue';

const props = defineProps({
    form: {
        type: Object,
        required: true,
    },
    categories: {
        type: Array,
        default: () => [],
    },
    submitLabel: {
        type: String,
        default: 'Salvar',
    },
});

const emit = defineEmits(['submit']);
</script>

<template>
    <form class="max-w-xl space-y-4" @submit.prevent="emit('submit')">
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
            <label for="sku" class="block text-sm font-medium">SKU</label>
            <input
                id="sku"
                v-model="form.sku"
                type="text"
                required
                class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
            >
            <InputError :message="form.errors.sku" />
        </div>

        <div>
            <label for="category_id" class="block text-sm font-medium">Categoria</label>
            <select
                id="category_id"
                v-model="form.category_id"
                class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
            >
                <option :value="null">Sem categoria</option>
                <option v-for="category in categories" :key="category.id" :value="category.id">
                    {{ category.name }}
                </option>
            </select>
            <InputError :message="form.errors.category_id" />
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

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="price" class="block text-sm font-medium">Preço (R$)</label>
                <input
                    id="price"
                    v-model="form.price"
                    type="number"
                    step="0.01"
                    min="0"
                    required
                    class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
                >
                <InputError :message="form.errors.price" />
            </div>

            <div>
                <label for="stock" class="block text-sm font-medium">Estoque</label>
                <input
                    id="stock"
                    v-model="form.stock"
                    type="number"
                    min="0"
                    required
                    class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
                >
                <InputError :message="form.errors.stock" />
            </div>
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input v-model="form.is_active" type="checkbox">
            Produto ativo (visível no catálogo)
        </label>

        <button
            type="submit"
            :disabled="form.processing"
            class="rounded bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:cursor-not-allowed disabled:bg-gray-300"
        >
            {{ submitLabel }}
        </button>
    </form>
</template>
