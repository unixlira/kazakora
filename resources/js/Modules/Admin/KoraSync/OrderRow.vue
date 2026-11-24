<script setup>
import { computed } from 'vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import { channelBrand } from './channelBrand';
import { shippingBadge } from './shippingBadge';

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

// Tipo de envio (Flex / Mercado Envios / Full / Xpress / coleta...): canal
// sozinho não diz como a caixa sai da loja — ver TipoDeEnvio no backend.
const envio = computed(() => shippingBadge(props.order));

// Full: o produto está no galpão do Mercado Livre, não no nosso. O card
// aparece na fila pra contar a venda do dia e pro operador dar baixa quando
// vê — sem o aviso, alguém sai procurando o produto na prateleira.
const ehFull = computed(() => props.order.shipping_type === 'full');

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
        class="mb-2.5 rounded-[10px] border p-3 @md:p-3.5 @2xl:p-4"
        :style="{
            background: isCancelled ? 'rgba(255,82,82,0.15)' : 'var(--ks-row-bg)',
            borderColor: isCancelled ? 'var(--ks-error)' : 'var(--ks-border)',
            borderWidth: isCancelled ? '2px' : '1px',
        }"
    >
        <!-- Layout por largura do CARD (container query, @container em
             Index.vue), não da janela: com menu lateral + Meta do dia a
             coluna da fila fica estreita mesmo em tela grande. Abaixo de
             @2xl o rodapé (canal, envio, status, botão) vai pra baixo das
             fotos; a partir dele volta a ser 1 linha à direita. -->
        <div class="flex flex-col gap-3 @2xl:flex-row @2xl:items-center @2xl:justify-between">
            <!-- Fotos + dados, 1 linha por produto -->
            <div class="min-w-0 flex-1 space-y-2">
                <div v-for="(product, idx) in order.products" :key="product.id ?? idx" class="flex items-center gap-3">
                    <div
                        class="flex h-12 w-12 shrink-0 @md:h-[52px] @md:w-[52px] items-center justify-center overflow-hidden rounded-full"
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
                    <div class="min-w-0 flex-1">
                        <p class="flex min-w-0 items-center gap-1.5 text-sm font-semibold" style="color: var(--ks-text)">
                            <span class="truncate" :title="order.customer_name">{{ order.customer_name || 'Cliente não informado' }}</span>
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
                                class="shrink-0 whitespace-nowrap rounded px-1.5 py-0.5 text-xs font-bold"
                                style="background: color-mix(in srgb, var(--ks-warning) 20%, transparent); color: var(--ks-warning)"
                            >Item {{ idx + 1 }}/{{ order.products.length }}</span>
                        </p>
                        <p class="mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs" style="color: var(--ks-text-secondary)">
                            <span class="break-all">Pedido {{ order.external_order_id || '—' }}</span>
                            <!-- Carrinho do Mercado Livre: 2+ pedidos do
                                 canal na MESMA caixa, com uma etiqueta só
                                 (ver groupOrdersShippedTogether no
                                 DashboardAgentController). O card já é a
                                 caixa inteira; o selo mostra os outros
                                 números pro operador conferir contra o
                                 painel do canal. -->
                            <span
                                v-if="order.pack_order_count > 1"
                                class="whitespace-nowrap rounded px-1.5 py-0.5 text-xs font-bold"
                                :title="`Carrinho do canal — pedidos: ${(order.pack_external_order_ids ?? []).join(', ')}`"
                                style="background: color-mix(in srgb, var(--ks-brand) 18%, transparent); color: var(--ks-brand)"
                            >Carrinho: {{ order.pack_order_count }} pedidos, 1 etiqueta</span>
                        </p>
                        <p class="mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs" style="color: var(--ks-text-secondary)">
                            <span class="break-all">SKU: {{ product.sku || '—' }}</span>
                            <span
                                class="whitespace-nowrap rounded px-1.5 py-0.5 text-xs font-bold"
                                style="background: color-mix(in srgb, var(--ks-brand) 15%, transparent); color: var(--ks-brand)"
                            >QTD: {{ product.quantity }}</span>
                        </p>
                    </div>
                </div>

                <p v-if="ehFull && !isCancelled" class="pt-1">
                    <span
                        class="inline-flex items-center gap-1.5 rounded-md border px-2 py-0.5 text-xs font-semibold"
                        title="Pedido Full: o Mercado Livre separa e envia. Aqui é só dar baixa pra venda contar no dia."
                        style="background: rgba(167,139,250,0.14); border-color: rgba(167,139,250,0.45); color: #A78BFA"
                    >
                        <i class="fas fa-warehouse text-[10px]"></i>
                        Full — quem separa e envia é o Mercado Livre
                    </span>
                </p>

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

            <!-- Canal + envio + status + botão -->
            <div
                class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2.5 border-t pt-3 @2xl:shrink-0 @2xl:flex-nowrap @2xl:justify-end @2xl:border-t-0 @2xl:pt-0"
                style="border-color: var(--ks-border)"
            >
                <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                    <span
                        class="whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-bold"
                        :style="{ background: `color-mix(in srgb, ${brand.color} 22%, transparent)`, color: brand.color }"
                    >{{ brand.short }}</span>

                    <!-- Tipo de entrega sempre numa linha só (pedido do Lira
                         24/11): nunca quebra; se faltar espaço, corta com
                         reticências e o nome inteiro fica no title. -->
                    <span
                        class="inline-flex min-w-0 max-w-full items-center gap-1 whitespace-nowrap rounded-full border px-2 py-1 text-xs font-bold @2xl:max-w-[15rem]"
                        :title="envio.titulo"
                        :style="{
                            background: `color-mix(in srgb, ${envio.cor} 16%, transparent)`,
                            borderColor: `color-mix(in srgb, ${envio.cor} 55%, transparent)`,
                            color: envio.cor,
                        }"
                    >
                        <i class="fas shrink-0 text-[10px]" :class="envio.icone"></i>
                        <span class="truncate">{{ envio.texto }}</span>
                    </span>
                </div>

                <StatusBadge v-if="isCancelled" status="cancelled" context="order" />

                <!-- No celular: status à esquerda e botão grande (40 px) à
                     direita, na largura toda do card; no desktop volta a
                     ser a coluna status-em-cima-botão-embaixo. -->
                <div
                    v-else-if="showPackButton"
                    class="flex w-full flex-wrap items-center justify-between gap-x-3 gap-y-1 @sm:w-auto @2xl:flex-col @2xl:flex-nowrap @2xl:items-end @2xl:gap-1"
                >
                    <span class="flex items-center gap-1.5 whitespace-nowrap text-xs font-semibold" style="color: var(--ks-text)">
                        <span class="h-2 w-2 shrink-0 rounded-full" :style="{ background: statusDotColor }"></span>
                        {{ statusPillText }}
                    </span>
                    <button
                        type="button"
                        class="min-h-10 min-w-[150px] whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium transition-colors disabled:cursor-not-allowed @2xl:min-h-0 @2xl:w-[130px] @2xl:min-w-0 @2xl:text-xs"
                        :style="isPacked
                            ? { background: 'var(--ks-brand)', color: '#00170F' }
                            : { background: 'transparent', border: '1px solid var(--ks-brand)', color: 'var(--ks-brand)' }"
                        :disabled="isPacked || packing"
                        @click="handlePack"
                    >
                        <i class="fas mr-1" :class="isPacked ? 'fa-check' : 'fa-box'"></i>
                        {{ packButtonText }}
                    </button>
                    <span v-if="error" class="basis-full text-right text-xs" style="color: var(--ks-error)">{{ error }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
