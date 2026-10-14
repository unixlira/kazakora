<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    state: {
        type: String,
        default: 'processing',
        validator: (value) => ['processing', 'awaiting_pix', 'boleto', 'success', 'timeout', 'failed'].includes(value),
    },
    orderTotal: { type: Number, default: null },
    // Número do pedido no formato da Shopee (pedido 2026-10-11).
    orderCode: { type: String, default: null },
    pixQrImageUrl: { type: String, default: null },
    pixQrCode: { type: String, default: null },
    pixSecondsLeft: { type: Number, default: null },
    failureMessage: { type: String, default: null },
    // Para onde vai o botão "Ver minha compra" no sucesso (e no boleto gerado).
    successUrl: { type: String, default: null },
    // Boleto gerado (2026-10-14): { linhaDigitavel, pdfUrl, vencimento }.
    boleto: { type: Object, default: null },
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

const linhaCopiada = ref(false);
const copiarLinha = async () => {
    if (! props.boleto?.linhaDigitavel) return;
    await navigator.clipboard.writeText(props.boleto.linhaDigitavel);
    linhaCopiada.value = true;
    setTimeout(() => { linhaCopiada.value = false; }, 2000);
};
const vencimentoBoleto = computed(() => {
    if (! props.boleto?.vencimento) return null;
    return new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'America/Sao_Paulo' })
        .format(new Date(props.boleto.vencimento));
});

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
        <div v-if="open" class="fixed inset-0 z-[120] flex items-center justify-center p-3 sm:p-4" role="alertdialog" aria-modal="true">
            <!-- Pix (pedido 2026-10-12): fundo cinza grafite suave em vez do preto. -->
            <div class="absolute inset-0 backdrop-blur-sm" :class="state === 'awaiting_pix' ? 'bg-zinc-700/60' : 'bg-store-fg/60'"></div>

            <!-- Pix (pedido 2026-10-12): sem padding no cartão, cada faixa cuida do seu,
                 e tudo dimensionado pela altura da tela para caber sem rolagem. -->
            <div class="relative max-h-[calc(100dvh-1.5rem)] w-full max-w-[420px] overflow-y-auto overflow-x-hidden rounded-2xl text-center shadow-xl" :class="state === 'awaiting_pix' ? 'bg-white p-0 [color-scheme:light]' : 'bg-store-bg-raised p-8'">
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
                    <!-- Pix (pedido 2026-10-12): sai o verde, entra o grafite da tarja de
                         confiança com o laranja da marca. Cabeçalho em linha e QR que encolhe
                         com a altura da tela, para não aparecer barra de rolagem. -->
                    <div class="rounded-t-2xl bg-gradient-to-br from-[#1c1c1e] to-[#111] px-5 pb-4 pt-4 text-white">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="flex items-center gap-2 whitespace-nowrap font-display text-lg font-bold">
                                <i class="fa-brands fa-pix text-[#32BCAD]"></i> Pague com Pix
                            </h2>
                            <p v-if="orderTotal !== null" class="whitespace-nowrap text-lg font-extrabold">{{ formatPrice(orderTotal) }}</p>
                        </div>
                        <div class="mt-3 flex justify-center">
                            <!-- Número do pedido em destaque (laranja da marca) com botão de copiar. -->
                            <div v-if="orderCode" class="flex items-center gap-1.5 rounded-xl bg-white px-2.5 py-1">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Pedido</span>
                                <span class="whitespace-nowrap text-base font-black uppercase italic tracking-tight text-[#F27A2A]">{{ orderCode }}</span>
                                <button type="button" class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-xs transition"
                                    :class="codigoCopiado ? 'bg-slate-900 text-white' : 'bg-orange-50 text-[#F27A2A] hover:bg-orange-100'"
                                    :aria-label="codigoCopiado ? 'Número copiado' : 'Copiar número do pedido'" @click="copiarCodigoDoPedido">
                                    <i class="fas" :class="codigoCopiado ? 'fa-check' : 'fa-copy'"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="px-5 pb-4 pt-4">
                        <div class="mx-auto w-fit rounded-2xl bg-white p-1.5 ring-2 ring-[#F27A2A] ring-offset-2">
                            <img v-if="pixQrImageUrl" :src="pixQrImageUrl" alt="QR Code Pix" class="size-[clamp(7.5rem,28dvh,12rem)]">
                        </div>

                        <ol class="mt-3 grid grid-cols-3 [@media(max-height:620px)]:hidden gap-2 text-[11px] leading-snug text-slate-600">
                            <li class="flex flex-col items-center gap-1"><span class="flex h-5 w-5 items-center justify-center rounded-full bg-slate-900 text-[10px] font-bold text-white">1</span><span>Abra o app do banco no <strong>Pix</strong></span></li>
                            <li class="flex flex-col items-center gap-1"><span class="flex h-5 w-5 items-center justify-center rounded-full bg-slate-900 text-[10px] font-bold text-white">2</span><span>Leia o QR ou use o <strong>copia e cola</strong></span></li>
                            <li class="flex flex-col items-center gap-1"><span class="flex h-5 w-5 items-center justify-center rounded-full bg-slate-900 text-[10px] font-bold text-white">3</span><span>A confirmação aparece aqui</span></li>
                        </ol>

                        <button v-if="pixQrCode" type="button"
                            class="mt-3 flex w-full items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-bold text-white shadow-md transition"
                            :class="copied ? 'bg-slate-900' : 'bg-[#F27A2A] hover:brightness-95'"
                            @click="copyPixCode">
                            <i class="fas" :class="copied ? 'fa-check' : 'fa-copy'"></i>
                            {{ copied ? 'Código copiado! Cole no app do banco' : 'Copiar código Pix' }}
                        </button>

                        <p class="mt-2.5 flex flex-wrap items-center justify-center gap-x-2 gap-y-1 text-xs text-slate-500">
                            <span><i class="fas fa-circle-notch mr-1 animate-spin text-[#F27A2A]"></i>Aguardando o pagamento</span>
                            <span v-if="pixCountdown" class="inline-flex items-center gap-1 rounded-full bg-orange-50 px-2 py-0.5 font-bold text-[#c2410c]">
                                <i class="fas fa-stopwatch"></i> expira em {{ pixCountdown }}
                            </span>
                        </p>
                    </div>

                    <!-- Tarja de confiança, como no topo da finalização. -->
                    <div class="flex flex-wrap items-center justify-center gap-x-2 gap-y-0.5 rounded-b-2xl bg-[#111] px-3 py-2.5 text-[10px] min-[360px]:text-[11px] font-bold uppercase tracking-wide text-white sm:text-xs">
                        <span class="whitespace-nowrap"><i class="fa-solid fa-truck-fast mr-1 text-[#0FB930]"></i>Frete grátis</span>
                        <span class="opacity-40">|</span>
                        <span class="whitespace-nowrap"><i class="fa-solid fa-lock mr-1 text-[#0FB930]"></i>Compra 100% segura</span>
                    </div>
                </template>

                <template v-else-if="state === 'boleto'">
                    <!-- Boleto gerado (2026-10-14): pedido feito, pagamento chega em dias. -->
                    <div class="-mx-8 -mt-8 rounded-t-2xl bg-gradient-to-br from-[#1c1c1e] to-[#111] px-5 pb-4 pt-5 text-white">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="flex items-center gap-2 whitespace-nowrap font-display text-lg font-bold">
                                <i class="fas fa-barcode text-[#F27A2A]"></i> Boleto gerado
                            </h2>
                            <p v-if="orderTotal !== null" class="whitespace-nowrap text-lg font-extrabold">{{ formatPrice(orderTotal) }}</p>
                        </div>
                        <p v-if="orderCode" class="mt-2 text-sm">
                            Pedido <span class="font-black uppercase italic text-[#F27A2A]">{{ orderCode }}</span>
                        </p>
                    </div>

                    <p v-if="vencimentoBoleto" class="mt-5 text-sm text-store-fg-muted">
                        Pague até <strong class="text-store-fg">{{ vencimentoBoleto }}</strong>
                    </p>

                    <div v-if="boleto?.linhaDigitavel" class="mt-3 text-left">
                        <p class="text-xs font-bold uppercase tracking-wide text-store-fg-muted">Linha digitável</p>
                        <p class="mt-1 break-all rounded-lg border border-dashed border-store-border-strong bg-store-bg-sunken px-3 py-2 font-mono text-sm text-store-fg" data-testid="linha-digitavel">{{ boleto.linhaDigitavel }}</p>
                    </div>

                    <button v-if="boleto?.linhaDigitavel" type="button"
                        class="mt-3 flex w-full items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-bold text-white shadow-md transition"
                        :class="linhaCopiada ? 'bg-slate-900' : 'bg-[#F27A2A] hover:brightness-95'"
                        @click="copiarLinha">
                        <i class="fas" :class="linhaCopiada ? 'fa-check' : 'fa-copy'"></i>
                        {{ linhaCopiada ? 'Linha copiada! Cole no app do banco' : 'Copiar linha digitável' }}
                    </button>
                    <a v-if="boleto?.pdfUrl" :href="boleto.pdfUrl" target="_blank" rel="noopener"
                        class="mt-2 flex w-full items-center justify-center gap-2 rounded-xl border border-store-border px-4 py-3 text-sm font-semibold text-store-fg no-underline hover:border-[#F27A2A]">
                        <i class="fas fa-file-pdf text-[#F27A2A]"></i> Abrir o boleto (PDF)
                    </a>

                    <ul class="mx-auto mt-4 flex flex-col gap-1.5 text-left text-xs text-store-fg-muted">
                        <li><i class="fas fa-envelope mr-2 w-4 text-center text-[#F27A2A]"></i>Também mandamos o boleto para o seu e-mail.</li>
                        <li><i class="fas fa-clock mr-2 w-4 text-center text-[#F27A2A]"></i>O banco leva até 3 dias úteis para confirmar o pagamento.</li>
                        <li><i class="fas fa-box mr-2 w-4 text-center text-[#F27A2A]"></i>O pedido segue para separação assim que o pagamento for confirmado. Se o boleto vencer sem pagamento, o pedido é cancelado.</li>
                    </ul>

                    <Link v-if="successUrl" :href="successUrl"
                        class="mt-5 flex w-full items-center justify-center gap-2 rounded-xl bg-[#0FB930] px-4 py-3 text-sm font-bold text-white no-underline shadow-md hover:brightness-95">
                        <i class="fas fa-receipt"></i> Ver meu pedido
                    </Link>
                    <Link href="/" class="mt-2 block w-full rounded-xl border border-store-border px-4 py-3 text-sm font-semibold text-store-fg no-underline hover:border-[#F27A2A]">
                        Continuar comprando
                    </Link>
                </template>

                <template v-else-if="state === 'success'">
                    <!-- Sucesso caprichado (pedido 2026-10-11): faixa verde com confete,
                         número do pedido em laranja e botões em vez de só redirecionar. -->
                    <div class="relative -mx-8 -mt-8 overflow-hidden rounded-t-2xl bg-gradient-to-br from-[#0FB930] to-[#0a8a23] px-6 pb-7 pt-8 text-white">
                        <span v-for="n in 14" :key="n" aria-hidden="true" class="confete"
                            :style="{ left: `${(n * 37) % 100}%`, animationDelay: `${(n % 7) * 0.18}s`, backgroundColor: ['#F27A2A', '#fde047', '#ffffff', '#38bdf8'][n % 4] }"></span>
                        <div class="sucesso-check mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-white text-3xl text-[#0FB930] shadow-lg">
                            <i class="fas fa-check"></i>
                        </div>
                        <h2 class="mt-3 font-display text-2xl font-bold">Pagamento aprovado!</h2>
                        <p class="mt-1 text-sm opacity-90">Oba! Sua compra está confirmada 🎉</p>
                    </div>

                    <div v-if="orderCode" class="mx-auto -mt-4 flex w-fit items-center gap-2 rounded-xl bg-white px-3 py-1.5 shadow-md ring-1 ring-black/5">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Pedido</span>
                        <span class="text-xl font-black uppercase italic tracking-tight text-[#F27A2A]">{{ orderCode }}</span>
                        <button type="button" class="flex h-7 w-7 items-center justify-center rounded-lg text-sm transition"
                            :class="codigoCopiado ? 'bg-emerald-100 text-emerald-600' : 'bg-orange-50 text-[#F27A2A] hover:bg-orange-100'"
                            :aria-label="codigoCopiado ? 'Número copiado' : 'Copiar número do pedido'" @click="copiarCodigoDoPedido">
                            <i class="fas" :class="codigoCopiado ? 'fa-check' : 'fa-copy'"></i>
                        </button>
                    </div>

                    <p v-if="orderTotal !== null" class="mt-4 text-sm text-store-fg-muted">
                        Total pago: <span class="font-bold text-store-fg">{{ formatPrice(orderTotal) }}</span>
                    </p>
                    <ul class="mx-auto mt-3 flex max-w-[300px] flex-col gap-1.5 text-left text-sm text-store-fg-muted">
                        <li><i class="fas fa-envelope-circle-check mr-2 w-4 text-center text-[#0FB930]"></i>Enviamos a confirmação para o seu e-mail.</li>
                        <li><i class="fas fa-box-open mr-2 w-4 text-center text-[#F27A2A]"></i>Já estamos preparando o seu pedido.</li>
                        <li><i class="fas fa-truck-fast mr-2 w-4 text-center text-sky-500"></i>O rastreio aparece em Meus Pedidos.</li>
                    </ul>

                    <Link v-if="successUrl" :href="successUrl"
                        class="mt-5 flex w-full items-center justify-center gap-2 rounded-xl bg-[#F27A2A] px-4 py-3.5 text-sm font-bold text-white no-underline shadow-md hover:brightness-95">
                        <i class="fas fa-receipt"></i> Ver minha compra
                    </Link>
                    <Link href="/" class="mt-2 block w-full rounded-xl border border-store-border px-4 py-3 text-sm font-semibold text-store-fg no-underline hover:border-[#F27A2A]">
                        Continuar comprando
                    </Link>
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

<style scoped>
.confete { position:absolute; top:-10px; width:7px; height:12px; border-radius:2px; opacity:.9; animation:cair 2.4s ease-in infinite; }
@keyframes cair { 0% { transform:translateY(0) rotate(0); opacity:1; } 100% { transform:translateY(190px) rotate(540deg); opacity:0; } }
.sucesso-check { animation:pulo .5s cubic-bezier(.34,1.56,.64,1) both; }
@keyframes pulo { from { transform:scale(.3); opacity:0; } to { transform:scale(1); opacity:1; } }
@media (prefers-reduced-motion: reduce) { .confete, .sucesso-check { animation:none; } .confete { display:none; } }
</style>
