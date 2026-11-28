<script setup>
import { rastrearPurchase } from '@/Shared/pixelMeta';
import { rastrearCompraGoogle } from '@/Shared/tagGoogle';
// Checkout v2 (pedido 2026-10-10): uma tela só, sem topo e rodapé da loja,
// feita pra fechar a venda, resumo do pedido ao lado. Desde 2026-10-23 no
// modelo Yampi (referência seguro.mavoloja.com.br): 3 caixas em sequência
// (Identificação, Entrega, Pagamento), só a etapa atual aberta.
// Usa o mesmo backend do checkout em 2 etapas: grava a entrega por fetch
// (JSON) e cria o pagamento por /finalizacao/pagamento; o QR do Pix e a
// confirmação do cartão chegam nas props desta mesma página.
import CheckoutLayout from '@/Shared/Layouts/CheckoutLayout.vue';
import PaymentProcessingModal from '@/Shared/Components/PaymentProcessingModal.vue';
import ConfirmarWhatsappModal from '@/Shared/Components/ConfirmarWhatsappModal.vue';
import { deveAlertarDdd55, sugestaoSem55 } from './alertaWhatsapp';
import { maskCep, useCep } from '@/Shared/useCep';
import { campoDoErro, useMedicaoCheckout } from './medicaoCheckout';
import { maskCnpj, maskCpf, maskPhone } from '@/Shared/useMasks';
// Depoimentos de volta (pedido do Lira 2026-11-23, à noite): carrossel parado, com bullets e
// no máximo 4. A nota com estrelas e o total de avaliações continuam ocultos.
// Depoimentos comentados no template (2026-11-24).
// import CarrosselAvaliacoes from '@/Shared/Components/CarrosselAvaliacoes.vue';
import CardConfianca from './Partes/CardConfianca.vue';
import CartaoPrevia from './Partes/CartaoPrevia.vue';
import { loadMercadoPagoSdk, mensagemDoMotivoCartao, mensagemErroCartao, motivoDoErroCartao, motivoNaTelaDoCartao } from '@/Shared/useMercadoPagoSdk';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue';

const props = defineProps({
    items: { type: Array, default: () => [] },
    // Média das avaliações dos produtos do carrinho: { media, total } ou null.
    avaliacaoItens: { type: Object, default: null },
    // Livros de brinde dos produtos do carrinho: { imagem, quantidade, titulos } ou null.
    livrosBrinde: { type: Object, default: null },
    subtotal: { type: Number, default: 0 },
    // UF da região em que o cliente navegou (CEP ou IP). O aviso de outra região agora sai pela diferença de frete (2026-11-24).
    ufReferencia: { type: String, default: null },
    productsDiscount: { type: Number, default: 0 },
    couponCode: { type: String, default: null },
    discountAmount: { type: Number, default: 0 },
    // Cupom da 2ª unidade (LeveDois, 27/11/2026): o Pix vale sobre o que sobra depois do cupom.
    couponPixDepois: { type: Boolean, default: false },
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
    // Modelo Yampi (2026-10-23): avaliações reais do banco.
    avaliacoesCarrossel: { type: Array, default: () => [] },
    // Cupom da faixa do topo (BLACKFRIDAY, 2026-11-06): { code, descricao } ou null.
    cupomFaixa: { type: Object, default: null },
});

// Desconto Checkout em R$ fixo vira % com casas a mais: mostra no máximo 2 (2026-10-18).
const pixPercentualTexto = computed(() => Number(Number(props.pixDiscountPercentage).toFixed(2)).toLocaleString('pt-BR'));
// Saída pelo Pix nas mensagens de erro do cartão (2026-11-24).
const saidaPix = computed(() => (props.pixDiscountPercentage > 0 ? `pague no Pix com ${pixPercentualTexto.value}% de desconto` : 'pague no Pix'));

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
    // Rascunho/recuperação do carrinho também passa pela máscara (tira o 55 do país, 26/11/2026).
    phone: maskPhone(props.customer?.phone || draftGuest.phone || props.draft?.new_address?.phone || ''),
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
    conferirWhatsapp();
};

// ---------- Alerta do WhatsApp com DDD 55 (pedido do Lira 2026-11-23) ----------
// Depois que o CEP responde: celular com DDD 55 e CEP fora da área do DDD 55
// (alertaWhatsapp.js) abre o "Confira seu WhatsApp". Confirmado ou corrigido,
// não volta mais para aquele número. Medição só com campo = celular, nunca o número.
// celularDigitado guarda os dígitos como a pessoa digitou (até 13), antes da
// máscara cortar em 11, para sugerir o número sem o 55 do país.
const celularDigitado = ref(String(pessoa.phone ?? '').replace(/\D/g, ''));
const aoDigitarCelular = (valor) => {
    celularDigitado.value = String(valor ?? '').replace(/\D/g, '').slice(0, 13);
    pessoa.phone = maskPhone(valor);
};
const whatsappConferidos = new Set();
const alertaWhatsapp = reactive({ aberto: false, atual: '', sugestao: '' });
const conferirWhatsapp = () => {
    const numero = String(pessoa.phone ?? '').replace(/\D/g, '');
    if (props.order || alertaWhatsapp.aberto || whatsappConferidos.has(numero)) return;
    if (!deveAlertarDdd55(pessoa.phone, endereco.zip)) return;
    const sugestao = sugestaoSem55(celularDigitado.value);
    Object.assign(alertaWhatsapp, { aberto: true, atual: pessoa.phone, sugestao: sugestao ? maskPhone(sugestao) : '' });
    medicao.registrar('whatsapp_alerta_ddd55', { campo: 'celular' }, null, true);
};
const fecharAlertaWhatsapp = (numero) => {
    const antes = String(alertaWhatsapp.atual ?? '').replace(/\D/g, '');
    const depois = String(numero ?? '').replace(/\D/g, '');
    whatsappConferidos.add(antes);
    whatsappConferidos.add(depois);
    const corrigiu = depois.length >= 10 && depois !== antes;
    if (corrigiu) {
        pessoa.phone = maskPhone(depois);
        celularDigitado.value = depois;
    }
    medicao.registrar(corrigiu ? 'whatsapp_corrigiu' : 'whatsapp_confirmou', { campo: 'celular' }, null, true);
    alertaWhatsapp.aberto = false;
};

const enderecoSelecionado = computed(() => props.addresses.find((address) => address.id === addressId.value) ?? null);
const cepEfetivo = computed(() => (usarNovoEndereco.value ? endereco.zip : (enderecoSelecionado.value?.zip ?? '')));

// Aviso de outra região (pedido do Lira 2026-11-24): só quando o frete do CEP
// passa do que já está no preço (diferencaFrete, conta do servidor). Antes o
// aviso saía só por trocar de estado, mesmo sem nada a pagar a mais.

// ---------- 3. Entrega ----------
// Frete grátis com o frete embutido (pedido 2026-10-12): o preço dos produtos
// já tem o frete da região. Quando o CEP muda, o servidor devolve itens e
// subtotal com o frete exato desse CEP e as opções de entrega com os nomes que o
// cliente vê (pedido do Lira 2026-11-24, EntregasDaLoja): "Entrega Rápida" ou
// "Entrega Normal" grátis e "Entrega Expressa" paga (motoboy em até 4 horas na
// Grande SP ou SEDEX pagando só a diferença), cada uma com o "Chega ...".
const freteCarregando = ref(false);
const entregas = ref([]);
const opcaoGratis = computed(() => entregas.value.find((opcao) => opcao.id === 'gratis') ?? null);
const expressa = computed(() => entregas.value.find((opcao) => opcao.id === 'expressa') ?? null);
// Só o excedente (o que passa do frete que já está no preço); zero = grátis.
const precoExpressa = computed(() => {
    const preco = Number(expressa.value?.preco ?? 0);
    if (preco <= 0) return 'Grátis';
    return `${expressa.value?.excedente ? '+ ' : ''}${formatPrice(preco)}`;
});
// A tela nasce sempre no frete grátis (pedido 2026-10-12).
const expressaEscolhida = ref(false);
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
// Frete do CEP acima do embutido no preço (pedido do Lira 2026-11-24): o preço
// dos produtos não muda; a diferença entra como linha "Frete" e no total.
const diferencaFrete = ref(0);
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
        entregas.value = [];
        return;
    }
    freteCarregando.value = true;
    try {
        const response = await fetch(`/finalizacao/entrega-gratis?cep=${cep}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) throw new Error();
        const data = await response.json();
        entregas.value = Array.isArray(data.entregas) ? data.entregas : [];
        descontoPlataforma.value = Number(data.desconto_plataforma) || 0;
        diferencaFrete.value = round2(Number(data.diferenca_frete) || 0);
        medicao.registrar('cep_respondido', {}, 'cep_respondido');
        if (!expressa.value) expressaEscolhida.value = false;
        itens.value = data.items ?? itens.value;
        subtotalAtual.value = Number(data.subtotal ?? subtotalAtual.value);
        if (cupomAplicado.value) {
            descontoCupom.value = Number(data.discount_amount) || 0;
            pixDepoisDoCupom.value = !!data.pix_depois_do_cupom;
        }
        if (rolarAteFrete) {
            rolarAteFrete = false;
            mostrarOpcoesFrete();
        }
    } catch {
        entregas.value = [];
        descontoPlataforma.value = 0;
        diferencaFrete.value = 0;
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
            only: ['items', 'subtotal', 'productsDiscount', 'couponCode', 'discountAmount', 'couponPixDepois', 'pixDiscountPercentage', 'avaliacaoItens', 'livrosBrinde'],
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
const valorFrete = computed(() => (expressaEscolhida.value && expressa.value
    ? Number(expressa.value.preco)
    : round2(Number(entregaEscolhida.value?.price ?? 0) + diferencaFrete.value)));

// ---------- Cupom (pedido 2026-10-10) ----------
// Aplica por JSON: busca o cupom na base, confere as regras e o desconto
// entra no total na hora, sem recarregar a tela.
const cupomAplicado = ref(props.couponCode);
const descontoCupom = ref(Number(props.discountAmount) || 0);
const pixDepoisDoCupom = ref(!!props.couponPixDepois);
const cupomDescricao = ref(null);
const cupom = ref('');
const cupomCarregando = ref(false);
const cupomErro = ref(page.props.errors?.code ?? null);
// Motivo do servidor (mínimo, limite de usos...) embaixo do "Cupom inválido ou vencido" (2026-11-23).
const cupomErroDetalhe = ref(null);
const CUPOM_INVALIDO = 'Cupom inválido ou vencido';

// Se o servidor tirar o cupom (expirou entre aplicar e pagar), a tela acompanha.
watch(() => [props.couponCode, props.discountAmount, props.couponPixDepois], ([codigo, valor, pixDepois]) => {
    cupomAplicado.value = codigo;
    descontoCupom.value = Number(valor) || 0;
    pixDepoisDoCupom.value = !!pixDepois;
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

// Um cupom por vez (2026-11-06): com um aplicado o campo trava e o Aplicar
// some até tirar no X; dois cliques seguidos não mandam dois pedidos. O
// servidor confere de novo e troca o do rascunho, nunca soma.
const normalizarCupom = (codigo) => String(codigo ?? '').replace(/\s+/g, '').toUpperCase();
// Medição do cupom (pedido do Lira 2026-11-23): clique no "Aplicar cupom" (ou
// Enter no campo), deu certo ou deu erro. Só o nome do campo ("cupom"), NUNCA
// o código digitado.
const medirCupom = (evento) => medicao.registrar(evento, { campo: 'cupom' }, null, true);
const aplicarCupom = async () => {
    if (cupomCarregando.value) return;
    medirCupom('cupom_clicou');
    const codigo = normalizarCupom(cupom.value);
    cupomErroDetalhe.value = null;
    if (cupomAplicado.value) {
        if (codigo && codigo !== normalizarCupom(cupomAplicado.value)) {
            cupomErro.value = `Só um cupom por compra. Tire o ${cupomAplicado.value} no X para usar outro.`;
            medirCupom('cupom_erro');
        }
        return;
    }
    if (!codigo) {
        cupomErro.value = 'Digite o código do cupom.';
        medirCupom('cupom_erro');
        return;
    }
    cupomCarregando.value = true;
    cupomErro.value = null;
    try {
        const response = await chamarCupom('POST', { code: codigo });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const motivo = data.errors?.code?.[0] ?? data.message ?? null;
            if (response.status === 422) {
                cupomErro.value = CUPOM_INVALIDO;
                cupomErroDetalhe.value = motivo;
            } else {
                cupomErro.value = motivo ?? 'Não foi possível aplicar o cupom agora.';
            }
            medirCupom('cupom_erro');
            return;
        }
        cupomAplicado.value = data.code;
        descontoCupom.value = Number(data.discount_amount) || 0;
        pixDepoisDoCupom.value = !!data.pix_depois_do_cupom;
        cupomDescricao.value = data.descricao;
        cupom.value = '';
        medirCupom('cupom_aplicado');
    } catch {
        cupomErro.value = 'Sem conexão. Tente de novo.';
        medirCupom('cupom_erro');
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
    cupomErroDetalhe.value = null;
    await nextTick();
    campoCupom.value?.focus();
};

const removerCupom = async () => {
    cupomCarregando.value = true;
    try {
        const response = await chamarCupom('DELETE');
        if (!response.ok) throw new Error();
        cupomAplicado.value = null;
        descontoCupom.value = 0;
        pixDepoisDoCupom.value = false;
        cupomDescricao.value = null;
    } catch {
        cupomErro.value = 'Não foi possível tirar o cupom agora. Tente de novo.';
    } finally {
        cupomCarregando.value = false;
    }
};

// Estado do cupom para a tela e para leitor de tela (2026-11-06).
const cupomEstado = computed(() => {
    if (cupomCarregando.value) return cupomAplicado.value ? 'removendo' : 'aplicando';
    if (cupomAplicado.value) return 'aplicado';
    return cupomErro.value ? 'erro' : 'vazio';
});

// ---------- Faixa do cupom (BLACKFRIDAY, pedido 2026-11-06) ----------
// No lugar do pop-up do BEMVINDO: faixa compacta com o código e "Aplicar"
// (antes "Copiar cupom", troca pedida pelo Lira em 2026-11-06). O clique põe
// o código no campo do cupom e aplica pelo mesmo caminho do campo, com as
// mesmas regras (um cupom por vez, servidor confere). Desde 2026-11-24, na Black
// Friday automática (cupomFaixa.auto) o servidor já aplica sozinho e o botão vira "Aplicar de novo".
const faixaTentou = ref(false);
const faixaAplicada = computed(() => !!props.cupomFaixa && normalizarCupom(cupomAplicado.value) === normalizarCupom(props.cupomFaixa.code));
const aplicarCupomFaixa = async () => {
    const codigo = props.cupomFaixa?.code;
    if (!codigo || cupomCarregando.value) return;
    faixaTentou.value = true;
    cupom.value = codigo;
    await aplicarCupom();
};

// Celular x computador (2026-11-06): o campo do cupom fica num lugar só da
// página, no topo no celular (o resumo nasce recolhido) e no resumo no computador.
const consultaComputador = typeof window !== 'undefined' ? window.matchMedia('(min-width: 1024px)') : null;
const ehComputador = ref(consultaComputador?.matches ?? true);
const acompanharTela = (evento) => { ehComputador.value = evento.matches; };
onMounted(() => consultaComputador?.addEventListener('change', acompanharTela));
onUnmounted(() => consultaComputador?.removeEventListener('change', acompanharTela));

// ---------- 4. Pagamento ----------
const metodo = ref('pix');
// Pix desde 2026-10-24: o % sobre os produtos ANTES do cupom (3% do BEMVINDO
// + 2% do Pix = 5%), sem passar do que sobrou depois do cupom. Mesma conta do
// DescontoPix::descontoSobreCupom. Cupom da 2ª unidade: o % vale sobre o que sobra depois do cupom.
const descontoPixCalculado = computed(() => {
    if (pixDepoisDoCupom.value) {
        const sobra = round2(Math.max(0, subtotalAtual.value - descontoPlataforma.value - descontoCupom.value));
        return Math.min(round2(sobra * props.pixDiscountPercentage / 100), sobra);
    }
    const base = Math.max(0, subtotalAtual.value - descontoPlataforma.value);
    return Math.min(round2(base * props.pixDiscountPercentage / 100), round2(Math.max(0, base - descontoCupom.value)));
});
const descontoPix = computed(() => (metodo.value === 'pix' && props.pixDiscountPercentage > 0 ? descontoPixCalculado.value : 0));
const totalCartao = computed(() => round2(subtotalAtual.value - descontoCupom.value - descontoPlataforma.value + valorFrete.value));
const totalAPagar = computed(() => (props.order ? Number(props.order.total) : round2(totalCartao.value - descontoPix.value)));

// Card Payment Brick do Mercado Pago embutido (sem o botão próprio dele):
// o "Finalizar pedido" único chama getFormData(), que tokeniza o cartão no
// navegador — o número do cartão nunca passa pelo nosso servidor.
let brick = null;
let brickTimer = null;
const brickPronto = ref(false);
const brickErro = ref(null);
// Último erro do Brick como veio (onError/criação), para saber o motivo
// quando o Finalizar falhar (2026-11-24). Nunca sai da página.
let brickErroBruto = null;

const cartaoBin = ref('');
// E-mail que o Brick recebe. Sem e-mail válido ainda (Identificação vazia),
// vai um endereço neutro só para o Brick não abrir o campo dele: esse valor
// nunca chega ao pagamento, que usa o e-mail da Identificação no servidor.
const EMAIL_BRICK_NEUTRO = 'pagador@kazakora.com';
const emailDoBrick = () => {
    const email = String(pessoa.email ?? '').trim();
    return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email) ? email : EMAIL_BRICK_NEUTRO;
};
let emailNoBrick = null;

const desmontarBrick = () => {
    brick?.unmount();
    brick = null;
    brickPronto.value = false;
    cartaoBin.value = '';
};

const montarBrick = async () => {
    desmontarBrick();
    brickErro.value = null;
    brickErroBruto = null;
    if (!props.mercadoPagoPublicKey || totalCartao.value <= 0) return;

    try {
        const MercadoPago = await loadMercadoPagoSdk();
        const mp = new MercadoPago(props.mercadoPagoPublicKey, { locale: 'pt-BR' });
        await nextTick();
        emailNoBrick = emailDoBrick();
        brick = await mp.bricks().create('cardPayment', 'cartao-brick-v2', {
            // E-mail do pagador vem da Identificação (pedido 2026-10-25): com
            // payer.email preenchido o Brick não mostra o campo de e-mail. O
            // servidor não usa o e-mail do Brick: o pagamento vai ao Mercado
            // Pago com o e-mail da conta/convidado salvo em salvarEntrega()
            // logo antes (CheckoutController::mercadoPagoPayer).
            initialization: { amount: totalCartao.value, payer: { email: emailDoBrick() } },
            customization: {
                visual: { hidePaymentButton: true, hideFormTitle: true, style: { theme: 'default' } },
                // Só crédito: o servidor manda type credit_card na API de Orders,
                // e débito/pré-pago no Brick não têm parcelas (o seletor some).
                paymentMethods: { minInstallments: 1, maxInstallments: 12, types: { excluded: ['debit_card', 'prepaid_card'] } },
            },
            callbacks: {
                onReady: () => { brickPronto.value = true; },
                // Prévia do cartão (modelo Yampi): o Brick só entrega os 6 primeiros
                // dígitos (BIN); o resto do número nunca sai do campo seguro dele.
                // Cartão novo apaga o erro do anterior (ex.: cartão não aceito).
                onBinChange: (bin) => {
                    cartaoBin.value = String(bin ?? '').replace(/\D/g, '').slice(0, 8);
                    brickErro.value = null;
                    brickErroBruto = null;
                },
                onError: (error) => {
                    brickErroBruto = error;
                    brickErro.value = mensagemErroCartao(error);
                },
                onSubmit: () => Promise.resolve(),
            },
        });
    } catch (error) {
        brickErroBruto = error ?? 'nao_carregou';
        brickErro.value = mensagemErroCartao(error);
    }
};

watch([metodo, totalCartao], ([novoMetodo], [metodoAntes]) => {
    clearTimeout(brickTimer);
    if (novoMetodo !== 'card' || props.order) {
        desmontarBrick();
        return;
    }
    // Só o valor mudou (frete, cupom) com o cartão já aberto: atualiza o valor
    // no Brick sem remontar, para não apagar o que o cliente já digitou
    // (2026-11-24). Remonta só se o Brick não tiver update().
    if (metodoAntes === 'card' && brick && brickPronto.value && typeof brick.update === 'function') {
        brickTimer = setTimeout(() => {
            try {
                if (brick?.update({ amount: totalCartao.value }) === false) montarBrick();
            } catch {
                montarBrick();
            }
        }, 400);
        return;
    }
    brickTimer = setTimeout(montarBrick, 400);
});

// E-mail da Identificação mudou com o cartão aberto: remonta o Brick com o
// valor atual, mas só se o cliente ainda não começou a digitar o cartão
// (remontar apaga o que foi digitado). O pagamento usa o e-mail atual de
// qualquer jeito, porque vem do servidor.
watch(() => pessoa.email, () => {
    if (metodo.value !== 'card' || props.order || !brick || cartaoBin.value) return;
    clearTimeout(brickTimer);
    brickTimer = setTimeout(() => {
        if (metodo.value === 'card' && !cartaoBin.value && emailDoBrick() !== emailNoBrick) montarBrick();
    }, 800);
});

// ---------- Selos de confiança ----------
// Os 4 cartões com imagem (Pagamento Seguro, Avaliações Positivas, Satisfação
// Garantida, Parcelamento) ficam só na página do produto (pedido 2026-10-25).

// Selos e checks verdes do padrão KazaKora (mesmos da página de produto,
// 2026-10-19), abaixo do resumo (pedido 2026-10-23). Só fatos de toda compra.
const SELOS_VERIFICADOS = [
    ['Loja verificada', 'Empresa com CNPJ ativo e selo RA Verificada no Reclame Aqui'],
    ['Compra segura', 'Pagamento processado com segurança; não guardamos dados do cartão'],
];
// Nota fiscal (pedido do Lira 2026-11-23): diz que vai por e-mail assim que o pagamento é
// confirmado, com os livros digitais quando o pedido ganha o brinde.
// Só esta frase fica (pedido do Lira 2026-11-23, à noite). Os selos Loja verificada e Compra
// segura e os checks de frete grátis e devolução ficam comentados no template, sem apagar:
//   'Frete grátis para todo o Brasil',
//   'Devolução grátis em até 7 dias após o recebimento',
// 26/11/2026 (pedido do Lira): a nota sai depois que o cliente confirma o endereço no WhatsApp
// (SendOrderApprovedWhatsAppJob segura a NF-e até o "Tudo certo" ou 30 min). Os livros digitais
// já aparecem como linha própria "de presente / GRÁTIS" no resumo do pedido.
const CHECKS_LOJA = computed(() => [
    'Nota fiscal enviada para o seu e-mail assim que você confirmar o endereço na mensagem de WhatsApp que vamos te enviar',
]);

// ---------- Finalizar ----------
const erros = ref({});
const processando = ref(false);
const erroGeral = ref(null);

// Repetição automática (pedido do Lira em 27/11/2026, depois da cliente
// Bernardete: o "Finalizar" voltou 429 e a venda se perdeu). Falha de rede ou
// do servidor não pode virar venda perdida: a tela tenta de novo sozinha até
// 6 vezes, com aviso amigável, sempre com a mesma chave de tentativa (o
// servidor nunca cobra duas vezes a mesma). Só 422 (dado errado), recusa do
// cartão e sessão expirada (419) aparecem direto para o cliente.
const ESPERAS_DA_REPETICAO = [2000, 4000, 6000, 10000, 15000, 20000];
const reenvio = ref(null);
const esperar = (ms) => new Promise((resolver) => { setTimeout(resolver, ms); });
const novaTentativa = () => (window.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`);
const avisarFalhaDePagamento = (etapaQueFalhou, status, tentativas) => {
    try {
        fetch('/finalizacao/pagamento-falhou', {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            body: JSON.stringify({ status, tentativas, metodo: metodo.value, etapa: etapaQueFalhou }),
        }).catch(() => {});
    } catch {
        // Aviso é bônus: nunca atrapalha o cliente.
    }
};
const desistirDaRepeticao = (etapaQueFalhou, status, tentativas) => {
    reenvio.value = null;
    processando.value = false;
    erroGeral.value = status === 419
        ? 'Sua sessão expirou. Atualize a página (seus dados continuam salvos) e toque em "Finalizar pedido" de novo.'
        : `Não conseguimos falar com o banco agora e o seu pedido não foi cobrado. Toque em "Finalizar pedido" para tentar de novo${metodo.value === 'pix' ? '' : ' ou pague no Pix'}.`;
    medicao.erro('pagamento', `sem_resposta_${status}`);
    if (status !== 419) avisarFalhaDePagamento(etapaQueFalhou, status, tentativas);
};
// Depois de uma tentativa nesta tela, valem só os erros dela (nada de erro
// antigo guardado na sessão aparecendo de novo — BUG REAL 2026-10-10).
const tentouAqui = ref(false);
const erro = (campo) => erros.value[campo]?.[0] ?? erros.value[campo] ?? (tentouAqui.value ? null : serverErrors.value[campo]) ?? null;
const CAMPOS_ENDERECO = ['new_address.zip', 'new_address.street', 'new_address.number', 'new_address.neighborhood', 'new_address.city', 'new_address.state', 'new_address.recipient_name'];
const errosEndereco = computed(() => [...new Set(CAMPOS_ENDERECO.map((campo) => erro(campo)).filter(Boolean))]);

const xsrf = () => decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/) ?? [])[1] ?? '');

const payloadEntrega = () => {
    // Entrega Expressa: motoboy (Grande SP) ou SEDEX pela diferença (o antigo turbo).
    const escolheuExpressa = Boolean(expressaEscolhida.value && expressa.value);
    const data = { shipping_method_id: shippingMethodId.value, turbo: escolheuExpressa && expressa.value.tipo === 'sedex', motoboy: escolheuExpressa && expressa.value.tipo === 'motoboy' };

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

// Leads (pedido do Lira 2026-11-06): cada campo preenchido (nome, e-mail,
// CPF, telefone, CEP) vai ao servidor por ajax, 800 ms depois de parar de
// digitar, para a tela Admin > Leads. Sem esperar resposta; falha não
// aparece para o cliente e não atrapalha a compra.
const camposDoLead = () => ({
    nome: pessoa.name, email: pessoa.email, cpf: pessoa.cpf, telefone: pessoa.phone, cep: cepEfetivo.value,
});
let ultimoLeadEnviado = '';
let esperaLead = null;
const enviarCampoDoLead = (campo) => {
    clearTimeout(esperaLead);
    esperaLead = setTimeout(() => {
        const campos = camposDoLead();
        const assinatura = JSON.stringify(campos);
        if (assinatura === ultimoLeadEnviado || !Object.values(campos).some((v) => String(v ?? '').trim())) return;
        ultimoLeadEnviado = assinatura;
        try {
            fetch('/finalizacao/lead-campo', {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
                body: JSON.stringify({ ...campos, campo }),
            }).catch(() => {});
        } catch {
            // nunca atrapalha o checkout
        }
    }, 800);
};
if (!props.order) {
    watch(() => pessoa.name, () => enviarCampoDoLead('nome'));
    watch(() => pessoa.email, () => enviarCampoDoLead('email'));
    watch(() => pessoa.cpf, () => enviarCampoDoLead('cpf'));
    watch(() => pessoa.phone, () => enviarCampoDoLead('telefone'));
    watch(cepEfetivo, () => enviarCampoDoLead('cep'));
    // Já veio preenchido (cliente logado ou voltou para a tela): manda uma vez ao abrir.
    onMounted(() => enviarCampoDoLead(null));
}

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
    // Rede ou servidor falhando: tenta de novo sozinho (mesma regra do pagamento).
    for (let n = 0; ; n++) {
        let status = 0;
        try {
            const resposta = await fetch('/finalizacao/entrega', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
                body: JSON.stringify(payloadEntrega()),
            });

            if (resposta.status === 422) {
                const corpo = await resposta.json();
                reenvio.value = null;
                erros.value = corpo.errors ?? {};
                erroGeral.value = corpo.message ?? 'Confira os campos destacados.';
                etapa.value = etapaDosErros(erros.value) ?? etapa.value;
                irParaErro();
                return false;
            }

            if (resposta.ok) {
                return true;
            }
            status = resposta.status;
        } catch {
            status = 0;
        }

        if (status === 419 || n >= ESPERAS_DA_REPETICAO.length) {
            desistirDaRepeticao('entrega', status, n);
            return false;
        }
        reenvio.value = { n: n + 1, total: ESPERAS_DA_REPETICAO.length };
        await esperar(ESPERAS_DA_REPETICAO[n]);
    }
};

// A frase do aceite fica debaixo do "Ir para Pagamento" (modelo Mavo,
// 2026-10-24); quem caiu direto no pagamento sem passar por ele a vê no
// Finalizar pedido.
// Device ID do Mercado Pago (2026-11-24): o security.js gera window.MP_DEVICE_SESSION_ID, que vai junto
// do pagamento; sem ele o antifraude recusava cartão bom como "high_risk".
onMounted(() => {
    if (typeof window === 'undefined' || window.MP_DEVICE_SESSION_ID || document.getElementById('mp-security-js')) return;
    const script = document.createElement('script');
    script.id = 'mp-security-js';
    script.src = 'https://www.mercadopago.com/v2/security.js';
    script.setAttribute('view', 'checkout');
    script.async = true;
    document.head.appendChild(script);
});

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
        // Bairro junto da rua e CEP com a cidade: a linha cortada no computador nunca esconde o CEP.
        rua: `${a.street}, ${a.number}${a.complement ? ` - ${a.complement}` : ''}${a.neighborhood ? ` · ${a.neighborhood}` : ''}`,
        cidade: `${a.city}/${a.state} · CEP ${a.zip}`,
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

    const pagamento = { payment_method: metodo.value, split: false, terms_accepted: true, mp_device_id: window.MP_DEVICE_SESSION_ID ?? null };

    if (metodo.value === 'card') {
        // Motivo específico na tela e na medição (2026-11-24): antes era só
        // "Confira os dados do cartão." e o cliente não sabia o que corrigir.
        // Nada é desmontado aqui: o que foi digitado continua no Brick.
        const falhaNoCartao = (motivo) => {
            erroGeral.value = mensagemDoMotivoCartao(motivo, saidaPix.value);
            brickErro.value = erroGeral.value;
            medicao.erro('cartao', motivo);
            processando.value = false;
            document.getElementById('cartao-brick-v2')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        };

        // Formulário do cartão nem carregou (SDK bloqueado, rede, erro do Brick).
        if (!brick || !brickPronto.value || typeof brick.getFormData !== 'function') {
            falhaNoCartao('nao_carregou');
            return;
        }

        let dados = null;
        let erroDoBrick = null;
        try {
            // getFormData pode não responder se o campo seguro travar: 20 s e desiste.
            dados = await Promise.race([
                brick.getFormData(),
                new Promise((_, rejeitar) => { setTimeout(() => rejeitar(new Error('sem_token: tempo esgotado')), 20000); }),
            ]);
        } catch (error) {
            erroDoBrick = error ?? null;
        }

        if (!dados?.token) {
            const motivo = motivoDoErroCartao(erroDoBrick)
                ?? motivoDoErroCartao(brickErroBruto)
                ?? motivoNaTelaDoCartao(document.getElementById('cartao-brick-v2'))
                ?? (erroDoBrick ? 'outro' : 'sem_token');
            falhaNoCartao(motivo);
            return;
        }

        if (!Number(dados.installments)) {
            falhaNoCartao('parcelas');
            return;
        }
        Object.assign(pagamento, {
            mp_card_token: dados.token,
            mp_card_installments: dados.installments,
            mp_payment_method_id: dados.payment_method_id,
            mp_issuer_id: dados.issuer_id ?? null,
        });
    }

    enviarPagamento({ ...pagamento, tentativa: novaTentativa() }, 0);
};

// Manda o pagamento; falha de rede/servidor repete sozinha com a MESMA tentativa.
const enviarPagamento = (pagamento, n) => {
    let repetir = null;
    const agendar = (status) => {
        repetir = status;
        if (status === 419 || n >= ESPERAS_DA_REPETICAO.length) {
            desistirDaRepeticao('pagamento', status, n);
            return;
        }
        reenvio.value = { n: n + 1, total: ESPERAS_DA_REPETICAO.length };
        setTimeout(() => enviarPagamento(pagamento, n + 1), ESPERAS_DA_REPETICAO[n]);
    };

    router.post('/finalizacao/pagamento', pagamento, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { reenvio.value = null; },
        onError: (e) => { reenvio.value = null; erros.value = e; erroGeral.value = e.payment ?? e.cart ?? e['guest.email'] ?? e['guest.cpf'] ?? e.cpf ?? e.mp_card_installments ?? e.mp_card_token ?? e.mp_payment_method_id ?? 'Não foi possível concluir. Confira os dados.'; },
        // Nunca a caixa de erro do servidor (em inglês) por cima do checkout.
        onHttpException: (resposta) => { agendar(resposta?.status ?? 0); return false; },
        onNetworkError: () => { agendar(0); return false; },
        onFinish: () => { if (repetir === null) processando.value = false; },
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
                // Compra do Google Ads (2026-11-19): o servidor só manda com aceite.
                rastrearCompraGoogle(dados.google_compra);
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
// "Viu o formulário" (2026-11-10): observa a etapa que abriu, não só a Identificação.
// Quem volta com os dados já preenchidos cai na Entrega ou no Pagamento, a
// Identificação fica escondida e a medição contava essa pessoa como "não viu".
onMounted(() => { if (!props.order) medicao.iniciar(document.getElementById(`etapa-${etapa.value}`) ?? secaoDados.value); });
onUnmounted(() => medicao.parar());

// ---------- Resumo ----------
// Celular (pedido 2026-11-06, desfaz o "nasce aberto" de 24/10): a barra
// "Resumo (n)" com o total nasce recolhida para a Identificação caber na
// primeira tela; tocar abre os valores e os produtos. O cupom fica fora,
// logo abaixo da barra. No computador o resumo fica sempre ao lado.
const resumoAberto = ref(false);
const fotoDoItem = (item) => {
    const imagens = item.product?.images ?? [];
    return (imagens.find((imagem) => imagem.is_primary) ?? imagens[0])?.thumb_url ?? (imagens.find((imagem) => imagem.is_primary) ?? imagens[0])?.url ?? null;
};
const totalItens = computed(() => itens.value.reduce((soma, item) => soma + item.quantity, 0));
// Contador do resumo: só os itens pagos (pedido do Lira 2026-11-24; antes, desde 2026-10-24,
// o brinde digital contava como um a mais e 1 travesseiro virava "Produtos (2)"). O brinde
// continua listado como GRÁTIS, fora da contagem.
const totalProdutosResumo = computed(() => totalItens.value);
const precoUnitario = (item) => (item.quantity > 0 ? item.subtotal / item.quantity : item.subtotal);
// Selo do item só com oferta de verdade: Oferta Relâmpago
// (fim vindo do servidor) ou Oferta do Dia (vale até 23:59:59 de hoje em SP).
const fimDaOfertaDoDia = () => `${new Date().toLocaleDateString('en-CA', { timeZone: 'America/Sao_Paulo' })}T23:59:59-03:00`;
const ofertaDoItem = (item) => {
    if (item.product?.oferta_relampago?.fim) return { nome: 'Oferta Relâmpago', fim: item.product.oferta_relampago.fim };
    if (item.product?.oferta_do_dia) return { nome: 'Oferta do Dia', fim: fimDaOfertaDoDia() };
    return null;
};

const totalNoPix = computed(() => (props.pixDiscountPercentage > 0
    ? round2(totalCartao.value - descontoPixCalculado.value)
    : totalCartao.value));

// Cards da etapa Pagamento (2026-11-24): nome, uma linha curta e o selo do desconto, que é o
// "Desconto Checkout" dos produtos (DescontoPix no servidor), nunca um número fixo aqui.
const opcoesPagamento = computed(() => [
    {
        id: 'pix', nome: 'Pix', icone: 'fa-brands fa-pix text-[#32BCAD]',
        linha: props.pixDiscountPercentage > 0 ? formatPrice(totalNoPix.value) : 'aprovação imediata',
        destaque: props.pixDiscountPercentage > 0,
        selo: props.pixDiscountPercentage > 0 ? `-${pixPercentualTexto.value}%` : null,
    },
    { id: 'card', nome: 'Cartão de Crédito', icone: 'fa-solid fa-credit-card text-slate-700', linha: 'até 12x', destaque: false, selo: null },
    { id: 'boleto', nome: 'Boleto Bancário', icone: 'fa-solid fa-barcode text-slate-700', linha: `vence em ${props.boletoDiasVencimento} dias`, destaque: false, selo: null },
]);

// Campos em pílula com fundo cinza claro (modelo Yampi).
const inputClass = 'mt-1 w-full rounded-full border border-transparent bg-[#F1F2F4] px-5 py-3 text-[15px] text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-slate-300 focus:bg-white focus:ring-2 focus:ring-emerald-100';
const inputErroClass = 'border-red-500 bg-red-50 ring-1 ring-red-200';
const botaoVerde = 'flex w-full items-center justify-center gap-2 rounded-full bg-[#00843f] px-6 py-4 text-lg font-bold text-white shadow-[0_6px_16px_rgba(0,132,63,0.28)] transition hover:brightness-105 active:translate-y-px disabled:cursor-not-allowed disabled:opacity-60';
const caixaClass = 'scroll-mt-4 rounded-[24px] bg-white p-5 shadow-[0_4px_24px_rgba(15,23,42,0.07)] md:p-7 lg:p-5 xl:p-7';
// Celular (modelo Mavo, pedido 2026-10-24): só a etapa atual aparece; a feita
// fecha (volta pelo "Voltar") e a próxima não fica apagada na tela. No
// computador as três seguem à mostra, a próxima esmaecida.
// No computador o card é coluna flex: o resumo da etapa feita cresce até o fim
// do card, e Identificação e Entrega fecham com a mesma altura (2026-10-24).
const classeEtapa = (numero) => [caixaClass, 'lg:flex lg:flex-col', etapa.value !== numero && 'hidden', etapa.value < numero && 'lg:opacity-60'];
// Grade da coluna da esquerda no computador: Identificação e Entrega 50/50, Pagamento embaixo.
const areasSemErro = "lg:[grid-template-areas:'ident_entrega'_'pag_pag']";
const areasComErro = "lg:[grid-template-areas:'erro_erro'_'ident_entrega'_'pag_pag']";
</script>

<template>
    <Head title="Finalize sua compra" />

    <CheckoutLayout :sem-relogio="!!props.order">
        <!-- Faixa do cupom (BLACKFRIDAY, pedido 2026-11-06), no celular e no computador, no lugar do
             pop-up do BEMVINDO. "Aplicar" põe o código no campo do cupom e aplica. -->
        <div v-if="cupomFaixa && !order" data-faixa-cupom class="bg-[#141414] text-white">
            <!-- Black Friday automática (pedido do Lira 2026-11-24): o servidor já aplicou o cupom.
                 Aplicado: só o aviso. Cliente tirou: botão para aplicar de novo com um clique.
                 Outro cupom no lugar: regra do cupom único (não acumula), sem botão. -->
            <div v-if="cupomFaixa.auto" class="mx-auto flex w-full max-w-[1320px] items-center justify-between gap-3 px-4 py-2 md:px-6 lg:justify-center lg:gap-5" data-faixa-blackfriday-auto>
                <p v-if="faixaAplicada" class="flex min-w-0 items-center gap-1.5 whitespace-nowrap text-[13px] font-bold leading-snug sm:text-sm">
                    <span class="inline-flex h-[18px] w-[18px] shrink-0 items-center justify-center rounded-full bg-[#00a650] text-[10px] text-white" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                    <span>Cupom</span>
                    <span class="rounded-[4px] border border-dashed border-[#FFD400] px-1.5 py-px font-extrabold tracking-wide text-[#FFD400]">{{ cupomFaixa.code }}</span>
                    <span>aplicado: {{ cupomFaixa.rotulo }}</span>
                </p>
                <p v-else-if="cupomAplicado" class="min-w-0 text-[13px] leading-snug sm:text-sm">
                    <strong class="font-extrabold uppercase tracking-wide text-[#FF7A33]">Black Friday:</strong>
                    o {{ cupomFaixa.code }} dá {{ cupomFaixa.rotulo }}. Só um cupom por compra: tire o {{ cupomAplicado }} para usar o {{ cupomFaixa.code }}.
                </p>
                <template v-else>
                    <p class="min-w-0 text-[13px] leading-snug sm:text-sm">
                        <strong class="font-extrabold uppercase tracking-wide text-[#FF7A33]">Black Friday:</strong>
                        {{ cupomFaixa.rotulo }} com o cupom <span class="whitespace-nowrap font-mono font-bold tracking-wider">{{ cupomFaixa.code }}</span>
                    </p>
                    <button type="button" data-reaplicar-blackfriday :disabled="cupomCarregando"
                        class="inline-flex min-h-9 shrink-0 items-center gap-1.5 rounded-full bg-white px-3.5 py-1.5 text-xs font-bold text-slate-900 transition hover:bg-slate-100 disabled:opacity-70"
                        @click="aplicarCupomFaixa">
                        <i :class="cupomCarregando && faixaTentou ? 'fa-solid fa-spinner animate-spin' : 'fa-solid fa-tag'" aria-hidden="true"></i>
                        {{ cupomCarregando && faixaTentou ? 'Aplicando' : 'Aplicar de novo' }}
                    </button>
                </template>
            </div>
            <div v-else class="mx-auto flex w-full max-w-[1320px] items-center justify-between gap-3 px-4 py-2 md:px-6 lg:justify-center lg:gap-5">
                <!-- Escrito, sem botão (pedido do Lira 2026-11-24: ninguém usava o "Aplicar"). -->
                <p class="min-w-0 text-[13px] leading-snug sm:text-sm">
                    <strong class="font-extrabold uppercase tracking-wide text-[#FF7A33]">Black Friday:</strong>
                    digite o cupom
                    <span class="select-all whitespace-nowrap rounded-md border border-dashed border-white/60 px-1.5 py-px font-mono font-bold tracking-wider">{{ cupomFaixa.code }}</span>
                    no campo <strong>Cupom de desconto</strong> para ganhar {{ cupomFaixa.descricao }}
                </p>
                <span v-if="faixaAplicada" class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-[#00843f] px-3 py-1.5 text-xs font-bold">
                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i>Aplicado
                </span>
                <!-- Botão "Aplicar" da faixa oculto a pedido do Lira em 2026-11-24 (não apagar).
                <button v-else type="button" data-aplicar-cupom-faixa :disabled="cupomCarregando"
                    class="inline-flex min-h-9 shrink-0 items-center gap-1.5 rounded-full bg-white px-3.5 py-1.5 text-xs font-bold text-slate-900 transition hover:bg-slate-100 disabled:opacity-70"
                    @click="aplicarCupomFaixa">
                    <i :class="cupomCarregando && faixaTentou ? 'fa-solid fa-spinner animate-spin' : 'fa-solid fa-tag'" aria-hidden="true"></i>
                    {{ cupomCarregando && faixaTentou ? 'Aplicando' : 'Aplicar' }}
                </button>
                -->
            </div>
            <p v-if="faixaTentou && cupomErro && !faixaAplicada" class="pb-2 text-center text-[11px] text-white/80" aria-live="polite">
                {{ cupomErro }}
            </p>
        </div>

        <!-- Celular: resumo recolhido numa barra com o total (pedido 2026-11-06), abre ao tocar. -->
        <button type="button" class="flex w-full items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3 text-left lg:hidden"
            :aria-expanded="resumoAberto" aria-controls="resumo-compra" @click="resumoAberto = !resumoAberto">
            <span class="min-w-0">
                <span class="block text-sm font-bold text-slate-900">Resumo ({{ totalProdutosResumo }}) <span class="font-semibold text-sky-700">{{ resumoAberto ? 'fechar' : 'ver' }}</span></span>
                <!-- Brinde digital em segundo plano: uma linha pequena; o detalhe fica no resumo aberto. -->
                <span v-if="livrosBrinde" class="block truncate text-[11px] text-slate-500"><i class="fa-solid fa-gift mr-1 text-[#00843f]" aria-hidden="true"></i>Inclui brinde digital grátis</span>
            </span>
            <span class="flex shrink-0 items-center gap-2 text-right text-slate-900">
                <span>
                    <span class="block text-base font-extrabold">{{ formatPrice(totalAPagar) }}</span>
                    <span v-if="!order && descontoPix > 0" class="block text-[11px] font-semibold text-[#00843f]">no Pix</span>
                </span>
                <i class="fa-solid fa-chevron-down text-xs transition" :class="{ 'rotate-180': resumoAberto }" aria-hidden="true"></i>
            </span>
        </button>
        <!-- Celular: o campo do cupom vem para cá (Teleport abaixo), sempre à mostra com o resumo recolhido. -->
        <div id="cupom-celular" class="border-b border-slate-200 bg-white px-4 py-2.5 lg:hidden"></div>

        <!-- Campo do cupom: um só na página, levado para o topo no celular ou para o resumo no computador. -->
        <Teleport defer :to="ehComputador ? '#cupom-computador' : '#cupom-celular'">
            <form class="flex gap-2" data-cupom-form @submit.prevent="faixaTentou = false; aplicarCupom()">
                <label class="relative min-w-0 flex-1">
                    <span class="sr-only">Cupom de desconto</span>
                    <input :value="cupomAplicado || cupom" data-medir="cupom" type="text" placeholder="Cupom de desconto" autocapitalize="characters" enterkeyhint="go"
                        :readonly="!!cupomAplicado" :disabled="cupomCarregando && !cupomAplicado" :aria-invalid="cupomEstado === 'erro'" aria-describedby="cupom-dica cupom-status"
                        ref="campoCupom"
                        class="w-full rounded-full border px-5 py-2.5 text-sm uppercase outline-none placeholder:normal-case"
                        :class="cupomAplicado ? 'border-2 border-[#00843f] bg-white pr-11 font-semibold text-slate-900' : [cupomErro ? 'border-red-400' : 'border-transparent', 'bg-[#F1F2F4] focus:border-slate-300 focus:bg-white']"
                        @input="cupom = $event.target.value; cupomErro = null; cupomErroDetalhe = null">
                    <button v-if="cupomAplicado" type="button" data-limpar-cupom :disabled="cupomCarregando || !!order" aria-label="Tirar o cupom"
                        class="absolute right-2 top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-full text-slate-500 hover:bg-slate-100 hover:text-red-600 disabled:opacity-50"
                        @click="limparCupom">
                        <i class="fa-solid" :class="cupomCarregando ? 'fa-spinner animate-spin' : 'fa-xmark'"></i>
                    </button>
                </label>
                <!-- Botão com cara de botão (pedido do Lira 2026-11-23): as pessoas focavam no campo e não
                     viam o "Aplicar". Laranja de destaque, texto "Aplicar cupom", mão no hover, foco visível
                     e "Aplicando…" enquanto confere. Enter no campo aplica também (submit do form). -->
                <button v-if="!cupomAplicado" type="submit" data-aplicar-cupom :disabled="cupomCarregando || !!order" :aria-busy="cupomCarregando"
                    class="inline-flex shrink-0 cursor-pointer items-center justify-center gap-1.5 whitespace-nowrap rounded-full bg-[#C2410C] px-4 py-2.5 text-sm font-bold text-white shadow-[0_3px_10px_rgba(194,65,12,0.30)] transition hover:bg-[#9A3412] active:translate-y-px focus:outline-none focus-visible:ring-2 focus-visible:ring-[#C2410C] focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60">
                    <i class="fa-solid" :class="cupomCarregando ? 'fa-spinner animate-spin' : 'fa-tag'" aria-hidden="true"></i>
                    {{ cupomCarregando ? 'Aplicando…' : 'Aplicar cupom' }}
                </button>
            </form>
            <!-- Dica enquanto digita (2026-11-23). -->
            <p id="cupom-dica" class="ml-4 mt-1 text-[11px] text-slate-600" :class="{ 'sr-only': !(cupom.trim() && !cupomAplicado && !cupomCarregando && !cupomErro) }">
                <template v-if="cupom.trim() && !cupomAplicado && !cupomCarregando && !cupomErro"><i class="fa-solid fa-hand-pointer mr-1 text-[#C2410C]" aria-hidden="true"></i>Toque em <strong>Aplicar cupom</strong> para usar o desconto</template>
            </p>
            <p id="cupom-status" aria-live="polite" class="text-xs" :class="cupomEstado === 'erro' ? 'mt-1 text-red-600' : 'text-[#00843f]'">
                <template v-if="cupomEstado === 'erro'">
                    <span class="block font-semibold"><i class="fa-solid fa-circle-exclamation mr-1"></i>{{ cupomErro }}</span>
                    <span v-if="cupomErroDetalhe && cupomErroDetalhe !== cupomErro" class="block text-[11px] text-red-500">{{ cupomErroDetalhe }}</span>
                </template>
                <template v-else-if="cupomEstado === 'aplicado'"><span class="mt-1 block font-semibold"><i class="fa-solid fa-circle-check mr-1"></i>Cupom {{ cupomAplicado }} aplicado!<template v-if="descontoCupom > 0"> Desconto de {{ formatPrice(descontoCupom) }}</template></span></template>
                <template v-else-if="cupomEstado === 'removendo'"><span class="mt-1 block text-slate-500">Tirando o cupom…</span></template>
            </p>
        </Teleport>

        <!-- Computador, a partir de 1024px (pedido 2026-10-24, mesmo padrão nas 3 lojas): duas
             colunas. Esquerda: Identificação e Entrega lado a lado (50/50) e o Pagamento embaixo,
             na largura toda. Direita: Resumo da compra, Itens do pedido, avaliações e selos
             empilhados. Os blocos de dentro do main viram "contents" para entrar na grade dele. -->
        <div class="mx-auto grid w-full max-w-[1320px] grid-cols-1 gap-5 px-3 py-5 md:px-6 lg:grid-cols-[minmax(0,1fr)_340px] lg:items-start lg:gap-6 lg:py-9 xl:grid-cols-[minmax(0,1fr)_400px]">
            <!-- ETAPAS -->
            <!-- A linha do aviso de erro só entra na grade com erro, senão sobra o espaço dela no topo. -->
            <!-- Com as duas etapas fechadas (no Pagamento), Identificação e Entrega esticam até a mesma altura. -->
            <main class="order-2 min-w-0 lg:order-1 lg:grid lg:grid-cols-2 lg:gap-6"
                :class="[reenvio || erroGeral || serverErrors.payment || serverErrors.cart ? areasComErro : areasSemErro, etapa === 3 ? 'lg:items-stretch' : 'lg:items-start']">
                <div v-if="reenvio" role="status" aria-live="polite" class="mb-4 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 lg:mb-0 lg:[grid-area:erro]">
                    <i class="fa-solid fa-spinner mt-0.5 animate-spin"></i>
                    <div>
                        <strong>O sistema do banco está instável neste momento.</strong>
                        Estamos tentando de novo automaticamente (tentativa {{ reenvio.n }} de {{ reenvio.total }}). Não feche esta página: seu pedido não será cobrado duas vezes.
                    </div>
                </div>
                <div v-else-if="erroGeral || serverErrors.payment || serverErrors.cart" role="alert" class="mb-4 flex items-start gap-3 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 lg:mb-0 lg:[grid-area:erro]">
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
                                    <i class="fa-solid text-xs" :class="icone" aria-hidden="true"></i>{{ rotulo }}
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
                                        <input :value="pessoa.phone" data-medir="celular" type="tel" inputmode="numeric" autocomplete="tel" :class="[inputClass, erro('new_address.phone') && inputErroClass]" placeholder="WhatsApp/Celular" @input="aoDigitarCelular($event.target.value)">
                                        <small v-if="erro('new_address.phone')" class="ml-4 text-xs text-red-600">{{ erro('new_address.phone') }}</small>
                                    </label>
                                </div>
                            </div>
                            <button type="button" :class="[botaoVerde, 'mt-5']" @click="irParaEntrega">Ir para Entrega</button>
                            <p class="mt-3 text-center text-xs text-slate-500">
                                Ao prosseguir com a compra, você concorda com as
                                <Link href="/politica-de-privacidade" target="_blank" class="font-bold text-slate-700 hover:underline">Políticas de Privacidade</Link>
                                e passa a receber o <Link href="/termos-de-uso#newsletter" target="_blank" class="font-bold text-slate-700 hover:underline">Caderno KazaKora</Link> por e-mail (sai quando quiser)
                            </p>
                        </template>

                        <div v-else class="mt-3 flex items-start justify-between gap-3 rounded-2xl bg-[#F6F7F8] p-4 text-sm text-slate-600 lg:flex-1">
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

                                <!-- Vários itens: recalcula com todos na mesma caixa antes de pagar (2026-10-12). -->
                                <div v-if="freteCarregando && totalItens > 1" role="status" class="mb-3 flex items-center gap-3 rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
                                    <i class="fa-solid fa-box-open fa-bounce text-lg text-sky-700"></i>
                                    <span>Seus <strong>{{ totalItens }} itens</strong> vão juntos na mesma caixa. Recalculando o frete para você pagar menos…</span>
                                </div>
                                <div v-else-if="descontoPlataforma > 0" role="status" class="mb-3 flex items-center gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                                    <i class="fa-solid fa-box text-lg text-emerald-700"></i>
                                    <span>Seus {{ totalItens }} itens vão na mesma caixa e o frete ficou mais barato: <strong>{{ formatPrice(descontoPlataforma) }} de desconto</strong> já aplicado no total.</span>
                                </div>

                                <div v-if="diferencaFrete > 0 && !freteCarregando" role="alert" data-testid="aviso-frete-outra-regiao" class="mb-3 flex items-start gap-3 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                    <i class="fa-solid fa-triangle-exclamation mt-0.5 text-lg text-amber-600"></i>
                                    <span>O frete grátis cobre a região em que você navegou. Para este CEP há <strong>{{ formatPrice(diferencaFrete) }}</strong> de diferença no frete, já somada no total.</span>
                                </div>

                                <!-- Grátis (já no preço) ou Entrega Expressa (2026-10-12; nomes de 2026-11-24): uma exclui a outra; a tela nasce no grátis. -->
                                <label class="flex cursor-pointer items-center gap-3 rounded-2xl border p-3.5 transition"
                                    :class="!expressaEscolhida || !expressa ? 'border-emerald-500 bg-emerald-50/60 ring-1 ring-emerald-500' : 'border-slate-200 bg-white hover:border-slate-300'">
                                    <input type="checkbox" class="h-4 w-4 accent-emerald-600" :checked="!expressaEscolhida || !expressa" @change="$event.target.checked = true; expressaEscolhida = false">
                                    <span class="flex-1 text-sm text-slate-700">
                                        <strong class="text-slate-900"><i class="fa-solid fa-truck-fast text-emerald-700"></i> {{ opcaoGratis?.nome ?? 'Frete Grátis' }}</strong>
                                        <span v-if="opcaoGratis?.chega" class="ml-1 text-slate-500">· {{ opcaoGratis.chega }}</span>
                                    </span>
                                    <span class="text-sm font-bold" :class="diferencaFrete > 0 ? 'text-slate-900' : 'text-emerald-700'">{{ diferencaFrete > 0 ? `+ ${formatPrice(diferencaFrete)}` : 'Grátis' }}</span>
                                </label>

                                <!-- Entrega Expressa (o antigo Envio Prioritário): motoboy na Grande SP pelo preço fixo ou SEDEX pagando só a diferença. Escolhida = laranja do tema. -->
                                <label v-if="expressa"
                                    class="mt-2 flex cursor-pointer items-center gap-3 rounded-2xl border p-3.5 transition"
                                    :class="expressaEscolhida ? 'border-[#F44D00] bg-orange-50 ring-1 ring-[#F44D00]' : 'border-slate-200 bg-white hover:border-slate-300'">
                                    <input type="checkbox" class="h-4 w-4 accent-[#F44D00]" :checked="expressaEscolhida" @change="expressaEscolhida = $event.target.checked">
                                    <span class="flex-1 text-sm text-slate-700">
                                        <strong :class="expressaEscolhida ? 'text-[#c2450c]' : 'text-slate-900'"><i class="fa-solid text-[#F44D00]" :class="expressa.tipo === 'motoboy' ? 'fa-motorcycle' : 'fa-truck-fast'"></i> {{ expressa.nome }}</strong>
                                        <span class="ml-1 text-slate-500">· {{ expressa.chega }}</span>
                                    </span>
                                    <span class="text-sm font-bold" :class="expressaEscolhida ? 'text-[#c2450c]' : 'text-slate-900'">{{ precoExpressa }}</span>
                                </label>
                                <small v-if="erro('shipping_method_id')" class="mt-1 block text-xs text-red-600">{{ erro('shipping_method_id') }}</small>
                            </div>

                            <button type="button" :disabled="salvandoEntrega || freteCarregando" :class="[botaoVerde, 'mt-5']" @click="irParaPagamento">
                                <i v-if="salvandoEntrega" class="fa-solid fa-spinner animate-spin"></i>
                                {{ salvandoEntrega ? 'Salvando...' : 'Ir para Pagamento' }}
                            </button>
                            <!-- Aceite dos termos: foi para o CardConfianca, em frase curta (pedido do Lira 2026-11-24). -->
                        </template>

                        <!-- Três linhas como a Identificação (2026-10-24): no computador a cidade fica numa linha só, com o endereço inteiro no title. -->
                        <div v-else class="mt-3 flex items-start justify-between gap-3 rounded-2xl bg-[#F6F7F8] p-4 text-sm text-slate-600 lg:flex-1">
                            <div class="flex min-w-0 items-start gap-1.5 break-words">
                                <i class="fa-solid fa-circle-check mt-1 text-[#00843f]"></i>
                                <div class="min-w-0">
                                    <p v-if="enderecoResumo" class="font-semibold text-slate-900 lg:truncate" :title="enderecoResumo.rua">{{ enderecoResumo.rua }}</p>
                                    <p v-if="enderecoResumo" class="lg:truncate" :title="enderecoResumo.cidade">{{ enderecoResumo.cidade }}</p>
                                    <p class="mt-1 font-semibold text-slate-700 lg:mt-0">
                                        <template v-if="expressaEscolhida && expressa">{{ expressa.nome }}: {{ precoExpressa }} · {{ expressa.chega }}</template>
                                        <template v-else>{{ diferencaFrete > 0 ? `Frete ${formatPrice(diferencaFrete)}` : 'Frete GRÁTIS' }}<template v-if="opcaoGratis"> · {{ opcaoGratis.nome }} · {{ opcaoGratis.chega }}</template></template>
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

                            <!-- Ordem e nomes iguais nos 3 sites (pedido do Lira 2026-10-14): Pix, Cartão de Crédito, Boleto Bancário.
                                 Desde 2026-11-24 são 3 cards lado a lado (também no celular), para o botão de finalizar aparecer
                                 sem rolar. O texto longo de cada forma foi para o quadro de detalhe abaixo. O selo do desconto
                                 segue o da FerraKora (verde #00843f, letra branca), agora redondo no canto do card. -->
                            <div class="mt-4 grid grid-cols-3 gap-2" role="group" aria-label="Forma de pagamento">
                                <button v-for="opcao in opcoesPagamento" :key="opcao.id" type="button"
                                    class="relative flex min-w-0 flex-col items-center justify-start rounded-2xl border-2 px-1.5 pb-2.5 pt-3 text-center transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2"
                                    :class="metodo === opcao.id ? 'border-emerald-500 bg-emerald-50 shadow-[0_2px_8px_rgba(16,185,129,0.25)]' : 'border-slate-200 bg-white hover:border-slate-300'"
                                    :aria-pressed="metodo === opcao.id" @click="metodo = opcao.id">
                                    <span class="absolute left-1.5 top-1.5 flex h-4 w-4 items-center justify-center rounded-full border-2" :class="metodo === opcao.id ? 'border-emerald-600' : 'border-slate-300'" aria-hidden="true"><span v-if="metodo === opcao.id" class="h-2 w-2 rounded-full bg-emerald-600"></span></span>
                                    <span v-if="opcao.selo" class="absolute -right-2 -top-2 z-10 flex h-9 min-w-9 items-center justify-center rounded-full bg-[#00843f] px-1 text-[11px] shadow-sm ring-2 ring-white font-extrabold leading-none tracking-tight text-white">{{ opcao.selo }}<span class="sr-only"> de desconto</span></span>
                                    <i :class="[opcao.icone, 'mt-1 text-2xl']" aria-hidden="true"></i>
                                    <span class="mt-1.5 flex min-h-[2.25rem] items-center text-[13px] font-bold leading-tight text-slate-900">{{ opcao.nome }}</span>
                                    <span class="mt-0.5 block w-full text-balance break-words text-[11px] font-semibold leading-tight" :class="opcao.destaque ? 'font-extrabold text-[#00843f]' : 'text-slate-500'">{{ opcao.linha }}</span>
                                </button>
                            </div>

                            <div v-if="metodo === 'pix'" class="mt-4 rounded-2xl bg-emerald-50 p-4 text-sm text-emerald-900">
                                <p v-if="descontoPix > 0" class="flex items-center gap-2 font-bold text-emerald-700">
                                    <i class="fa-solid fa-circle-check"></i> Desconto de {{ pixPercentualTexto }}% ativado: você economiza {{ formatPrice(descontoPix) }}
                                </p>
                                <p v-if="pixDiscountPercentage > 0" class="mt-1 font-semibold text-emerald-800">Total no Pix: {{ formatPrice(totalNoPix) }}</p>
                                <ul class="mt-2 space-y-1 text-emerald-800">
                                    <li><i class="fa-solid fa-qrcode mr-2 w-4"></i>O QR Code aparece assim que você finalizar.</li>
                                    <li><i class="fa-solid fa-mobile-screen mr-2 w-4"></i>Pague pelo app do seu banco, copiando o código ou lendo o QR.</li>
                                    <li><i class="fa-solid fa-check mr-2 w-4"></i>Aprovação em segundos e envio mais rápido.</li>
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
                                <!-- Texto que saiu do card do cartão (2026-11-24). Sem valor da parcela: o Mercado Pago cobra juros
                                     a partir de 2x e o valor real só sai com o cartão digitado. Desde 2026-11-06 os juros são do cliente. -->
                                <p class="mb-3 text-center text-xs text-slate-600">Aprovação imediata · à vista sem juros ou até 12x com juros do cartão</p>
                                <!-- Prévia do cartão (modelo Yampi, cartão black): bandeira e 6 primeiros dígitos, que é o que o Mercado Pago libera. -->
                                <CartaoPrevia :bin="cartaoBin" />
                                <!-- O Mercado Pago só mostra as parcelas depois de reconhecer o cartão pelos primeiros dígitos. -->
                                <p v-if="brickPronto && cartaoBin.length < 6" class="mb-3 rounded-lg bg-slate-50 px-3 py-2 text-center text-xs text-slate-600"><i class="fa-solid fa-credit-card mr-1"></i>Digite o número do cartão para escolher em quantas parcelas pagar (até 12x).</p>
                                <!-- Juros pelo cliente (pedido 2026-11-06): o valor de cada parcela e o total com juros aparecem no seletor do Mercado Pago. -->
                                <!-- Aviso laranja dos juros retirado a pedido do Lira (2026-11-24); os juros aparecem no seletor de parcelas do Mercado Pago. -->
                                <div id="cartao-brick-v2"></div>
                                <p v-if="!brickPronto && !brickErro" class="py-6 text-center text-sm text-slate-500"><i class="fa-solid fa-spinner mr-2 animate-spin"></i>Carregando ambiente seguro do cartão...</p>
                                <div v-if="brickErro" role="alert" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm font-medium text-red-600">
                                    <p>{{ brickErro }}</p>
                                    <button type="button" class="mt-2 rounded-lg border border-[#00843f] bg-white px-3 py-1.5 text-xs font-bold text-[#00843f] hover:bg-emerald-50" @click="metodo = 'pix'; erroGeral = null">
                                        <i class="fa-brands fa-pix mr-1" aria-hidden="true"></i>Pagar no Pix<template v-if="pixDiscountPercentage > 0"> com {{ pixPercentualTexto }}% de desconto</template>
                                    </button>
                                </div>
                            </div>

                            <button id="finalizar-pedido" type="button" :disabled="processando || freteCarregando || !!order || (metodo === 'card' && !brickPronto)"
                                :class="[botaoVerde, 'mt-5 text-xl']" @click="finalizar">
                                <i class="fa-solid" :class="processando ? 'fa-spinner animate-spin' : 'fa-lock'"></i>
                                {{ reenvio ? `Tentando de novo (${reenvio.n} de ${reenvio.total})...` : processando ? 'Processando...' : 'Finalizar pedido' }}
                            </button>
                            <!-- Selo do Mercado Pago (pedido do Lira 2026-11-23): o cartão é processado pelo Brick deles.
                                 Símbolo oficial (mlstatic, o mesmo das páginas do Mercado Pago), com folga em volta (área
                                 de proteção). Só aparece quando o Mercado Pago é o gateway ativo, para ser sempre verdade. -->
                            <!-- Selo do Mercado Pago e aceite dos termos: foram para o CardConfianca (pedido do Lira 2026-11-24). -->
                        </div>
                    </section>

                    <!-- Celular: avaliações debaixo das etapas, para não empurrar o formulário para baixo do resumo (2026-10-24).
                         Ocultas e de volta no mesmo dia (2026-11-23), agora paradas e com bullets. -->
                    <!-- Depoimentos comentados a pedido do Lira (2026-11-24), KazaKora e FerraKora.
                    <CarrosselAvaliacoes :avaliacoes="avaliacoesCarrossel" class="lg:hidden" /> -->
                    <CardConfianca :mercado-pago="paymentGateway === 'mercadopago'" :nota-fiscal="CHECKS_LOJA[0]" class="mt-4 lg:hidden" />
                    </div>
                </div>
            </main>

            <!-- RESUMO DA COMPRA (no computador, coluna da direita: valores e itens em dois cartões) -->
            <aside id="resumo-compra" class="order-1 lg:order-2 lg:flex lg:flex-col lg:gap-6" :class="{ 'hidden lg:flex': !resumoAberto }">
                <div class="rounded-[24px] bg-white p-5 shadow-[0_4px_24px_rgba(15,23,42,0.07)] md:p-6 lg:contents">
                    <!-- Computador: cupom, Pix e totais num cartão no topo da coluna da direita. -->
                    <div class="lg:rounded-[24px] lg:bg-white lg:p-6 lg:shadow-[0_4px_24px_rgba(15,23,42,0.07)]">
                        <h2 class="border-b border-slate-100 pb-3 text-base font-extrabold text-slate-900">Resumo da compra</h2>

                        <!-- Computador: o campo do cupom (Teleport no topo da página) fica aqui, sempre à mostra. -->
                        <div id="cupom-computador" class="mt-4 hidden lg:block"></div>

                        <dl class="mt-4 space-y-2 text-sm text-slate-600">
                            <div class="flex justify-between"><dt>Produtos ({{ totalProdutosResumo }})</dt><dd>{{ formatPrice(subtotalAtual) }}</dd></div>
                            <div v-if="descontoCupom > 0" class="flex justify-between text-[#00843f]"><dt>Cupom {{ cupomAplicado }}</dt><dd>-{{ formatPrice(descontoCupom) }}</dd></div>
                            <div v-if="descontoPlataforma > 0 && !order" class="flex justify-between text-[#00843f]"><dt>Desconto da plataforma</dt><dd>-{{ formatPrice(descontoPlataforma) }}</dd></div>
                            <div v-if="descontoPix > 0 && !order" class="flex justify-between text-[#00843f]"><dt>Desconto Pix ({{ pixPercentualTexto }}%)</dt><dd>-{{ formatPrice(descontoPix) }}</dd></div>
                            <div class="flex justify-between" data-testid="linha-frete"><dt>{{ valorFrete > 0 ? 'Frete' : 'Entrega' }}<span v-if="expressaEscolhida && expressa" class="ml-1 text-xs font-semibold text-[#c2450c]">Expressa</span></dt><dd :class="valorFrete === 0 ? 'font-semibold text-[#00843f]' : ''">{{ entregaEscolhida ? (valorFrete === 0 ? 'Grátis' : formatPrice(valorFrete)) : '-' }}</dd></div>
                            <div class="flex items-start justify-between border-t border-slate-100 pt-3 text-slate-900">
                                <dt class="text-base font-extrabold">Total</dt>
                                <dd class="text-right">
                                    <span class="block text-xl font-extrabold">{{ formatPrice(totalAPagar) }}</span>
                                    <span v-if="!order" class="block text-xs font-semibold text-slate-600">ou até 12x com juros no cartão</span>
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
                            <!-- Nota com estrelas e total de avaliações oculto a pedido do Lira em 2026-11-23 (não apagar; o
                                 avaliacaoItens continua vindo do servidor, só não aparece na tela).
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
                            -->
                        </div>

                        <!-- Selos Loja verificada e Compra segura + 3 checks verdes (padrão KazaKora, #974).
                             Só a frase da nota fiscal fica (pedido do Lira 2026-11-23, à noite): os selos estão
                             comentados abaixo, sem apagar, e frete grátis e devolução em CHECKS_LOJA.
                        <ul class="flex flex-wrap gap-x-3.5 gap-y-1.5 text-[13px] font-semibold text-slate-800" aria-label="Selos de verificação">
                            <li v-for="[rotulo, dica] in SELOS_VERIFICADOS" :key="rotulo" :title="dica" class="inline-flex items-center gap-1.5">
                                <svg class="h-[18px] w-[18px] fill-[#1d9bf0] stroke-white" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M12 2l2.4 1.8 3-.1.9 2.9 2.4 1.8-1 2.8 1 2.8-2.4 1.8-.9 2.9-3-.1L12 22l-2.4-1.8-3 .1-.9-2.9-2.4-1.8 1-2.8-1-2.8 2.4-1.8.9-2.9 3 .1zM8.5 12l2.5 2.5 4.5-5" />
                                </svg>
                                {{ rotulo }}
                            </li>
                        </ul>
                        -->
                        <!-- Frase da nota fiscal: foi para o CardConfianca, debaixo do resumo (pedido do Lira 2026-11-24). -->
                    </div>
                </div>

                <!-- Carrossel de avaliações reais (modelo Yampi). No celular fica debaixo das etapas (2026-10-24).
                     Ocultas e de volta no mesmo dia (2026-11-23), agora paradas e com bullets. -->
                <!-- Depoimentos comentados a pedido do Lira (2026-11-24), KazaKora e FerraKora.
                <CarrosselAvaliacoes :avaliacoes="avaliacoesCarrossel" class="mt-4 hidden lg:block lg:mt-0" /> -->
                <CardConfianca :mercado-pago="paymentGateway === 'mercadopago'" :nota-fiscal="CHECKS_LOJA[0]" class="mt-4 hidden lg:mt-0 lg:block" />
            </aside>
        </div>

        <!-- Confira seu WhatsApp: DDD 55 com CEP de fora da área do DDD 55 (pedido do Lira 2026-11-23). -->
        <ConfirmarWhatsappModal :open="alertaWhatsapp.aberto && estado === 'idle'" :atual="alertaWhatsapp.atual" :sugestao="alertaWhatsapp.sugestao"
            @confirmar="fecharAlertaWhatsapp" @corrigir="fecharAlertaWhatsapp" />

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
