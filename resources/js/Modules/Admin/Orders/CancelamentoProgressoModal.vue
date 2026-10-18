<script setup>
import { onUnmounted, ref, watch } from 'vue';

// Resultado do "Cancelar pedido" (Lira, 2026-10-18): o SweetAlert mostrava
// tudo pronto de uma vez. Agora cada etapa (nota, estorno, estoque, pedido,
// e-mail) entra uma por vez com o carregando ao lado, e o check verde grande
// só aparece depois da última. O backend já fez tudo quando o modal abre:
// aqui só a apresentação é em sequência, o resultado de cada etapa é o real.
const props = defineProps({
    open: { type: Boolean, default: false },
    resultado: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const TEMPO_DA_ETAPA = 850;
const PAUSA_ENTRE_ETAPAS = 250;

const visiveis = ref(0);
const concluidas = ref(0);
const terminou = ref(false);
let timers = [];

const limpar = () => {
    timers.forEach(clearTimeout);
    timers = [];
};
const depois = (ms, fn) => timers.push(setTimeout(fn, ms));

const animar = () => {
    limpar();
    visiveis.value = 0;
    concluidas.value = 0;
    terminou.value = false;

    const etapas = props.resultado?.etapas ?? [];
    let t = 150;
    etapas.forEach((_, i) => {
        depois(t, () => { visiveis.value = i + 1; });
        t += TEMPO_DA_ETAPA;
        depois(t, () => { concluidas.value = i + 1; });
        t += PAUSA_ENTRE_ETAPAS;
    });
    depois(t + 150, () => { terminou.value = true; });
};

const fechar = () => {
    if (terminou.value) emit('close');
};

const onKeydown = (event) => {
    if (event.key === 'Escape' && props.open) fechar();
};

watch(
    () => props.open,
    (aberto) => {
        document.body.style.overflow = aberto ? 'hidden' : '';
        if (aberto) {
            document.addEventListener('keydown', onKeydown);
            animar();
        } else {
            document.removeEventListener('keydown', onKeydown);
            limpar();
        }
    },
    { immediate: true },
);

onUnmounted(() => {
    limpar();
    document.removeEventListener('keydown', onKeydown);
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <!-- admin-shell: mesmas cores do admin dentro do Teleport (ver ConfirmModal). -->
        <div v-if="open && resultado" class="admin-shell fixed inset-0 z-[100] flex items-center justify-center p-4 text-[var(--surface-foreground)]">
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="fechar"></div>
            <div class="relative w-full max-w-sm overflow-y-auto rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-xl"
                style="max-height: 90vh;" role="dialog" aria-modal="true" aria-live="polite">
                <Transition name="final">
                    <div v-if="terminou" class="mb-4 flex flex-col items-center text-center">
                        <span class="flex h-16 w-16 items-center justify-center rounded-full"
                            :class="resultado.ok ? 'bg-green-100 text-green-600' : 'bg-amber-100 text-amber-600'">
                            <i class="fas text-3xl" :class="resultado.ok ? 'fa-check' : 'fa-triangle-exclamation'" aria-hidden="true"></i>
                        </span>
                        <h2 class="mt-3 text-base font-semibold">
                            {{ resultado.ok ? 'Pedido cancelado com sucesso' : 'Pedido cancelado, mas falta uma etapa' }}
                        </h2>
                    </div>
                </Transition>

                <h2 v-if="!terminou" class="mb-3 text-base font-semibold">Cancelando pedido…</h2>

                <TransitionGroup tag="ul" name="etapa" class="space-y-2">
                    <li v-for="(etapa, i) in resultado.etapas.slice(0, visiveis)" :key="etapa.etapa + i"
                        class="flex items-start gap-2.5 text-sm">
                        <span class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center">
                            <i v-if="i >= concluidas" class="fas fa-spinner fa-spin text-slate-400" aria-hidden="true"></i>
                            <i v-else-if="etapa.ok" class="fas fa-check text-green-600" aria-hidden="true"></i>
                            <i v-else class="fas fa-xmark text-red-600" aria-hidden="true"></i>
                        </span>
                        <span class="min-w-0">
                            <strong class="block">{{ etapa.etapa }}</strong>
                            <span v-if="i < concluidas" class="block text-xs" :class="etapa.ok ? 'text-slate-500' : 'text-red-600'">{{ etapa.texto }}</span>
                            <span v-else class="block text-xs text-slate-400">Processando…</span>
                        </span>
                    </li>
                </TransitionGroup>

                <Transition name="final">
                    <div v-if="terminou" class="mt-4 flex justify-end">
                        <button type="button" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis"
                            @click="emit('close')">
                            Ok
                        </button>
                    </div>
                </Transition>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.etapa-enter-active,
.final-enter-active {
    transition: opacity 0.35s ease, transform 0.35s ease;
}
.etapa-enter-from {
    opacity: 0;
    transform: translateY(4px);
}
.final-enter-from {
    opacity: 0;
    transform: scale(0.9);
}
@media (prefers-reduced-motion: reduce) {
    .etapa-enter-active,
    .final-enter-active {
        transition: opacity 0.2s ease;
    }
    .etapa-enter-from,
    .final-enter-from {
        transform: none;
    }
}
</style>
