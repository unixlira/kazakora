<script setup>
import InputError from '@/Shared/Components/InputError.vue';
import { computed, ref, watch } from 'vue';
import { useSkuPreview } from '@/Shared/useSkuPreview';

// Pedido 2026-10-18: o preço digitado é o preço da loja; o Pix desconta só no checkout ("Desconto Checkout").

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
    // BUG REAL 2026-08-17: na edição, o campo Estoque mandava um valor
    // ABSOLUTO pré-carregado quando a página abriu — se uma venda real
    // descontasse o estoque enquanto a tela ficava aberta, salvar
    // sobrescrevia o estoque real pelo valor antigo da tela (achados
    // reais: -51/-167/-182/-240 unidades numa tacada só). isEdit troca o
    // campo por um AJUSTE explícito (+/-, default 0) só na edição — não
    // tocar nele nunca muda o estoque, não importa há quanto tempo a
    // página está aberta. Criação continua com o valor absoluto normal
    // (produto novo, sem estoque concorrente pra proteger).
    isEdit: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['submit']);


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

// Pedido 2026-10-18: resumo financeiro no cadastro. Mesma conta da loja
// (Product::precoSemOferta() e DescontoPix): Desconto Site sobre o preço
// cheio, Pix sobre o preço já com o Desconto Site. Frete fixo estimado em
// R$ 30,00. Lucro = preço final - custo - frete.
const FRETE_ESTIMADO = 30;

const moeda = (valor) => Number(valor || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
const percentual = (valor) => `${Number(valor || 0).toLocaleString('pt-BR', { maximumFractionDigits: 2 })}%`;
const limitarPct = (valor) => Math.min(100, Math.max(0, Number(valor) || 0));
const centavos = (valor) => Math.round(valor * 100) / 100;

const resumo = computed(() => {
    const cheio = Math.max(0, Number(props.form.price) || 0);
    const custo = Math.max(0, Number(props.form.cost_price) || 0);
    const descontoSite = limitarPct(props.form.discount_percentage);
    const descontoCheckout = limitarPct(props.form.checkout_discount_percentage);
    const precoSite = centavos(cheio * (1 - descontoSite / 100));
    const precoFinal = centavos(precoSite - centavos(precoSite * descontoCheckout / 100));

    return {
        cheio,
        custo,
        descontoSite,
        descontoCheckout,
        precoFinal,
        lucro: centavos(precoFinal - custo - FRETE_ESTIMADO),
    };
});
</script>

<template>
    <!-- Pedido 2026-10-17: grid em linhas (Nome | Categoria; Marca | Modelo | Cor;
         Variação em destaque) e formulário mais largo, pra não quebrar rótulo. -->
    <form class="max-w-4xl space-y-5" @submit.prevent="emit('submit')">
        <div class="grid gap-4 sm:grid-cols-2">
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
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
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
        </div>

        <div class="rounded-lg border border-primary/40 bg-lightprimary p-4">
            <label for="variation" class="block text-sm font-semibold">Variação</label>
            <p id="variation-hint" class="text-xs text-gray-500">Tamanho, voltagem, capacidade... É o que diferencia este item dos irmãos e entra no SKU.</p>
            <input
                id="variation"
                v-model="form.variation"
                type="text"
                placeholder="Ex: P, 20W, 12L"
                aria-describedby="variation-hint"
                class="mt-2 w-full rounded border border-gray-300 bg-white px-3 py-2 sm:max-w-md"
            >
            <InputError :message="form.errors.variation" />
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

        <div class="grid items-start gap-4 sm:grid-cols-3">
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
                <p class="mt-1 text-xs text-gray-500">
                    Preço cheio da loja. Os descontos do site e do Pix ficam nos campos abaixo.
                </p>
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

            <div v-if="isEdit">
                <label for="stock_adjustment" class="block text-sm font-medium">
                    Estoque atual: {{ form.stock }} — Ajustar (+/-)
                </label>
                <input
                    id="stock_adjustment"
                    v-model.number="form.stock_adjustment"
                    type="number"
                    step="1"
                    placeholder="0"
                    class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
                >
                <p class="mt-1 text-xs text-gray-400">
                    Deixe 0 pra não mexer no estoque. Some (ex: 5) ou subtraia (ex: -3) — nunca digite o total final, senão uma venda que chegar
                    entre você abrir esta tela e salvar seria desfeita por engano.
                </p>
                <InputError :message="form.errors.stock_adjustment" />
            </div>
            <div v-else>
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

        <div class="grid items-start gap-4 sm:grid-cols-2">
            <div>
                <label for="discount_percentage" class="block text-sm font-medium">Desconto Site (%)</label>
                <input id="discount_percentage" v-model="form.discount_percentage" type="number" step="any" min="0" max="100"
                    placeholder="0" class="mt-1 w-full rounded border border-gray-300 px-3 py-2">
                <p class="mt-1 text-xs text-gray-400">Desconto direto no produto: o card e a página mostram o preço riscado e o preço com desconto.</p>
                <InputError :message="form.errors.discount_percentage" />
            </div>
            <div>
                <label for="checkout_discount_percentage" class="block text-sm font-medium">Desconto Checkout (%)</label>
                <input id="checkout_discount_percentage" v-model="form.checkout_discount_percentage" type="number" step="0.01" min="0" max="100"
                    placeholder="0" class="mt-1 w-full rounded border border-gray-300 px-3 py-2">
                <p class="mt-1 text-xs text-gray-400">Desconto no Pix: sai no checkout só pagando 100% no Pix. Vazio = sem desconto no Pix.</p>
                <InputError :message="form.errors.checkout_discount_percentage" />
            </div>
        </div>

        <!-- Pedido 2026-10-18: resumo financeiro num alerta de sucesso verde.
             Texto branco, descontos em vermelho e Preço Final em verde destacado.
             Claro: verde forte; escuro: verde fechado com borda. -->
        <div
            role="status"
            aria-live="polite"
            class="rounded-lg border border-success-emphasis bg-success-emphasis p-4 text-white dark:border-success/60 dark:bg-[#0b2a20]"
        >
            <p class="flex items-center gap-2 text-sm font-semibold">
                <i class="fas fa-circle-check" aria-hidden="true"></i>
                Resumo financeiro
            </p>

            <dl class="mt-3 grid gap-x-6 gap-y-1.5 text-sm sm:grid-cols-2">
                <div class="flex justify-between gap-3">
                    <dt>Preço de custo</dt>
                    <dd class="font-medium tabular-nums">{{ moeda(resumo.custo) }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt>Valor do Produto Cheio</dt>
                    <dd class="font-medium tabular-nums">{{ moeda(resumo.cheio) }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt>Desconto Opcional</dt>
                    <dd class="resumo-negativo font-semibold tabular-nums">-{{ percentual(resumo.descontoSite) }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt>Desconto do Checkout</dt>
                    <dd class="resumo-negativo font-semibold tabular-nums">-{{ percentual(resumo.descontoCheckout) }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt>Estimativa de Frete</dt>
                    <dd class="font-medium tabular-nums">-{{ moeda(FRETE_ESTIMADO) }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt>Lucro Líquido Estimado</dt>
                    <dd class="font-semibold tabular-nums" :class="{ 'resumo-negativo': resumo.lucro < 0 }">{{ moeda(resumo.lucro) }}</dd>
                </div>
            </dl>

            <div class="mt-3 flex flex-wrap items-baseline justify-between gap-2 rounded-md bg-black/20 px-3 py-2">
                <span class="text-sm font-semibold">Preço Final</span>
                <span class="resumo-preco-final text-2xl font-extrabold tabular-nums">{{ moeda(resumo.precoFinal) }}</span>
            </div>
        </div>

        <div class="flex flex-wrap gap-x-6 gap-y-2">
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
        </div>

        <button
            type="submit"
            :disabled="form.processing"
            class="rounded bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:cursor-not-allowed disabled:bg-gray-300"
        >
            {{ submitLabel }}
        </button>
    </form>
</template>

<style scoped>
/* Vermelho e verde claros o bastante para ler sobre o fundo verde, nos dois temas. */
.resumo-negativo { color: #fecaca; }
.resumo-preco-final { color: #86efac; }
:global(.dark .resumo-negativo) { color: #fca5a5; }
:global(.dark .resumo-preco-final) { color: #4ade80; }
</style>
