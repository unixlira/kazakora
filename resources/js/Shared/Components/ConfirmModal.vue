<script setup>
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

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
    // Largura do painel (Meta ADS 2026-10-19: editar anúncio usa max-w-2xl).
    width: {
        type: String,
        default: 'max-w-lg',
    },
});

const emit = defineEmits(['close', 'confirm']);

const onKeydown = (event) => {
    if (event.key === 'Escape' && props.open && !props.loading) emit('close');
};

onMounted(() => document.addEventListener('keydown', onKeydown));
onUnmounted(() => document.removeEventListener('keydown', onKeydown));

// Foco vai para o modal ao abrir e volta para quem abriu ao fechar (teclado e leitor de tela).
const painel = ref(null);
let focoAnterior = null;
const tituloId = `confirm-modal-${Math.random().toString(36).slice(2, 9)}`;

watch(
    () => props.open,
    async (isOpen) => {
        document.body.style.overflow = isOpen ? 'hidden' : '';
        if (isOpen) {
            focoAnterior = document.activeElement;
            await nextTick();
            painel.value?.focus();
        } else if (focoAnterior instanceof HTMLElement) {
            focoAnterior.focus();
            focoAnterior = null;
        }
    },
);
</script>

<template>
    <Teleport to="body">
        <!-- admin-shell: o Teleport tira o modal de dentro do AdminLayout, e as
             cores do admin (--surface etc.) só valem dentro dessa classe; sem
             ela o modal ficava transparente (visto em 12/10/2026). -->
        <div v-if="open" class="admin-shell fixed inset-0 z-[100] flex items-center justify-center p-4 text-[var(--surface-foreground)]">
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="!loading && emit('close')"></div>
            <div ref="painel" role="dialog" aria-modal="true" :aria-labelledby="tituloId" tabindex="-1"
                class="relative w-full overflow-y-auto rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-6 shadow-xl focus:outline-none"
                :class="width" style="max-height: 90vh;">
                <button type="button" :disabled="loading"
                    class="absolute right-4 top-4 flex h-8 w-8 items-center justify-center rounded-full text-slate-400 hover:bg-[var(--surface-muted)] disabled:opacity-50"
                    aria-label="Fechar" @click="emit('close')">
                    <i class="fas fa-xmark" aria-hidden="true"></i>
                </button>

                <h2 :id="tituloId" class="pr-8 text-lg font-semibold">{{ title }}</h2>

                <div class="mt-3">
                    <slot />
                </div>

                <div class="mt-5 flex flex-wrap justify-end gap-2">
                    <slot name="actions">
                    <button type="button" :disabled="loading"
                        class="rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:bg-[var(--surface-muted)] disabled:opacity-50"
                        @click="emit('close')">
                        {{ cancelLabel }}
                    </button>
                    <button type="button" :disabled="loading || confirmDisabled"
                        class="rounded-lg px-4 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-50"
                        :class="danger ? 'bg-error hover:opacity-90' : 'bg-primary hover:bg-primary-emphasis'"
                        @click="emit('confirm')">
                        <i v-if="loading" class="fas fa-spinner fa-spin mr-1.5" aria-hidden="true"></i>
                        {{ confirmLabel }}
                    </button>
                    </slot>
                </div>
            </div>
        </div>
    </Teleport>
</template>
