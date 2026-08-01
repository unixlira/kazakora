<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import PaymentProcessingModal from '@/Shared/Components/PaymentProcessingModal.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { loadStripe } from '@stripe/stripe-js';
import { loadMercadoPagoSdk } from '@/Shared/useMercadoPagoSdk';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    items: { type: Array, default: () => [] },
    subtotal: { type: Number, default: 0 },
    productsDiscount: { type: Number, default: 0 },
    shippingCost: { type: Number, default: 0 },
    couponCode: { type: String, default: null },
    discountAmount: { type: Number, default: 0 },
    total: { type: Number, default: 0 },
    originalTotal: { type: Number, default: 0 },
    order: { type: Object, default: null },
    clientSecret: { type: String, default: null },
    stripeKey: { type: String, default: null },
    methodType: { type: String, default: null },
    pendingSecondMethod: { type: String, default: null },
    // Pix via Mercado Pago: QR já pronto na resposta do backend, sem
    // nenhuma etapa de confirmação no front-end (diferente do Stripe).
    mercadoPagoPix: { type: Object, default: null },
    // 'stripe' ou 'mercadopago' — decide se cartão usa o Payment Element do
    // Stripe (Fase 2 abaixo) ou o Card Payment Brick do Mercado Pago.
    paymentGateway: { type: String, default: 'stripe' },
    mercadoPagoPublicKey: { type: String, default: null },
    // Cartão via Mercado Pago já foi aprovado/autorizado no backend antes
    // desta página renderizar (sem Payment Element pra confirmar) — só
    // falta começar o polling, igual ao Pix.
    mercadoPagoCardConfirmed: { type: Boolean, default: false },
});

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const METHOD_LABELS = {
    card: 'Cartão de crédito',
    pix: 'Pix',
};

// Fase 1: escolher método(s) de pagamento
const chooseForm = useForm({
    payment_method: 'card',
    split: false,
    payment_method_secondary: 'pix',
    split_percentage: 50,
    terms_accepted: false,
});

// Cartão + Mercado Pago precisa do Card Payment Brick tokenizar o cartão
// no navegador ANTES de qualquer chamada ao backend (o backend nunca vê o
// número do cartão) — split sempre tem cartão como parte 1
// (sortMethodsSafely no backend), então qualquer split com MP também
// precisa passar por aqui primeiro.
const mpCardPhase = ref(false);
const mpCardError = ref(null);
const mpCardReady = ref(false);
let mpBrickController = null;

const submitChoice = async () => {
    const needsMpCardToken = props.paymentGateway === 'mercadopago'
        && (chooseForm.payment_method === 'card' || chooseForm.split);

    if (needsMpCardToken) {
        mpCardPhase.value = true;
        await nextTick();
        mountMpCardBrick();
        return;
    }

    chooseForm.post('/finalizacao/pagamento', {
        onError: () => window.scrollTo({ top: 0, behavior: 'smooth' }),
    });
};

const backToChoice = () => {
    mpCardPhase.value = false;
    mpCardError.value = null;
    mpBrickController?.unmount();
    mpBrickController = null;
};

const mountMpCardBrick = async () => {
    mpCardError.value = null;
    mpCardReady.value = false;

    try {
        const MercadoPago = await loadMercadoPagoSdk();
        const mp = new MercadoPago(props.mercadoPagoPublicKey, { locale: 'pt-BR' });
        const primaryAmount = chooseForm.split
            ? Number((props.total * (chooseForm.split_percentage / 100)).toFixed(2))
            : props.total;

        mpBrickController = await mp.bricks().create('cardPayment', 'mpCardBrick-container', {
            initialization: { amount: primaryAmount },
            callbacks: {
                onReady: () => { mpCardReady.value = true; },
                onError: (error) => {
                    mpCardError.value = error?.message ?? 'Não foi possível carregar o formulário de cartão.';
                },
                onSubmit: (formData) => new Promise((resolve, reject) => {
                    chooseForm.transform((data) => ({
                        ...data,
                        mp_card_token: formData.token,
                        mp_card_installments: formData.installments,
                        mp_payment_method_id: formData.payment_method_id,
                        mp_issuer_id: formData.issuer_id ?? null,
                    })).post('/finalizacao/pagamento', {
                        onSuccess: () => resolve(),
                        onError: () => {
                            window.scrollTo({ top: 0, behavior: 'smooth' });
                            reject();
                        },
                    });
                }),
            },
        });
    } catch (error) {
        mpCardError.value = error?.message ?? 'Não foi possível carregar o formulário de cartão.';
    }
};

const couponForm = useForm({ code: '' });
const showCouponInput = ref(!!props.couponCode);

const applyCoupon = () => {
    couponForm.post('/finalizacao/pagamento/cupom', {
        preserveScroll: true,
        onSuccess: () => couponForm.reset(),
    });
};

// Fase 2: confirmar com o Stripe Payment Element
const stripeInstance = ref(null);
const elementsInstance = ref(null);
const stripeError = ref(null);
const confirming = ref(false);
const elementReady = ref(false);

const mountPaymentElement = async () => {
    elementReady.value = false;
    stripeError.value = null;

    if (! props.clientSecret || ! props.stripeKey) return;

    stripeInstance.value = await loadStripe(props.stripeKey);
    elementsInstance.value = stripeInstance.value.elements({ clientSecret: props.clientSecret, locale: 'pt-BR' });
    elementsInstance.value.create('payment').mount('#payment-element');
    elementReady.value = true;
};

watch(() => props.clientSecret, mountPaymentElement, { immediate: true });

// Estado "processando pagamento": cobre o intervalo entre clicar em "Finalizar
// compra" e a confirmação real — que pode vir da resposta síncrona do Stripe
// (cartão) OU do polling que espera o webhook confirmar (nunca confiamos só
// na resposta do front-end pra marcar como pago de verdade).
const paymentState = ref('idle'); // idle | processing | awaiting_pix | success | timeout | failed
const failureMessage = ref(null);
const pixQrImageUrl = ref(null);
const pixQrCode = ref(null);
const pixSecondsLeft = ref(null);

let pixCountdownTimer = null;
let pollTimer = null;
let pollStartedAt = null;

const clearTimers = () => {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = null;
    if (pixCountdownTimer) clearInterval(pixCountdownTimer);
    pixCountdownTimer = null;
};

onUnmounted(() => {
    clearTimers();
    mpBrickController?.unmount();
});

const startPixCountdown = (expiresAtUnixSeconds) => {
    const expiresAtMs = expiresAtUnixSeconds * 1000;
    const tick = () => {
        const secondsLeft = Math.max(0, Math.round((expiresAtMs - Date.now()) / 1000));
        pixSecondsLeft.value = secondsLeft;

        // Expira localmente assim que o contador zera, sem esperar o
        // próximo poll confirmar — evita ficar preso na tela de QR code
        // depois do prazo (10 minutos) já ter passado.
        if (secondsLeft === 0 && paymentState.value === 'awaiting_pix') {
            clearTimers();
            failureMessage.value = 'O tempo para pagar esse Pix acabou.';
            paymentState.value = 'failed';
        }
    };
    tick();
    pixCountdownTimer = setInterval(tick, 1000);
};

const startPolling = (orderId) => {
    pollStartedAt = Date.now();

    const poll = async () => {
        try {
            const response = await fetch(`/finalizacao/${orderId}/status`, { headers: { Accept: 'application/json' } });
            const data = await response.json();

            if (data.status === 'paid') {
                clearTimers();
                paymentState.value = 'success';
                setTimeout(() => router.visit(data.redirect), 1500);
                return;
            }

            if (data.status === 'pending') {
                clearTimers();
                confirming.value = false;

                // Pix (método único, não split) recusado/expirado — mostra o
                // motivo em vermelho em vez de já sair da tela sem explicar
                // nada. Split com uma parcela falhando continua indo direto
                // pra escolha de método (o Payment Element atual já não
                // serve mais, não tem o que mostrar ali).
                if (paymentState.value === 'awaiting_pix') {
                    failureMessage.value = 'Não foi possível confirmar esse pagamento Pix.';
                    paymentState.value = 'failed';
                    return;
                }

                router.visit('/finalizacao/pagamento');
                return;
            }
        } catch {
            // instabilidade de rede — continua tentando, não desiste
        }

        if (paymentState.value === 'processing' && Date.now() - pollStartedAt > 60000) {
            paymentState.value = 'timeout';
        }
    };

    poll();
    pollTimer = setInterval(poll, 4000);
};

const confirmPayment = async () => {
    if (! stripeInstance.value || ! elementsInstance.value) return;

    confirming.value = true;
    stripeError.value = null;
    paymentState.value = 'processing';

    const { error, paymentIntent } = await stripeInstance.value.confirmPayment({
        elements: elementsInstance.value,
        redirect: 'if_required',
    });

    if (error) {
        clearTimers();
        paymentState.value = 'idle';
        stripeError.value = error.message;
        confirming.value = false;
        return;
    }

    if (paymentIntent?.next_action?.type === 'pix_display_qr_code') {
        const qr = paymentIntent.next_action.pix_display_qr_code;
        pixQrImageUrl.value = qr.image_url_png ?? null;
        pixQrCode.value = qr.data ?? null;
        paymentState.value = 'awaiting_pix';
        if (qr.expires_at) startPixCountdown(qr.expires_at);
    }

    if (props.pendingSecondMethod) {
        router.post(`/finalizacao/${props.order.id}/pagamento/proxima-parte`, {
            method_type: props.pendingSecondMethod,
        }, {
            onSuccess: () => { paymentState.value = 'idle'; },
            onFinish: () => { confirming.value = false; },
        });
        return;
    }

    startPolling(props.order.id);
};

const savings = computed(() => props.discountAmount);

// Verdadeiro só na Fase 1 (escolher método) — usado tanto lá quanto pro
// cupom no resumo da compra, que não faz sentido nas fases seguintes.
const isChoosingMethod = computed(() =>
    ! props.clientSecret && ! props.mercadoPagoPix && ! mpCardPhase.value && ! props.mercadoPagoCardConfirmed);

// Pix via Mercado Pago já chega com o QR pronto (a criação do pagamento
// aconteceu no backend, antes desta página renderizar) — só mostrar e
// começar o polling, sem nenhuma etapa de confirmação como o Stripe exige.
onMounted(() => {
    if (props.mercadoPagoPix) {
        pixQrImageUrl.value = props.mercadoPagoPix.qrCodeBase64
            ? `data:image/png;base64,${props.mercadoPagoPix.qrCodeBase64}`
            : null;
        pixQrCode.value = props.mercadoPagoPix.qrCode ?? null;
        paymentState.value = 'awaiting_pix';

        if (props.mercadoPagoPix.expiresAt) {
            startPixCountdown(new Date(props.mercadoPagoPix.expiresAt).getTime() / 1000);
        }

        startPolling(props.order.id);
        return;
    }

    // Cartão via Mercado Pago já resolveu no backend (aprovado/autorizado),
    // sem nada pra confirmar aqui. Se ainda falta a segunda parcela (Pix,
    // num split), dispara sozinho — não há ação nenhuma do cliente pra
    // esperar nessa etapa. A resposta troca esta página por uma nova com
    // mercadoPagoPix preenchido, que cai no bloco acima ao remontar.
    if (props.mercadoPagoCardConfirmed) {
        paymentState.value = 'processing';

        if (props.pendingSecondMethod) {
            router.post(`/finalizacao/${props.order.id}/pagamento/proxima-parte`, {
                method_type: props.pendingSecondMethod,
            }, {
                onError: () => { paymentState.value = 'idle'; },
            });
            return;
        }

        startPolling(props.order.id);
    }
});
</script>

<template>
    <Head title="Escolha como pagar" />

    <AppLayout>
        <div class="mx-auto max-w-[1100px] px-4 py-12 md:px-6">
            <h1 class="font-display text-3xl font-semibold">Escolha como pagar</h1>

            <div class="mt-8 grid gap-10 lg:grid-cols-[1.4fr_1fr] lg:items-start">
                <div>
                    <!-- Cartão + Mercado Pago: Card Payment Brick tokeniza no navegador -->
                    <template v-if="mpCardPhase">
                        <div class="rounded-2xl border border-store-border bg-store-bg-raised p-5">
                            <h2 class="mb-4 text-sm font-semibold uppercase text-store-fg-muted">Dados do cartão</h2>
                            <div id="mpCardBrick-container"></div>
                            <p v-if="mpCardError" class="mt-3 text-sm text-red-600">{{ mpCardError }}</p>
                        </div>

                        <div class="mt-6 flex justify-start">
                            <button type="button" class="text-sm font-medium text-store-fg-muted hover:text-store-fg" @click="backToChoice">
                                ← Voltar
                            </button>
                        </div>
                    </template>

                    <!-- Cartão via Mercado Pago já resolveu no backend, sem nada pra confirmar aqui — ver PaymentProcessingModal -->
                    <template v-else-if="mercadoPagoCardConfirmed">
                        <div class="rounded-2xl border border-store-border bg-store-bg-raised p-5">
                            <h2 class="mb-2 text-sm font-semibold uppercase text-store-fg-muted">Processando pagamento</h2>
                            <p class="text-sm text-store-fg-muted">Confirmando seu pagamento no cartão, só um instante.</p>
                        </div>
                    </template>

                    <!-- Fase 1: escolher método -->
                    <template v-else-if="isChoosingMethod">
                        <p v-if="chooseForm.errors.cart" class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm font-medium text-red-700 dark:bg-red-900/30 dark:text-red-300">
                            <i class="fas fa-triangle-exclamation mr-2"></i>{{ chooseForm.errors.cart }}
                        </p>
                        <p v-if="chooseForm.errors.payment" class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm font-medium text-red-700 dark:bg-red-900/30 dark:text-red-300">
                            <i class="fas fa-triangle-exclamation mr-2"></i>{{ chooseForm.errors.payment }}
                        </p>

                        <div class="rounded-2xl border border-store-border bg-store-bg-raised p-5">
                            <label class="flex items-center justify-between gap-3">
                                <span class="text-sm font-medium">Combinar 2 meios de pagamento</span>
                                <span class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full transition-colors"
                                    :class="chooseForm.split ? 'bg-store-accent' : 'bg-store-border-strong'"
                                    @click="chooseForm.split = !chooseForm.split">
                                    <input type="checkbox" v-model="chooseForm.split" class="peer sr-only">
                                    <span class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform" :class="chooseForm.split ? 'translate-x-6' : 'translate-x-1'"></span>
                                </span>
                            </label>
                        </div>

                        <div class="mt-6 rounded-2xl border border-store-border bg-store-bg-raised p-5">
                            <h2 class="mb-3 text-sm font-semibold uppercase text-store-fg-muted">
                                {{ chooseForm.split ? 'Meio de pagamento 1' : 'Meio de pagamento' }}
                            </h2>
                            <div class="flex flex-col gap-2">
                                <label v-for="method in ['pix', 'card']" :key="method"
                                    class="flex cursor-pointer items-center gap-3 rounded-xl border p-3 transition-colors"
                                    :class="chooseForm.payment_method === method ? 'border-store-accent bg-store-accent/5' : 'border-store-border hover:border-store-border-strong'">
                                    <input v-model="chooseForm.payment_method" type="radio" :value="method" class="h-4 w-4 accent-store-accent">
                                    <i class="fas w-5 text-store-accent" :class="method === 'pix' ? 'fa-qrcode' : 'fa-credit-card'"></i>
                                    <div>
                                        <p class="text-sm font-medium">{{ METHOD_LABELS[method] }}</p>
                                        <p class="text-xs text-store-fg-muted">
                                            {{ method === 'pix' ? 'Aprovação imediata' : 'Em até 12x' }}
                                        </p>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <div v-if="chooseForm.split" class="mt-6 rounded-2xl border border-store-border bg-store-bg-raised p-5">
                            <h2 class="mb-3 text-sm font-semibold uppercase text-store-fg-muted">Meio de pagamento 2</h2>
                            <div class="flex flex-col gap-2">
                                <label v-for="method in ['pix', 'card'].filter((m) => m !== chooseForm.payment_method)" :key="method"
                                    class="flex cursor-pointer items-center gap-3 rounded-xl border p-3 transition-colors"
                                    :class="chooseForm.payment_method_secondary === method ? 'border-store-accent bg-store-accent/5' : 'border-store-border hover:border-store-border-strong'">
                                    <input v-model="chooseForm.payment_method_secondary" type="radio" :value="method" class="h-4 w-4 accent-store-accent">
                                    <i class="fas w-5 text-store-accent" :class="method === 'pix' ? 'fa-qrcode' : 'fa-credit-card'"></i>
                                    <span class="text-sm font-medium">{{ METHOD_LABELS[method] }}</span>
                                </label>
                            </div>

                            <label class="mt-4 block text-sm font-medium">
                                Percentual no meio de pagamento 1: {{ chooseForm.split_percentage }}%
                                <input v-model.number="chooseForm.split_percentage" type="range" min="1" max="99" class="mt-2 w-full accent-store-accent">
                            </label>
                            <p class="mt-1 text-xs text-store-fg-muted">
                                {{ formatPrice(total * (chooseForm.split_percentage / 100)) }} no meio 1 ·
                                {{ formatPrice(total * (1 - chooseForm.split_percentage / 100)) }} no meio 2
                            </p>
                        </div>

                        <label class="mt-6 flex items-start gap-3 text-sm">
                            <input v-model="chooseForm.terms_accepted" type="checkbox" class="mt-0.5 h-4 w-4 accent-store-accent">
                            <span>
                                Li e estou ciente das condições desta compra e dos
                                <Link href="/termos-de-uso" target="_blank" class="text-store-accent hover:underline">termos de uso</Link>.
                            </span>
                        </label>
                        <InputError v-if="chooseForm.errors.terms_accepted" :message="chooseForm.errors.terms_accepted" />

                        <div class="mt-6 flex justify-end">
                            <button type="button" :disabled="!chooseForm.terms_accepted || chooseForm.processing"
                                class="rounded-lg bg-store-accent px-6 py-3 text-sm font-semibold text-store-accent-contrast hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="submitChoice">
                                Continuar
                            </button>
                        </div>
                    </template>

                    <!-- Pix via Mercado Pago: QR já pronto, sem etapa de confirmação aqui — ver PaymentProcessingModal -->
                    <template v-else-if="mercadoPagoPix">
                        <div class="rounded-2xl border border-store-border bg-store-bg-raised p-5">
                            <h2 class="mb-2 text-sm font-semibold uppercase text-store-fg-muted">Pagamento via Pix</h2>
                            <p class="text-sm text-store-fg-muted">
                                Escaneie o QR code ou copie o código Pix na janela para concluir o pagamento.
                            </p>
                        </div>
                    </template>

                    <!-- Fase 2: confirmar com o Stripe -->
                    <template v-else>
                        <div class="rounded-2xl border border-store-border bg-store-bg-raised p-5">
                            <h2 class="mb-4 text-sm font-semibold uppercase text-store-fg-muted">
                                {{ pendingSecondMethod ? 'Confirme o meio de pagamento 1' : 'Confirme o pagamento' }}
                            </h2>
                            <div id="payment-element"></div>
                            <p v-if="stripeError" class="mt-3 text-sm text-red-600">{{ stripeError }}</p>
                        </div>

                        <div class="mt-6 flex justify-end">
                            <button type="button" :disabled="!elementReady || confirming"
                                class="rounded-lg bg-store-accent px-6 py-3 text-sm font-semibold text-store-accent-contrast hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="confirmPayment">
                                {{ confirming ? 'Processando...' : 'Finalizar compra' }}
                            </button>
                        </div>
                    </template>
                </div>

                <!-- Resumo da compra -->
                <div class="rounded-2xl border border-store-border bg-store-bg-raised p-6 lg:sticky lg:top-24">
                    <h2 class="mb-4 font-display text-lg font-semibold">Resumo da compra</h2>
                    <div class="flex flex-col gap-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-store-fg-muted">Produto</span>
                            <span class="font-store-mono">{{ formatPrice(subtotal + productsDiscount) }}</span>
                        </div>
                        <div v-if="productsDiscount > 0" class="flex justify-between text-emerald-600 dark:text-emerald-400">
                            <span>Desconto do produto</span>
                            <span class="font-store-mono">-{{ formatPrice(productsDiscount) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-store-fg-muted">Frete</span>
                            <span class="font-store-mono" :class="shippingCost === 0 ? 'text-emerald-600 dark:text-emerald-400' : ''">
                                {{ shippingCost === 0 ? 'Grátis' : formatPrice(shippingCost) }}
                            </span>
                        </div>

                        <template v-if="isChoosingMethod">
                            <button v-if="!showCouponInput" type="button" class="text-left font-medium text-store-accent hover:underline" @click="showCouponInput = true">
                                Inserir código do cupom
                            </button>
                            <div v-else class="flex gap-2">
                                <input v-model="couponForm.code" type="text" placeholder="Código do cupom"
                                    class="min-w-0 flex-1 rounded-lg border border-store-border-strong bg-store-bg px-3 py-1.5 text-sm">
                                <button type="button" class="rounded-lg bg-store-bg-sunken px-3 py-1.5 text-sm font-medium hover:opacity-80" @click="applyCoupon">
                                    Aplicar
                                </button>
                            </div>
                            <p v-if="couponForm.errors.code" class="text-xs text-red-600">{{ couponForm.errors.code }}</p>
                        </template>
                    </div>

                    <div class="mt-4 border-t border-store-border pt-4">
                        <template v-if="discountAmount > 0">
                            <div class="flex items-baseline justify-between">
                                <span class="font-semibold">Você pagará</span>
                                <span class="text-right">
                                    <span class="mr-1 block text-xs text-store-fg-faint line-through">{{ formatPrice(originalTotal) }}</span>
                                    <span class="text-lg font-semibold text-store-accent">{{ formatPrice(total) }}</span>
                                </span>
                            </div>
                            <p class="mt-1 text-right text-sm font-medium text-emerald-600 dark:text-emerald-400">
                                Você economizou {{ formatPrice(savings) }}
                            </p>
                        </template>
                        <div v-else class="flex items-baseline justify-between">
                            <span class="font-semibold">Total</span>
                            <span class="text-lg font-semibold text-store-accent">{{ formatPrice(total) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <PaymentProcessingModal
            :open="paymentState !== 'idle'"
            :state="paymentState"
            :order-total="order?.total ?? total"
            :pix-qr-image-url="pixQrImageUrl"
            :pix-qr-code="pixQrCode"
            :pix-seconds-left="pixSecondsLeft"
            :failure-message="failureMessage"
            @retry="() => router.visit('/finalizacao/pagamento')" />
    </AppLayout>
</template>
