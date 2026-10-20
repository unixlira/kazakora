<script setup>
import { nextTick, onUnmounted, ref, watch } from 'vue';

// Modal único de espera do admin (Meta ADS 2026-10-20, pedido do Lira): no
// lugar dos avisos que abriam e fechavam um atrás do outro no canto da
// tela, um modal no centro com o carregando, que some sozinho quando o
// processo termina (quem usa só controla o open). Sem X e sem clique fora;
// com podeSair, mostra o botão para continuar usando a tela enquanto espera.
const props = defineProps({
    open: { type: Boolean, default: false },
    titulo: { type: String, default: 'Aguarde enquanto o anúncio é preparado' },
    mensagem: { type: String, default: '' },
    // 0 a 100; null = sem barra (só o carregando).
    progresso: { type: Number, default: null },
    detalhe: { type: String, default: '' },
    podeSair: { type: Boolean, default: false },
    rotuloSair: { type: String, default: 'Continuar usando a tela' },
});

const emit = defineEmits(['sair']);

const painel = ref(null);
const tituloId = `aguarde-modal-${Math.random().toString(36).slice(2, 9)}`;

watch(
    () => props.open,
    async (aberto) => {
        document.body.style.overflow = aberto ? 'hidden' : '';
        if (aberto) {
            await nextTick();
            painel.value?.focus();
        }
    },
    { immediate: true },
);
onUnmounted(() => { document.body.style.overflow = ''; });
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition-opacity duration-150" enter-from-class="opacity-0" leave-active-class="transition-opacity duration-200" leave-to-class="opacity-0">
            <div v-if="open" class="admin-shell fixed inset-0 z-[110] flex items-center justify-center p-4 text-[var(--surface-foreground)]">
                <div class="absolute inset-0 bg-black/50 backdrop-blur-sm"></div>
                <div ref="painel" role="alertdialog" aria-modal="true" :aria-labelledby="tituloId" aria-busy="true" tabindex="-1"
                    class="relative w-full max-w-md rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-6 text-center shadow-xl focus:outline-none">
                    <div class="mx-auto flex h-14 w-14 items-center justify-center">
                        <i class="fas fa-circle-notch animate-spin text-4xl text-primary motion-reduce:animate-none" aria-hidden="true"></i>
                    </div>
                    <h2 :id="tituloId" class="mt-4 text-lg font-semibold">{{ titulo }}</h2>
                    <p v-if="mensagem" class="mt-2 text-sm text-slate-500">{{ mensagem }}</p>

                    <div v-if="progresso !== null" class="mx-auto mt-4 h-2 max-w-xs overflow-hidden rounded-full bg-slate-500/20"
                        role="progressbar" :aria-valuenow="Math.round(progresso)" aria-valuemin="0" aria-valuemax="100" :aria-label="titulo">
                        <div class="h-full bg-primary transition-all duration-300" :style="{ width: `${Math.max(3, Math.min(100, progresso))}%` }"></div>
                    </div>
                    <p v-if="detalhe" class="mt-2 text-xs font-medium text-slate-500" role="status" aria-live="polite">{{ detalhe }}</p>

                    <button v-if="podeSair" type="button"
                        class="mt-5 rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:bg-[var(--surface-muted)]"
                        @click="emit('sair')">
                        {{ rotuloSair }}
                    </button>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
