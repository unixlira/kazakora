<script setup>
import { computed } from 'vue';
import { channelBrand } from './channelBrand';

/**
 * Linha de pedido da fila — mesmo template visual do card do app desktop
 * KoraSync (MainWindow.xaml, OrderQueueRestItemTemplate): 1 foto por
 * produto + cliente/pedido/SKU•QTD, faixa do canal, status + botão
 * "Em separação"/"Concluído" agrupados à direita.
 */
const props = defineProps({
    order: { type: Object, required: true },
    packing: { type: Boolean, default: false },
    error: { type: String, default: null },
    // false nas abas Separados/Cancelados — o botão de embalar não faz
    // sentido pra pedido que já está resolvido de um jeito ou de outro
    // (mesma regra de PackButtonVisibility no app desktop).
    showPackButton: { type: Boolean, default: true },
});

const emit = defineEmits(['pack']);

const isCancelled = computed(() => props.order.status === 'cancelled');
const isPacked = computed(() => !!props.order.packed_at);

// 3º estado do botão (IsAwaitingLabel no app desktop): venda agendada, já
// embalada aqui, mas o canal ainda não liberou a etiqueta — não é "ainda
// não separei", é "já separei, só falta o canal liberar pra sair".
const isAwaitingLabel = computed(() => !!props.order.scheduled_for && isPacked.value && !props.order.label_ready);

const brand = computed(() => channelBrand(props.order.channel));

const statusPillText = computed(() => {
    if (isAwaitingLabel.value) return 'Aguardando etiqueta';

    return isPacked.value ? 'Finalizado' : 'Aguardando';
});

const statusDotColor = computed(() => (isAwaitingLabel.value || !isPacked.value ? 'var(--ks-warning)' : 'var(--ks-brand)'));

const packButtonText = computed(() => {
    if (props.packing) return 'Em separação...';

    return isPacked.value ? 'Concluído' : 'Em separação';
});

function productImageUrl(productId) {
    return productId
        ? `/admin/korasync-api/queue/${props.order.id}/image/${productId}`
        : `/admin/korasync-api/queue/${props.order.id}/image`;
}

function onImageError(event) {
    event.target.style.display = 'none';
}

function handlePack() {
    if (isCancelled.value || isPacked.value || props.packing) return;
    emit('pack', props.order);
}
</script>

<template>
    <div
        class="mb-2.5 rounded-[10px] border p-3.5 md:p-4"
        :style="{
            background: isCancelled ? 'rgba(255,82,82,0.15)' : 'var(--ks-row-bg)',
            borderColor: isCancelled ? 'var(--ks-error)' : 'var(--ks-border)',
            borderWidth: isCancelled ? '2px' : '1px',
        }"
    >
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <!-- Fotos + dados, 1 linha por produto -->
            <div class="min-w-0 flex-1 space-y-2">
                <div v-for="(product, idx) in order.products" :key="product.id ?? idx" class="flex items-center gap-3">
                    <div
                        class="flex h-[52px] w-[52px] shrink-0 items-center justify-center overflow-hidden rounded-full"
                        style="background: var(--ks-bg)"
                    >
                        <img
                            :src="productImageUrl(product.product_id)"
                            :alt="product.name"
                            :title="product.name"
                            class="h-full w-full object-cover"
                            loading="lazy"
                            @error="onImageError"
                        >
                    </div>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold" style="color: var(--ks-text)">
                            {{ order.customer_name || 'Cliente não informado' }}
                            <!-- Pedido de mais de 1 item: cada linha diz qual
                                 item é ("Item 2/2"). Sem isso, 2 itens do
                                 mesmo produto (2 variações do mesmo anúncio
                                 do Mercado Livre caem no mesmo produto local)
                                 ficam com nome/pedido/SKU idênticos e parecem
                                 a MESMA linha repetida por bug de tela — o
                                 operador embala 1 e fecha o pedido faltando
                                 item. -->
                            <span
                                v-if="order.products.length > 1"
                                class="ml-1 rounded px-1.5 py-0.5 text-xs font-bold"
                                style="background: color-mix(in srgb, var(--ks-warning) 20%, transparent); color: var(--ks-warning)"
                            >Item {{ idx + 1 }}/{{ order.products.length }}</span>
                        </p>
                        <p class="text-xs" style="color: var(--ks-text-secondary)">Pedido {{ order.external_order_id || '—' }}</p>
                        <p class="text-xs" style="color: var(--ks-text-secondary)">
                            SKU: {{ product.sku || '—' }}
                            <span
                                class="ml-1 rounded px-1.5 py-0.5 text-xs font-bold"
                                style="background: color-mix(in srgb, var(--ks-brand) 15%, transparent); color: var(--ks-brand)"
                            >QTD: {{ product.quantity }}</span>
                        </p>
                    </div>
                </div>

                <p v-if="order.stock_shortage?.length" class="flex flex-wrap gap-1.5 pt-1">
                    <span
                        v-for="(shortage, idx) in order.stock_shortage"
                        :key="idx"
                        class="rounded-md border px-2 py-0.5 text-xs font-semibold"
                        style="background: color-mix(in srgb, var(--ks-warning) 15%, transparent); border-color: var(--ks-warning); color: var(--ks-warning)"
                    >
                        Falta {{ shortage.missing }}x {{ shortage.sku || shortage.name }}
                    </span>
                </p>
            </div>

            <!-- Canal + status + botão -->
            <div class="flex shrink-0 items-center justify-between gap-4 md:justify-end">
                <span
                    class="rounded-full px-2.5 py-1 text-xs font-bold"
                    :style="{ background: `color-mix(in srgb, ${brand.color} 22%, transparent)`, color: brand.color }"
                >{{ brand.short }}</span>

                <span v-if="isCancelled" class="rounded-md border px-2 py-1 text-xs font-bold"
                    style="background: color-mix(in srgb, var(--ks-error) 15%, transparent); border-color: var(--ks-error); color: var(--ks-error)"
                >CANCELADO</span>

                <div v-else-if="showPackButton" class="flex flex-col items-end gap-1">
                    <span class="flex items-center gap-1.5 text-xs font-semibold" style="color: var(--ks-text)">
                        <span class="h-2 w-2 rounded-full" :style="{ background: statusDotColor }"></span>
                        {{ statusPillText }}
                    </span>
                    <button
                        type="button"
                        class="w-[130px] rounded-lg px-3 py-1.5 text-xs font-medium transition-colors disabled:cursor-not-allowed"
                        :style="isPacked
                            ? { background: 'var(--ks-brand)', color: '#00170F' }
                            : { background: 'transparent', border: '1px solid var(--ks-brand)', color: 'var(--ks-brand)' }"
                        :disabled="isPacked || packing"
                        @click="handlePack"
                    >
                        <i class="fas mr-1" :class="isPacked ? 'fa-check' : 'fa-box'"></i>
                        {{ packButtonText }}
                    </button>
                    <span v-if="error" class="text-xs" style="color: var(--ks-error)">{{ error }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
