<script setup>
import { router, useForm } from '@inertiajs/vue3';

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

const thumbnail = (item) => item.images?.find((image) => image.is_primary)?.url ?? item.images?.[0]?.url ?? null;
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

        <div v-if="variations.parent" class="rounded border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
            Este produto é uma variação de
            <a :href="`/admin/produtos/${variations.parent.id}/editar`" class="font-semibold underline">{{ variations.parent.name }}</a>
            (SKU {{ variations.parent.sku }}).
        </div>

        <div>
            <p class="text-sm font-medium text-slate-600">
                {{ variations.parent ? 'Outras variações do mesmo grupo' : 'Variações deste produto' }}
            </p>

            <div v-if="variations.siblings.length" class="mt-2 flex flex-col gap-2">
                <div v-for="item in variations.siblings" :key="item.id"
                    class="flex items-center gap-3 rounded border border-slate-200 px-3 py-2">
                    <img v-if="thumbnail(item)" :src="thumbnail(item)" :alt="item.name" class="h-12 w-12 rounded object-cover">
                    <div v-else class="flex h-12 w-12 items-center justify-center rounded bg-slate-100 text-slate-300">
                        <i class="fas fa-image"></i>
                    </div>

                    <div class="flex-1">
                        <a :href="`/admin/produtos/${item.id}/editar`" class="font-medium text-slate-700 hover:underline">{{ item.name }}</a>
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

        <div v-if="! variations.parent" class="border-t border-slate-200 pt-4">
            <p class="text-sm font-medium text-slate-600">Adicionar variação</p>

            <a :href="`/admin/produtos/criar?parent_product_id=${product.id}`"
                class="mt-2 inline-block rounded border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">
                <i class="fas fa-plus mr-1.5 text-xs"></i>
                Nova variação (cadastro do zero)
            </a>

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
