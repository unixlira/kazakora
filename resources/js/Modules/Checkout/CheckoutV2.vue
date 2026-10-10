<script setup>
// Checkout v2 (pedido 2026-10-10): uma tela só, sem topo e rodapé da loja,
// feita pra fechar a venda — dados, endereço, entrega e pagamento juntos,
// resumo do pedido ao lado. Pix já mostra os 5% aplicados ao ser escolhido.
// Usa o mesmo backend do checkout em 2 etapas: grava a entrega por fetch
// (JSON) e cria o pagamento por /finalizacao/pagamento; o QR do Pix e a
// confirmação do cartão chegam nas props desta mesma página.
import CheckoutLayout from '@/Shared/Layouts/CheckoutLayout.vue';
import PaymentProcessingModal from '@/Shared/Components/PaymentProcessingModal.vue';
import { maskCep, useCep } from '@/Shared/useCep';
import { maskCpf, maskPhone } from '@/Shared/useMasks';
import { loadMercadoPagoSdk } from '@/Shared/useMercadoPagoSdk';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onUnmounted, reactive, ref, watch } from 'vue';

const props = defineProps({
    items: { type: Array, default: () => [] },
    subtotal: { type: Number, default: 0 },
    productsDiscount: { type: Number, default: 0 },
    couponCode: { type: String, default: null },
    discountAmount: { type: Number, default: 0 },
    pixDiscountPercentage: { type: Number, default: 0 },
    addresses: { type: Array, default: () => [] },
    shippingMethods: { type: Array, default: () => [] },
    customer: { type: Object, default: null },
    draft: { type: Object, default: null },
    paymentGateway: { type: String, default: 'mercadopago' },
    mercadoPagoPublicKey: { type: String, default: null },
    // Depois que o pedido é criado (mesma página, props novas):
    order: { type: Object, default: null },
    methodType: { type: String, default: null },
    mercadoPagoPix: { type: Object, default: null },
    mercadoPagoCardConfirmed: { type: Boolean, default: false },
});

const page = usePage();
const serverErrors = computed(() => page.props.errors ?? {});
const isLoggedIn = computed(() => !!props.customer);

const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value) || 0);
const round2 = (value) => Math.round(value * 100) / 100;

// ---------- 1. Dados pessoais ----------
const draftGuest = props.draft?.guest ?? {};
const pessoa = reactive({
    name: props.customer?.name ?? draftGuest.name ?? '',
    email: props.customer?.email ?? draftGuest.email ?? '',
    cpf: props.customer?.cpf ? maskCpf(props.customer.cpf) : (draftGuest.cpf ?? ''),
    phone: props.customer?.phone ? maskPhone(props.customer.phone) : (draftGuest.phone ?? props.draft?.new_address?.phone ?? ''),
});

// ---------- 2. Endereço ----------
const enderecoSalvoValido = props.addresses.some((address) => address.id === props.draft?.address_id);
const addressId = ref(enderecoSalvoValido ? props.draft.address_id : (props.addresses[0]?.id ?? null));
const usarNovoEndereco = ref(!addressId.value);
const endereco = reactive({
    zip: props.draft?.new_address?.zip ?? '',
    street: props.draft?.new_address?.street ?? '',
    number: props.draft?.new_address?.number ?? '',
    complement: props.draft?.new_address?.complement ?? '',
    neighborhood: props.draft?.new_address?.neighborhood ?? '',
    city: props.draft?.new_address?.city ?? '',
    state: props.draft?.new_address?.state ?? '',
});

const { loading: cepLoading, error: cepError, lookup: lookupCep } = useCep();
const numeroInput = ref(null);

const onCep = async (event) => {
    endereco.zip = maskCep(event.target.value);
    if (endereco.zip.replace(/\D/g, '').length !== 8) return;
    const result = await lookupCep(endereco.zip);
    if (result) {
        endereco.street = result.street || endereco.street;
        endereco.neighborhood = result.neighborhood || endereco.neighborhood;
        endereco.city = result.city;
        endereco.state = result.state;
        await nextTick();
        numeroInput.value?.focus();
    }
};

const enderecoSelecionado = computed(() => props.addresses.find((address) => address.id === addressId.value) ?? null);
const cepEfetivo = computed(() => (usarNovoEndereco.value ? endereco.zip : (enderecoSelecionado.value?.zip ?? '')));

// ---------- 3. Entrega ----------
const freteCarregando = ref(false);
const entregaExpressa = ref(null);
// Só o frete grátis (pedido 2026-10-10). O CEP ainda é consultado pra
// mostrar a entrega expressa, mas as cotações pagas não aparecem.
const opcoesEntrega = computed(() => props.shippingMethods);
const shippingMethodId = ref(props.shippingMethods[0]?.id ?? null);

// O CEP só serve pro aviso de entrega expressa (não cota frete pago).
watch(cepEfetivo, async (zip) => {
    const cep = (zip ?? '').replace(/\D/g, '');
    if (cep.length !== 8) {
        entregaExpressa.value = null;
        return;
    }
    freteCarregando.value = true;
    try {
        const response = await fetch(`/frete/prazo?cep=${cep}`, { headers: { Accept: 'application/json' } });
        entregaExpressa.value = response.ok ? ((await response.json()).entrega_expressa ?? null) : null;
    } catch {
        entregaExpressa.value = null;
    } finally {
        freteCarregando.value = false;
    }
}, { immediate: true });

const entregaEscolhida = computed(() => opcoesEntrega.value.find((option) => option.id === shippingMethodId.value) ?? null);
const valorFrete = computed(() => Number(entregaEscolhida.value?.price ?? 0));

// ---------- Cupom (pedido 2026-10-10) ----------
// Aplica por JSON: busca o cupom na base, confere as regras e o desconto
// entra no total na hora, sem recarregar a tela.
const cupomAplicado = ref(props.couponCode);
const descontoCupom = ref(Number(props.discountAmount) || 0);
const cupomDescricao = ref(null);
const cupom = ref('');
const mostrarCupom = ref(false);
const cupomCarregando = ref(false);
const cupomErro = ref(page.props.errors?.code ?? null);

// Se o servidor tirar o cupom (expirou entre aplicar e pagar), a tela acompanha.
watch(() => [props.couponCode, props.discountAmount], ([codigo, valor]) => {
    cupomAplicado.value = codigo;
    descontoCupom.value = Number(valor) || 0;
});
watch(() => page.props.errors?.code, (mensagem) => {
    if (mensagem) cupomErro.value = mensagem;
});

const chamarCupom = (method, body) => fetch('/finalizacao/cupom', {
    method,
    credentials: 'same-origin',
    headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/) ?? [])[1] ?? ''),
    },
    body: body ? JSON.stringify(body) : undefined,
});

const aplicarCupom = async () => {
    if (!cupom.value.trim() || cupomCarregando.value) return;
    cupomCarregando.value = true;
    cupomErro.value = null;
    try {
        const response = await chamarCupom('POST', { code: cupom.value });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            cupomErro.value = data.errors?.code?.[0] ?? data.message ?? 'Não foi possível aplicar o cupom agora.';
            return;
        }
        cupomAplicado.value = data.code;
        descontoCupom.value = Number(data.discount_amount) || 0;
        cupomDescricao.value = data.descricao;
        cupom.value = '';
        mostrarCupom.value = false;
    } catch {
        cupomErro.value = 'Sem conexão. Tente de novo.';
    } finally {
        cupomCarregando.value = false;
    }
};

const removerCupom = async () => {
    cupomCarregando.value = true;
    try {
        await chamarCupom('DELETE');
        cupomAplicado.value = null;
        descontoCupom.value = 0;
        cupomDescricao.value = null;
    } finally {
        cupomCarregando.value = false;
    }
};

// ---------- 4. Pagamento ----------
const metodo = ref('pix');
const descontoPix = computed(() => (metodo.value === 'pix' && props.pixDiscountPercentage > 0
    ? round2((props.subtotal - descontoCupom.value) * props.pixDiscountPercentage / 100)
    : 0));
const totalCartao = computed(() => round2(props.subtotal - descontoCupom.value + valorFrete.value));
const totalAPagar = computed(() => (props.order ? Number(props.order.total) : round2(totalCartao.value - descontoPix.value)));
const parcela12 = computed(() => totalCartao.value / 12);

// Card Payment Brick do Mercado Pago embutido (sem o botão próprio dele):
// o "Finalizar pedido" único chama getFormData(), que tokeniza o cartão no
// navegador — o número do cartão nunca passa pelo nosso servidor.
let brick = null;
let brickTimer = null;
const brickPronto = ref(false);
const brickErro = ref(null);

const desmontarBrick = () => {
    brick?.unmount();
    brick = null;
    brickPronto.value = false;
};

const montarBrick = async () => {
    desmontarBrick();
    brickErro.value = null;
    if (!props.mercadoPagoPublicKey || totalCartao.value <= 0) return;

    try {
        const MercadoPago = await loadMercadoPagoSdk();
        const mp = new MercadoPago(props.mercadoPagoPublicKey, { locale: 'pt-BR' });
        await nextTick();
        brick = await mp.bricks().create('cardPayment', 'cartao-brick-v2', {
            initialization: { amount: totalCartao.value },
            customization: {
                visual: { hidePaymentButton: true, hideFormTitle: true, style: { theme: 'default' } },
                paymentMethods: { maxInstallments: 12 },
            },
            callbacks: {
                onReady: () => { brickPronto.value = true; },
                onError: (error) => { brickErro.value = error?.message ?? 'Não foi possível carregar o formulário do cartão.'; },
                onSubmit: () => Promise.resolve(),
            },
        });
    } catch (error) {
        brickErro.value = error?.message ?? 'Não foi possível carregar o formulário do cartão.';
    }
};

watch([metodo, totalCartao], ([novoMetodo]) => {
    clearTimeout(brickTimer);
    if (novoMetodo !== 'card' || props.order) {
        desmontarBrick();
        return;
    }
    brickTimer = setTimeout(montarBrick, 400);
});

// ---------- Selos de confiança ----------
// Textos com a identidade da KazaKora (a izeshop foi só referência de formato).
const SELOS = [
    { imagem: '/images/checkout/pagamento-100-seguro-checkout.png', titulo: 'Pagamento 100% Seguro', texto: 'Seu pagamento passa direto pelo Mercado Pago, com criptografia. Seus dados de cartão nunca ficam com a gente.' },
    { imagem: '/images/checkout/avaliacoes-positivas-checkout.png', titulo: 'Curadoria KazaKora', texto: 'Cada produto é escolhido a dedo e avaliado por quem já comprou. Dúvidas? A gente responde no WhatsApp.' },
    { imagem: '/images/checkout/satisfacao-garantida-checkout.png', titulo: 'Satisfação Garantida', texto: '7 dias para se arrepender e 30 dias de garantia. Trocou de ideia ou veio com defeito? A gente resolve sem complicação.' },
    { imagem: '/images/checkout/parcele-12x-cartao-checkout.png', titulo: 'Parcele em até 12x', texto: 'No cartão, com proteção antifraude e envio rápido. Ou pague no Pix e ganhe desconto na hora.' },
];

// ---------- Finalizar ----------
const erros = ref({});
const processando = ref(false);
const erroGeral = ref(null);
const erro = (campo) => erros.value[campo]?.[0] ?? erros.value[campo] ?? serverErrors.value[campo] ?? null;

const xsrf = () => decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/) ?? [])[1] ?? '');

const payloadEntrega = () => {
    const data = { shipping_method_id: shippingMethodId.value };

    if (!usarNovoEndereco.value && addressId.value) {
        data.address_id = addressId.value;
    } else {
        data.new_address = { ...endereco, state: (endereco.state || '').toUpperCase().slice(0, 2), recipient_name: pessoa.name, phone: pessoa.phone, label: 'Entrega' };
    }

    if (!isLoggedIn.value) {
        data.guest = { name: pessoa.name, email: pessoa.email, cpf: pessoa.cpf, phone: pessoa.phone };
    }

    return data;
};

const irParaErro = () => nextTick(() => {
    document.querySelector('[data-erro="1"]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
});

const finalizar = async () => {
    erros.value = {};
    erroGeral.value = null;
    processando.value = true;

    try {
        const resposta = await fetch('/finalizacao/entrega', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            body: JSON.stringify(payloadEntrega()),
        });

        if (resposta.status === 422) {
            const corpo = await resposta.json();
            erros.value = corpo.errors ?? {};
            erroGeral.value = corpo.message ?? 'Confira os campos destacados.';
            processando.value = false;
            irParaErro();
            return;
        }

        if (!resposta.ok) throw new Error();
    } catch {
        erroGeral.value = 'Não foi possível salvar seus dados agora. Confira sua conexão e tente de novo.';
        processando.value = false;
        return;
    }

    const pagamento = { payment_method: metodo.value, split: false, terms_accepted: true };

    if (metodo.value === 'card') {
        try {
            const dados = await brick?.getFormData();
            if (!dados?.token) throw new Error();
            Object.assign(pagamento, {
                mp_card_token: dados.token,
                mp_card_installments: dados.installments,
                mp_payment_method_id: dados.payment_method_id,
                mp_issuer_id: dados.issuer_id ?? null,
            });
        } catch {
            erroGeral.value = 'Confira os dados do cartão.';
            processando.value = false;
            document.getElementById('cartao-brick-v2')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
    }

    router.post('/finalizacao/pagamento', pagamento, {
        preserveScroll: true,
        preserveState: true,
        onError: (e) => { erros.value = e; erroGeral.value = e.payment ?? e.cart ?? e['guest.email'] ?? 'Não foi possível concluir. Confira os dados.'; },
        onFinish: () => { processando.value = false; },
    });
};

// ---------- Depois do pedido criado: QR do Pix / confirmação do cartão ----------
const estado = ref('idle');
const motivoFalha = ref(null);
const pixImagem = ref(null);
const pixCodigo = ref(null);
const pixSegundos = ref(null);
let pollTimer = null;
let pixTimer = null;
let pollInicio = null;

const pararTimers = () => {
    clearInterval(pollTimer);
    clearInterval(pixTimer);
    pollTimer = null;
    pixTimer = null;
};

onUnmounted(() => {
    pararTimers();
    clearTimeout(brickTimer);
    desmontarBrick();
});

const acompanhar = (orderId) => {
    pollInicio = Date.now();
    const consultar = async () => {
        try {
            const resposta = await fetch(`/finalizacao/${orderId}/status`, { headers: { Accept: 'application/json' } });
            const dados = await resposta.json();
            if (dados.status === 'paid') {
                pararTimers();
                estado.value = 'success';
                setTimeout(() => router.visit(dados.redirect), 1500);
                return;
            }
            if (dados.status === 'pending') {
                pararTimers();
                motivoFalha.value = estado.value === 'awaiting_pix' ? 'Não foi possível confirmar esse pagamento Pix.' : 'O pagamento não foi aprovado. Tente outro cartão ou pague com Pix.';
                estado.value = 'failed';
                return;
            }
        } catch {
            // instabilidade de rede — continua tentando
        }
        if (estado.value === 'processing' && Date.now() - pollInicio > 60000) estado.value = 'timeout';
    };
    consultar();
    pollTimer = setInterval(consultar, 4000);
};

watch(() => props.mercadoPagoPix, (pix) => {
    if (!pix || !props.order) return;
    pararTimers();
    pixImagem.value = pix.qrCodeBase64 ? `data:image/png;base64,${pix.qrCodeBase64}` : null;
    pixCodigo.value = pix.qrCode ?? null;
    estado.value = 'awaiting_pix';
    if (pix.expiresAt) {
        const expira = new Date(pix.expiresAt).getTime();
        const tick = () => {
            pixSegundos.value = Math.max(0, Math.round((expira - Date.now()) / 1000));
            if (pixSegundos.value === 0 && estado.value === 'awaiting_pix') {
                pararTimers();
                motivoFalha.value = 'O tempo para pagar esse Pix acabou.';
                estado.value = 'failed';
            }
        };
        tick();
        pixTimer = setInterval(tick, 1000);
    }
    acompanhar(props.order.id);
}, { immediate: true });

watch(() => props.mercadoPagoCardConfirmed, (confirmado) => {
    if (!confirmado || !props.order) return;
    pararTimers();
    estado.value = 'processing';
    acompanhar(props.order.id);
}, { immediate: true });

const tentarDeNovo = () => router.visit('/finalizacao');

// ---------- Resumo ----------
const resumoAberto = ref(false);
const fotoDoItem = (item) => {
    const imagens = item.product?.images ?? [];
    return (imagens.find((imagem) => imagem.is_primary) ?? imagens[0])?.thumb_url ?? (imagens.find((imagem) => imagem.is_primary) ?? imagens[0])?.url ?? null;
};
const totalItens = computed(() => props.items.reduce((soma, item) => soma + item.quantity, 0));

const inputClass = 'mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-[15px] text-slate-900 outline-none transition focus:border-sky-400 focus:ring-2 focus:ring-sky-200';
const inputErroClass = 'border-red-500 ring-1 ring-red-200';
</script>

<template>
    <Head title="Finalize sua compra" />

    <CheckoutLayout>
        <div class="mx-auto grid w-full max-w-[1160px] gap-6 px-3 py-6 md:px-6 lg:grid-cols-[minmax(0,62fr)_minmax(0,38fr)] lg:items-start lg:py-9">
            <!-- Resumo no celular (recolhível) -->
            <button type="button" class="flex items-center justify-between rounded-xl border border-slate-200 bg-white px-4 py-3 text-left shadow-sm lg:hidden" @click="resumoAberto = !resumoAberto">
                <span class="flex items-center gap-2 text-sm font-medium text-slate-700">
                    <i class="fa-solid fa-cart-shopping text-emerald-600"></i>
                    {{ resumoAberto ? 'Ocultar resumo' : 'Ver resumo' }} ({{ totalItens }} {{ totalItens === 1 ? 'item' : 'itens' }})
                    <i class="fa-solid fa-chevron-down text-xs transition" :class="{ 'rotate-180': resumoAberto }"></i>
                </span>
                <span class="text-base font-bold text-slate-900">{{ formatPrice(totalAPagar) }}</span>
            </button>

            <!-- FORMULÁRIO -->
            <main class="order-2 lg:order-1">
                <div class="mb-5 flex items-center gap-3">
                    <span class="flex h-11 w-11 items-center justify-center rounded-full bg-emerald-100 text-emerald-600"><i class="fa-solid fa-lock text-lg"></i></span>
                    <div>
                        <h1 class="text-2xl font-bold text-slate-900">Finalize sua compra</h1>
                        <p class="text-sm text-slate-500">Leva menos de 1 minuto. Seus dados estão protegidos.</p>
                    </div>
                </div>

                <div v-if="erroGeral || serverErrors.payment || serverErrors.cart" class="mb-4 flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    <i class="fa-solid fa-circle-exclamation mt-0.5"></i>
                    <div>
                        {{ erroGeral || serverErrors.payment || serverErrors.cart }}
                        <Link v-if="erro('guest.email')" href="/entrar" class="ml-1 font-semibold underline">Entrar na minha conta</Link>
                    </div>
                </div>

                <div class="space-y-4">
                    <!-- 1. Dados pessoais -->
                    <section class="rounded-xl border border-b-[3px] border-slate-200 bg-white p-5 md:p-6">
                        <header class="mb-4">
                            <h2 class="flex items-center gap-2 text-lg font-semibold text-slate-900">
                                <span class="flex h-7 w-7 items-center justify-center rounded-full bg-slate-900 text-xs font-bold text-white">1</span>
                                Dados pessoais
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                <template v-if="isLoggedIn">Comprando como <strong class="text-slate-700">{{ customer.email }}</strong></template>
                                <template v-else>Preencha seus dados corretamente · Já tem conta? <Link href="/entrar" class="font-medium text-sky-600 hover:underline">Entrar</Link></template>
                            </p>
                        </header>

                        <div class="grid gap-3 md:grid-cols-2">
                            <label class="md:col-span-2" :data-erro="erro('guest.name') ? 1 : 0">
                                <span class="text-sm font-medium text-slate-700">Nome completo</span>
                                <input v-model="pessoa.name" type="text" autocomplete="name" :readonly="isLoggedIn" :class="[inputClass, erro('guest.name') && inputErroClass, isLoggedIn && 'bg-slate-50']" placeholder="Seu nome e sobrenome">
                                <small v-if="erro('guest.name')" class="text-xs text-red-600">{{ erro('guest.name') }}</small>
                            </label>
                            <label :data-erro="erro('new_address.phone') ? 1 : 0">
                                <span class="text-sm font-medium text-slate-700">Celular com DDD (WhatsApp)</span>
                                <input :value="pessoa.phone" type="tel" inputmode="numeric" autocomplete="tel" :class="[inputClass, erro('new_address.phone') && inputErroClass]" placeholder="(11) 99999-9999" @input="pessoa.phone = maskPhone($event.target.value)">
                                <small v-if="erro('new_address.phone')" class="text-xs text-red-600">{{ erro('new_address.phone') }}</small>
                            </label>
                            <label v-if="!isLoggedIn" :data-erro="erro('guest.cpf') ? 1 : 0">
                                <span class="text-sm font-medium text-slate-700">CPF</span>
                                <input :value="pessoa.cpf" type="text" inputmode="numeric" :class="[inputClass, erro('guest.cpf') && inputErroClass]" placeholder="000.000.000-00" @input="pessoa.cpf = maskCpf($event.target.value)">
                                <small v-if="erro('guest.cpf')" class="text-xs text-red-600">{{ erro('guest.cpf') }}</small>
                            </label>
                            <label v-if="!isLoggedIn" class="md:col-span-2" :data-erro="erro('guest.email') ? 1 : 0">
                                <span class="text-sm font-medium text-slate-700">E-mail</span>
                                <input v-model="pessoa.email" type="email" autocomplete="email" :class="[inputClass, erro('guest.email') && inputErroClass]" placeholder="seuemail@exemplo.com">
                                <small v-if="erro('guest.email')" class="text-xs text-red-600">{{ erro('guest.email') }}</small>
                                <small v-else class="text-xs text-slate-500">Você recebe a confirmação do pedido e o acesso à sua conta neste e-mail.</small>
                            </label>
                        </div>
                    </section>

                    <!-- 2. Endereço -->
                    <section class="rounded-xl border border-b-[3px] border-slate-200 bg-white p-5 md:p-6">
                        <header class="mb-4">
                            <h2 class="flex items-center gap-2 text-lg font-semibold text-slate-900">
                                <span class="flex h-7 w-7 items-center justify-center rounded-full bg-slate-900 text-xs font-bold text-white">2</span>
                                Endereço de entrega
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">Onde o produto será entregue</p>
                        </header>

                        <div v-if="addresses.length && !usarNovoEndereco" class="space-y-2">
                            <label v-for="address in addresses" :key="address.id"
                                class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition"
                                :class="addressId === address.id ? 'border-emerald-500 bg-emerald-50/60 ring-1 ring-emerald-500' : 'border-slate-200 hover:border-slate-300'">
                                <input v-model="addressId" type="radio" :value="address.id" class="mt-1 h-4 w-4 accent-emerald-600">
                                <span class="text-sm text-slate-700">
                                    <strong class="block text-slate-900">{{ address.street }}, {{ address.number }}<template v-if="address.complement"> - {{ address.complement }}</template></strong>
                                    {{ address.neighborhood }} · {{ address.city }}/{{ address.state }} · CEP {{ address.zip }}
                                </span>
                            </label>
                            <button type="button" class="mt-1 text-sm font-medium text-sky-600 hover:underline" @click="usarNovoEndereco = true">
                                <i class="fa-solid fa-plus mr-1"></i> Entregar em outro endereço
                            </button>
                        </div>

                        <div v-else class="grid grid-cols-6 gap-3">
                            <label class="col-span-6 md:col-span-2" :data-erro="erro('new_address.zip') ? 1 : 0">
                                <span class="text-sm font-medium text-slate-700">CEP</span>
                                <div class="relative">
                                    <input :value="endereco.zip" type="text" inputmode="numeric" autocomplete="postal-code" maxlength="9" :class="[inputClass, erro('new_address.zip') && inputErroClass]" placeholder="00000-000" @input="onCep">
                                    <i v-if="cepLoading" class="fa-solid fa-spinner absolute right-3 top-1/2 mt-0.5 -translate-y-1/2 animate-spin text-slate-400"></i>
                                </div>
                                <small v-if="cepError || erro('new_address.zip')" class="text-xs text-red-600">{{ cepError || erro('new_address.zip') }}</small>
                            </label>
                            <label class="col-span-6 md:col-span-4" :data-erro="erro('new_address.street') ? 1 : 0">
                                <span class="text-sm font-medium text-slate-700">Endereço</span>
                                <input v-model="endereco.street" type="text" autocomplete="address-line1" :class="[inputClass, erro('new_address.street') && inputErroClass]" placeholder="Rua, avenida...">
                            </label>
                            <label class="col-span-2" :data-erro="erro('new_address.number') ? 1 : 0">
                                <span class="text-sm font-medium text-slate-700">Número</span>
                                <input ref="numeroInput" v-model="endereco.number" type="text" :class="[inputClass, erro('new_address.number') && inputErroClass]" placeholder="Nº">
                            </label>
                            <label class="col-span-4">
                                <span class="text-sm font-medium text-slate-700">Complemento <span class="font-normal text-slate-400">(opcional)</span></span>
                                <input v-model="endereco.complement" type="text" autocomplete="address-line2" :class="inputClass" placeholder="Apto, bloco, casa...">
                            </label>
                            <label class="col-span-6 md:col-span-2" :data-erro="erro('new_address.neighborhood') ? 1 : 0">
                                <span class="text-sm font-medium text-slate-700">Bairro</span>
                                <input v-model="endereco.neighborhood" type="text" :class="[inputClass, erro('new_address.neighborhood') && inputErroClass]">
                            </label>
                            <label class="col-span-4 md:col-span-3" :data-erro="erro('new_address.city') ? 1 : 0">
                                <span class="text-sm font-medium text-slate-700">Cidade</span>
                                <input v-model="endereco.city" type="text" autocomplete="address-level2" :class="[inputClass, erro('new_address.city') && inputErroClass]">
                            </label>
                            <label class="col-span-2 md:col-span-1" :data-erro="erro('new_address.state') ? 1 : 0">
                                <span class="text-sm font-medium text-slate-700">UF</span>
                                <input v-model="endereco.state" type="text" maxlength="2" autocomplete="address-level1" :class="[inputClass, 'uppercase', erro('new_address.state') && inputErroClass]">
                            </label>
                            <p v-if="['new_address.street','new_address.number','new_address.neighborhood','new_address.city','new_address.state'].some((c) => erro(c))" class="col-span-6 text-xs text-red-600">Preencha o endereço completo.</p>
                            <button v-if="addresses.length" type="button" class="col-span-6 text-left text-sm font-medium text-sky-600 hover:underline" @click="usarNovoEndereco = false">
                                <i class="fa-solid fa-arrow-left mr-1"></i> Usar um endereço salvo
                            </button>
                        </div>
                    </section>

                    <!-- 3. Entrega -->
                    <section class="rounded-xl border border-b-[3px] border-slate-200 bg-white p-5 md:p-6">
                        <header class="mb-4">
                            <h2 class="flex items-center gap-2 text-lg font-semibold text-slate-900">
                                <span class="flex h-7 w-7 items-center justify-center rounded-full bg-slate-900 text-xs font-bold text-white">3</span>
                                Forma de entrega
                                <i v-if="freteCarregando" class="fa-solid fa-spinner animate-spin text-sm text-slate-400"></i>
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">Método de entrega</p>
                        </header>

                        <p v-if="entregaExpressa" class="mb-3 flex items-center gap-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700">
                            <i class="fa-solid fa-bolt"></i> {{ entregaExpressa.mensagem }}
                        </p>

                        <!-- Sempre frete grátis, logado ou não, sem escolha (pedido 2026-10-10). -->
                        <div class="flex items-center gap-3 rounded-lg border border-emerald-500 bg-emerald-50/60 p-3 ring-1 ring-emerald-500">
                            <i class="fa-solid fa-truck-fast text-emerald-600"></i>
                            <span class="flex-1 text-sm text-slate-700">
                                <strong class="text-slate-900">Frete GRÁTIS</strong>
                                <span class="ml-1 text-slate-500">(1 à 7 dias úteis)</span>
                            </span>
                            <span class="text-sm font-bold text-emerald-600">Grátis</span>
                        </div>
                        <small v-if="erro('shipping_method_id')" class="mt-1 block text-xs text-red-600">{{ erro('shipping_method_id') }}</small>
                    </section>

                    <!-- 4. Pagamento -->
                    <section class="rounded-xl border border-b-[3px] border-slate-200 bg-white p-5 md:p-6">
                        <header class="mb-4">
                            <h2 class="flex items-center gap-2 text-lg font-semibold text-slate-900">
                                <span class="flex h-7 w-7 items-center justify-center rounded-full bg-slate-900 text-xs font-bold text-white">4</span>
                                Forma de pagamento
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">Escolha a melhor forma de pagamento para você</p>
                        </header>

                        <div class="grid grid-cols-2 gap-3">
                            <button type="button" class="relative flex flex-col items-center gap-1.5 rounded-xl border-2 px-3 py-4 transition"
                                :class="metodo === 'pix' ? 'border-emerald-500 bg-emerald-50 shadow-sm' : 'border-slate-200 bg-white hover:border-slate-300'"
                                @click="metodo = 'pix'">
                                <span v-if="pixDiscountPercentage > 0" class="absolute -top-2.5 right-2 rounded-full bg-emerald-600 px-2 py-0.5 text-[11px] font-bold text-white shadow">-{{ pixDiscountPercentage }}%</span>
                                <i class="fa-brands fa-pix text-2xl text-[#32BCAD]"></i>
                                <span class="text-sm font-semibold text-slate-900">Pix</span>
                                <span class="text-xs text-slate-500">Aprovação na hora</span>
                            </button>
                            <button type="button" class="flex flex-col items-center gap-1.5 rounded-xl border-2 px-3 py-4 transition"
                                :class="metodo === 'card' ? 'border-emerald-500 bg-emerald-50 shadow-sm' : 'border-slate-200 bg-white hover:border-slate-300'"
                                @click="metodo = 'card'">
                                <i class="fa-solid fa-credit-card text-2xl text-slate-700"></i>
                                <span class="text-sm font-semibold text-slate-900">Cartão de crédito</span>
                                <span class="text-xs text-slate-500">Até 12x de {{ formatPrice(parcela12) }}</span>
                            </button>
                        </div>

                        <div v-if="metodo === 'pix'" class="mt-4 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">
                            <p v-if="descontoPix > 0" class="flex items-center gap-2 font-bold text-emerald-700">
                                <i class="fa-solid fa-circle-check"></i> Desconto de {{ pixDiscountPercentage }}% ativado: você economiza {{ formatPrice(descontoPix) }}
                            </p>
                            <ul class="mt-2 space-y-1 text-emerald-800">
                                <li><i class="fa-solid fa-qrcode mr-2 w-4"></i>O QR Code aparece assim que você finalizar.</li>
                                <li><i class="fa-solid fa-mobile-screen mr-2 w-4"></i>Pague pelo app do seu banco, copiando o código ou lendo o QR.</li>
                                <li><i class="fa-solid fa-bolt mr-2 w-4"></i>Aprovação em segundos e envio mais rápido.</li>
                            </ul>
                        </div>

                        <div v-else class="mt-4">
                            <div id="cartao-brick-v2"></div>
                            <p v-if="!brickPronto && !brickErro" class="py-6 text-center text-sm text-slate-500"><i class="fa-solid fa-spinner mr-2 animate-spin"></i>Carregando ambiente seguro do cartão...</p>
                            <p v-if="brickErro" class="mt-2 text-sm text-red-600">{{ brickErro }}</p>
                        </div>

                        <button id="finalizar-pedido" type="button" :disabled="processando || !!order || (metodo === 'card' && !brickPronto)"
                            class="mt-5 flex w-full items-center justify-center gap-3 rounded-xl bg-[#0FB930] px-6 py-4 text-xl font-bold text-white shadow-[0_4px_0_#0a8a23] transition hover:brightness-105 active:translate-y-0.5 active:shadow-[0_2px_0_#0a8a23] disabled:cursor-not-allowed disabled:opacity-60 md:text-2xl"
                            @click="finalizar">
                            <i class="fa-solid" :class="processando ? 'fa-spinner animate-spin' : 'fa-lock'"></i>
                            {{ processando ? 'Processando...' : 'Finalizar pedido' }}
                        </button>
                        <p class="mt-2 text-center text-xs text-slate-500">
                            Ao finalizar, você concorda com os <Link href="/termos-de-uso" target="_blank" class="underline">termos de uso</Link> e a <Link href="/politica-de-privacidade" target="_blank" class="underline">política de privacidade</Link>.
                        </p>
                    </section>
                </div>
            </main>

            <!-- RESUMO -->
            <aside class="order-1 lg:order-2 lg:sticky lg:top-6" :class="{ 'hidden lg:block': !resumoAberto }">
                <div class="rounded-xl border border-b-[3px] border-slate-200 bg-white p-5 md:p-6">
                    <h2 class="mb-4 text-xl font-semibold text-slate-800">Resumo do seu pedido</h2>

                    <ul class="divide-y divide-slate-100">
                        <li v-for="item in items" :key="item.product.id" class="flex items-center gap-3 py-3">
                            <span class="relative shrink-0">
                                <img v-if="fotoDoItem(item)" :src="fotoDoItem(item)" :alt="item.product.name" class="h-16 w-16 rounded-lg border border-slate-200 object-cover">
                                <span v-else class="flex h-16 w-16 items-center justify-center rounded-lg bg-slate-100"><i class="fa-solid fa-box text-slate-400"></i></span>
                                <span class="absolute -right-2 -top-2 flex h-5 min-w-5 items-center justify-center rounded-full bg-slate-700 px-1 text-[11px] font-bold text-white">{{ item.quantity }}</span>
                            </span>
                            <span class="min-w-0 flex-1 text-sm text-slate-600">{{ item.product.name }}</span>
                            <span class="text-sm font-medium text-slate-800">{{ formatPrice(item.subtotal) }}</span>
                        </li>
                    </ul>
                    <Link href="/carrinho" class="mt-1 inline-block text-xs font-medium text-sky-600 hover:underline">Alterar itens do carrinho</Link>

                    <div class="mt-3 border-t border-slate-100 pt-3">
                        <div v-if="cupomAplicado" class="flex items-center justify-between gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm">
                            <span class="text-emerald-800">
                                <i class="fa-solid fa-ticket mr-1"></i> Cupom <strong>{{ cupomAplicado }}</strong> aplicado
                                <span v-if="cupomDescricao" class="block text-xs text-emerald-700">{{ cupomDescricao }}</span>
                            </span>
                            <button type="button" :disabled="cupomCarregando" class="text-xs font-semibold text-slate-500 hover:text-red-600 disabled:opacity-50" @click="removerCupom">
                                <i class="fa-solid" :class="cupomCarregando ? 'fa-spinner animate-spin' : 'fa-xmark'"></i> Remover
                            </button>
                        </div>
                        <button v-else-if="!mostrarCupom" type="button" class="text-sm font-medium text-[#F44D00] hover:underline" @click="mostrarCupom = true">
                            <i class="fa-solid fa-ticket mr-1"></i> Tem um cupom? Clique aqui
                        </button>
                        <form v-else class="flex gap-2" @submit.prevent="aplicarCupom">
                            <input v-model="cupom" type="text" placeholder="Código do cupom" autocapitalize="characters" :disabled="cupomCarregando"
                                class="min-w-0 flex-1 rounded-lg border px-3 py-2 text-sm uppercase outline-none focus:border-[#F44D00]"
                                :class="cupomErro ? 'border-red-400' : 'border-slate-300'" @input="cupomErro = null">
                            <button type="submit" :disabled="!cupom.trim() || cupomCarregando"
                                class="inline-flex min-w-[92px] items-center justify-center gap-1.5 rounded-lg bg-[#F44D00] px-4 py-2 text-sm font-semibold text-white hover:bg-[#E74900] disabled:opacity-50">
                                <i v-if="cupomCarregando" class="fa-solid fa-spinner animate-spin"></i>
                                {{ cupomCarregando ? 'Buscando' : 'Aplicar' }}
                            </button>
                        </form>
                        <p v-if="cupomErro" class="mt-1 text-xs text-red-600"><i class="fa-solid fa-circle-exclamation mr-1"></i>{{ cupomErro }}</p>
                    </div>

                    <dl class="mt-3 space-y-2 border-t border-slate-100 pt-3 text-sm text-slate-700">
                        <div class="flex justify-between"><dt>Subtotal</dt><dd>{{ formatPrice(subtotal) }}</dd></div>
                        <div v-if="descontoCupom > 0" class="flex justify-between text-[#24ae4e]"><dt>Cupom {{ cupomAplicado }}</dt><dd>-{{ formatPrice(descontoCupom) }}</dd></div>
                        <div v-if="descontoPix > 0 && !order" class="flex justify-between text-[#24ae4e]"><dt>Desconto Pix ({{ pixDiscountPercentage }}%)</dt><dd>-{{ formatPrice(descontoPix) }}</dd></div>
                        <div class="flex justify-between"><dt>Entrega</dt><dd :class="valorFrete === 0 ? 'font-semibold text-[#24ae4e]' : ''">{{ entregaEscolhida ? (valorFrete === 0 ? 'Grátis' : formatPrice(valorFrete)) : '—' }}</dd></div>
                        <div class="flex items-baseline justify-between border-t border-slate-100 pt-3 text-base font-semibold text-slate-900">
                            <dt>Total</dt>
                            <dd class="text-xl">{{ formatPrice(totalAPagar) }}</dd>
                        </div>
                        <p v-if="metodo === 'card' && !order" class="text-right text-xs text-slate-500">ou até 12x de {{ formatPrice(parcela12) }} no cartão</p>
                    </dl>
                </div>

                <!-- Selos de confiança com imagem (pedido 2026-10-10, textos no modelo izeshop
                     adaptados à KazaKora). -->
                <ul class="mt-4 space-y-3">
                    <li v-for="selo in SELOS" :key="selo.titulo" class="flex items-start gap-3 rounded-xl border border-slate-200 bg-white p-4">
                        <img :src="selo.imagem" :alt="selo.titulo" width="56" height="56" loading="lazy" decoding="async" class="h-14 w-14 shrink-0">
                        <div class="text-sm text-slate-600">
                            <strong class="block text-slate-900">{{ selo.titulo }}</strong>
                            {{ selo.texto }}
                        </div>
                    </li>
                </ul>
            </aside>
        </div>

        <PaymentProcessingModal
            :open="estado !== 'idle'"
            :state="estado"
            :order-total="order ? Number(order.total) : totalAPagar"
            :pix-qr-image-url="pixImagem"
            :pix-qr-code="pixCodigo"
            :pix-seconds-left="pixSegundos"
            :failure-message="motivoFalha"
            @retry="tentarDeNovo" />
    </CheckoutLayout>
</template>
