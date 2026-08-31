<script setup>
import { computed } from 'vue';

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

const createdDate = computed(() => {
    if (!props.shipment.created_at) return '—';

    return new Date(props.shipment.created_at).toLocaleDateString('pt-BR');
});
</script>

<template>
    <div
        class="mb-2.5 flex flex-col gap-2 rounded-[10px] border p-3.5 md:flex-row md:items-center md:justify-between md:p-4"
        style="background: var(--ks-row-bg); border-color: var(--ks-border)"
    >
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold" style="color: var(--ks-text)">{{ shipment.customer_name || 'Cliente não informado' }}</p>
            <p class="text-xs" style="color: var(--ks-text-secondary)">
                Pedido {{ shipment.external_order_id || '—' }} · Criado: {{ createdDate }}
            </p>
            <p class="mt-1 truncate text-xs" style="color: var(--ks-text-secondary)">
                {{ shipment.products?.map((p) => `${p.quantity}x ${p.name}`).join(', ') || '—' }}
            </p>
        </div>

        <div class="flex shrink-0 items-center gap-3 md:flex-col md:items-end md:gap-1">
            <span
                class="rounded-full px-2.5 py-1 text-xs font-bold"
                :style="shipment.is_overdue
                    ? { background: 'color-mix(in srgb, var(--ks-error) 20%, transparent)', color: 'var(--ks-error)' }
                    : { background: 'color-mix(in srgb, #FFE600 20%, transparent)', color: '#B59F00' }"
            >
                <i class="fas fa-calendar-day mr-1"></i>
                Agendado {{ scheduledDate }}
            </span>
            <span v-if="shipment.is_overdue" class="text-xs font-semibold" style="color: var(--ks-error)">Venceu — canal ainda não liberou</span>
            <span v-else class="text-xs" style="color: var(--ks-text-secondary)">{{ shipment.shipping_method || 'Aguardando liberação' }}</span>
        </div>
    </div>
</template>
