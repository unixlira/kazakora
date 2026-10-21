<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    product: {
        type: Object,
        required: true,
    },
    variations: {
        type: Object,
        required: true, // { parent: Object|null, siblings: Array }
    },
    linkableOrphans: {
        type: Array,
        default: () => [],
    },
    images: {
        type: Array,
        default: () => [],
    },
});

const attachForm = useForm({
    variation_product_id: '',
});

const attach = () => {
    if (! attachForm.variation_product_id) return;

    attachForm.post(`/admin/produtos/${props.product.id}/variacoes/vincular`, {
        preserveScroll: true,
        onSuccess: () => { attachForm.variation_product_id = ''; },
    });
};

const detach = (variationId) => {
    if (! confirm('Desvincular essa variação? Ela vira um produto independente de novo, nada mais muda nela.')) return;

    router.post(`/admin/produtos/${variationId}/variacoes/desvincular`, {}, { preserveScroll: true });
};

// Miniatura (thumb_url) em vez da original de 2048 px, que pesava na memória da aba.
const fotoPequena = (image) => image?.thumb_url || image?.url || null;
const thumbnail = (item) => fotoPequena(item.images?.find((image) => image.is_primary) ?? item.images?.[0]);

// Foto da variação (pedido 2026-10-20): qual foto aparece na loja quando o
// cliente escolhe a variação. Sem escolha, a loja usa a principal.
const grupo = computed(() => {
    if (! props.variations.parent && ! props.variations.siblings.length) return [];

    const atual = { ...props.product, images: props.images };
    const itens = props.variations.parent
        ? [props.variations.parent, atual, ...props.variations.siblings]
        : [atual, ...props.variations.siblings];

    return itens.map((item) => ({ ...item, images: [...(item.images ?? [])].sort((a, b) => a.position - b.position) }));
});
const salvandoFoto = ref(null);
const escolherFoto = (item, imageId) => {
    if ((item.images.find((image) => image.is_variant)?.id ?? null) === imageId) return;

    salvandoFoto.value = item.id;
    router.put(`/admin/produtos/${item.id}/imagens/variacao`, { image_id: imageId }, {
        preserveScroll: true,
        onFinish: () => { salvandoFoto.value = null; },
    });
};
</script>

<template>
    <div class="space-y-6">
        <div>
            <h3 class="text-sm font-semibold uppercase text-slate-500">Variações</h3>
            <p class="mt-1 text-xs text-slate-500">
                Variações reais do mesmo item físico (tamanho, voltagem, capacidade...), estilo Shopee/Mercado Livre —
                cada uma continua sendo um produto completo (SKU, fotos, estoque, dados fiscais próprios), só ficam
                agrupadas visualmente e aparecem como opções uma na outra na página do produto.
            </p>
        </div>

        <div v-if="variations.parent" class="rounded border border-[var(--surface-border)] bg-[var(--surface-muted)] px-4 py-3 text-sm text-[var(--surface-foreground)]">
            Este produto é uma variação de
            <Link :href="`/admin/produtos/${variations.parent.id}/editar`" class="text-link font-semibold underline">{{ variations.parent.name }}</Link>
            (SKU {{ variations.parent.sku }}).
        </div>

        <div>
            <p class="text-sm font-medium text-slate-600">
                {{ variations.parent ? 'Outras variações do mesmo grupo' : 'Variações deste produto' }}
            </p>

            <div v-if="variations.siblings.length" class="mt-2 flex flex-col gap-2">
                <div v-for="item in variations.siblings" :key="item.id"
                    class="flex items-center gap-3 rounded border border-slate-200 px-3 py-2">
                    <img v-if="thumbnail(item)" :src="thumbnail(item)" :alt="item.name" width="48" height="48" loading="lazy" decoding="async" class="h-12 w-12 rounded object-cover">
                    <div v-else class="flex h-12 w-12 items-center justify-center rounded bg-slate-100 text-slate-300">
                        <i class="fas fa-image"></i>
                    </div>

                    <div class="flex-1">
                        <Link :href="`/admin/produtos/${item.id}/editar`" class="font-medium text-slate-700 hover:underline">{{ item.name }}</Link>
                        <p class="text-xs text-slate-500">
                            SKU {{ item.sku }}
                            <span v-if="item.variation"> · Variação: {{ item.variation }}</span>
                            · Estoque: {{ item.stock }}
                        </p>
                    </div>

                    <button type="button" class="text-xs font-medium text-red-600 hover:underline" @click="detach(item.id)">
                        Desvincular
                    </button>
                </div>
            </div>
            <p v-else class="mt-2 text-sm text-slate-500">Nenhuma outra variação cadastrada ainda.</p>
        </div>

        <div v-if="grupo.length" class="border-t border-slate-200 pt-4">
            <p class="text-sm font-medium text-slate-600">Foto de cada variação na loja</p>
            <p class="mt-1 text-xs text-slate-500">
                Clique na foto que deve aparecer como principal quando o cliente escolher a variação.
                Em "Automática", a loja usa a foto principal do produto.
            </p>

            <div class="mt-3 flex flex-col gap-3">
                <div v-for="item in grupo" :key="item.id" class="rounded border border-slate-200 px-3 py-2"
                    :class="{ 'opacity-60': salvandoFoto === item.id }">
                    <p class="text-sm font-medium text-slate-700">
                        {{ item.variation || item.name }}
                        <span v-if="item.id === product.id" class="text-xs font-normal text-slate-400">(este produto)</span>
                        <span class="text-xs font-normal text-slate-400"> · SKU {{ item.sku }}</span>
                    </p>

                    <div v-if="item.images.length" class="mt-2 flex flex-wrap gap-2">
                        <button type="button" :disabled="salvandoFoto !== null"
                            class="flex h-16 w-16 items-center justify-center rounded border-2 px-1 text-center text-[11px] font-medium leading-tight"
                            :class="item.images.some((image) => image.is_variant) ? 'border-slate-200 text-slate-500 hover:border-slate-400' : 'border-emerald-600 bg-emerald-50 text-emerald-700'"
                            :aria-pressed="! item.images.some((image) => image.is_variant)"
                            @click="escolherFoto(item, null)">
                            Automática
                        </button>
                        <button v-for="image in item.images" :key="image.id" type="button" :disabled="salvandoFoto !== null"
                            class="relative h-16 w-16 overflow-hidden rounded border-2"
                            :class="image.is_variant ? 'border-emerald-600' : 'border-slate-200 hover:border-slate-400'"
                            :aria-pressed="image.is_variant" :title="image.is_variant ? 'Foto desta variação na loja' : 'Usar esta foto para a variação'"
                            @click="escolherFoto(item, image.id)">
                            <img :src="fotoPequena(image)" :alt="item.name" width="64" height="64" loading="lazy" decoding="async" class="h-full w-full object-cover">
                            <span v-if="image.is_variant" class="absolute right-0.5 top-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-emerald-600 text-[9px] text-white">
                                <i class="fas fa-check"></i>
                            </span>
                            <span v-else-if="image.is_primary" class="absolute inset-x-0 bottom-0 bg-black/50 text-[9px] text-white">principal</span>
                        </button>
                    </div>
                    <p v-else class="mt-1 text-xs text-slate-400">Sem fotos. Adicione na aba Fotos e vídeo do produto.</p>
                </div>
            </div>
        </div>

        <div v-if="! variations.parent" class="border-t border-slate-200 pt-4">
            <p class="text-sm font-medium text-slate-600">Adicionar variação</p>

            <Link :href="`/admin/produtos/criar?parent_product_id=${product.id}`"
                class="mt-2 inline-block rounded border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">
                <i class="fas fa-plus mr-1.5 text-xs"></i>
                Nova variação (cadastro do zero)
            </Link>

            <div v-if="linkableOrphans.length" class="mt-4">
                <label class="block text-sm text-slate-500">Ou vincular um produto já cadastrado como variação deste</label>
                <div class="mt-1 flex gap-2">
                    <select v-model="attachForm.variation_product_id" class="w-full rounded border border-gray-300 px-3 py-2">
                        <option value="">Selecione um produto...</option>
                        <option v-for="orphan in linkableOrphans" :key="orphan.id" :value="orphan.id">
                            {{ orphan.name }} (SKU {{ orphan.sku }})
                        </option>
                    </select>
                    <button type="button" :disabled="! attachForm.variation_product_id || attachForm.processing"
                        class="shrink-0 rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-slate-300"
                        @click="attach">
                        Vincular
                    </button>
                </div>
                <p class="mt-1 text-xs text-slate-400">
                    Útil pra corrigir um produto que foi cadastrado separado por engano (ex: um 2º anúncio duplicado
                    do mesmo item no canal) — ele mantém SKU, fotos e estoque próprios, só passa a aparecer como
                    variação deste.
                </p>
            </div>
        </div>
    </div>
</template>
