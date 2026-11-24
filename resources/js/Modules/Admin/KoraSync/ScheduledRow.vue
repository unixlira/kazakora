<script setup>
import { computed } from 'vue';
import { shippingBadge } from './shippingBadge';

/**
 * Linha da aba "Vendas futuras" (scheduled-shipments) — venda com entrega
 * agendada pelo canal (Coleta/Places do Mercado Livre), etiqueta ainda não
 * liberada. Sem botão de embalar aqui (mesma regra do app desktop: essa
 * aba é só visibilidade/aviso, embalar acontece na Fila normal quando o
 * canal liberar de verdade).
 */
const props = defineProps({
    shipment: { type: Object, required: true },
});

const scheduledDate = computed(() => {
    if (!props.shipment.scheduled_for) return '—';

    return new Date(props.shipment.scheduled_for).toLocaleDateString('pt-BR');
});

// Antes esta linha mostrava o shipping_method cru ("xd_drop_off") no
// rodapé do card — código de API, não informação pro operador.
const envio = computed(() => shippingBadge(props.shipment));

const createdDate = computed(() => {
    if (!props.shipment.created_at) return '—';

    return new Date(props.shipment.created_at).toLocaleDateString('pt-BR');
});
</script>

<template>
    <div
        class="mb-2.5 flex flex-col gap-3 rounded-[10px] border p-3 @md:p-3.5 @2xl:flex-row @2xl:items-center @2xl:justify-between @2xl:gap-2 @2xl:p-4"
        style="background: var(--ks-row-bg); border-color: var(--ks-border)"
    >
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold" style="color: var(--ks-text)" :title="shipment.customer_name">{{ shipment.customer_name || 'Cliente não informado' }}</p>
            <p class="mt-0.5 flex flex-wrap gap-x-1 text-xs" style="color: var(--ks-text-secondary)">
                <span class="break-all">Pedido {{ shipment.external_order_id || '—' }}</span>
                <span class="whitespace-nowrap">· Criado: {{ createdDate }}</span>
            </p>
            <p class="mt-1 truncate text-xs" style="color: var(--ks-text-secondary)">
                {{ shipment.products?.map((p) => `${p.quantity}x ${p.name}`).join(', ') || '—' }}
            </p>
        </div>

        <!-- Celular: selos lado a lado (quebram inteiros, nunca no meio da
             palavra) e o aviso embaixo; desktop: coluna à direita. -->
        <div
            class="flex flex-wrap items-center gap-x-2 gap-y-2 border-t pt-3 @2xl:shrink-0 @2xl:flex-col @2xl:items-end @2xl:gap-1 @2xl:border-t-0 @2xl:pt-0"
            style="border-color: var(--ks-border)"
        >
            <span
                class="whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-bold"
                :style="shipment.is_overdue
                    ? { background: 'color-mix(in srgb, var(--ks-error) 20%, transparent)', color: 'var(--ks-error)' }
                    : { background: 'color-mix(in srgb, #FFE600 20%, transparent)', color: '#B59F00' }"
            >
                <i class="fas fa-calendar-day mr-1"></i>
                Agendado {{ scheduledDate }}
            </span>
            <!-- Tipo de entrega numa linha só (pedido do Lira 24/11). -->
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
            <span v-if="shipment.is_overdue" class="basis-full text-xs font-semibold @2xl:basis-auto" style="color: var(--ks-error)">Venceu — canal ainda não liberou</span>
            <span v-else class="basis-full text-xs @2xl:basis-auto" style="color: var(--ks-text-secondary)">Aguardando liberação</span>
        </div>
    </div>
</template>
