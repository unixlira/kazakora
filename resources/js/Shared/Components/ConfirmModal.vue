<script setup>
import { onMounted, onUnmounted, watch } from 'vue';

// Modal de confirmação genérico do admin — pedido explícito 2026-08-09
// (cancelamento de nota fiscal). Diferente de Shared/Modal.vue (que usa os
// tokens --color-store-* da loja): este usa --surface/--surface-border, os
// mesmos tokens que o resto do admin (Invoices/Show.vue, DataTable, etc.)
// já usa, pra não renderizar com a paleta errada dentro do AdminLayout.
const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        required: true,
    },
    confirmLabel: {
        type: String,
        default: 'Confirmar',
    },
    cancelLabel: {
        type: String,
        default: 'Voltar',
    },
    danger: {
        type: Boolean,
        default: false,
    },
    loading: {
        type: Boolean,
        default: false,
    },
    confirmDisabled: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close', 'confirm']);

const onKeydown = (event) => {
    if (event.key === 'Escape' && props.open && !props.loading) emit('close');
};

onMounted(() => document.addEventListener('keydown', onKeydown));
onUnmounted(() => document.removeEventListener('keydown', onKeydown));

watch(
    () => props.open,
    (isOpen) => {
        document.body.style.overflow = isOpen ? 'hidden' : '';
    },
);
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[100] flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="!loading && emit('close')"></div>
            <div class="relative w-full max-w-lg overflow-y-auto rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-6 shadow-xl"
                style="max-height: 90vh;">
                <button type="button" :disabled="loading"
                    class="absolute right-4 top-4 flex h-8 w-8 items-center justify-center rounded-full text-slate-400 hover:bg-[var(--surface-muted)] disabled:opacity-50"
                    aria-label="Fechar" @click="emit('close')">
                    <i class="fas fa-xmark"></i>
                </button>

                <h2 class="pr-8 text-lg font-semibold">{{ title }}</h2>

                <div class="mt-3">
                    <slot />
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" :disabled="loading"
                        class="rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:bg-[var(--surface-muted)] disabled:opacity-50"
                        @click="emit('close')">
                        {{ cancelLabel }}
                    </button>
                    <button type="button" :disabled="loading || confirmDisabled"
                        class="rounded-lg px-4 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-50"
                        :class="danger ? 'bg-error hover:opacity-90' : 'bg-primary hover:bg-primary-emphasis'"
                        @click="emit('confirm')">
                        <i v-if="loading" class="fas fa-spinner fa-spin mr-1.5"></i>
                        {{ confirmLabel }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
