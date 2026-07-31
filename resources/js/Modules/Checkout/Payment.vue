<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { loadStripe } from '@stripe/stripe-js';
import { computed, ref, watch } from 'vue';

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
    pendingSecondMethod: { type: String, default: null },
});

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const METHOD_LABELS = {
    card: 'Cartão de crédito',
    pix: 'Pix',
    boleto: 'Boleto',
};

// Fase 1: escolher método(s) de pagamento
const chooseForm = useForm({
    payment_method: 'card',
    split: false,
    payment_method_secondary: 'pix',
    split_percentage: 50,
    terms_accepted: false,
});

const submitChoice = () => chooseForm.post('/finalizacao/pagamento');

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
    elementsInstance.value = stripeInstance.value.elements({ clientSecret: props.clientSecret });
    elementsInstance.value.create('payment').mount('#payment-element');
    elementReady.value = true;
};

watch(() => props.clientSecret, mountPaymentElement, { immediate: true });

const confirmPayment = async () => {
    if (! stripeInstance.value || ! elementsInstance.value) return;

    confirming.value = true;
    stripeError.value = null;

    const { error } = await stripeInstance.value.confirmPayment({
        elements: elementsInstance.value,
        redirect: 'if_required',
    });

    if (error) {
        stripeError.value = error.message;
        confirming.value = false;
        return;
    }

    if (props.pendingSecondMethod) {
        router.post(`/finalizacao/${props.order.id}/pagamento/proxima-parte`, {
            method_type: props.pendingSecondMethod,
        }, {
            onFinish: () => { confirming.value = false; },
        });
    } else {
        router.post(`/finalizacao/${props.order.id}/concluir`, {}, {
            onFinish: () => { confirming.value = false; },
        });
    }
};

const savings = computed(() => props.discountAmount);
</script>

<template>
    <Head title="Escolha como pagar" />

    <AppLayout>
        <div class="mx-auto max-w-[1100px] px-4 py-12 md:px-6">
            <h1 class="font-display text-3xl font-semibold">Escolha como pagar</h1>

            <div class="mt-8 grid gap-10 lg:grid-cols-[1.4fr_1fr] lg:items-start">
                <div>
                    <!-- Fase 1: escolher método -->
                    <template v-if="!clientSecret">
                        <p v-if="chooseForm.errors.cart" class="mb-4 text-sm text-red-600">{{ chooseForm.errors.cart }}</p>
                        <p v-if="chooseForm.errors.payment" class="mb-4 text-sm text-red-600">{{ chooseForm.errors.payment }}</p>

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
                                <label v-for="method in ['pix', 'card', 'boleto']" :key="method"
                                    class="flex cursor-pointer items-center gap-3 rounded-xl border p-3 transition-colors"
                                    :class="chooseForm.payment_method === method ? 'border-store-accent bg-store-accent/5' : 'border-store-border hover:border-store-border-strong'">
                                    <input v-model="chooseForm.payment_method" type="radio" :value="method" class="h-4 w-4 accent-store-accent">
                                    <i class="fas w-5 text-store-accent" :class="method === 'pix' ? 'fa-qrcode' : method === 'card' ? 'fa-credit-card' : 'fa-barcode'"></i>
                                    <div>
                                        <p class="text-sm font-medium">{{ METHOD_LABELS[method] }}</p>
                                        <p class="text-xs text-store-fg-muted">
                                            {{ method === 'pix' ? 'Aprovação imediata' : method === 'card' ? 'Em até 12x' : 'Vence em 3 dias úteis' }}
                                        </p>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <div v-if="chooseForm.split" class="mt-6 rounded-2xl border border-store-border bg-store-bg-raised p-5">
                            <h2 class="mb-3 text-sm font-semibold uppercase text-store-fg-muted">Meio de pagamento 2</h2>
                            <div class="flex flex-col gap-2">
                                <label v-for="method in ['pix', 'card', 'boleto'].filter((m) => m !== chooseForm.payment_method)" :key="method"
                                    class="flex cursor-pointer items-center gap-3 rounded-xl border p-3 transition-colors"
                                    :class="chooseForm.payment_method_secondary === method ? 'border-store-accent bg-store-accent/5' : 'border-store-border hover:border-store-border-strong'">
                                    <input v-model="chooseForm.payment_method_secondary" type="radio" :value="method" class="h-4 w-4 accent-store-accent">
                                    <i class="fas w-5 text-store-accent" :class="method === 'pix' ? 'fa-qrcode' : method === 'card' ? 'fa-credit-card' : 'fa-barcode'"></i>
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
                                {{ confirming ? 'Processando...' : 'Pagar' }}
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

                        <template v-if="!clientSecret">
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
    </AppLayout>
</template>
