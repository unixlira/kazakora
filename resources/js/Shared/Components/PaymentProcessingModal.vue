<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    state: {
        type: String,
        default: 'processing',
        validator: (value) => ['processing', 'awaiting_pix', 'success', 'timeout', 'failed'].includes(value),
    },
    orderTotal: { type: Number, default: null },
    // Número do pedido no formato da Shopee (pedido 2026-10-11).
    orderCode: { type: String, default: null },
    pixQrImageUrl: { type: String, default: null },
    pixQrCode: { type: String, default: null },
    pixSecondsLeft: { type: Number, default: null },
    failureMessage: { type: String, default: null },
});

const emit = defineEmits(['retry']);

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const pixCountdown = computed(() => {
    if (props.pixSecondsLeft === null) return null;
    const minutes = Math.floor(Math.max(0, props.pixSecondsLeft) / 60);
    const seconds = Math.max(0, props.pixSecondsLeft) % 60;
    return `${minutes}:${String(seconds).padStart(2, '0')}`;
});

const copied = ref(false);
const codigoCopiado = ref(false);
const copiarCodigoDoPedido = async () => {
    if (! props.orderCode) return;
    await navigator.clipboard.writeText(props.orderCode);
    codigoCopiado.value = true;
    setTimeout(() => { codigoCopiado.value = false; }, 2000);
};

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

            <div class="relative w-full max-w-[440px] rounded-2xl p-8 text-center shadow-xl" :class="state === 'awaiting_pix' ? 'max-h-[95vh] overflow-y-auto bg-white [color-scheme:light]' : 'bg-store-bg-raised'">
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
                    <!-- Pix com mais cor (pedido 2026-10-11): faixa verde com o valor,
                         QR em destaque, passo a passo e a tarja de confiança no rodapé. -->
                    <div class="-mx-8 -mt-8 rounded-t-2xl bg-gradient-to-br from-[#0FB930] to-[#0a8a23] px-6 pb-5 pt-6 text-white">
                        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-white/20 text-2xl">
                            <i class="fa-brands fa-pix"></i>
                        </div>
                        <h2 class="mt-2 font-display text-xl font-bold">Pague com Pix</h2>
                        <!-- Número do pedido em destaque (laranja da marca) com botão de copiar. -->
                        <div v-if="orderCode" class="mx-auto mt-2 flex w-fit items-center gap-2 rounded-xl bg-white px-3 py-1.5 shadow-md">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Pedido</span>
                            <span class="text-xl font-black uppercase italic tracking-tight text-[#F27A2A]">{{ orderCode }}</span>
                            <button type="button" class="flex h-7 w-7 items-center justify-center rounded-lg text-sm transition"
                                :class="codigoCopiado ? 'bg-emerald-100 text-emerald-600' : 'bg-orange-50 text-[#F27A2A] hover:bg-orange-100'"
                                :aria-label="codigoCopiado ? 'Número copiado' : 'Copiar número do pedido'" @click="copiarCodigoDoPedido">
                                <i class="fas" :class="codigoCopiado ? 'fa-check' : 'fa-copy'"></i>
                            </button>
                        </div>
                        <p v-if="orderTotal !== null" class="mt-2 text-sm font-semibold opacity-90">Total: {{ formatPrice(orderTotal) }}</p>
                        <p v-if="pixCountdown" class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-black/25 px-3 py-1 text-xs font-bold">
                            <i class="fas fa-stopwatch animate-pulse"></i> Expira em {{ pixCountdown }}
                        </p>
                    </div>

                    <div class="mx-auto mt-5 w-fit rounded-2xl border-4 border-[#0FB930] bg-white p-2 shadow-md">
                        <img v-if="pixQrImageUrl" :src="pixQrImageUrl" alt="QR Code Pix" class="h-48 w-48">
                    </div>

                    <ol class="mx-auto mt-4 flex max-w-[320px] flex-col gap-1.5 text-left text-sm text-slate-700">
                        <li class="flex gap-2"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[#0FB930] text-[11px] font-bold text-white">1</span><span>Abra o app do seu banco e escolha <strong>Pix</strong>.</span></li>
                        <li class="flex gap-2"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[#0FB930] text-[11px] font-bold text-white">2</span><span>Escaneie o QR Code ou use o <strong>copia e cola</strong>.</span></li>
                        <li class="flex gap-2"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[#0FB930] text-[11px] font-bold text-white">3</span><span>Pronto! A confirmação aparece aqui sozinha.</span></li>
                    </ol>

                    <button v-if="pixQrCode" type="button"
                        class="mt-4 flex w-full items-center justify-center gap-2 rounded-xl px-4 py-3.5 text-sm font-bold text-white shadow-md transition"
                        :class="copied ? 'bg-emerald-600' : 'bg-[#0FB930] hover:brightness-95'"
                        @click="copyPixCode">
                        <i class="fas" :class="copied ? 'fa-check' : 'fa-copy'"></i>
                        {{ copied ? 'Código copiado! Cole no app do banco' : 'Copiar código Pix' }}
                    </button>
                    <p v-if="pixQrCode" class="mt-1.5 truncate px-2 font-mono text-[10px] text-slate-400">{{ pixQrCode }}</p>

                    <p class="mt-3 flex items-center justify-center gap-1.5 text-xs text-slate-500">
                        <i class="fas fa-circle-notch animate-spin text-[#0FB930]"></i> Aguardando o pagamento…
                    </p>

                    <!-- Tarja de confiança, como no topo da finalização. -->
                    <div class="-mx-8 -mb-8 mt-5 flex items-center justify-center gap-2 rounded-b-2xl bg-[#111] px-3 py-3 text-[11px] font-bold uppercase tracking-wide text-white sm:text-xs">
                        <span class="whitespace-nowrap"><i class="fa-solid fa-truck-fast mr-1 text-[#0FB930]"></i>Frete grátis <span class="italic text-[#22c55e]"><i class="fa-solid fa-bolt"></i>FULL</span></span>
                        <span class="opacity-40">|</span>
                        <span class="whitespace-nowrap"><i class="fa-solid fa-lock mr-1 text-[#0FB930]"></i>Compra 100% segura</span>
                    </div>
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

                <template v-else-if="state === 'failed'">
                    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/50">
                        <i class="fas fa-xmark text-2xl text-red-600 dark:text-red-300"></i>
                    </div>
                    <h2 class="mt-4 font-display text-xl font-semibold text-store-fg">Pagamento não concluído</h2>
                    <p class="mt-2 text-sm text-store-fg-muted">{{ failureMessage ?? 'Não foi possível confirmar esse pagamento.' }}</p>
                    <button type="button"
                        class="mt-6 w-full rounded-lg bg-store-accent px-6 py-3 text-sm font-semibold text-store-accent-contrast hover:opacity-90"
                        @click="emit('retry')">
                        Tentar novamente
                    </button>
                </template>
            </div>
        </div>
    </Teleport>
</template>
