<script setup>
import InputError from '@/Shared/Components/InputError.vue';
import { ref, watch } from 'vue';
import { useSkuPreview } from '@/Shared/useSkuPreview';

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

const discountType = ref(
    props.form.discount_percentage ? 'percentage' : (props.form.discount_amount ? 'amount' : ''),
);

watch(discountType, (type) => {
    if (type !== 'percentage') props.form.discount_percentage = null;
    if (type !== 'amount') props.form.discount_amount = null;
});

// Prévia automática do SKU: dispara depois que o usuário para de digitar
// (debounce) em qualquer campo que alimenta o gerador (categoria, nome,
// marca, modelo, cor, variação). Um produto que já chegou do servidor COM
// sku (tela de edição) nunca entra aqui — SkuGeneratorService nunca
// regenera um SKU existente (ver ProductController::update()), então
// ficar reescrevendo o campo ali seria mentira (não é o que vai ser
// salvo). Já num produto novo (sku começa vazio), a prévia é recalculada
// a cada mudança até o usuário salvar de vez.
const hadSkuOnLoad = Boolean(props.form.sku);
const { loading: skuLoading, preview: previewSku } = useSkuPreview();
let skuDebounceTimer = null;

watch(
    () => [props.form.category_id, props.form.name, props.form.brand, props.form.model, props.form.color, props.form.variation],
    () => {
        if (hadSkuOnLoad) {
            return;
        }

        clearTimeout(skuDebounceTimer);
        skuDebounceTimer = setTimeout(async () => {
            const result = await previewSku({
                category_id: props.form.category_id,
                name: props.form.name,
                brand: props.form.brand,
                model: props.form.model,
                color: props.form.color,
                variation: props.form.variation,
            });

            if (result) {
                props.form.sku = result.toUpperCase();
            }
        }, 600);
    },
);

// Pedido explícito 2026-08-10: na edição, o SKU passa a poder ser digitado
// à mão (antes era travado pra sempre — ver ProductController::update(),
// também ajustado) + um botão do lado que gera uma sugestão nova a partir
// dos campos atuais, reaproveitando o mesmo endpoint de prévia (não grava
// nada sozinho, só preenche o campo — quem confirma é o "Salvar" normal do
// formulário, igual qualquer outro campo editado).
const regeneratingSku = ref(false);

const regenerateSku = async () => {
    regeneratingSku.value = true;

    const result = await previewSku({
        category_id: props.form.category_id,
        name: props.form.name,
        brand: props.form.brand,
        model: props.form.model,
        color: props.form.color,
        variation: props.form.variation,
    });

    if (result) {
        props.form.sku = result.toUpperCase();
    }

    regeneratingSku.value = false;
};
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

        <div v-if="form.sku !== undefined">
            <label for="sku" class="block text-sm font-medium">SKU</label>

            <!-- Edição: campo editável + botão que gera uma sugestão nova
                 do lado. Criação: continua travado, só mostrando a prévia
                 automática (comportamento de sempre, sem alteração). -->
            <div v-if="hadSkuOnLoad" class="mt-1 flex gap-2">
                <input
                    id="sku"
                    v-model="form.sku"
                    type="text"
                    class="w-full rounded border border-gray-300 px-3 py-2 uppercase"
                    @input="form.sku = form.sku.toUpperCase()"
                >
                <button
                    type="button"
                    :disabled="regeneratingSku"
                    class="flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded border border-gray-300 px-3 py-2 text-sm font-medium hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50"
                    title="Gerar um novo SKU automaticamente a partir dos campos acima"
                    @click="regenerateSku"
                >
                    <i class="fas fa-arrows-rotate" :class="{ 'fa-spin': regeneratingSku }"></i>
                    Gerar novo
                </button>
            </div>
            <div v-else class="relative mt-1">
                <input
                    id="sku"
                    name="sku"
                    type="text"
                    :value="skuLoading ? 'Gerando...' : (form.sku || 'Preencha os campos acima para gerar')"
                    disabled
                    class="w-full rounded border border-warning bg-lightwarning px-3 py-2 pr-9 text-warning-emphasis disabled:cursor-not-allowed"
                >
                <i v-if="skuLoading" class="fas fa-spinner fa-spin absolute right-3 top-1/2 -translate-y-1/2 text-warning-emphasis"></i>
            </div>

            <p class="mt-1 text-xs text-gray-400">
                <template v-if="hadSkuOnLoad">Pode editar livremente ou clicar em "Gerar novo" pra sugerir outro a partir dos campos acima.</template>
                <template v-else>Gerado automaticamente a partir de categoria, nome, marca, modelo, cor e variação, em caixa alta.</template>
            </p>
            <InputError :message="form.errors.sku" />
        </div>

        <div>
            <label for="description" class="block text-sm font-medium">Descrição</label>
            <textarea
                id="description"
                v-model="form.description"
                rows="4"
                class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
            />
            <p class="mt-1 text-xs text-gray-500">
                Dica: separe parágrafos com uma linha em branco. Um título como "Principais benefícios:" seguido de
                linhas começando com "-" vira uma lista com destaque visual na página do produto.
            </p>
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
                <label for="cost_price" class="block text-sm font-medium">Preço de custo (R$)</label>
                <input
                    id="cost_price"
                    v-model="form.cost_price"
                    type="number"
                    step="0.01"
                    min="0"
                    placeholder="Opcional"
                    class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
                >
                <p class="mt-1 text-xs text-gray-400">Uso interno (margem/relatórios) — não aparece na loja.</p>
                <InputError :message="form.errors.cost_price" />
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

        <div>
            <label for="discount_type" class="block text-sm font-medium">Desconto (opcional)</label>
            <select id="discount_type" v-model="discountType" class="mt-1 w-full rounded border border-gray-300 px-3 py-2">
                <option value="">Sem desconto</option>
                <option value="percentage">Percentual (%)</option>
                <option value="amount">Valor fixo (R$)</option>
            </select>

            <div v-if="discountType === 'percentage'" class="mt-2">
                <input v-model="form.discount_percentage" type="number" step="0.01" min="0" max="100"
                    placeholder="Ex: 10" class="w-full rounded border border-gray-300 px-3 py-2">
                <InputError :message="form.errors.discount_percentage" />
            </div>
            <div v-if="discountType === 'amount'" class="mt-2">
                <input v-model="form.discount_amount" type="number" step="0.01" min="0"
                    placeholder="Ex: 20.00" class="w-full rounded border border-gray-300 px-3 py-2">
                <InputError :message="form.errors.discount_amount" />
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
