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

        <div v-if="form.sku !== undefined">
            <label for="sku" class="block text-sm font-medium">SKU</label>
            <input
                id="sku"
                name="sku"
                type="text"
                :value="form.sku || 'Gerado automaticamente ao salvar'"
                readonly
                class="mt-1 w-full rounded border border-gray-200 bg-gray-50 px-3 py-2 text-gray-500"
            >
            <p class="mt-1 text-xs text-gray-400">
                Gerado automaticamente a partir de categoria, nome, marca, modelo, cor e variação. Não pode ser editado.
            </p>
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

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="brand" class="block text-sm font-medium">Marca</label>
                <input
                    id="brand"
                    v-model="form.brand"
                    type="text"
                    class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
                >
                <InputError :message="form.errors.brand" />
            </div>
            <div>
                <label for="model" class="block text-sm font-medium">Modelo</label>
                <input
                    id="model"
                    v-model="form.model"
                    type="text"
                    class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
                >
                <InputError :message="form.errors.model" />
            </div>
            <div>
                <label for="color" class="block text-sm font-medium">Cor</label>
                <input
                    id="color"
                    v-model="form.color"
                    type="text"
                    placeholder="Ex: Preta, Inox"
                    class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
                >
                <InputError :message="form.errors.color" />
            </div>
            <div>
                <label for="variation" class="block text-sm font-medium">Variação (tamanho, voltagem, capacidade...)</label>
                <input
                    id="variation"
                    v-model="form.variation"
                    type="text"
                    placeholder="Ex: P, 20W, 12L"
                    class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
                >
                <InputError :message="form.errors.variation" />
            </div>
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

        <label class="flex items-center gap-2 text-sm">
            <input v-model="form.is_featured" type="checkbox">
            Destaque (aparece na seção "Destaques" da home)
        </label>

        <label class="flex items-center gap-2 text-sm">
            <input v-model="form.is_new_release" type="checkbox">
            Lançamento (aparece como novidade)
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
