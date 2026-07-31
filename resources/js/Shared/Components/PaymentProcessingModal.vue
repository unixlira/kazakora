<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    state: {
        type: String,
        default: 'processing',
        validator: (value) => ['processing', 'awaiting_pix', 'success', 'timeout'].includes(value),
    },
    orderTotal: { type: Number, default: null },
    pixQrImageUrl: { type: String, default: null },
    pixQrCode: { type: String, default: null },
    pixSecondsLeft: { type: Number, default: null },
});

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const pixCountdown = computed(() => {
    if (props.pixSecondsLeft === null) return null;
    const minutes = Math.floor(Math.max(0, props.pixSecondsLeft) / 60);
    const seconds = Math.max(0, props.pixSecondsLeft) % 60;
    return `${minutes}:${String(seconds).padStart(2, '0')}`;
});

const copied = ref(false);

const copyPixCode = async () => {
    if (! props.pixQrCode) return;
    await navigator.clipboard.writeText(props.pixQrCode);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 2000);
};
</script>

<template>
    <!-- Não fechável: sem botão de X, sem clique fora, sem ESC — de propósito. -->
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[120] flex items-center justify-center p-4" role="alertdialog" aria-modal="true">
            <div class="absolute inset-0 bg-store-fg/60 backdrop-blur-sm"></div>

            <div class="relative w-full max-w-[440px] rounded-2xl bg-store-bg-raised p-8 text-center shadow-xl">
                <template v-if="state === 'processing' || state === 'timeout'">
                    <div class="mx-auto flex h-14 w-14 items-center justify-center">
                        <i class="fas fa-circle-notch animate-spin text-4xl text-store-accent"></i>
                    </div>
                    <h2 class="mt-4 font-display text-xl font-semibold text-store-fg">Processando seu pagamento...</h2>
                    <p class="mt-2 text-sm text-store-fg-muted">Não feche nem atualize esta página.</p>

                    <p v-if="state === 'timeout'" class="mt-4 rounded-lg bg-amber-100 px-4 py-3 text-sm text-amber-800 dark:bg-amber-900/50 dark:text-amber-200">
                        Ainda estamos confirmando seu pagamento — pode levar mais alguns instantes. Você pode acompanhar em
                        <Link href="/pedidos" class="font-semibold underline">Meus Pedidos</Link>.
                    </p>
                </template>

                <template v-else-if="state === 'awaiting_pix'">
                    <h2 class="font-display text-xl font-semibold text-store-fg">Aguardando pagamento via Pix</h2>
                    <p class="mt-1 text-sm text-store-fg-muted">Escaneie o QR Code ou copie o código abaixo no app do seu banco.</p>

                    <img v-if="pixQrImageUrl" :src="pixQrImageUrl" alt="QR Code Pix" class="mx-auto mt-4 h-48 w-48 rounded-xl border border-store-border">

                    <button v-if="pixQrCode" type="button"
                        class="mt-4 w-full truncate rounded-lg border border-store-border-strong bg-store-bg px-3 py-2 text-xs text-store-fg-muted hover:border-store-accent"
                        @click="copyPixCode">
                        <i class="fas mr-1.5" :class="copied ? 'fa-check text-emerald-500' : 'fa-copy'"></i>
                        {{ copied ? 'Copiado!' : 'Copiar código Pix' }}
                    </button>

                    <p v-if="pixCountdown" class="mt-4 inline-flex items-center gap-2 rounded-full bg-store-bg-sunken px-3 py-1 text-sm font-semibold text-store-fg">
                        <i class="fas fa-clock text-store-accent"></i>
                        Expira em {{ pixCountdown }}
                    </p>
                </template>

                <template v-else-if="state === 'success'">
                    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/50">
                        <i class="fas fa-check text-2xl text-emerald-600 dark:text-emerald-300"></i>
                    </div>
                    <h2 class="mt-4 font-display text-xl font-semibold text-store-fg">Pagamento confirmado!</h2>
                    <p v-if="orderTotal !== null" class="mt-2 text-sm text-store-fg-muted">
                        Total pago: <span class="font-semibold text-store-fg">{{ formatPrice(orderTotal) }}</span>
                    </p>
                    <p class="mt-1 text-sm text-store-fg-muted">Redirecionando para os detalhes do pedido...</p>
                </template>
            </div>
        </div>
    </Teleport>
</template>
