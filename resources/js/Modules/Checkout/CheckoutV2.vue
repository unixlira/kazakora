<script setup>
import { rastrearPurchase } from '@/Shared/pixelMeta';
// Checkout v2 (pedido 2026-10-10): uma tela só, sem topo e rodapé da loja,
// feita pra fechar a venda, resumo do pedido ao lado. Desde 2026-10-23 no
// modelo Yampi (referência seguro.mavoloja.com.br): 3 caixas em sequência
// (Identificação, Entrega, Pagamento), só a etapa atual aberta.
// Usa o mesmo backend do checkout em 2 etapas: grava a entrega por fetch
// (JSON) e cria o pagamento por /finalizacao/pagamento; o QR do Pix e a
// confirmação do cartão chegam nas props desta mesma página.
import CheckoutLayout from '@/Shared/Layouts/CheckoutLayout.vue';
import PaymentProcessingModal from '@/Shared/Components/PaymentProcessingModal.vue';
import { maskCep, useCep } from '@/Shared/useCep';
import { campoDoErro, useMedicaoCheckout } from './medicaoCheckout';
import { maskCnpj, maskCpf, maskPhone } from '@/Shared/useMasks';
import CarrosselAvaliacoes from '@/Shared/Components/CarrosselAvaliacoes.vue';
import { loadMercadoPagoSdk, mensagemErroCartao } from '@/Shared/useMercadoPagoSdk';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue';

const props = defineProps({
    items: { type: Array, default: () => [] },
    // Média das avaliações dos produtos do carrinho: { media, total } ou null.
    avaliacaoItens: { type: Object, default: null },
    // Livros de brinde dos produtos do carrinho: { imagem, quantidade, titulos } ou null.
    livrosBrinde: { type: Object, default: null },
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
    // Boleto (2026-10-14): { linhaDigitavel, codigoBarras, pdfUrl, vencimento }.
    mercadoPagoBoleto: { type: Object, default: null },
    boletoDiasVencimento: { type: Number, default: 3 },
    // Modelo Yampi (2026-10-23): avaliações reais do banco e cupom de 1ª compra cadastrado (ou null).
    avaliacoesCarrossel: { type: Array, default: () => [] },
    cupomPrimeiraCompra: { type: Object, default: null },
});

// Desconto Checkout em R$ fixo vira % com casas a mais: mostra no máximo 2 (2026-10-18).
const pixPercentualTexto = computed(() => Number(Number(props.pixDiscountPercentage).toFixed(2)).toLocaleString('pt-BR'));

const page = usePage();
const serverErrors = computed(() => page.props.errors ?? {});
const isLoggedIn = computed(() => !!props.customer);

// Medição da tela (pedido 2026-10-23): o que a pessoa fez, nunca o que digitou.
const medicao = useMedicaoCheckout();
const secaoDados = ref(null);

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

// Pessoa física ou jurídica (pedido 2026-10-24, modelo Mavo). PROTÓTIPO: a
// razão social e o CNPJ vão no envio (campo "empresa"), mas o servidor ainda
// não grava e o pedido e a nota seguem no CPF do responsável. Ligar na nota
// fiscal é mudança fiscal e espera o OK do Lira.
const tipoPessoa = ref(props.draft?.empresa?.cnpj ? 'pj' : 'pf');
const empresa = reactive({
    razaoSocial: props.draft?.empresa?.razao_social ?? '',
    cnpj: props.draft?.empresa?.cnpj ? maskCnpj(props.draft.empresa.cnpj) : '',
});
const ehPj = computed(() => tipoPessoa.value === 'pj');

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
const cepInput = ref(null);
const ruaInput = ref(null);
const numeroInput = ref(null);
// Modelo Mavo (pedido 2026-10-24): endereço novo começa só com o CEP; o resto
// aparece depois da consulta, preenchido se achou e em branco se não achou.
// Rascunho com rua já preenchida (voltou para a tela) mostra tudo direto.
const cepConsultado = ref(Boolean(endereco.street));

const onCep = async (event) => {
    const zip = maskCep(event.target.value);
    rolarAteFrete = zip.replace(/\D/g, '').length === 8;
    endereco.zip = zip;
    if (!rolarAteFrete) return;
    const result = await lookupCep(endereco.zip);
    cepConsultado.value = true;
    if (result) {
        endereco.street = result.street || endereco.street;
        endereco.neighborhood = result.neighborhood || endereco.neighborhood;
        endereco.city = result.city;
        endereco.state = result.state;
    }
    await nextTick();
    (result?.street ? numeroInput : ruaInput).value?.focus();
};

const enderecoSelecionado = computed(() => props.addresses.find((address) => address.id === addressId.value) ?? null);
const cepEfetivo = computed(() => (usarNovoEndereco.value ? endereco.zip : (enderecoSelecionado.value?.zip ?? '')));

// ---------- 3. Entrega ----------
// Frete grátis com o frete embutido (pedido 2026-10-12): o preço dos produtos
// já tem o frete da região. Quando o CEP muda, o servidor devolve itens e
// subtotal com o frete exato desse CEP, a entrega FULL (Grande SP) ou a opção
// TURBO (SEDEX pagando só a diferença).
const freteCarregando = ref(false);
const entregaExpressa = ref(null);
const prazoGratis = ref(null);
const turbo = ref(null);
// A tela nasce sempre no frete grátis (pedido 2026-10-12).
const turboEscolhido = ref(false);
const itens = ref(props.items);
// Estrelas do resumo (pedido 2026-10-21): cada estrela preenche a fração da
// média que cabe nela (4,8 = 4 cheias e 80% da 5ª), como no StarRating.
const livrosBrindeNome = computed(() => (props.livrosBrinde?.quantidade === 1 ? 'Livro digital (PDF) de presente' : `${props.livrosBrinde?.quantidade} livros digitais (PDF) de presente`));
const livrosBrindeAlt = computed(() => `Livros digitais grátis com a compra: ${(props.livrosBrinde?.titulos ?? []).join(', ')}`);
const notaItens = computed(() => Math.max(0, Math.min(5, Number(props.avaliacaoItens?.media) || 0)));
const preenchimentoEstrela = (estrela) => Math.max(0, Math.min(100, (notaItens.value - (estrela - 1)) * 100));
const subtotalAtual = ref(Number(props.subtotal) || 0);
// Vários itens numa caixa só (2026-10-12): o frete real sai menor que o
// somado no preço de cada item; a diferença entra como "Desconto da plataforma".
const descontoPlataforma = ref(0);
const opcoesEntrega = computed(() => props.shippingMethods);
const shippingMethodId = ref(props.shippingMethods[0]?.id ?? null);

// Rolagem até as opções de frete (pedido 2026-10-23, igual à página do
// produto): só depois que o cliente digita o CEP, não ao abrir a tela nem ao
// trocar de endereço salvo. Rola o mínimo para a caixa aparecer inteira.
const opcoesFreteEl = ref(null);
let rolarAteFrete = false;
const mostrarOpcoesFrete = async () => {
    await nextTick();
    const el = opcoesFreteEl.value;
    if (!el) return;
    const caixa = el.getBoundingClientRect();
    if (caixa.top >= 0 && caixa.bottom <= window.innerHeight) return;
    const suave = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    el.scrollIntoView({ behavior: suave ? 'smooth' : 'auto', block: 'nearest' });
};

const atualizarFrete = async (zip) => {
    const cep = (zip ?? '').replace(/\D/g, '');
    if (cep.length !== 8) {
        entregaExpressa.value = null;
        turbo.value = null;
        prazoGratis.value = null;
        return;
    }
    freteCarregando.value = true;
    try {
        const response = await fetch(`/finalizacao/entrega-gratis?cep=${cep}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) throw new Error();
        const data = await response.json();
        entregaExpressa.value = data.entrega_expressa ?? null;
        prazoGratis.value = data.prazo ?? null;
        turbo.value = data.turbo ?? null;
        descontoPlataforma.value = Number(data.desconto_plataforma) || 0;
        medicao.registrar('cep_respondido', {}, 'cep_respondido');
        if (!turbo.value) turboEscolhido.value = false;
        itens.value = data.items ?? itens.value;
        subtotalAtual.value = Number(data.subtotal ?? subtotalAtual.value);
        if (cupomAplicado.value) descontoCupom.value = Number(data.discount_amount) || 0;
        if (rolarAteFrete) {
            rolarAteFrete = false;
            mostrarOpcoesFrete();
        }
    } catch {
        entregaExpressa.value = null;
        turbo.value = null;
        descontoPlataforma.value = 0;
    } finally {
        freteCarregando.value = false;
    }
};
watch(cepEfetivo, atualizarFrete, { immediate: true });

// +/− no resumo (modelo Yampi, 2026-10-23): grava a quantidade, recarrega os
// itens e o subtotal do servidor e refaz o frete embutido do CEP atual.
watch(() => props.items, (novos) => { itens.value = novos; });
watch(() => props.subtotal, (novo) => { subtotalAtual.value = Number(novo) || 0; });
const alterandoItem = ref(null);
const alterarQuantidade = async (item, delta) => {
    const nova = item.quantity + delta;
    const estoque = Number(item.product?.stock);
    if (nova < 1 || (Number.isFinite(estoque) && nova > estoque) || alterandoItem.value || props.order) return;
    alterandoItem.value = item.product.id;
    try {
        const resposta = await fetch(`/finalizacao/itens/${item.product.id}`, {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/) ?? [])[1] ?? '') },
            body: JSON.stringify({ quantity: nova }),
        });
        if (!resposta.ok) throw new Error();
        await new Promise((resolve) => router.reload({
            only: ['items', 'subtotal', 'productsDiscount', 'couponCode', 'discountAmount', 'pixDiscountPercentage', 'avaliacaoItens', 'livrosBrinde'],
            preserveScroll: true,
            preserveState: true,
            onFinish: resolve,
        }));
        await atualizarFrete(cepEfetivo.value);
    } catch {
        erroGeral.value = 'Não foi possível mudar a quantidade agora. Tente de novo.';
    } finally {
        alterandoItem.value = null;
    }
};

const entregaEscolhida = computed(() => opcoesEntrega.value.find((option) => option.id === shippingMethodId.value) ?? null);
const valorFrete = computed(() => (turboEscolhido.value && turbo.value ? Number(turbo.value.preco) : Number(entregaEscolhida.value?.price ?? 0)));
const diasUteis = (dias) => `${dias} ${dias === 1 ? 'dia útil' : 'dias úteis'}`;

// ---------- Cupom (pedido 2026-10-10) ----------
// Aplica por JSON: busca o cupom na base, confere as regras e o desconto
// entra no total na hora, sem recarregar a tela.
const cupomAplicado = ref(props.couponCode);
const descontoCupom = ref(Number(props.discountAmount) || 0);
const cupomDescricao = ref(null);
const cupom = ref('');
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
    if (cupomCarregando.value || cupomAplicado.value) return;
    if (!cupom.value.trim()) {
        cupomErro.value = 'Digite o código do cupom.';
        return;
    }
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
    } catch {
        cupomErro.value = 'Sem conexão. Tente de novo.';
    } finally {
        cupomCarregando.value = false;
    }
};

const campoCupom = ref(null);

// X dentro do campo: tira o cupom, esvazia o campo e deixa pronto para digitar outro.
const limparCupom = async () => {
    await removerCupom();
    cupom.value = '';
    cupomErro.value = null;
    await nextTick();
    campoCupom.value?.focus();
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
// Pix desde 2026-10-24: o % sobre os produtos ANTES do cupom (3% do BEMVINDO
// + 2% do Pix = 5%), sem passar do que sobrou depois do cupom. Mesma conta do
// DescontoPix::descontoSobreCupom.
const descontoPixCalculado = computed(() => {
    const base = Math.max(0, subtotalAtual.value - descontoPlataforma.value);
    return Math.min(round2(base * props.pixDiscountPercentage / 100), round2(Math.max(0, base - descontoCupom.value)));
});
const descontoPix = computed(() => (metodo.value === 'pix' && props.pixDiscountPercentage > 0 ? descontoPixCalculado.value : 0));
const totalCartao = computed(() => round2(subtotalAtual.value - descontoCupom.value - descontoPlataforma.value + valorFrete.value));
const totalAPagar = computed(() => (props.order ? Number(props.order.total) : round2(totalCartao.value - descontoPix.value)));
const parcela12 = computed(() => totalCartao.value / 12);

// Card Payment Brick do Mercado Pago embutido (sem o botão próprio dele):
// o "Finalizar pedido" único chama getFormData(), que tokeniza o cartão no
// navegador — o número do cartão nunca passa pelo nosso servidor.
let brick = null;
let brickTimer = null;
const brickPronto = ref(false);
const brickErro = ref(null);

const cartaoBin = ref('');
const BANDEIRAS_BIN = [
    ['elo', /^(4011(78|79)|43(1274|8935)|45(1416|7393|763(1|2))|50(4175|6699|67[0-7][0-9]|9000)|627780|63(6297|6368)|650(03([^4])|04([0-9])|05(0|1)|4(0[5-9]|3[0-9]|8[5-9]|9[0-9])|5([0-2][0-9]|3[0-8])|9([2-6][0-9]|7[0-8])|541|700|720|901)|651652|655000|655021)/],
    ['hipercard', /^(606282|3841)/],
    ['amex', /^3[47]/],
    ['diners', /^3(0[0-5]|[68])/],
    ['visa', /^4/],
    ['mastercard', /^(5[1-5]|2(2[2-9]|[3-6]|7[01]|720))/],
];
const cartaoBandeira = computed(() => BANDEIRAS_BIN.find(([, regra]) => regra.test(cartaoBin.value))?.[0] ?? null);
const cartaoNumeroPrevia = computed(() => {
    const digitos = cartaoBin.value.padEnd(16, '•');
    return digitos.replace(/(.{4})/g, '$1 ').trim();
});

const desmontarBrick = () => {
    brick?.unmount();
    brick = null;
    brickPronto.value = false;
    cartaoBin.value = '';
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
                // Prévia do cartão (modelo Yampi): o Brick só entrega os 6 primeiros
                // dígitos (BIN); o resto do número nunca sai do campo seguro dele.
                onBinChange: (bin) => { cartaoBin.value = String(bin ?? '').replace(/\D/g, '').slice(0, 8); },
                onError: (error) => { brickErro.value = mensagemErroCartao(error); },
                onSubmit: () => Promise.resolve(),
            },
        });
    } catch (error) {
        brickErro.value = mensagemErroCartao(error);
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
    { imagem: '/images/checkout/pagamento-100-seguro-checkout-v4.webp', titulo: 'Pagamento 100% Seguro', texto: 'Seu pagamento passa direto pelo Mercado Pago, com criptografia. Seus dados de cartão nunca ficam com a gente.' },
    { imagem: '/images/checkout/avaliacoes-positivas-checkout-v4.webp', titulo: 'Avaliações Positivas', texto: 'Cada produto é escolhido a dedo e avaliado por quem já comprou. Dúvidas? A gente responde no WhatsApp.' },
    { imagem: '/images/checkout/satisfacao-garantida-checkout-v4.webp', titulo: 'Satisfação Garantida', texto: 'Receba sua compra e fique 100% satisfeito(a). Se não gostar, devolvemos todo o seu dinheiro, sem burocracia.' },
    { imagem: '/images/checkout/parcele-12x-cartao-checkout-v4.webp', titulo: 'Parcele em até 12x', texto: 'No cartão, com proteção antifraude e envio rápido. Ou pague no Pix e ganhe desconto na hora, ou no boleto.' },
];

// Selos e checks verdes do padrão KazaKora (mesmos da página de produto,
// 2026-10-19), abaixo do resumo (pedido 2026-10-23). Só fatos de toda compra.
const SELOS_VERIFICADOS = [
    ['Loja verificada', 'Empresa com CNPJ ativo e selo RA Verificada no Reclame Aqui'],
    ['Compra segura', 'Pagamento processado com segurança; não guardamos dados do cartão'],
];
const CHECKS_LOJA = ['Frete grátis para a sua casa', 'Nota fiscal em todo pedido', '7 dias para desistir da compra após receber'];

// ---------- Finalizar ----------
const erros = ref({});
const processando = ref(false);
const erroGeral = ref(null);
// Depois de uma tentativa nesta tela, valem só os erros dela (nada de erro
// antigo guardado na sessão aparecendo de novo — BUG REAL 2026-10-10).
const tentouAqui = ref(false);
const erro = (campo) => erros.value[campo]?.[0] ?? erros.value[campo] ?? (tentouAqui.value ? null : serverErrors.value[campo]) ?? null;
const CAMPOS_ENDERECO = ['new_address.zip', 'new_address.street', 'new_address.number', 'new_address.neighborhood', 'new_address.city', 'new_address.state', 'new_address.recipient_name'];
const errosEndereco = computed(() => [...new Set(CAMPOS_ENDERECO.map((campo) => erro(campo)).filter(Boolean))]);

const xsrf = () => decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/) ?? [])[1] ?? '');

const payloadEntrega = () => {
    const data = { shipping_method_id: shippingMethodId.value, turbo: Boolean(turboEscolhido.value && turbo.value) };

    if (!usarNovoEndereco.value && addressId.value) {
        data.address_id = addressId.value;
    } else {
        data.new_address = { ...endereco, state: (endereco.state || '').toUpperCase().slice(0, 2), recipient_name: pessoa.name, phone: pessoa.phone, label: 'Entrega' };
    }

    if (ehPj.value) {
        data.empresa = { razao_social: empresa.razaoSocial.trim(), cnpj: empresa.cnpj };
    }

    if (!isLoggedIn.value) {
        data.guest = { name: pessoa.name, email: pessoa.email, cpf: pessoa.cpf, phone: pessoa.phone };
    } else if (pedirCpf.value) {
        data.cpf = pessoa.cpf;
    }

    return data;
};

const irParaErro = () => nextTick(() => {
    document.querySelector('[data-erro="1"]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
});

// Nome completo com mais de 10 caracteres (pedido 2026-10-10) — confere
// antes de enviar, sem esperar o servidor.
const NOME_MINIMO = 11;
const nomeCurto = () => !isLoggedIn.value && (pessoa.name ?? '').trim().length < NOME_MINIMO;
const MENSAGEM_NOME = 'Digite seu nome completo (nome e sobrenome, com mais de 10 caracteres).';
const conferirNome = () => {
    if (nomeCurto() && (pessoa.name ?? '').trim() !== '') {
        erros.value = { ...erros.value, 'guest.name': [MENSAGEM_NOME] };
    } else if (erros.value['guest.name']) {
        const { 'guest.name': _, ...resto } = erros.value;
        erros.value = resto;
    }
};

// CPF: confere os dígitos verificadores na hora (o servidor confere de novo).
const cpfValido = (valor) => {
    const cpf = String(valor ?? '').replace(/\D/g, '');
    if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false;
    for (let t = 9; t < 11; t += 1) {
        let soma = 0;
        for (let i = 0; i < t; i += 1) soma += Number(cpf[i]) * (t + 1 - i);
        if (Number(cpf[t]) !== ((10 * soma) % 11) % 10) return false;
    }
    return true;
};
// CPF: convidado sempre informa; logado só quando o do cadastro está vazio ou
// inválido (pedido 2026-10-11 — o Mercado Pago recusa Pix com CPF inválido).
// CNPJ: dígitos verificadores, como o CPF.
const cnpjValido = (valor) => {
    const cnpj = String(valor ?? '').replace(/\D/g, '');
    if (cnpj.length !== 14 || /^(\d)\1{13}$/.test(cnpj)) return false;
    const digito = (tamanho) => {
        let soma = 0;
        let peso = tamanho - 7;
        for (let i = 0; i < tamanho; i += 1) {
            soma += Number(cnpj[i]) * peso;
            peso = peso === 2 ? 9 : peso - 1;
        }
        const resto = soma % 11;
        return resto < 2 ? 0 : 11 - resto;
    };
    return digito(12) === Number(cnpj[12]) && digito(13) === Number(cnpj[13]);
};
const conferirCnpj = () => {
    if (!String(empresa.cnpj ?? '').trim()) return;
    if (!cnpjValido(empresa.cnpj)) {
        erros.value = { ...erros.value, 'empresa.cnpj': ['CNPJ inválido. Confira os números.'] };
    } else if (erros.value['empresa.cnpj']) {
        const { 'empresa.cnpj': _, ...resto } = erros.value;
        erros.value = resto;
    }
};
const pedirCpf = computed(() => !isLoggedIn.value || !cpfValido(props.customer?.cpf));
const campoCpf = computed(() => (isLoggedIn.value ? 'cpf' : 'guest.cpf'));

const conferirCpf = () => {
    if (!pedirCpf.value || !String(pessoa.cpf ?? '').trim()) return;
    if (!cpfValido(pessoa.cpf)) {
        erros.value = { ...erros.value, [campoCpf.value]: ['CPF inválido. Confira os números.'] };
    } else if (erros.value[campoCpf.value]) {
        const { [campoCpf.value]: _, ...resto } = erros.value;
        erros.value = resto;
    }
};

// ---------- Etapas (modelo Yampi, pedido 2026-10-23) ----------
// Identificação, Entrega e Pagamento, uma aberta por vez. A etapa feita fecha
// com o resumo do que foi preenchido e o "Editar". "Ir para Pagamento" já
// grava a entrega no servidor (mesma validação de sempre, e-mail/CPF em uso
// voltam para a Identificação); o Finalizar grava de novo e cria o pagamento.
const etapa = ref(1);
const salvandoEntrega = ref(false);
// Celular: obrigatório para visitante e para endereço novo (o servidor pede o
// mesmo); logado entregando num endereço salvo segue sem, como antes.
const telefoneValido = () => (isLoggedIn.value && !usarNovoEndereco.value) || String(pessoa.phone ?? '').replace(/\D/g, '').length >= 10;
const emailValido = () => isLoggedIn.value || /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(String(pessoa.email ?? '').trim());
const empresaValida = () => !ehPj.value || (empresa.razaoSocial.trim().length >= 3 && cnpjValido(empresa.cnpj));
const dadosCompletos = () => !nomeCurto() && emailValido() && (!pedirCpf.value || cpfValido(pessoa.cpf)) && telefoneValido() && empresaValida();
const CAMPOS_RUA = ['street', 'number', 'neighborhood', 'city', 'state'];
const enderecoCompleto = () => (!usarNovoEndereco.value && !!addressId.value)
    || (endereco.zip.replace(/\D/g, '').length === 8 && CAMPOS_RUA.every((campo) => String(endereco[campo] ?? '').trim()));
const ehErroDeDados = (chave) => chave.startsWith('guest.') || chave.startsWith('empresa.') || chave === 'cpf' || chave === 'new_address.phone' || chave === 'session';
const ehErroDeEntrega = (chave) => chave.startsWith('new_address.') || chave === 'address_id' || chave === 'shipping_method_id';
const etapaDosErros = (lista) => {
    const chaves = Object.keys(lista ?? {});
    if (chaves.some(ehErroDeDados)) return 1;
    if (chaves.some(ehErroDeEntrega)) return 2;
    return null;
};

// Abre na primeira etapa que falta: logado com tudo no cadastro cai direto
// no Pagamento (como desde 2026-10-18); visitante volta onde parou.
etapa.value = (() => {
    if (props.order) return 3;
    const comErro = etapaDosErros(serverErrors.value);
    if (comErro) return comErro;
    if (!dadosCompletos()) return 1;
    if (!enderecoCompleto()) return 2;
    return isLoggedIn.value || props.draft?.new_address ? 3 : 2;
})();

const irPara = (numero) => {
    etapa.value = numero;
    nextTick(() => {
        document.getElementById(`etapa-${numero}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        // Entrega abre com o cursor piscando no CEP (modelo Mavo, 2026-10-24).
        if (numero === 2 && !endereco.zip) cepInput.value?.focus({ preventScroll: true });
    });
};

const errosDosDados = () => {
    const lista = {};
    if (nomeCurto()) lista['guest.name'] = [(pessoa.name ?? '').trim() ? MENSAGEM_NOME : 'Digite seu nome completo.'];
    if (!emailValido()) lista['guest.email'] = [String(pessoa.email ?? '').trim() ? 'E-mail inválido. Confira.' : 'Digite seu e-mail.'];
    if (pedirCpf.value && !cpfValido(pessoa.cpf)) lista[campoCpf.value] = [String(pessoa.cpf ?? '').trim() ? 'CPF inválido. Confira os números.' : 'Digite seu CPF.'];
    if (!telefoneValido()) lista['new_address.phone'] = ['Informe seu celular com DDD.'];
    if (ehPj.value && empresa.razaoSocial.trim().length < 3) lista['empresa.razao_social'] = ['Digite a razão social da empresa.'];
    if (ehPj.value && !cnpjValido(empresa.cnpj)) lista['empresa.cnpj'] = [String(empresa.cnpj ?? '').trim() ? 'CNPJ inválido. Confira os números.' : 'Digite o CNPJ.'];
    return lista;
};

// Carrinho abandonado (pedido 2026-10-24): manda o contato para o servidor
// sem esperar a resposta. Falhou? Tanto faz para o cliente: nada aparece na
// tela e a compra segue. CSRF pelo X-XSRF-TOKEN, como o resto da página.
const gravarLead = () => {
    try {
        fetch('/finalizacao/lead', {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            body: JSON.stringify({ nome: pessoa.name, email: pessoa.email, cpf: pessoa.cpf, telefone: pessoa.phone }),
        }).catch(() => {});
    } catch {
        // nunca atrapalha o checkout
    }
};

const irParaEntrega = () => {
    tentouAqui.value = true;
    erroGeral.value = null;
    erros.value = errosDosDados();
    if (Object.keys(erros.value).length) {
        irParaErro();
        return;
    }
    gravarLead();
    irPara(2);
};

// Grava dados + entrega no servidor (JSON). Erro 422 reabre a etapa do campo.
const salvarEntrega = async () => {
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
            etapa.value = etapaDosErros(erros.value) ?? etapa.value;
            irParaErro();
            return false;
        }

        if (!resposta.ok) throw new Error();
        return true;
    } catch {
        erroGeral.value = 'Não foi possível salvar seus dados agora. Confira sua conexão e tente de novo.';
        return false;
    }
};

// A frase do aceite fica debaixo do "Ir para Pagamento" (modelo Mavo,
// 2026-10-24); quem caiu direto no pagamento sem passar por ele a vê no
// Finalizar pedido.
const aceiteMostrado = ref(false);
const irParaPagamento = async () => {
    if (freteCarregando.value || salvandoEntrega.value) return;
    tentouAqui.value = true;
    erroGeral.value = null;
    erros.value = errosDosDados();
    if (Object.keys(erros.value).length) {
        etapa.value = 1;
        irParaErro();
        return;
    }
    salvandoEntrega.value = true;
    const ok = await salvarEntrega();
    salvandoEntrega.value = false;
    if (ok) {
        aceiteMostrado.value = true;
        irPara(3);
    }
};

// Em duas linhas (pedido 2026-10-24): rua/número/complemento e bairro/cidade/CEP,
// quebrando quando a coluna fica estreita na coluna do computador.
const enderecoResumo = computed(() => {
    const a = usarNovoEndereco.value ? endereco : enderecoSelecionado.value;
    if (!a) return null;
    return {
        rua: `${a.street}, ${a.number}${a.complement ? ` - ${a.complement}` : ''}`,
        cidade: `${a.neighborhood} · ${a.city}/${a.state} · CEP ${a.zip}`,
    };
});

const finalizar = async () => {
    if (freteCarregando.value) return;
    medicao.finalizarClicado();
    erros.value = {};
    erroGeral.value = null;
    tentouAqui.value = true;

    const errosLocais = errosDosDados();
    if (Object.keys(errosLocais).length) {
        erros.value = errosLocais;
        erroGeral.value = 'Confira os campos destacados.';
        etapa.value = 1;
        irParaErro();
        return;
    }

    processando.value = true;

    if (!(await salvarEntrega())) {
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
            medicao.erro('cartao');
            processando.value = false;
            document.getElementById('cartao-brick-v2')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
    }

    router.post('/finalizacao/pagamento', pagamento, {
        preserveScroll: true,
        preserveState: true,
        onError: (e) => { erros.value = e; erroGeral.value = e.payment ?? e.cart ?? e['guest.email'] ?? e['guest.cpf'] ?? e.cpf ?? 'Não foi possível concluir. Confira os dados.'; },
        onFinish: () => { processando.value = false; },
    });
};

// ---------- Depois do pedido criado: QR do Pix / confirmação do cartão ----------
const estado = ref('idle');
const urlSucesso = ref(null);
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
                // Purchase do Pixel com o event_id do servidor (2026-10-18).
                rastrearPurchase(dados.meta_purchase);
                urlSucesso.value = `/pedidos/${orderId}`;
                estado.value = 'success';
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

// Boleto gerado (2026-10-14): sem polling — o pagamento chega em dias e quem
// confirma é a varredura do servidor. A tela só mostra a linha e o PDF.
watch(() => props.mercadoPagoBoleto, (boleto) => {
    if (!boleto || !props.order) return;
    pararTimers();
    urlSucesso.value = `/pedidos/${props.order.id}`;
    estado.value = 'boleto';
}, { immediate: true });

watch(() => props.mercadoPagoCardConfirmed, (confirmado) => {
    if (!confirmado || !props.order) return;
    pararTimers();
    estado.value = 'processing';
    acompanhar(props.order.id);
}, { immediate: true });

const tentarDeNovo = () => router.visit('/finalizacao');

// ---------- Medição ----------
// Só na tela de preencher: com o pedido já criado (QR do Pix, cartão) não mede.
const NOME_PAGAMENTO = { pix: 'pix', card: 'cartao', boleto: 'boleto' };
watch(metodo, (novo) => medicao.registrar('pagamento', { campo: NOME_PAGAMENTO[novo] ?? 'pix' }, `pagamento:${novo}`));
watch(erros, (lista) => Object.keys(lista ?? {}).forEach((chave) => medicao.erro(campoDoErro(chave))));
watch(serverErrors, (lista) => Object.keys(lista ?? {}).forEach((chave) => medicao.erro(campoDoErro(chave))));
watch(cepError, (mensagem) => { if (mensagem) medicao.erro('cep'); });
onMounted(() => { if (!props.order) medicao.iniciar(secaoDados.value); });
onUnmounted(() => medicao.parar());

// ---------- Pop-up do cupom de boas-vindas (modelo Yampi, 2026-10-23) ----------
// Desde 2026-10-24 oferece o BEMVINDO, que não entra mais sozinho: "Aplicar
// cupom" põe o código no campo e aplica; fechar não aplica nada. Só com cupom
// cadastrado e válido (o servidor manda null se não houver). Uma vez por
// navegador (cookie).
const COOKIE_CUPOM = 'kazakora_cupom_1a';
const popupCupom = ref(false);
const botaoAplicarPopup = ref(null);
const fecharPopupCupom = () => { popupCupom.value = false; };
const aplicarCupomDoPopup = async () => {
    popupCupom.value = false;
    cupom.value = props.cupomPrimeiraCompra?.code ?? '';
    await aplicarCupom();
};
const fecharComEsc = (evento) => { if (evento.key === 'Escape') fecharPopupCupom(); };
onMounted(() => {
    if (!props.cupomPrimeiraCompra || props.order || cupomAplicado.value) return;
    if (document.cookie.split('; ').some((item) => item.startsWith(`${COOKIE_CUPOM}=`))) return;
    document.cookie = `${COOKIE_CUPOM}=1; max-age=${60 * 60 * 24 * 365}; path=/; SameSite=Lax`;
    popupCupom.value = true;
    window.addEventListener('keydown', fecharComEsc);
    nextTick(() => botaoAplicarPopup.value?.focus());
});
onUnmounted(() => window.removeEventListener('keydown', fecharComEsc));

// ---------- Resumo ----------
// Aberto no celular (pedido 2026-10-24, modelo Mavo; desfaz o de 23/10): a
// barra "Resumo (n)" no topo recolhe e abre, e já nasce aberta com o cupom,
// os valores e os produtos. No computador o resumo fica sempre ao lado.
const resumoAberto = ref(true);
const fotoDoItem = (item) => {
    const imagens = item.product?.images ?? [];
    return (imagens.find((imagem) => imagem.is_primary) ?? imagens[0])?.thumb_url ?? (imagens.find((imagem) => imagem.is_primary) ?? imagens[0])?.url ?? null;
};
const totalItens = computed(() => itens.value.reduce((soma, item) => soma + item.quantity, 0));
// Contador do resumo (pedido do Lira 2026-10-24): o combo de livros digitais
// conta como um item a mais. O aviso de "itens na mesma caixa" segue só com os físicos.
const totalProdutosResumo = computed(() => totalItens.value + (props.livrosBrinde ? 1 : 0));
const precoUnitario = (item) => (item.quantity > 0 ? item.subtotal / item.quantity : item.subtotal);
// Selo do item e relógio do topo só com oferta de verdade: Oferta Relâmpago
// (fim vindo do servidor) ou Oferta do Dia (vale até 23:59:59 de hoje em SP).
const fimDaOfertaDoDia = () => `${new Date().toLocaleDateString('en-CA', { timeZone: 'America/Sao_Paulo' })}T23:59:59-03:00`;
const ofertaDoItem = (item) => {
    if (item.product?.oferta_relampago?.fim) return { nome: 'Oferta Relâmpago', fim: item.product.oferta_relampago.fim };
    if (item.product?.oferta_do_dia) return { nome: 'Oferta do Dia', fim: fimDaOfertaDoDia() };
    return null;
};
// A que acaba primeiro entre as do carrinho.
const ofertaFim = computed(() => itens.value
    .map((item) => ofertaDoItem(item)?.fim)
    .filter(Boolean)
    .sort((a, b) => new Date(a) - new Date(b))[0] ?? null);

const totalNoPix = computed(() => (props.pixDiscountPercentage > 0
    ? round2(totalCartao.value - descontoPixCalculado.value)
    : totalCartao.value));

// Campos em pílula com fundo cinza claro (modelo Yampi).
const inputClass = 'mt-1 w-full rounded-full border border-transparent bg-[#F1F2F4] px-5 py-3 text-[15px] text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-slate-300 focus:bg-white focus:ring-2 focus:ring-emerald-100';
const inputErroClass = 'border-red-500 bg-red-50 ring-1 ring-red-200';
const botaoVerde = 'flex w-full items-center justify-center gap-2 rounded-full bg-[#00843f] px-6 py-4 text-lg font-bold text-white shadow-[0_6px_16px_rgba(0,132,63,0.28)] transition hover:brightness-105 active:translate-y-px disabled:cursor-not-allowed disabled:opacity-60';
const caixaClass = 'scroll-mt-4 rounded-[24px] bg-white p-5 shadow-[0_4px_24px_rgba(15,23,42,0.07)] md:p-7 lg:p-5 xl:p-7';
// Celular (modelo Mavo, pedido 2026-10-24): só a etapa atual aparece; a feita
// fecha (volta pelo "Voltar") e a próxima não fica apagada na tela. No
// computador as três seguem à mostra, a próxima esmaecida.
const classeEtapa = (numero) => [caixaClass, etapa.value !== numero && 'hidden lg:block', etapa.value < numero && 'lg:opacity-60'];
</script>

<template>
    <Head title="Finalize sua compra" />

    <CheckoutLayout :oferta-fim="props.order ? null : ofertaFim" :sem-relogio="!!props.order">
        <!-- Celular: resumo recolhido numa barra no topo, abre ao tocar (modelo Yampi). -->
        <button type="button" class="flex w-full items-center justify-between border-b border-slate-200 bg-white px-4 py-3.5 text-left lg:hidden"
            :aria-expanded="resumoAberto" aria-controls="resumo-compra" @click="resumoAberto = !resumoAberto">
            <span class="text-sm font-bold text-slate-900">Resumo ({{ totalProdutosResumo }})</span>
            <span class="flex items-center gap-2 text-base font-bold text-slate-900">
                {{ formatPrice(totalAPagar) }}
                <i class="fa-solid fa-chevron-down text-xs transition" :class="{ 'rotate-180': resumoAberto }" aria-hidden="true"></i>
            </span>
        </button>

        <!-- Computador, a partir de 1024px (pedido 2026-10-24, mesmo padrão nas 3 lojas): duas
             colunas. Esquerda: Identificação e Entrega lado a lado (50/50) e o Pagamento embaixo,
             na largura toda. Direita: Resumo da compra, Itens do pedido, avaliações e selos
             empilhados. Os blocos de dentro do main viram "contents" para entrar na grade dele. -->
        <div class="mx-auto grid w-full max-w-[1320px] grid-cols-1 gap-5 px-3 py-5 md:px-6 lg:grid-cols-[minmax(0,1fr)_340px] lg:items-start lg:gap-6 lg:py-9 xl:grid-cols-[minmax(0,1fr)_400px]">
            <!-- ETAPAS -->
            <main class="order-2 min-w-0 lg:order-1 lg:grid lg:grid-cols-2 lg:items-start lg:gap-6 lg:[grid-template-areas:'erro_erro'_'ident_entrega'_'pag_pag']">
                <div v-if="erroGeral || serverErrors.payment || serverErrors.cart" role="alert" class="mb-4 flex items-start gap-3 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 lg:mb-0 lg:[grid-area:erro]">
                    <i class="fa-solid fa-circle-exclamation mt-0.5"></i>
                    <div>
                        {{ erroGeral || serverErrors.payment || serverErrors.cart }}
                        <Link v-if="erro('guest.email') || erro('guest.cpf')" href="/entrar" class="ml-1 font-semibold underline">Entrar na minha conta</Link>
                    </div>
                </div>

                <div class="space-y-4 lg:contents">
                    <!-- 1 de 3: Identificação (no computador, metade da linha de cima) -->
                    <section id="etapa-1" ref="secaoDados" class="lg:[grid-area:ident]" :class="classeEtapa(1)">
                        <header class="flex items-baseline justify-between gap-3">
                            <h2 class="text-xl font-extrabold text-slate-900">Identificação</h2>
                            <span class="text-xs text-slate-500 lg:hidden">1 de 3</span>
                        </header>

                        <template v-if="etapa === 1">
                            <!-- Pessoa física / jurídica (pedido 2026-10-24, modelo Mavo). -->
                            <div class="mt-4 grid grid-cols-2 gap-1 rounded-full bg-[#F1F2F4] p-1" role="radiogroup" aria-label="Tipo de comprador">
                                <button v-for="[valor, rotulo, icone] in [['pf', 'Pessoa física', 'fa-user'], ['pj', 'Pessoa jurídica', 'fa-building']]" :key="valor"
                                    type="button" role="radio" :aria-checked="tipoPessoa === valor"
                                    class="flex items-center justify-center gap-2 whitespace-nowrap rounded-full px-3 py-2.5 text-sm font-semibold transition lg:px-2 lg:text-xs xl:px-3 xl:text-sm"
                                    :class="tipoPessoa === valor ? 'bg-white text-slate-900 shadow-[0_1px_6px_rgba(15,23,42,0.12)]' : 'text-slate-500 hover:text-slate-800'"
                                    @click="tipoPessoa = valor">
                                    <i class="fa-solid text-xs lg:hidden xl:inline-block" :class="icone" aria-hidden="true"></i>{{ rotulo }}
                                </button>
                            </div>
                            <div class="mt-3 space-y-3">
                                <template v-if="ehPj">
                                    <label class="block" :data-erro="erro('empresa.razao_social') ? 1 : 0">
                                        <span class="sr-only">Razão social</span>
                                        <input v-model="empresa.razaoSocial" data-medir="razao_social" type="text" autocomplete="organization" :class="[inputClass, erro('empresa.razao_social') && inputErroClass]" placeholder="Razão social">
                                        <small v-if="erro('empresa.razao_social')" class="ml-4 text-xs text-red-600">{{ erro('empresa.razao_social') }}</small>
                                    </label>
                                    <label class="block" :data-erro="erro('empresa.cnpj') ? 1 : 0">
                                        <span class="sr-only">CNPJ</span>
                                        <input :value="empresa.cnpj" data-medir="cnpj" type="text" inputmode="numeric" :class="[inputClass, erro('empresa.cnpj') && inputErroClass]" placeholder="CNPJ" @input="empresa.cnpj = maskCnpj($event.target.value)" @blur="conferirCnpj">
                                        <small v-if="erro('empresa.cnpj')" class="ml-4 text-xs text-red-600">{{ erro('empresa.cnpj') }}</small>
                                    </label>
                                </template>
                                <label class="block" :data-erro="erro('guest.name') ? 1 : 0">
                                    <span class="sr-only">{{ ehPj ? 'Nome completo do responsável' : 'Nome completo' }}</span>
                                    <input v-model="pessoa.name" data-medir="nome" type="text" autocomplete="name" minlength="11" :readonly="isLoggedIn" @blur="conferirNome" :class="[inputClass, erro('guest.name') && inputErroClass, isLoggedIn && 'text-slate-600']" placeholder="Nome completo">
                                    <small v-if="erro('guest.name')" class="ml-4 text-xs text-red-600">{{ erro('guest.name') }}</small>
                                </label>
                                <!-- Logado (pedido 2026-10-18): sem campo de e-mail; o pedido usa o e-mail da conta. -->
                                <label v-if="!isLoggedIn" class="block" :data-erro="erro('guest.email') ? 1 : 0">
                                    <span class="sr-only">E-mail</span>
                                    <input v-model="pessoa.email" data-medir="email" type="email" autocomplete="email" :class="[inputClass, erro('guest.email') && inputErroClass]" placeholder="E-mail">
                                    <small v-if="erro('guest.email')" class="ml-4 text-xs text-red-600">{{ erro('guest.email') }}</small>
                                </label>
                                <!-- No computador o card é meia coluna: CPF e celular um por linha até 1536px. -->
                                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1 2xl:grid-cols-2">
                                    <label v-if="pedirCpf" class="block" :data-erro="erro(campoCpf) ? 1 : 0">
                                        <span class="sr-only">{{ ehPj ? 'CPF do responsável' : 'CPF' }}</span>
                                        <input :value="pessoa.cpf" data-medir="cpf" type="text" inputmode="numeric" :class="[inputClass, erro(campoCpf) && inputErroClass]" :placeholder="ehPj ? 'CPF do responsável' : 'CPF'" @input="pessoa.cpf = maskCpf($event.target.value)" @blur="conferirCpf">
                                        <small v-if="isLoggedIn && !erro(campoCpf)" class="ml-4 text-xs text-red-600">O CPF do seu cadastro está inválido.</small>
                                        <small v-if="erro(campoCpf)" class="ml-4 text-xs text-red-600">{{ erro(campoCpf) }}</small>
                                    </label>
                                    <label class="block" :data-erro="erro('new_address.phone') ? 1 : 0">
                                        <span class="sr-only">WhatsApp ou celular com DDD</span>
                                        <input :value="pessoa.phone" data-medir="celular" type="tel" inputmode="numeric" autocomplete="tel" :class="[inputClass, erro('new_address.phone') && inputErroClass]" placeholder="WhatsApp/Celular" @input="pessoa.phone = maskPhone($event.target.value)">
                                        <small v-if="erro('new_address.phone')" class="ml-4 text-xs text-red-600">{{ erro('new_address.phone') }}</small>
                                    </label>
                                </div>
                            </div>
                            <button type="button" :class="[botaoVerde, 'mt-5']" @click="irParaEntrega">Ir para Entrega</button>
                            <p class="mt-3 text-center text-xs text-slate-500">
                                Ao prosseguir com a compra, você concorda com as
                                <Link href="/politica-de-privacidade" target="_blank" class="font-bold text-slate-700 hover:underline">Políticas de Privacidade</Link>
                            </p>
                        </template>

                        <div v-else class="mt-3 flex items-start justify-between gap-3 rounded-2xl bg-[#F6F7F8] p-4 text-sm text-slate-600">
                            <div class="min-w-0 break-words">
                                <p class="font-semibold text-slate-900"><i class="fa-solid fa-circle-check mr-1 text-[#00843f]"></i>{{ pessoa.name }}</p>
                                <p v-if="ehPj">{{ empresa.razaoSocial }} · CNPJ {{ empresa.cnpj }}</p>
                                <p v-if="!isLoggedIn" class="break-all">{{ pessoa.email }}</p>
                                <!-- Um por linha no card estreito, sem quebrar o número no meio. -->
                                <p class="flex flex-wrap gap-x-2">
                                    <span v-if="pedirCpf && pessoa.cpf" class="whitespace-nowrap">{{ pessoa.cpf }}</span>
                                    <span v-if="pessoa.phone" class="whitespace-nowrap">{{ pessoa.phone }}</span>
                                </p>
                            </div>
                            <button v-if="!order" type="button" class="shrink-0 text-sm font-semibold text-sky-700 hover:underline" @click="irPara(1)">Editar</button>
                        </div>
                    </section>

                    <div class="space-y-4 lg:contents">
                    <!-- 2 de 3: Entrega -->
                    <section id="etapa-2" class="lg:[grid-area:entrega]" :class="classeEtapa(2)">
                        <header class="flex items-baseline justify-between gap-3">
                            <h2 class="flex items-center gap-2 text-xl font-extrabold" :class="etapa < 2 ? 'text-slate-500' : 'text-slate-900'">
                                Entrega
                                <i v-if="freteCarregando && etapa === 2" class="fa-solid fa-spinner animate-spin text-sm text-slate-400"></i>
                            </h2>
                            <span class="flex items-baseline gap-3 text-xs text-slate-500">
                                <button v-if="etapa === 2" type="button" class="text-sm font-semibold text-slate-900 hover:underline" @click="irPara(1)">Voltar</button>
                                <span class="lg:hidden">2 de 3</span>
                            </span>
                        </header>

                        <p v-if="etapa < 2" class="mt-2 text-sm text-slate-500">Preencha suas informações pessoais para continuar</p>

                        <template v-else-if="etapa === 2">
                            <div v-if="addresses.length && !usarNovoEndereco" class="mt-4 space-y-2">
                                <label v-for="address in addresses" :key="address.id"
                                    class="flex cursor-pointer items-start gap-3 rounded-2xl border p-3 transition"
                                    :class="addressId === address.id ? 'border-emerald-500 bg-emerald-50/60 ring-1 ring-emerald-500' : 'border-slate-200 hover:border-slate-300'">
                                    <input v-model="addressId" type="radio" :value="address.id" class="mt-1 h-4 w-4 accent-emerald-600">
                                    <span class="min-w-0 break-words text-sm text-slate-700">
                                        <strong class="block text-slate-900">{{ address.street }}, {{ address.number }}<template v-if="address.complement"> - {{ address.complement }}</template></strong>
                                        <span class="block">{{ address.neighborhood }} · {{ address.city }}/{{ address.state }} · CEP {{ address.zip }}</span>
                                    </span>
                                </label>
                                <button type="button" class="mt-1 text-sm font-medium text-sky-700 hover:underline" @click="usarNovoEndereco = true">
                                    <i class="fa-solid fa-plus mr-1"></i> Entregar em outro endereço
                                </button>
                            </div>

                            <div v-else class="mt-4 grid grid-cols-6 gap-3">
                                <label class="col-span-6 sm:col-span-3" :data-erro="erro('new_address.zip') ? 1 : 0">
                                    <span class="sr-only">CEP</span>
                                    <div class="relative">
                                        <input ref="cepInput" :value="endereco.zip" data-medir="cep" type="text" inputmode="numeric" autocomplete="postal-code" maxlength="9" :class="[inputClass, erro('new_address.zip') && inputErroClass]" placeholder="CEP" @input="onCep">
                                        <i v-if="cepLoading" class="fa-solid fa-spinner absolute right-4 top-1/2 mt-0.5 -translate-y-1/2 animate-spin text-slate-400"></i>
                                    </div>
                                    <small v-if="cepError || erro('new_address.zip')" class="ml-4 text-xs text-red-600">{{ cepError || erro('new_address.zip') }}</small>
                                </label>
                                <a href="https://buscacepinter.correios.com.br/app/endereco/index.php" target="_blank" rel="noopener" class="col-span-6 self-center text-xs font-medium text-sky-700 hover:underline sm:col-span-3">Não sei meu CEP</a>
                                <template v-if="cepConsultado || errosEndereco.length">
                                <label class="col-span-6" :data-erro="erro('new_address.street') ? 1 : 0">
                                    <span class="sr-only">Endereço</span>
                                    <input ref="ruaInput" v-model="endereco.street" data-medir="endereco" type="text" autocomplete="address-line1" :class="[inputClass, erro('new_address.street') && inputErroClass]" placeholder="Endereço (rua, avenida...)">
                                </label>
                                <label class="col-span-2" :data-erro="erro('new_address.number') ? 1 : 0">
                                    <span class="sr-only">Número</span>
                                    <input ref="numeroInput" v-model="endereco.number" data-medir="numero" type="text" :class="[inputClass, erro('new_address.number') && inputErroClass]" placeholder="Número">
                                </label>
                                <label class="col-span-4">
                                    <span class="sr-only">Complemento (opcional)</span>
                                    <input v-model="endereco.complement" data-medir="complemento" type="text" autocomplete="address-line2" :class="inputClass" placeholder="Complemento (opcional)">
                                </label>
                                <label class="col-span-6 sm:col-span-2 lg:col-span-6" :data-erro="erro('new_address.neighborhood') ? 1 : 0">
                                    <span class="sr-only">Bairro</span>
                                    <input v-model="endereco.neighborhood" data-medir="bairro" type="text" :class="[inputClass, erro('new_address.neighborhood') && inputErroClass]" placeholder="Bairro">
                                </label>
                                <label class="col-span-4 sm:col-span-3 lg:col-span-4" :data-erro="erro('new_address.city') ? 1 : 0">
                                    <span class="sr-only">Cidade</span>
                                    <input v-model="endereco.city" data-medir="cidade" type="text" autocomplete="address-level2" :class="[inputClass, erro('new_address.city') && inputErroClass]" placeholder="Cidade">
                                </label>
                                <label class="col-span-2 sm:col-span-1 lg:col-span-2" :data-erro="erro('new_address.state') ? 1 : 0">
                                    <span class="sr-only">UF</span>
                                    <input v-model="endereco.state" data-medir="uf" type="text" maxlength="2" autocomplete="address-level1" :class="[inputClass, 'uppercase', erro('new_address.state') && inputErroClass]" placeholder="UF">
                                </label>
                                </template>
                                <!-- Diz exatamente o que falta (antes era só "Preencha o endereço completo"). -->
                                <p v-if="errosEndereco.length" class="col-span-6 text-xs text-red-600">
                                    <i class="fa-solid fa-circle-exclamation mr-1"></i>{{ errosEndereco.join(' ') }}
                                </p>
                                <button v-if="addresses.length" type="button" class="col-span-6 text-left text-sm font-medium text-sky-700 hover:underline" @click="usarNovoEndereco = false">
                                    <i class="fa-solid fa-arrow-left mr-1"></i> Usar um endereço salvo
                                </button>
                            </div>

                            <!-- Opções de frete -->
                            <!-- Endereço novo: as opções aparecem depois da consulta do CEP (modelo Mavo, 2026-10-24). -->
                            <div v-if="!usarNovoEndereco || cepConsultado || erro('shipping_method_id')" ref="opcoesFreteEl" class="mt-5 scroll-mb-4">
                                <p class="mb-2 text-sm font-bold text-slate-900"><i class="fa-solid fa-house mr-1 text-slate-500"></i>Receba em casa</p>
                                <p v-if="entregaExpressa" class="mb-3 flex items-center gap-2 rounded-2xl bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-700">
                                    <span class="font-extrabold"><i class="fa-solid fa-bolt"></i> Full</span> {{ entregaExpressa.mensagem }}
                                </p>

                                <!-- Vários itens: recalcula com todos na mesma caixa antes de pagar (2026-10-12). -->
                                <div v-if="freteCarregando && totalItens > 1" role="status" class="mb-3 flex items-center gap-3 rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
                                    <i class="fa-solid fa-box-open fa-bounce text-lg text-sky-700"></i>
                                    <span>Seus <strong>{{ totalItens }} itens</strong> vão juntos na mesma caixa. Recalculando o frete para você pagar menos…</span>
                                </div>
                                <div v-else-if="descontoPlataforma > 0" role="status" class="mb-3 flex items-center gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                                    <i class="fa-solid fa-box text-lg text-emerald-700"></i>
                                    <span>Seus {{ totalItens }} itens vão na mesma caixa e o frete ficou mais barato: <strong>{{ formatPrice(descontoPlataforma) }} de desconto</strong> já aplicado no total.</span>
                                </div>

                                <!-- Frete grátis (já no preço) ou TURBO (2026-10-12): uma exclui a outra; a tela nasce no grátis. -->
                                <label class="flex cursor-pointer items-center gap-3 rounded-2xl border p-3.5 transition"
                                    :class="!turboEscolhido || !turbo ? 'border-emerald-500 bg-emerald-50/60 ring-1 ring-emerald-500' : 'border-slate-200 bg-white hover:border-slate-300'">
                                    <input type="checkbox" class="h-4 w-4 accent-emerald-600" :checked="!turboEscolhido || !turbo" @change="$event.target.checked = true; turboEscolhido = false">
                                    <span class="flex-1 text-sm text-slate-700">
                                        <strong class="text-slate-900"><i class="fa-solid fa-truck-fast text-emerald-700"></i> Frete Grátis</strong> <span v-if="entregaExpressa" class="selo-full"><i class="fa-solid fa-bolt"></i>FULL</span>
                                        <span v-if="!entregaExpressa" class="ml-1 text-slate-500">· PAC ({{ prazoGratis ? `até ${diasUteis(prazoGratis)}` : '1 à 7 dias úteis' }})</span>
                                    </span>
                                    <span class="text-sm font-bold text-emerald-700">Grátis</span>
                                </label>

                                <!-- Envio Prioritário (o TURBO de 2026-10-23, nome da Mavo desde 2026-10-24): SEDEX pagando só a diferença, também na área FULL. Escolhido = laranja do tema. -->
                                <label v-if="turbo"
                                    class="mt-2 flex cursor-pointer items-center gap-3 rounded-2xl border p-3.5 transition"
                                    :class="turboEscolhido ? 'border-[#F44D00] bg-orange-50 ring-1 ring-[#F44D00]' : 'border-slate-200 bg-white hover:border-slate-300'">
                                    <input type="checkbox" class="h-4 w-4 accent-[#F44D00]" :checked="turboEscolhido" @change="turboEscolhido = $event.target.checked">
                                    <span class="flex-1 text-sm text-slate-700">
                                        <strong :class="turboEscolhido ? 'text-[#c2450c]' : 'text-slate-900'"><i class="fa-solid fa-bolt text-[#F44D00]"></i> Envio Prioritário</strong>
                                        <span class="ml-1 text-slate-500">· SEDEX 
                                            <template v-if="turbo.prazo">chega em até {{ diasUteis(turbo.prazo) }}<template v-if="turbo.dias_a_menos"> ({{ diasUteis(turbo.dias_a_menos) }} antes)</template></template>
                                            <template v-else>entrega mais rápida pelo SEDEX</template>
                                        </span>
                                    </span>
                                    <span class="text-sm font-bold" :class="turboEscolhido ? 'text-[#c2450c]' : 'text-slate-900'">+ {{ formatPrice(turbo.preco) }}</span>
                                </label>
                                <small v-if="erro('shipping_method_id')" class="mt-1 block text-xs text-red-600">{{ erro('shipping_method_id') }}</small>
                            </div>

                            <button type="button" :disabled="salvandoEntrega || freteCarregando" :class="[botaoVerde, 'mt-5']" @click="irParaPagamento">
                                <i v-if="salvandoEntrega" class="fa-solid fa-spinner animate-spin"></i>
                                {{ salvandoEntrega ? 'Salvando...' : 'Ir para Pagamento' }}
                            </button>
                            <!-- Aceite dos termos e dos cookies (2026-10-24): no lugar do aviso flutuante; o servidor grava a prova ao finalizar. -->
                            <p class="mt-3 text-center text-[11px] leading-snug text-slate-500">
                                Ao prosseguir com a compra, você declara que leu e aceita as <Link href="/politica-de-privacidade" target="_blank" class="font-bold no-underline hover:text-slate-800">políticas de privacidade</Link> (incluindo a captação de dados por <Link href="/politica-de-cookies" target="_blank" class="font-bold no-underline hover:text-slate-800">cookies</Link>) e os <Link href="/termos-de-uso" target="_blank" class="font-bold no-underline hover:text-slate-800">termos de uso</Link>.
                            </p>
                        </template>

                        <div v-else class="mt-3 flex items-start justify-between gap-3 rounded-2xl bg-[#F6F7F8] p-4 text-sm text-slate-600">
                            <div class="flex min-w-0 items-start gap-1.5 break-words">
                                <i class="fa-solid fa-circle-check mt-1 text-[#00843f]"></i>
                                <div class="min-w-0">
                                    <p v-if="enderecoResumo" class="font-semibold text-slate-900">{{ enderecoResumo.rua }}</p>
                                    <p v-if="enderecoResumo">{{ enderecoResumo.cidade }}</p>
                                    <p class="mt-1 font-semibold text-slate-700">
                                        <template v-if="turboEscolhido && turbo">Envio Prioritário: + {{ formatPrice(turbo.preco) }}</template>
                                        <template v-else>Frete GRÁTIS<template v-if="entregaExpressa"> · FULL</template><template v-else-if="prazoGratis"> · até {{ diasUteis(prazoGratis) }}</template></template>
                                    </p>
                                </div>
                            </div>
                            <button v-if="!order" type="button" class="shrink-0 text-sm font-semibold text-sky-700 hover:underline" @click="irPara(2)">Editar</button>
                        </div>
                    </section>

                    <!-- 3 de 3: Pagamento. O conteúdo fica montado (v-show) para o campo seguro do cartão não se perder ao editar outra etapa. -->
                    <section id="etapa-3" class="lg:[grid-area:pag]" :class="classeEtapa(3)">
                        <header class="flex items-baseline justify-between gap-3">
                            <h2 class="text-xl font-extrabold" :class="etapa < 3 ? 'text-slate-500' : 'text-slate-900'">Pagamento</h2>
                            <span class="flex items-baseline gap-3 text-xs text-slate-500">
                                <button v-if="etapa === 3 && !order" type="button" class="text-sm font-semibold text-slate-900 hover:underline" @click="irPara(2)">Voltar</button>
                                <span class="lg:hidden">3 de 3</span>
                            </span>
                        </header>
                        <p v-if="etapa < 3" class="mt-2 text-sm text-slate-500">Preencha suas informações de entrega para continuar</p>

                        <div v-show="etapa === 3" id="pagamento-v2">
                            <p class="mt-1 text-sm text-slate-500">Escolha a melhor forma de pagamento para você</p>

                            <div class="mt-4 space-y-2.5">
                                <!-- Ordem e nomes iguais nos 3 sites (pedido do Lira 2026-10-14): Pix, Cartão de Crédito, Boleto Bancário. -->
                                <button type="button" class="relative flex w-full items-center gap-3 rounded-2xl border-2 px-4 py-3.5 text-left transition"
                                    :class="metodo === 'pix' ? 'border-emerald-500 bg-emerald-50' : 'border-slate-200 bg-white hover:border-slate-300'"
                                    :aria-pressed="metodo === 'pix'" @click="metodo = 'pix'">
                                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2" :class="metodo === 'pix' ? 'border-emerald-600' : 'border-slate-300'"><span v-if="metodo === 'pix'" class="h-2.5 w-2.5 rounded-full bg-emerald-600"></span></span>
                                    <i class="fa-brands fa-pix text-xl text-[#32BCAD]"></i>
                                    <span class="flex-1">
                                        <span class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-bold text-slate-900">Pix <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-800"><i class="fa-solid fa-bolt text-[10px]"></i>Aprovação Imediata</span></span>
                                        <span v-if="pixDiscountPercentage > 0" class="block text-sm font-extrabold text-[#00843f]">{{ formatPrice(totalNoPix) }} no Pix</span>
                                    </span>
                                    <span v-if="pixDiscountPercentage > 0" class="rounded-full bg-[#00843f] px-2.5 py-1 text-xs font-bold text-white">-{{ pixPercentualTexto }}%</span>
                                </button>
                                <button type="button" class="flex w-full items-center gap-3 rounded-2xl border-2 px-4 py-3.5 text-left transition"
                                    :class="metodo === 'card' ? 'border-emerald-500 bg-emerald-50' : 'border-slate-200 bg-white hover:border-slate-300'"
                                    :aria-pressed="metodo === 'card'" @click="metodo = 'card'">
                                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2" :class="metodo === 'card' ? 'border-emerald-600' : 'border-slate-300'"><span v-if="metodo === 'card'" class="h-2.5 w-2.5 rounded-full bg-emerald-600"></span></span>
                                    <i class="fa-solid fa-credit-card text-xl text-slate-700"></i>
                                    <span class="flex-1">
                                        <span class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-bold text-slate-900">Cartão de Crédito <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-800"><i class="fa-solid fa-bolt text-[10px]"></i>Aprovação Imediata</span></span>
                                        <span class="block text-xs text-slate-500">Até 12x de {{ formatPrice(parcela12) }}</span>
                                    </span>
                                </button>
                                <button type="button" class="flex w-full items-center gap-3 rounded-2xl border-2 px-4 py-3.5 text-left transition"
                                    :class="metodo === 'boleto' ? 'border-emerald-500 bg-emerald-50' : 'border-slate-200 bg-white hover:border-slate-300'"
                                    :aria-pressed="metodo === 'boleto'" @click="metodo = 'boleto'">
                                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2" :class="metodo === 'boleto' ? 'border-emerald-600' : 'border-slate-300'"><span v-if="metodo === 'boleto'" class="h-2.5 w-2.5 rounded-full bg-emerald-600"></span></span>
                                    <i class="fa-solid fa-barcode text-xl text-slate-700"></i>
                                    <span class="flex-1">
                                        <span class="block text-sm font-bold text-slate-900">Boleto Bancário</span>
                                        <span class="block text-xs text-slate-500">Vence em {{ boletoDiasVencimento }} dias</span>
                                    </span>
                                </button>
                            </div>

                            <div v-if="metodo === 'pix'" class="mt-4 rounded-2xl bg-emerald-50 p-4 text-sm text-emerald-900">
                                <p v-if="descontoPix > 0" class="flex items-center gap-2 font-bold text-emerald-700">
                                    <i class="fa-solid fa-circle-check"></i> Desconto de {{ pixPercentualTexto }}% ativado: você economiza {{ formatPrice(descontoPix) }}
                                </p>
                                <ul class="mt-2 space-y-1 text-emerald-800">
                                    <li><i class="fa-solid fa-qrcode mr-2 w-4"></i>O QR Code aparece assim que você finalizar.</li>
                                    <li><i class="fa-solid fa-mobile-screen mr-2 w-4"></i>Pague pelo app do seu banco, copiando o código ou lendo o QR.</li>
                                    <li><i class="fa-solid fa-bolt mr-2 w-4"></i>Aprovação em segundos e envio mais rápido.</li>
                                </ul>
                            </div>

                            <div v-else-if="metodo === 'boleto'" class="mt-4 rounded-2xl bg-slate-50 p-4 text-sm text-slate-700">
                                <ul class="space-y-1">
                                    <li><i class="fa-solid fa-barcode mr-2 w-4"></i>O boleto aparece assim que você finalizar e também vai para o seu e-mail.</li>
                                    <li><i class="fa-solid fa-calendar-day mr-2 w-4"></i>Vence em {{ boletoDiasVencimento }} dias. Pague pelo app do banco ou na lotérica.</li>
                                    <li><i class="fa-solid fa-clock mr-2 w-4"></i>O banco leva até 3 dias úteis para confirmar; o envio começa depois disso.</li>
                                </ul>
                                <p v-if="pixDiscountPercentage > 0" class="mt-2 text-xs text-slate-500">O desconto de {{ pixPercentualTexto }}% vale só no Pix.</p>
                            </div>

                            <div v-else class="mt-4">
                                <!-- Prévia do cartão (modelo Yampi): bandeira e 6 primeiros dígitos, que é o que o Mercado Pago libera. -->
                                <div class="relative mx-auto mb-4 aspect-[1.586] w-full max-w-[300px] overflow-hidden rounded-2xl bg-gradient-to-br from-[#1f2937] via-[#111827] to-[#00843f] p-5 text-white shadow-lg" aria-hidden="true">
                                    <div class="flex items-start justify-between">
                                        <span class="block h-7 w-10 rounded-md bg-gradient-to-br from-amber-200 to-amber-400"></span>
                                        <span class="text-sm font-extrabold uppercase tracking-wide">{{ cartaoBandeira ?? '' }}</span>
                                    </div>
                                    <p class="mt-6 font-mono text-lg tracking-[0.14em] tabular-nums">{{ cartaoNumeroPrevia }}</p>
                                    <div class="mt-4 flex items-end justify-between text-[10px] uppercase text-white/70">
                                        <span>Nome do titular</span>
                                        <span>Validade</span>
                                    </div>
                                </div>
                                <div id="cartao-brick-v2"></div>
                                <p v-if="!brickPronto && !brickErro" class="py-6 text-center text-sm text-slate-500"><i class="fa-solid fa-spinner mr-2 animate-spin"></i>Carregando ambiente seguro do cartão...</p>
                                <p v-if="brickErro" role="alert" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm font-medium text-red-600">{{ brickErro }}</p>
                            </div>

                            <button id="finalizar-pedido" type="button" :disabled="processando || freteCarregando || !!order || (metodo === 'card' && !brickPronto)"
                                :class="[botaoVerde, 'mt-5 text-xl']" @click="finalizar">
                                <i class="fa-solid" :class="processando ? 'fa-spinner animate-spin' : 'fa-lock'"></i>
                                {{ processando ? 'Processando...' : 'Finalizar pedido' }}
                            </button>
                            <p v-if="!aceiteMostrado" class="mt-3 text-center text-[11px] leading-snug text-slate-500">
                                Ao prosseguir com a compra, você declara que leu e aceita as <Link href="/politica-de-privacidade" target="_blank" class="font-bold no-underline hover:text-slate-800">políticas de privacidade</Link> (incluindo a captação de dados por <Link href="/politica-de-cookies" target="_blank" class="font-bold no-underline hover:text-slate-800">cookies</Link>) e os <Link href="/termos-de-uso" target="_blank" class="font-bold no-underline hover:text-slate-800">termos de uso</Link>.
                            </p>
                        </div>
                    </section>

                    <!-- Celular: avaliações debaixo das etapas, para não empurrar o formulário para baixo do resumo (2026-10-24). -->
                    <CarrosselAvaliacoes :avaliacoes="avaliacoesCarrossel" class="lg:hidden" />

                    <!-- Celular: selos de confiança debaixo das etapas (pedido 2026-10-21). -->
                    <ul class="space-y-3 lg:hidden">
                        <li v-for="selo in SELOS" :key="selo.titulo" class="flex items-start gap-3 rounded-2xl bg-white p-4 shadow-[0_4px_24px_rgba(15,23,42,0.05)]">
                            <img :src="selo.imagem" :alt="selo.titulo" width="56" height="56" loading="lazy" decoding="async" class="h-14 w-14 shrink-0">
                            <div class="text-sm text-slate-600">
                                <strong class="block text-slate-900">{{ selo.titulo }}</strong>
                                {{ selo.texto }}
                            </div>
                        </li>
                    </ul>
                    </div>
                </div>
            </main>

            <!-- RESUMO DA COMPRA (no computador, coluna da direita: valores e itens em dois cartões) -->
            <aside id="resumo-compra" class="order-1 lg:order-2 lg:flex lg:flex-col lg:gap-6" :class="{ 'hidden lg:flex': !resumoAberto }">
                <div class="rounded-[24px] bg-white p-5 shadow-[0_4px_24px_rgba(15,23,42,0.07)] md:p-6 lg:contents">
                    <!-- Computador: cupom, Pix e totais num cartão no topo da coluna da direita. -->
                    <div class="lg:rounded-[24px] lg:bg-white lg:p-6 lg:shadow-[0_4px_24px_rgba(15,23,42,0.07)]">
                        <h2 class="border-b border-slate-100 pb-3 text-base font-extrabold text-slate-900">Resumo da compra</h2>

                        <!-- Cupom sempre à mostra (modelo Yampi). Desde 2026-10-24 o cupom aplicado
                             (o BEMVINDO vem do pop-up de boas-vindas) fica dentro do campo: borda verde e X para tirar. -->
                        <div class="mt-4">
                            <form class="flex gap-2" @submit.prevent="aplicarCupom">
                                <label class="relative min-w-0 flex-1">
                                    <span class="sr-only">Cupom de desconto</span>
                                    <input :value="cupomAplicado || cupom" data-medir="cupom" type="text" placeholder="Cupom (opcional)" autocapitalize="characters"
                                        :readonly="!!cupomAplicado" :disabled="cupomCarregando && !cupomAplicado"
                                        ref="campoCupom"
                                        class="w-full rounded-full border px-5 py-2.5 text-sm uppercase outline-none placeholder:normal-case"
                                        :class="cupomAplicado ? 'border-2 border-[#00843f] bg-white pr-11 font-semibold text-slate-900' : [cupomErro ? 'border-red-400' : 'border-transparent', 'bg-[#F1F2F4] focus:border-slate-300 focus:bg-white']"
                                        @input="cupom = $event.target.value; cupomErro = null">
                                    <button v-if="cupomAplicado" type="button" data-limpar-cupom :disabled="cupomCarregando || !!order" aria-label="Tirar o cupom"
                                        class="absolute right-2 top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-full text-slate-500 hover:bg-slate-100 hover:text-red-600 disabled:opacity-50"
                                        @click="limparCupom">
                                        <i class="fa-solid" :class="cupomCarregando ? 'fa-spinner animate-spin' : 'fa-xmark'"></i>
                                    </button>
                                </label>
                                <button type="submit" :disabled="!!cupomAplicado || cupomCarregando"
                                    class="inline-flex min-w-[100px] items-center justify-center gap-1.5 rounded-full bg-[#00843f] px-4 py-2.5 text-sm font-bold text-white hover:brightness-105 disabled:opacity-50">
                                    <i v-if="cupomCarregando && !cupomAplicado" class="fa-solid fa-spinner animate-spin"></i>
                                    {{ cupomCarregando && !cupomAplicado ? 'Buscando' : 'Aplicar' }}
                                </button>
                            </form>
                            <p v-if="cupomErro" class="mt-1 text-xs text-red-600"><i class="fa-solid fa-circle-exclamation mr-1"></i>{{ cupomErro }}</p>
                        </div>

                        <dl class="mt-4 space-y-2 text-sm text-slate-600">
                            <div class="flex justify-between"><dt>Produtos ({{ totalProdutosResumo }})</dt><dd>{{ formatPrice(subtotalAtual) }}</dd></div>
                            <div v-if="descontoCupom > 0" class="flex justify-between text-[#00843f]"><dt>Cupom {{ cupomAplicado }}</dt><dd>-{{ formatPrice(descontoCupom) }}</dd></div>
                            <div v-if="descontoPlataforma > 0 && !order" class="flex justify-between text-[#00843f]"><dt>Desconto da plataforma</dt><dd>-{{ formatPrice(descontoPlataforma) }}</dd></div>
                            <div v-if="descontoPix > 0 && !order" class="flex justify-between text-[#00843f]"><dt>Desconto Pix ({{ pixPercentualTexto }}%)</dt><dd>-{{ formatPrice(descontoPix) }}</dd></div>
                            <div class="flex justify-between"><dt>Entrega<span v-if="turboEscolhido && turbo" class="ml-1 text-xs font-semibold text-[#c2450c]"><i class="fa-solid fa-bolt"></i> Prioritário</span></dt><dd :class="valorFrete === 0 ? 'font-semibold text-[#00843f]' : ''">{{ entregaEscolhida ? (valorFrete === 0 ? 'Grátis' : formatPrice(valorFrete)) : '-' }}<!-- Sempre Grátis; FULL só quando o CEP está na área da entrega expressa. -->
                                <span v-if="entregaEscolhida && valorFrete === 0 && entregaExpressa" class="selo-full ml-1"><i class="fa-solid fa-bolt"></i>FULL</span></dd></div>
                            <div class="flex items-start justify-between border-t border-slate-100 pt-3 text-slate-900">
                                <dt class="text-base font-extrabold">Total</dt>
                                <dd class="text-right">
                                    <span class="block text-xl font-extrabold">{{ formatPrice(totalAPagar) }}</span>
                                    <span v-if="!order" class="block text-xs font-semibold text-slate-600">em até 12x no cartão</span>
                                </dd>
                            </div>
                        </dl>

                        <!-- Computador: o preço no Pix à mostra enquanto outra forma está escolhida. -->
                        <button v-if="pixDiscountPercentage > 0 && metodo !== 'pix' && !order" type="button"
                            class="mt-4 hidden w-full items-center justify-between gap-3 rounded-2xl bg-emerald-50 px-4 py-3 text-left text-sm text-emerald-900 hover:bg-emerald-100 lg:flex"
                            @click="metodo = 'pix'">
                            <span><i class="fa-brands fa-pix mr-1.5 text-[#32BCAD]"></i>No Pix: <strong>{{ formatPrice(totalNoPix) }}</strong></span>
                            <span class="rounded-full bg-[#00843f] px-2.5 py-1 text-xs font-bold text-white">-{{ pixPercentualTexto }}%</span>
                        </button>
                    </div>

                    <div class="lg:rounded-[24px] lg:bg-white lg:p-6 lg:shadow-[0_4px_24px_rgba(15,23,42,0.07)]">
                        <h2 class="hidden text-base font-extrabold text-slate-900 lg:block">Itens do pedido</h2>
                        <ul class="mt-3 divide-y divide-slate-100 border-t border-slate-100">
                            <li v-for="item in itens" :key="item.product.id" class="flex gap-3 py-4">
                                <img v-if="fotoDoItem(item)" :src="fotoDoItem(item)" :alt="item.product.name" width="64" height="64" class="h-16 w-16 shrink-0 rounded-xl border border-slate-200 object-cover">
                                <span v-else class="flex h-16 w-16 shrink-0 items-center justify-center rounded-xl bg-slate-100"><i class="fa-solid fa-box text-slate-400"></i></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm leading-snug text-slate-700">{{ item.product.name }}</p>
                                    <!-- Unidade, preço e selo da oferta no modelo Mavo (pedido 2026-10-24). -->
                                    <p class="mt-1 text-xs text-slate-500">Unid.: {{ item.quantity }}x<template v-if="item.product.variation"> {{ item.product.variation }}</template></p>
                                    <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-bold text-slate-900">
                                        {{ formatPrice(precoUnitario(item)) }}
                                        <span v-if="ofertaDoItem(item)" class="rounded-md bg-[#141414] px-2 py-0.5 text-[10px] font-extrabold uppercase tracking-wide text-white">{{ ofertaDoItem(item).nome }}</span>
                                    </p>
                                    <p v-if="item.quantity > 1" class="text-xs text-slate-500">Total: {{ formatPrice(item.subtotal) }}</p>
                                    <div class="mt-2 inline-flex items-center rounded-full border border-slate-200" role="group" :aria-label="`Quantidade de ${item.product.name}`">
                                        <button type="button" class="flex h-8 w-9 items-center justify-center text-slate-600 hover:text-slate-900 disabled:opacity-40"
                                            :disabled="item.quantity <= 1 || !!alterandoItem || !!order" aria-label="Diminuir quantidade" @click="alterarQuantidade(item, -1)">
                                            <i class="fa-solid fa-minus text-xs"></i>
                                        </button>
                                        <span class="min-w-8 text-center text-sm font-semibold tabular-nums" aria-live="polite">
                                            <i v-if="alterandoItem === item.product.id" class="fa-solid fa-spinner animate-spin text-xs text-slate-400"></i>
                                            <template v-else>{{ item.quantity }}</template>
                                        </span>
                                        <button type="button" class="flex h-8 w-9 items-center justify-center text-slate-600 hover:text-slate-900 disabled:opacity-40"
                                            :disabled="item.quantity >= Number(item.product.stock ?? Infinity) || !!alterandoItem || !!order" aria-label="Aumentar quantidade" @click="alterarQuantidade(item, 1)">
                                            <i class="fa-solid fa-plus text-xs"></i>
                                        </button>
                                    </div>
                                </div>
                            </li>
                            <!-- Livros de brinde como uma linha de produto, com GRÁTIS no lugar do valor (pedido do Lira 2026-10-21). -->
                            <li v-if="livrosBrinde" class="flex items-center gap-3 py-4">
                                <span class="relative shrink-0">
                                    <img :src="livrosBrinde.imagem" :alt="livrosBrindeAlt" width="64" height="64" loading="lazy" decoding="async" class="h-16 w-16 rounded-xl border border-slate-200 bg-white object-cover">
                                    <span class="absolute -right-2 -top-2 flex h-5 min-w-5 items-center justify-center rounded-full bg-slate-700 px-1 text-[11px] font-bold text-white">{{ livrosBrinde.quantidade }}</span>
                                </span>
                                <span class="min-w-0 flex-1 text-sm text-slate-600">{{ livrosBrindeNome }}</span>
                                <!-- Valor de referência riscado antes do GRÁTIS (pedido do Lira 2026-10-22); GRÁTIS no tamanho do Total, verde mantido (2026-10-24). -->
                                <span class="flex shrink-0 items-baseline gap-2">
                                    <s class="whitespace-nowrap text-sm text-slate-500"><span class="sr-only">De </span>R$ 97,50</s>
                                    <span class="text-xl font-extrabold text-[#00843f]">GRÁTIS</span>
                                </span>
                            </li>
                        </ul>
                        <!-- Estrelas da média ao lado do "Alterar itens" (pedido 2026-10-21). -->
                        <div class="flex items-center justify-between gap-2">
                            <Link href="/carrinho" class="inline-block shrink-0 text-xs font-medium text-sky-700 hover:underline">Alterar itens do carrinho</Link>
                            <span v-if="avaliacaoItens" class="ml-auto inline-flex shrink-0 items-center gap-1 whitespace-nowrap text-xs text-slate-600">
                                <span class="inline-flex items-center gap-0.5" role="img" :aria-label="`Nota ${notaItens.toFixed(1).replace('.', ',')} de 5`">
                                    <span v-for="estrela in 5" :key="estrela" class="relative inline-block" aria-hidden="true">
                                        <i class="fa-regular fa-star text-slate-400"></i>
                                        <span class="absolute left-0 top-0 h-full overflow-hidden" :style="{ width: `${preenchimentoEstrela(estrela)}%` }">
                                            <i class="fa-solid fa-star text-amber-500"></i>
                                        </span>
                                    </span>
                                </span>
                                <strong class="text-slate-800">{{ notaItens.toFixed(1).replace('.', ',') }}</strong>
                                <span>({{ avaliacaoItens.total.toLocaleString('pt-BR') }}<span class="max-sm:sr-only">{{ avaliacaoItens.total === 1 ? ' avaliação' : ' avaliações' }}</span>)</span>
                            </span>
                        </div>

                        <!-- Selos Loja verificada e Compra segura + 3 checks verdes (padrão KazaKora, #974). -->
                        <div class="mt-4 border-t border-slate-100 pt-4">
                            <ul class="flex flex-wrap gap-x-3.5 gap-y-1.5 text-[13px] font-semibold text-slate-800" aria-label="Selos de verificação">
                                <li v-for="[rotulo, dica] in SELOS_VERIFICADOS" :key="rotulo" :title="dica" class="inline-flex items-center gap-1.5">
                                    <svg class="h-[18px] w-[18px] fill-[#1d9bf0] stroke-white" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M12 2l2.4 1.8 3-.1.9 2.9 2.4 1.8-1 2.8 1 2.8-2.4 1.8-.9 2.9-3-.1L12 22l-2.4-1.8-3 .1-.9-2.9-2.4-1.8 1-2.8-1-2.8 2.4-1.8.9-2.9 3 .1zM8.5 12l2.5 2.5 4.5-5" />
                                    </svg>
                                    {{ rotulo }}
                                </li>
                            </ul>
                            <ul class="mt-3 space-y-1 text-sm text-slate-700">
                                <li v-for="item in CHECKS_LOJA" :key="item"><i class="fas fa-check mr-1.5 text-[1.1em] text-[#00a650] [-webkit-text-stroke:0.8px_#00a650]" aria-hidden="true"></i>{{ item }}</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Carrossel de avaliações reais (modelo Yampi). No celular fica debaixo das etapas (2026-10-24). -->
                <CarrosselAvaliacoes :avaliacoes="avaliacoesCarrossel" class="mt-4 hidden lg:block lg:mt-0" />

                <!-- Selos de confiança com imagem (pedido 2026-10-10). No celular ficam debaixo das etapas (2026-10-21). -->
                <ul class="mt-4 hidden space-y-3 lg:block lg:mt-0">
                    <li v-for="selo in SELOS" :key="selo.titulo" class="flex items-start gap-3 rounded-2xl bg-white p-4 shadow-[0_4px_24px_rgba(15,23,42,0.05)]">
                        <img :src="selo.imagem" :alt="selo.titulo" width="56" height="56" loading="lazy" decoding="async" class="h-14 w-14 shrink-0">
                        <div class="text-sm text-slate-600">
                            <strong class="block text-slate-900">{{ selo.titulo }}</strong>
                            {{ selo.texto }}
                        </div>
                    </li>
                </ul>
            </aside>
        </div>

        <!-- Pop-up do cupom de boas-vindas (BEMVINDO): só com cupom cadastrado e válido, uma vez por navegador. -->
        <div v-if="popupCupom && cupomPrimeiraCompra" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="fecharPopupCupom">
            <div role="dialog" aria-modal="true" aria-labelledby="popup-cupom-titulo" class="relative w-full max-w-[440px] rounded-2xl bg-white px-6 pb-8 pt-10 text-center shadow-2xl">
                <button type="button" class="absolute right-4 top-4 flex h-9 w-9 items-center justify-center rounded-full text-slate-700 hover:bg-slate-100" aria-label="Fechar" @click="fecharPopupCupom">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
                <i class="fa-solid fa-ticket text-5xl text-slate-800" aria-hidden="true"></i>
                <h2 id="popup-cupom-titulo" class="mt-5 text-2xl font-extrabold leading-tight text-slate-700">Boas-vindas! Ganhe {{ cupomPrimeiraCompra.descricao }}</h2>
                <p class="mt-3 text-sm text-slate-600">Use o cupom</p>
                <p class="mx-auto mt-1 inline-block rounded-xl border-2 border-dashed border-[#00843f] bg-emerald-50 px-5 py-2 text-xl font-extrabold tracking-widest text-[#00843f]" data-cupom-popup>{{ cupomPrimeiraCompra.code }}</p>
                <p v-if="pixDiscountPercentage > 0" class="mt-3 text-sm text-slate-600">Pagando no Pix, você ainda ganha mais {{ pixPercentualTexto }}% de desconto.</p>
                <button ref="botaoAplicarPopup" type="button" class="mx-auto mt-6 block w-full max-w-[240px] rounded-full bg-[#00843f] px-6 py-3.5 text-lg font-bold text-white shadow-[0_6px_16px_rgba(0,132,63,0.3)] hover:brightness-105" @click="aplicarCupomDoPopup">Aplicar cupom</button>
                <button type="button" class="mx-auto mt-4 block rounded-full px-4 py-2 text-sm font-semibold text-slate-500 hover:bg-slate-100" @click="fecharPopupCupom">Agora não</button>
            </div>
        </div>

        <PaymentProcessingModal
            :open="estado !== 'idle'"
            :state="estado"
            :order-total="order ? Number(order.total) : totalAPagar"
            :order-code="order?.external_order_id ?? null"
            :pix-qr-image-url="pixImagem"
            :pix-qr-code="pixCodigo"
            :pix-seconds-left="pixSegundos"
            :failure-message="motivoFalha"
            :success-url="urlSucesso"
            :boleto="mercadoPagoBoleto"
            @retry="tentarDeNovo" />
    </CheckoutLayout>
</template>
