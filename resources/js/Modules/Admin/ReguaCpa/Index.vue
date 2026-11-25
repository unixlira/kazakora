<script setup>
/**
 * Publicidade › Régua de CPA (pedido do Lira em 2026-11-25). Clone da
 * calculadora "Régua de CPA" adaptado à loja: escolhe um produto do catálogo
 * (custo e preço de cadastro), soma o frete que a loja paga e os insumos, e
 * usa as taxas oficiais (cartão e Pix do gateway, Simples). Calcula:
 *   1. preço de venda (markup e final comercial, ou o preço do produto);
 *   2. CPA máximo = preço − custo − taxas − imposto (0 a 0) e CPA alvo = metade;
 *   3. quanto cada carrinho pode custar (alvo ÷ 3 ótimo, ÷ 2 bom);
 *   4. plano de teste: criativos × R$/dia × dias (mínimo da Meta R$ 6/dia);
 *   5. diagnóstico de um criativo (gasto, cliques, carrinhos, vendas).
 * Nada vai para o banco; o que se digita fica só neste navegador.
 */
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    produtos: { type: Array, default: () => [] },
    taxas: { type: Object, required: true },
});

const MIN_DIA = 6;
const CHAVE = 'admin-regua-cpa-v1';
const BRL = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
const fmt = (v) => BRL.format(Number.isFinite(v) ? v : 0);
const pct = (v) => `${(Math.round(v * 10) / 10).toLocaleString('pt-BR')}%`;
const num = (s) => {
    if (s === null || s === undefined) return NaN;
    let t = String(s).replace(/R\$|\s|%/g, '');
    if (t.includes(',')) t = t.replace(/\./g, '').replace(',', '.');
    const n = parseFloat(t);
    return Number.isNaN(n) ? NaN : n;
};
const br = (v) => String(Math.round(v * 100) / 100).replace('.', ',');
const limitar = (v, a, b) => Math.max(a, Math.min(b, v));

const PRESETS = {
    loja: {
        nome: 'Site próprio (KazaKora / FerraKora)',
        dica: `Taxas oficiais da loja: cartão ${br(props.taxas.cartao)}%, Pix ${br(props.taxas.pix)}%, boleto R$ ${br(props.taxas.boleto_fixo)} e Simples ${br(props.taxas.imposto)}%.`,
        valores: { plat: 0, imposto: props.taxas.imposto, cartao: props.taxas.cartao, cartaoFixo: 0, pix: props.taxas.pix, boleto: props.taxas.boleto_fixo },
    },
    proprio: { nome: 'Personalizado', dica: 'Preencha as suas taxas em "Ajustar taxas".', valores: null },
};

const padrao = () => ({
    produtoId: '',
    custoProduto: '30,00',
    frete: br(props.taxas.frete),
    insumos: br(props.taxas.insumos),
    markupModo: '3',
    markupOutro: '3,5',
    final: '90',
    escolhido: null,
    precoManual: '',
    preset: 'loja',
    mixCartao: '70',
    mixPix: '25',
    plat: '0',
    imposto: br(props.taxas.imposto),
    cartao: br(props.taxas.cartao),
    cartaoFixo: '0',
    pix: br(props.taxas.pix),
    boleto: br(props.taxas.boleto_fixo),
    carrinhos: 3,
    criativos: 3,
    diasEscolhidos: null,
    dGasto: '12,00',
    dCliques: '30',
    dCarrinhos: '2',
    dVendas: '0',
});

const f = reactive(padrao());
try {
    const salvo = JSON.parse(localStorage.getItem(CHAVE) || 'null');
    if (salvo && typeof salvo === 'object') Object.assign(f, salvo);
} catch (e) { /* sem armazenamento: começa do padrão */ }
watch(f, () => { try { localStorage.setItem(CHAVE, JSON.stringify(f)); } catch (e) { /* ignora */ } }, { deep: true });

const taxasAbertas = ref(f.preset === 'proprio');
const busca = ref('');

function aplicarPreset() {
    const p = PRESETS[f.preset];
    if (!p.valores) { taxasAbertas.value = true; return; }
    f.plat = br(p.valores.plat); f.imposto = br(p.valores.imposto); f.cartao = br(p.valores.cartao);
    f.cartaoFixo = br(p.valores.cartaoFixo); f.pix = br(p.valores.pix); f.boleto = br(p.valores.boleto);
}

const produtosFiltrados = computed(() => {
    const t = busca.value.trim().toLowerCase();
    const lista = t ? props.produtos.filter((p) => p.nome.toLowerCase().includes(t) || String(p.id) === t) : props.produtos;
    return lista.slice(0, 200);
});
const produto = computed(() => props.produtos.find((p) => String(p.id) === String(f.produtoId)) || null);

function escolherProduto() {
    const p = produto.value;
    if (!p) return;
    f.custoProduto = br(p.custo);
    f.precoManual = br(p.preco);
    f.escolhido = null;
    f.diasEscolhidos = null;
}

const mixBoleto = computed(() => {
    const mc = limitar(num(f.mixCartao) || 0, 0, 100);
    const mp = limitar(num(f.mixPix) || 0, 0, 100 - mc);
    return 100 - mc - mp;
});

// ---------- preço comercial ----------
const passo = (p) => (p < 60 ? 5 : p < 300 ? 10 : p < 1000 ? 50 : 100);
const sobra = (e) => (e === '99' ? 0.01 : e === '97' ? 0.03 : e === 'int' ? 1 : 0.1);
function candidatos(p, e) {
    const st = passo(p); const off = sobra(e); const out = []; const n0 = Math.floor(p / st) - 2;
    for (let n = n0; n <= n0 + 6; n++) {
        const c = Math.round((n * st - off) * 100) / 100;
        if (c > 0.5 && !out.includes(c)) out.push(c);
    }
    return out.sort((a, b) => a - b);
}
const indicePadrao = (p, c) => { const i = c.findIndex((x) => x >= p * 0.985); return i < 0 ? c.length - 1 : i; };

function taxasSobre(P) {
    const mc = limitar(num(f.mixCartao) || 0, 0, 100);
    const mp = limitar(num(f.mixPix) || 0, 0, 100 - mc);
    const mb = 100 - mc - mp;
    const plat = (num(f.plat) || 0) / 100; const imp = (num(f.imposto) || 0) / 100;
    const card = (num(f.cartao) || 0) / 100; const cardFix = num(f.cartaoFixo) || 0;
    const pix = (num(f.pix) || 0) / 100; const bol = num(f.boleto) || 0;
    const pag = P * ((mc / 100) * card + (mp / 100) * pix) + (mc / 100) * cardFix + (mb / 100) * bol;
    return { plat: P * plat, pag, imposto: P * imp, total: P * plat + pag + P * imp };
}
const diasMinimos = (P) => (P < 100 ? 3 : P < 150 ? 4 : P <= 200 ? 5 : 6);
const porDia = (L, d) => Math.max(MIN_DIA, Math.floor((L / d) * 2) / 2);

const r = computed(() => {
    const custoProduto = Math.max(0, num(f.custoProduto) || 0);
    const frete = Math.max(0, num(f.frete) || 0);
    const insumos = Math.max(0, num(f.insumos) || 0);
    const C = custoProduto + frete + insumos;
    const m = f.markupModo === 'outro' ? (num(f.markupOutro) || 0) : Number(f.markupModo);
    const P0 = custoProduto * m;
    const cands = candidatos(P0, f.final);
    const di = indicePadrao(P0, cands);
    const manual = num(f.precoManual);
    let P;
    if (manual > 0) P = manual;
    else if (f.escolhido !== null && cands.includes(f.escolhido)) P = f.escolhido;
    else P = cands[di] || P0;
    const chips = [di - 1, di, di + 1, di + 2].filter((i) => i >= 0 && i < cands.length).map((i) => cands[i]);

    const T = taxasSobre(P);
    const L = P - C - T.total;
    const alvo = L / 2;
    const ok = L > 0.5;
    const z1 = alvo / 3; const z2 = alvo / 2;

    const partes = [
        { cls: 'produto', nome: 'Produto', v: custoProduto },
        { cls: 'frete', nome: 'Frete + insumos', v: frete + insumos },
        { cls: 'taxa', nome: 'Taxas do pagamento', v: T.plat + T.pag },
    ];
    if (T.imposto > 0) partes.push({ cls: 'imposto', nome: 'Imposto', v: T.imposto });
    if (ok) { partes.push({ cls: 'ads', nome: 'Anúncio (CPA alvo)', v: alvo }); partes.push({ cls: 'lucro', nome: 'Seu lucro', v: alvo }); }
    const tot = partes.reduce((s, x) => s + Math.max(0, x.v), 0) || 1;
    partes.forEach((x) => { x.w = (Math.max(0, x.v) / tot) * 100; });

    const md = diasMinimos(P);
    const cabe = Math.floor(Math.max(0, L) / MIN_DIA);
    const maxOpc = Math.max(3, Math.min(10, Math.max(md, cabe)));
    const opcoes = [];
    for (let d = 3; d <= maxOpc; d++) if (d === 3 || L / d >= MIN_DIA) opcoes.push(d);
    const sugerido = Math.min(md, opcoes[opcoes.length - 1]);
    const dias = f.diasEscolhidos && opcoes.includes(f.diasEscolhidos) ? f.diasEscolhidos : sugerido;
    const pc = porDia(L, dias);
    const baixo = ok && L < MIN_DIA * 3;
    const limiteDia = baixo ? Math.ceil(L / MIN_DIA) : dias;
    const linha = [];
    for (let i = 1; i <= dias; i++) {
        const acum = pc * i; const ultimo = i === limiteDia;
        linha.push({
            dia: i, acum, ultimo, primeiro: i === 1,
            barra: ok ? Math.min(100, (acum / L) * 100) : 0,
            meta: i === 1 ? 'Pode não vir carrinho. Normal.' : ultimo ? 'Limite. Sem venda, pausa.' : i > limiteDia ? 'Só se veio carrinho todo dia.' : 'Meta: 1+ carrinho ou custo caindo.',
        });
    }

    return { custoProduto, frete, insumos, C, m, P0, P, manual: manual > 0, chips, T, L, alvo, ok, z1, z2, partes, opcoes, sugerido, dias, pc, baixo, linha };
});

const zonas = computed(() => {
    const { alvo, z1, z2 } = r.value;
    const topo = alvo * 1.25 || 1;
    const w = (a, b) => Math.max(0, ((b - a) / topo) * 100);
    return [
        { cls: 'z1', nome: 'Ótimo', txt: `até ${fmt(z1)}`, w: w(0, z1), desc: '3 carrinhos cabem no CPA alvo.' },
        { cls: 'z2', nome: 'Bom', txt: `até ${fmt(z2)}`, w: w(z1, z2), desc: '2 carrinhos cabem no CPA alvo.' },
        { cls: 'z3', nome: 'Atenção', txt: `até ${fmt(alvo)}`, w: w(z2, alvo), desc: 'A venda vai sair perto do CPA máximo.' },
        { cls: 'z4', nome: 'Ruim', txt: `${fmt(alvo)}+`, w: null, desc: '2 carrinhos já dão o zero a zero.' },
    ];
});

const veredito = computed(() => {
    const { L, alvo, z1, z2 } = r.value;
    const metaCarrinho = alvo / f.carrinhos;
    const g = num(f.dGasto) || 0; const cl = num(f.dCliques) || 0; const ca = num(f.dCarrinhos) || 0; const v = num(f.dVendas) || 0;
    const cpc = cl > 0 ? g / cl : null; const custoCarr = ca > 0 ? g / ca : null; const cpa = v > 0 ? g / v : null;
    let cls = ''; let tag = ''; let titulo = ''; let texto = '';
    if (!(L > 0)) { cls = 'ruim'; tag = 'Sem margem'; titulo = 'Ajuste o preço primeiro'; texto = 'Com o preço atual não sobra verba para anúncio.'; }
    else if (v > 0) {
        if (cpa <= alvo) { cls = 'bom'; tag = 'Bom resultado'; titulo = 'Vendendo dentro do CPA alvo'; texto = `CPA de ${fmt(cpa)}. Mantenha e suba o orçamento aos poucos (+20%).`; }
        else if (cpa <= L) { cls = 'atencao'; tag = 'No lucro, apertado'; titulo = 'Vendeu acima do CPA alvo'; texto = `CPA de ${fmt(cpa)}, abaixo do máximo de ${fmt(L)}. Segure o orçamento e teste outro criativo.`; }
        else { cls = 'ruim'; tag = 'Prejuízo'; titulo = 'CPA acima do máximo'; texto = `Cada venda custa ${fmt(cpa)} e o máximo é ${fmt(L)}. Pause esse criativo.`; }
    } else if (g >= L) { cls = 'ruim'; tag = 'Pausar'; titulo = 'Bateu o CPA máximo sem venda'; texto = `Gastou ${fmt(g)} e o limite era ${fmt(L)}. Pause esse criativo.`; }
    else if (ca > 0) {
        if (custoCarr <= z1) { cls = 'bom'; tag = 'Sinal ótimo'; titulo = 'Carrinho muito barato'; texto = `Cada carrinho custa ${fmt(custoCarr)}. A venda deve sair. Continue até ${fmt(L)}.`; }
        else if (custoCarr <= z2) { cls = 'bom'; tag = 'Sinal bom'; titulo = 'Carrinho dentro da meta'; texto = `Cada carrinho custa ${fmt(custoCarr)}. Continue rodando.`; }
        else if (custoCarr < alvo) { cls = 'atencao'; tag = 'Atenção'; titulo = 'Carrinho caro'; texto = `Cada carrinho custa ${fmt(custoCarr)}. Deixe rodar até o limite, sem subir orçamento.`; }
        else { cls = 'ruim'; tag = 'Sinal ruim'; titulo = 'Carrinho no valor do CPA alvo'; texto = `Cada carrinho custa ${fmt(custoCarr)}. Dois já dão zero a zero. Se seguir assim até ${fmt(L)}, pause.`; }
    } else if (g >= alvo) { cls = 'atencao'; tag = 'Sinal fraco'; titulo = 'Metade do limite e nenhum carrinho'; texto = `Olhe o CPC e a página do produto. Se chegar a ${fmt(L)} assim, pause.`; }
    else { tag = 'Cedo demais'; titulo = 'Ainda não dá para julgar'; texto = `Deixe a Meta otimizar. Pode gastar até ${fmt(L)} antes de decidir.`; }
    const usado = L > 0 ? Math.min(100, (g / L) * 100) : 0;
    const taxa = cl > 0 && ca > 0 ? ca / cl : 0.08;
    return { cls, tag, titulo, texto, usado, falta: Math.max(0, L - g), cpc, custoCarr, cpa, cpcTeto: metaCarrinho * taxa, taxa, taxaPropria: cl > 0 && ca > 0, metaCarrinho };
});

function escolherPreco(c) { f.escolhido = c; f.precoManual = ''; f.diasEscolhidos = null; }
function mudouMarkup(v) { f.markupModo = v; f.escolhido = null; f.diasEscolhidos = null; f.precoManual = ''; f.produtoId = ''; }
function mudouFinal(v) { f.final = v; f.escolhido = null; f.precoManual = ''; }
function escolherDias(d) { f.diasEscolhidos = d === r.value.sugerido ? null : d; }
function recomecar() { Object.assign(f, padrao()); busca.value = ''; }

const aviso = ref('');
const textoPlano = ref('');
function plano() {
    const x = r.value;
    const nome = produto.value ? `${produto.value.nome}\n` : '';
    return `PLANO DE TESTE · Régua de CPA\n${nome}`
        + `Preço: ${fmt(x.P)} (custo ${fmt(x.C)}, taxas e imposto ${fmt(x.T.total)})\n`
        + `CPA máximo: ${fmt(x.L)} · CPA alvo: ${fmt(x.alvo)}\n`
        + `Carrinho: ideal até ${fmt(x.z1)} · bom até ${fmt(x.z2)} · ruim acima de ${fmt(x.alvo)}\n`
        + `Teste: ${f.criativos} criativos × ${fmt(x.pc)}/dia × ${x.dias} dias = até ${fmt(x.pc * f.criativos * x.dias)}\n`
        + 'Meta diária: 1+ carrinho por criativo ou custo por carrinho caindo\n'
        + `Pausar o criativo que gastar ${fmt(x.L)} sem venda.`;
}
async function copiarPlano() {
    const t = plano();
    try {
        await navigator.clipboard.writeText(t);
        aviso.value = 'Plano copiado';
        setTimeout(() => { aviso.value = ''; }, 2200);
    } catch (e) {
        textoPlano.value = t;
    }
}
</script>

<template>
    <Head title="Régua de CPA" />

    <AdminLayout>
        <div class="regua">
            <header class="topo">
                <div>
                    <h1>Régua de CPA</h1>
                    <p>Escolha o produto ou digite o custo. A régua calcula o preço, quanto pagar por venda, quanto cada carrinho pode custar e o orçamento do teste.</p>
                </div>
                <div class="links">
                    <Link href="/admin/publicidade/meta-ads/campanhas">Campanhas Meta →</Link>
                    <button type="button" class="link" @click="recomecar">Recomeçar</button>
                </div>
            </header>

            <div class="grade">
                <aside class="painel" aria-label="Dados do produto">
                    <div class="grupo">
                        <label class="rotulo" for="rc-busca">Produto do catálogo</label>
                        <input id="rc-busca" v-model="busca" class="campo" type="search" placeholder="Buscar pelo nome ou id">
                        <select id="rc-produto" v-model="f.produtoId" class="campo" @change="escolherProduto">
                            <option value="">Sem produto (digitar o custo)</option>
                            <option v-for="p in produtosFiltrados" :key="p.id" :value="String(p.id)">#{{ p.id }} · {{ p.nome }} · {{ fmt(p.preco) }}</option>
                        </select>
                        <p v-if="produto" class="dica">{{ produto.loja }} · custo {{ fmt(produto.custo) }} · preço {{ fmt(produto.preco) }} (usado como preço de venda)</p>
                    </div>

                    <div class="grupo">
                        <span class="rotulo">Custo total por venda</span>
                        <div class="tres">
                            <div class="campinho"><label for="rc-custo">Produto</label><div class="dinheiro"><span>R$</span><input id="rc-custo" v-model="f.custoProduto" inputmode="decimal" @input="f.escolhido = null; f.diasEscolhidos = null"></div></div>
                            <div class="campinho"><label for="rc-frete">Frete grátis</label><div class="dinheiro"><span>R$</span><input id="rc-frete" v-model="f.frete" inputmode="decimal"></div></div>
                            <div class="campinho"><label for="rc-insumos">Insumos</label><div class="dinheiro"><span>R$</span><input id="rc-insumos" v-model="f.insumos" inputmode="decimal"></div></div>
                        </div>
                        <p class="dica">Total: <b>{{ fmt(r.C) }}</b>. Frete é o que a loja paga no frete grátis (média); insumos é a embalagem (regra da loja).</p>
                    </div>

                    <div class="grupo">
                        <span class="rotulo">Markup sobre o produto</span>
                        <div class="seg" role="group" aria-label="Markup">
                            <button v-for="m in [['2.5', '2,5×'], ['3', '3×'], ['4', '4×'], ['outro', 'Outro']]" :key="m[0]" type="button" :aria-pressed="f.markupModo === m[0]" @click="mudouMarkup(m[0])">{{ m[1] }}</button>
                        </div>
                        <div v-if="f.markupModo === 'outro'" class="dinheiro"><input id="rc-markup" v-model="f.markupOutro" inputmode="decimal" aria-label="Markup personalizado" @input="f.escolhido = null"><span>×</span></div>
                    </div>

                    <div class="grupo">
                        <span class="rotulo">Final do preço</span>
                        <div class="seg" role="group" aria-label="Final do preço">
                            <button v-for="e in [['90', ',90'], ['99', ',99'], ['97', ',97'], ['int', 'Inteiro']]" :key="e[0]" type="button" :aria-pressed="f.final === e[0]" @click="mudouFinal(e[0])">{{ e[1] }}</button>
                        </div>
                    </div>

                    <div class="grupo">
                        <label class="rotulo" for="rc-preset">Taxas</label>
                        <select id="rc-preset" v-model="f.preset" class="campo" @change="aplicarPreset">
                            <option v-for="(p, k) in PRESETS" :key="k" :value="k">{{ p.nome }}</option>
                        </select>
                        <p class="dica">{{ PRESETS[f.preset].dica }}</p>
                        <details class="mais" :open="taxasAbertas" @toggle="taxasAbertas = $event.target.open">
                            <summary>Ajustar taxas e forma de pagamento</summary>
                            <div class="taxas">
                                <span class="sub">Como os clientes pagam</span>
                                <div class="tres">
                                    <div class="campinho"><label for="rc-mixc">Cartão</label><div class="dinheiro"><input id="rc-mixc" v-model="f.mixCartao" inputmode="decimal"><span>%</span></div></div>
                                    <div class="campinho"><label for="rc-mixp">Pix</label><div class="dinheiro"><input id="rc-mixp" v-model="f.mixPix" inputmode="decimal"><span>%</span></div></div>
                                    <div class="campinho"><label for="rc-mixb">Boleto</label><div class="dinheiro"><input id="rc-mixb" :value="br(mixBoleto)" readonly tabindex="-1"><span>%</span></div></div>
                                </div>
                                <div class="dois">
                                    <div class="campinho"><label for="rc-plat">Tarifa da plataforma</label><div class="dinheiro"><input id="rc-plat" v-model="f.plat" inputmode="decimal"><span>%</span></div></div>
                                    <div class="campinho"><label for="rc-imp">Imposto (Simples)</label><div class="dinheiro"><input id="rc-imp" v-model="f.imposto" inputmode="decimal"><span>%</span></div></div>
                                    <div class="campinho"><label for="rc-cartao">Cartão</label><div class="dinheiro"><input id="rc-cartao" v-model="f.cartao" inputmode="decimal"><span>%</span></div></div>
                                    <div class="campinho"><label for="rc-cartaofixo">Cartão, fixo</label><div class="dinheiro"><span>R$</span><input id="rc-cartaofixo" v-model="f.cartaoFixo" inputmode="decimal"></div></div>
                                    <div class="campinho"><label for="rc-pix">Pix</label><div class="dinheiro"><input id="rc-pix" v-model="f.pix" inputmode="decimal"><span>%</span></div></div>
                                    <div class="campinho"><label for="rc-boleto">Boleto, fixo</label><div class="dinheiro"><span>R$</span><input id="rc-boleto" v-model="f.boleto" inputmode="decimal"></div></div>
                                </div>
                            </div>
                        </details>
                    </div>

                    <div class="grupo">
                        <span class="rotulo">Carrinhos por venda</span>
                        <div class="seg" role="group" aria-label="Carrinhos por venda">
                            <button v-for="c in [2, 3, 4]" :key="c" type="button" :aria-pressed="f.carrinhos === c" @click="f.carrinhos = c">{{ c }}</button>
                        </div>
                        <p class="dica">Não sabe o da loja? Deixe 3.</p>
                    </div>
                </aside>

                <main class="resultado">
                    <!-- 1. preço -->
                    <section class="cartao" aria-labelledby="rc-h1">
                        <div class="cab"><span class="n">1</span><h2 id="rc-h1">Preço de venda</h2></div>
                        <div class="preco">
                            <div class="grande"><small>R$</small>{{ fmt(r.P).replace(/^R\$\s?/, '') }}</div>
                            <div class="origem">
                                <template v-if="r.manual">{{ produto ? 'Preço do produto no catálogo.' : 'Preço digitado por você.' }}<br>Pelo markup daria <b>{{ fmt(r.P0) }}</b>.</template>
                                <template v-else>{{ fmt(r.custoProduto) }} × {{ String(r.m).replace('.', ',') }} = <b>{{ fmt(r.P0) }}</b><br>arredondado para um preço comercial.</template>
                            </div>
                        </div>
                        <div class="chips">
                            <button v-for="c in r.chips" :key="c" type="button" class="chip" :aria-pressed="!r.manual && c === r.P" @click="escolherPreco(c)">{{ fmt(c) }}</button>
                            <span class="dica">ou digite:</span>
                            <div class="dinheiro curto"><span>R$</span><input id="rc-preco" v-model="f.precoManual" inputmode="decimal" placeholder="0,00" aria-label="Preço manual" @input="f.diasEscolhidos = null"></div>
                        </div>
                        <div class="pilha" aria-hidden="true">
                            <div v-for="x in r.partes" :key="x.cls" :class="'p-' + x.cls" :style="{ flex: `0 0 ${x.w}%` }">{{ x.w > 11 ? Math.round(x.w) + '%' : '' }}</div>
                        </div>
                        <div class="legenda">
                            <div v-for="x in r.partes" :key="x.cls" class="lg"><i :class="'p-' + x.cls"></i><span>{{ x.nome }}</span><b>{{ fmt(x.v) }}</b></div>
                        </div>
                    </section>

                    <!-- 2. CPA -->
                    <section class="cartao" aria-labelledby="rc-h2">
                        <div class="cab"><span class="n">2</span><h2 id="rc-h2">Quanto pagar por venda</h2></div>
                        <p v-if="r.ok" class="lead">Sobram <b>{{ fmt(r.L) }}</b> por venda depois do custo, das taxas e do imposto. O anúncio pode custar até isso por venda. A meta é a metade: <b>{{ fmt(r.alvo) }}</b>.</p>
                        <div v-else class="alerta"><b>Esse preço não paga o custo e as taxas.</b> Sobra {{ fmt(r.L) }} por venda. Suba o markup ou o preço.</div>
                        <div class="kpis">
                            <div class="kpi max"><span class="rotulo">CPA máximo</span><div class="v">{{ fmt(Math.max(0, r.L)) }}</div><p>Zero a zero. Passou disso sem vender, pausa.</p></div>
                            <div class="kpi alvo"><span class="rotulo">CPA alvo</span><div class="v">{{ fmt(Math.max(0, r.alvo)) }}</div><p>Vendeu por até isso: resultado bom.</p></div>
                            <div class="kpi"><span class="rotulo">Lucro no alvo</span><div class="v">{{ fmt(Math.max(0, r.alvo)) }}</div><p>{{ r.ok ? pct((r.alvo / r.P) * 100) + ' do preço, já pago o anúncio.' : 'Por venda, já pago o anúncio.' }}</p></div>
                        </div>
                        <details class="porque"><summary>Como chegamos nesses números</summary>
                            <p>Preço menos custo, frete, insumos, taxas e imposto é o lucro bruto. Se o anúncio custar exatamente isso por venda, você empata: esse é o CPA máximo. Metade dele é o CPA alvo. Vendendo nele, a loja ganha o mesmo tanto que pagou à Meta.</p>
                        </details>
                    </section>

                    <!-- 3. carrinho -->
                    <section class="cartao" aria-labelledby="rc-h3">
                        <div class="cab"><span class="n">3</span><h2 id="rc-h3">Quanto pode custar cada carrinho</h2></div>
                        <p class="lead"><template v-if="r.ok">Cada carrinho deve custar até <b>{{ fmt(r.z2) }}</b>. Até <b>{{ fmt(r.z1) }}</b> é o ideal.</template><template v-else>Ajuste o preço para ver a régua.</template></p>
                        <div class="zonas">
                            <div v-for="z in zonas" :key="z.cls" :class="z.cls" :style="z.w === null ? { flex: '1 1 0' } : { flex: `0 0 ${z.w}%` }"><b>{{ z.nome }}</b><span>{{ z.txt }}</span></div>
                        </div>
                        <div class="lista-zonas">
                            <div v-for="z in zonas" :key="z.cls" class="zl"><i :class="z.cls"></i><b>{{ z.nome }} · {{ z.txt }}</b><span>{{ z.desc }}</span></div>
                        </div>
                        <details class="porque"><summary>Tabela por número de carrinhos</summary>
                            <p>Nos primeiros dias quase não sai venda, então o sinal é o carrinho. Leva de 2 a 4 carrinhos para fechar uma venda, por isso o carrinho precisa custar 2 a 3 vezes menos que o CPA alvo.</p>
                            <div class="rolar">
                                <table class="tabela">
                                    <thead><tr><th>Carrinhos até vender</th><th>Máx. p/ lucrar</th><th>Máx. p/ empatar</th></tr></thead>
                                    <tbody>
                                        <tr v-for="n in [1, 2, 3, 4]" :key="n" :class="{ sel: n === f.carrinhos }">
                                            <td>{{ n }} {{ n === 1 ? 'carrinho' : 'carrinhos' }}{{ n === f.carrinhos ? ' · sua loja' : '' }}</td>
                                            <td>{{ fmt(Math.max(0, r.alvo / n)) }}</td>
                                            <td>{{ fmt(Math.max(0, r.L / n)) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    </section>

                    <!-- 4. plano -->
                    <section class="cartao" aria-labelledby="rc-h4">
                        <div class="cab entre">
                            <div class="cab"><span class="n">4</span><h2 id="rc-h4">Plano de teste</h2></div>
                            <button type="button" class="botao" @click="copiarPlano">Copiar plano</button>
                        </div>
                        <div v-if="textoPlano"><p class="dica">Selecione e copie:</p><textarea id="rc-plano" class="campo plano" readonly :value="textoPlano"></textarea></div>
                        <div class="ajuste">
                            <div class="ctl">
                                <span class="rotulo">Criativos</span>
                                <div class="passo">
                                    <button type="button" aria-label="Menos um criativo" :disabled="f.criativos <= 2" @click="f.criativos--">−</button>
                                    <output aria-live="polite">{{ f.criativos }}</output>
                                    <button type="button" aria-label="Mais um criativo" :disabled="f.criativos >= 5" @click="f.criativos++">+</button>
                                </div>
                                <span :class="['selo', { w: f.criativos > 3 }]">{{ f.criativos <= 3 ? 'Recomendado para começar' : 'Ok, mas comece com 2 a 3' }}</span>
                            </div>
                            <div class="ctl">
                                <span class="rotulo">Dias de teste</span>
                                <div class="dias">
                                    <button v-for="d in r.opcoes" :key="d" type="button" class="dia" :aria-pressed="d === r.dias" @click="escolherDias(d)">
                                        <span>{{ d }} dias<span v-if="d === r.sugerido" class="sug"> · sugerido</span></span>
                                        <small>{{ fmt(porDia(r.L, d)) }}/dia</small>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div v-if="!r.ok" class="alerta">Sem margem para anúncio com esse preço.</div>
                        <div v-else-if="r.baixo" class="alerta aviso"><b>O mínimo da Meta passa do seu limite.</b> Com R$ 6/dia, cada criativo chega no CPA máximo ({{ fmt(r.L) }}) no dia {{ Math.ceil(r.L / MIN_DIA) }}. Rode os 3 dias e só mantenha se vier carrinho todo dia, ou suba o preço.</div>
                        <div class="valores">
                            <div class="principal"><span class="rotulo">Por criativo</span><div class="v">{{ fmt(r.pc) }} <small>/dia</small></div><p>{{ r.pc <= MIN_DIA ? 'Mínimo da Meta' : `CPA máximo ÷ ${r.dias} dias` }}</p></div>
                            <div><span class="rotulo">Total por dia</span><div class="v">{{ fmt(r.pc * f.criativos) }}</div><p>{{ f.criativos }} criativos × {{ fmt(r.pc) }}</p></div>
                            <div><span class="rotulo">Teste completo</span><div class="v">{{ fmt(r.pc * r.dias * f.criativos) }}</div><p>Máximo, se nenhum criativo vender em {{ r.dias }} dias</p></div>
                        </div>
                        <div class="linha">
                            <div v-for="d in r.linha" :key="d.dia" :class="['dcard', { primeiro: d.primeiro, ultimo: d.ultimo }]">
                                <span class="dn">DIA {{ d.dia }}</span>
                                <div class="amt">{{ fmt(d.acum) }}<small>acumulado por criativo</small></div>
                                <div class="mini"><div :style="{ width: d.barra + '%' }"></div></div>
                                <div class="meta">{{ d.meta }}</div>
                            </div>
                        </div>
                        <div class="regras">
                            <div class="regra matar"><h3>Pausar</h3><ul><li>Criativo gastou {{ fmt(r.L) }} e não vendeu.</li><li>Depois de 3 dias, carrinho acima de {{ fmt(r.alvo) }}.</li><li>Todos os criativos pausados: mata o produto.</li></ul></div>
                            <div class="regra segurar"><h3>Segurar</h3><ul><li>Um dia sem carrinho, mas o seguinte melhorou.</li><li>Carrinho entre {{ fmt(r.z2) }} e {{ fmt(r.alvo) }}.</li><li>Vendeu entre {{ fmt(r.alvo) }} e {{ fmt(r.L) }}.</li></ul></div>
                            <div class="regra seguir"><h3>Bom resultado</h3><ul><li>Vendeu com CPA até {{ fmt(r.alvo) }}.</li><li>Carrinho todo dia, até {{ fmt(r.z2) }}.</li><li>Mantenha e suba o orçamento +20% a cada 2–3 dias.</li></ul></div>
                        </div>
                    </section>

                    <!-- 5. diagnóstico -->
                    <section class="cartao" aria-labelledby="rc-h5">
                        <div class="cab"><span class="n">5</span><h2 id="rc-h5">Como está o criativo?</h2></div>
                        <p class="lead">Números de <b>um criativo</b>, somando todos os dias (copie do Gerenciador). Os valores abaixo são um exemplo.</p>
                        <div class="quatro">
                            <div class="campinho"><label for="rc-dg">Valor gasto</label><div class="dinheiro"><span>R$</span><input id="rc-dg" v-model="f.dGasto" inputmode="decimal"></div></div>
                            <div class="campinho"><label for="rc-dcl">Cliques no link</label><div class="dinheiro"><input id="rc-dcl" v-model="f.dCliques" inputmode="numeric"></div></div>
                            <div class="campinho"><label for="rc-dca">Carrinhos</label><div class="dinheiro"><input id="rc-dca" v-model="f.dCarrinhos" inputmode="numeric"></div></div>
                            <div class="campinho"><label for="rc-dv">Vendas</label><div class="dinheiro"><input id="rc-dv" v-model="f.dVendas" inputmode="numeric"></div></div>
                        </div>
                        <div :class="['veredito', veredito.cls]">
                            <span class="tag">{{ veredito.tag }}</span>
                            <h3>{{ veredito.titulo }}</h3>
                            <p>{{ veredito.texto }}</p>
                            <div class="progresso" aria-hidden="true"><div :style="{ width: veredito.usado + '%' }"></div></div>
                            <div class="stats">
                                <div><span>Usado do limite </span><b>{{ Math.round(veredito.usado) }}%</b></div>
                                <div v-if="r.L > 0"><span>Falta até pausar </span><b>{{ fmt(veredito.falta) }}</b></div>
                                <div v-if="veredito.custoCarr !== null"><span>Custo por carrinho </span><b>{{ fmt(veredito.custoCarr) }}</b></div>
                                <div v-if="veredito.cpa !== null"><span>CPA </span><b>{{ fmt(veredito.cpa) }}</b></div>
                                <div v-if="veredito.cpc !== null"><span>CPC </span><b>{{ fmt(veredito.cpc) }}</b></div>
                                <div v-if="r.L > 0"><span>CPC teto </span><b>{{ fmt(veredito.cpcTeto) }}</b><template v-if="veredito.cpc !== null">{{ veredito.cpc <= veredito.cpcTeto ? ' ✓' : ' ✗' }}</template></div>
                            </div>
                            <p v-if="r.L > 0" class="mini-txt">CPC teto: quanto o clique pode custar para o carrinho sair até {{ fmt(veredito.metaCarrinho) }}, com {{ pct(veredito.taxa * 100) }} dos cliques virando carrinho{{ veredito.taxaPropria ? ' (o seu número).' : ' (média estimada).' }}</p>
                        </div>
                    </section>

                    <p class="rodape">Taxas vêm da configuração da loja e podem ser ajustadas acima. O mínimo da Meta considerado é R$ 6 por dia por conjunto. O que você digita fica salvo só neste navegador.</p>
                </main>
            </div>
            <div v-if="aviso" class="toast" role="status">{{ aviso }}</div>
        </div>
    </AdminLayout>
</template>

<style scoped>
/* Painel de entradas à esquerda, leitura do teste à direita (empilha no celular). */
.regua {
    --rc-bg: #f2f4f7; --rc-surface: #fff; --rc-sunk: #e8ecf1; --rc-ink: #0f1b2d; --rc-muted: #5a6577; --rc-line: #d9dfe7;
    --rc-accent: #2346d8; --rc-accent-soft: #e3e9ff; --rc-on-accent: #fff;
    --rc-produto: #8a94a6; --rc-frete: #a7b0bf; --rc-taxa: #c3cad6; --rc-imposto: #d6dce5;
    --rc-great: #0b7a55; --rc-good: #2fa36b; --rc-warn: #d48806; --rc-bad: #d23c3c;
    --rc-great-soft: #ddf3ea; --rc-warn-soft: #fbf0d9; --rc-bad-soft: #fbe3e3;
    color: var(--rc-ink); display: grid; gap: 20px; font-size: 15px; line-height: 1.5;
}
:global(.dark) .regua {
    --rc-bg: #0b111a; --rc-surface: #131b27; --rc-sunk: #0f1620; --rc-ink: #e7ecf3; --rc-muted: #95a1b3; --rc-line: #243042;
    --rc-accent: #7f9cff; --rc-accent-soft: #1b2647; --rc-on-accent: #0b111a;
    --rc-produto: #5d6880; --rc-frete: #4c566b; --rc-taxa: #3f4a5e; --rc-imposto: #323b4c;
    --rc-great: #3dd39a; --rc-good: #5bc48a; --rc-warn: #f0b341; --rc-bad: #f06b6b;
    --rc-great-soft: #12302a; --rc-warn-soft: #33280f; --rc-bad-soft: #371a1c;
}
h1, h2, h3 { margin: 0; text-wrap: balance; letter-spacing: -0.01em; }
.topo { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 12px 24px; }
.topo h1 { font-size: 1.6rem; font-weight: 800; }
.topo p { margin: 4px 0 0; color: var(--rc-muted); max-width: 62ch; font-size: 14px; }
.links { display: flex; gap: 16px; font-size: 14px; }
.links a, .link { color: var(--rc-accent); background: none; border: 0; padding: 0; cursor: pointer; font: inherit; }
.links a:hover, .link:hover { text-decoration: underline; }
.grade { display: grid; grid-template-columns: minmax(0, 360px) minmax(0, 1fr); gap: 20px; align-items: start; }
@media (max-width: 1000px) { .grade { grid-template-columns: minmax(0, 1fr); } }
.painel { background: var(--rc-surface); border: 1px solid var(--rc-line); border-radius: 14px; padding: 18px; display: grid; gap: 20px; position: sticky; top: 16px; min-width: 0; }
@media (max-width: 1000px) { .painel { position: static; } }
.grupo, .taxas { display: grid; gap: 8px; min-width: 0; }
.rotulo { font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: var(--rc-muted); font-weight: 700; }
.sub { font-size: 13px; color: var(--rc-muted); font-weight: 500; }
.dica { font-size: 12.5px; color: var(--rc-muted); margin: 0; }
.campo { width: 100%; min-width: 0; border: 1px solid var(--rc-line); border-radius: 10px; background: var(--rc-sunk); color: var(--rc-ink); font-size: 14px; padding: 9px 12px; }
.campo:focus { outline: none; border-color: var(--rc-accent); box-shadow: 0 0 0 3px var(--rc-accent-soft); }
.campinho { display: grid; gap: 3px; min-width: 0; }
.campinho label { font-size: 12.5px; color: var(--rc-muted); font-weight: 500; }
.dinheiro { display: flex; align-items: center; border: 1px solid var(--rc-line); border-radius: 10px; background: var(--rc-sunk); padding: 0 10px; min-width: 0; }
.dinheiro:focus-within { border-color: var(--rc-accent); box-shadow: 0 0 0 3px var(--rc-accent-soft); }
.dinheiro span { color: var(--rc-muted); font-size: 13px; font-weight: 500; }
.dinheiro input { flex: 1; min-width: 0; width: 100%; border: 0; background: transparent; color: var(--rc-ink); font-weight: 600; font-size: 15px; padding: 9px 5px; outline: none; box-shadow: none; font-variant-numeric: tabular-nums; }
.dinheiro.curto { width: 130px; }
.dois { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
.tres { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
.quatro { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
@media (max-width: 640px) { .quatro { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.seg { display: flex; background: var(--rc-sunk); border: 1px solid var(--rc-line); border-radius: 10px; padding: 3px; gap: 3px; }
.seg button { flex: 1; min-width: 0; border: 0; background: transparent; color: var(--rc-muted); font-weight: 600; font-size: 14px; padding: 7px 4px; border-radius: 7px; cursor: pointer; }
.seg button[aria-pressed="true"] { background: var(--rc-surface); color: var(--rc-ink); box-shadow: 0 1px 2px rgba(0, 0, 0, .12); }
button:focus-visible { outline: 2px solid var(--rc-accent); outline-offset: 2px; }
details.mais { border-top: 1px dashed var(--rc-line); padding-top: 10px; }
details summary { cursor: pointer; font-size: 13px; font-weight: 600; color: var(--rc-accent); }
details.mais[open] summary { margin-bottom: 10px; }
.resultado { display: grid; gap: 18px; min-width: 0; }
.cartao { background: var(--rc-surface); border: 1px solid var(--rc-line); border-radius: 14px; padding: 20px; display: grid; gap: 16px; min-width: 0; }
.cab { display: flex; align-items: center; gap: 10px; }
.cab.entre { justify-content: space-between; flex-wrap: wrap; gap: 8px; }
.cab h2 { font-size: 1.15rem; font-weight: 700; }
.n { width: 26px; height: 26px; border-radius: 8px; background: var(--rc-accent-soft); color: var(--rc-accent); font-weight: 700; font-size: 13px; display: grid; place-items: center; flex: none; }
.lead { margin: 0; max-width: 70ch; }
.preco { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 10px 28px; }
.grande { font-weight: 800; font-size: clamp(40px, 6vw, 58px); line-height: 1; letter-spacing: -0.02em; font-variant-numeric: tabular-nums; }
.grande small { font-size: .45em; font-weight: 700; margin-right: 4px; color: var(--rc-muted); }
.origem { color: var(--rc-muted); font-size: 14px; }
.origem b { color: var(--rc-ink); }
.chips { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.chip { border: 1px solid var(--rc-line); background: var(--rc-sunk); color: var(--rc-ink); font-weight: 600; font-size: 14px; padding: 6px 12px; border-radius: 999px; cursor: pointer; font-variant-numeric: tabular-nums; }
.chip[aria-pressed="true"] { background: var(--rc-accent); border-color: var(--rc-accent); color: var(--rc-on-accent); }
.pilha { display: flex; height: 44px; border-radius: 10px; overflow: hidden; background: var(--rc-sunk); }
.pilha div { min-width: 0; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 12px; white-space: nowrap; overflow: hidden; color: var(--rc-ink); }
.p-produto { background: var(--rc-produto); color: #fff !important; }
.p-frete { background: var(--rc-frete); }
.p-taxa { background: var(--rc-taxa); }
.p-imposto { background: var(--rc-imposto); }
.p-ads { background: var(--rc-accent); color: var(--rc-on-accent) !important; }
.p-lucro { background: var(--rc-great); color: #fff !important; }
.legenda { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px 16px; }
.lg { display: grid; grid-template-columns: 12px 1fr; gap: 2px 8px; align-items: center; }
.lg i { width: 12px; height: 12px; border-radius: 3px; display: block; }
.lg span { font-size: 13px; color: var(--rc-muted); }
.lg b { grid-column: 2; font-size: 16px; font-variant-numeric: tabular-nums; }
.kpis { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
@media (max-width: 640px) { .kpis { grid-template-columns: minmax(0, 1fr); } }
.kpi { border: 1px solid var(--rc-line); border-radius: 12px; padding: 14px; display: grid; gap: 6px; align-content: start; }
.kpi .v { font-weight: 800; font-size: 28px; line-height: 1.05; font-variant-numeric: tabular-nums; }
.kpi p { margin: 0; font-size: 13px; color: var(--rc-muted); }
.kpi.max { border-color: var(--rc-bad); background: var(--rc-bad-soft); }
.kpi.max .v { color: var(--rc-bad); }
.kpi.alvo { border-color: var(--rc-great); background: var(--rc-great-soft); }
.kpi.alvo .v { color: var(--rc-great); }
.alerta { border-radius: 10px; padding: 12px 14px; background: var(--rc-bad-soft); border: 1px solid var(--rc-bad); font-size: 14px; }
.alerta.aviso { background: var(--rc-warn-soft); border-color: var(--rc-warn); }
.porque { font-size: 14px; color: var(--rc-muted); }
.porque p { margin: 10px 0 0; max-width: 70ch; }
.zonas { display: flex; height: 52px; border-radius: 10px; overflow: hidden; }
.zonas div { display: flex; flex-direction: column; justify-content: center; padding: 0 10px; min-width: 0; overflow: hidden; }
.zonas b { font-size: 13px; white-space: nowrap; }
.zonas span { font-size: 12px; white-space: nowrap; font-variant-numeric: tabular-nums; }
@media (max-width: 560px) { .zonas span { display: none; } .zonas div { padding: 0 6px; } }
.z1 { background: var(--rc-great); color: #fff; }
.z2 { background: var(--rc-good); color: #fff; }
.z3 { background: var(--rc-warn); color: #1b1300; }
.z4 { background: var(--rc-bad); color: #fff; }
.lista-zonas { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; }
.zl { display: grid; grid-template-columns: 10px 1fr; gap: 2px 10px; font-size: 13.5px; }
.zl i { width: 10px; border-radius: 3px; grid-row: span 2; }
.zl b { font-variant-numeric: tabular-nums; }
.zl span { color: var(--rc-muted); }
.rolar { overflow-x: auto; margin-top: 10px; }
.tabela { width: 100%; border-collapse: collapse; font-size: 14px; font-variant-numeric: tabular-nums; color: var(--rc-ink); }
.tabela th, .tabela td { padding: 8px 10px; text-align: right; border-bottom: 1px solid var(--rc-line); white-space: nowrap; }
.tabela th:first-child, .tabela td:first-child { text-align: left; }
.tabela th { font-size: 11px; letter-spacing: .06em; text-transform: uppercase; color: var(--rc-muted); }
.tabela tr.sel td { background: var(--rc-accent-soft); font-weight: 700; }
.botao { border: 1px solid var(--rc-accent); background: transparent; color: var(--rc-accent); font-weight: 600; font-size: 14px; padding: 8px 14px; border-radius: 10px; cursor: pointer; }
.botao:hover { background: var(--rc-accent-soft); }
.plano { min-height: 150px; font-family: ui-monospace, Consolas, monospace; font-size: 13px; }
.ajuste { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 16px 28px; align-items: start; }
@media (max-width: 640px) { .ajuste { grid-template-columns: minmax(0, 1fr); } }
.ctl { display: grid; gap: 8px; min-width: 0; }
.passo { display: inline-flex; align-items: center; border: 1px solid var(--rc-line); border-radius: 10px; background: var(--rc-sunk); overflow: hidden; justify-self: start; }
.passo button { width: 40px; height: 42px; border: 0; background: transparent; color: var(--rc-ink); font-weight: 700; font-size: 20px; cursor: pointer; }
.passo button:disabled { color: var(--rc-line); cursor: default; }
.passo output { min-width: 50px; text-align: center; font-weight: 800; font-size: 22px; }
.selo { justify-self: start; font-weight: 600; font-size: 11.5px; padding: 3px 8px; border-radius: 999px; background: var(--rc-great-soft); color: var(--rc-great); }
.selo.w { background: var(--rc-warn-soft); color: var(--rc-warn); }
.dias { display: flex; flex-wrap: wrap; gap: 6px; }
.dia { border: 1px solid var(--rc-line); background: var(--rc-sunk); color: var(--rc-ink); font-weight: 600; font-size: 13px; padding: 6px 10px; border-radius: 8px; cursor: pointer; display: grid; line-height: 1.2; text-align: left; font-variant-numeric: tabular-nums; }
.dia small { font-weight: 500; color: var(--rc-muted); font-size: 11.5px; }
.dia[aria-pressed="true"] { border-color: var(--rc-accent); background: var(--rc-accent-soft); }
.sug { color: var(--rc-accent); }
.valores { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); border: 1px solid var(--rc-line); border-radius: 12px; overflow: hidden; }
@media (max-width: 640px) { .valores { grid-template-columns: minmax(0, 1fr); } }
.valores > div { padding: 14px 16px; display: grid; gap: 4px; min-width: 0; }
.valores > div + div { border-left: 1px solid var(--rc-line); }
@media (max-width: 640px) { .valores > div + div { border-left: 0; border-top: 1px solid var(--rc-line); } }
.valores .v { font-weight: 800; font-size: 26px; line-height: 1.05; font-variant-numeric: tabular-nums; }
.valores .v small { font-size: .5em; color: var(--rc-muted); font-weight: 700; }
.valores .principal { background: var(--rc-accent-soft); }
.valores .principal .v { color: var(--rc-accent); }
.valores p { margin: 0; font-size: 12.5px; color: var(--rc-muted); }
.linha { display: grid; grid-auto-flow: column; grid-auto-columns: minmax(118px, 1fr); gap: 8px; overflow-x: auto; padding-bottom: 4px; }
.dcard { border: 1px solid var(--rc-line); border-radius: 12px; padding: 12px; display: grid; gap: 8px; align-content: start; min-width: 0; background: var(--rc-surface); }
.dn { font-weight: 700; font-size: 11px; letter-spacing: .08em; color: var(--rc-muted); }
.amt { font-weight: 700; font-size: 17px; font-variant-numeric: tabular-nums; }
.amt small { display: block; font-weight: 500; font-size: 11.5px; color: var(--rc-muted); }
.mini { height: 6px; border-radius: 99px; background: var(--rc-sunk); position: relative; overflow: hidden; }
.mini div { position: absolute; inset: 0 auto 0 0; background: var(--rc-accent); border-radius: 99px; }
.meta { font-size: 12.5px; line-height: 1.35; }
.dcard.primeiro .meta { color: var(--rc-muted); }
.dcard.ultimo { border-color: var(--rc-bad); background: var(--rc-bad-soft); }
.dcard.ultimo .mini div { background: var(--rc-bad); }
.dcard.ultimo .meta { color: var(--rc-bad); font-weight: 600; }
.regras { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
@media (max-width: 760px) { .regras { grid-template-columns: minmax(0, 1fr); } }
.regra { border-radius: 12px; padding: 14px; display: grid; gap: 8px; align-content: start; border: 1px solid; }
.regra h3 { font-size: 15px; font-weight: 700; }
.regra ul { margin: 0; padding-left: 18px; display: grid; gap: 5px; font-size: 13.5px; list-style: disc; }
.regra.matar { background: var(--rc-bad-soft); border-color: var(--rc-bad); }
.regra.matar h3 { color: var(--rc-bad); }
.regra.segurar { background: var(--rc-warn-soft); border-color: var(--rc-warn); }
.regra.segurar h3 { color: var(--rc-warn); }
.regra.seguir { background: var(--rc-great-soft); border-color: var(--rc-great); }
.regra.seguir h3 { color: var(--rc-great); }
.veredito { border-radius: 12px; padding: 16px; display: grid; gap: 8px; border: 1px solid var(--rc-line); background: var(--rc-sunk); }
.veredito .tag { justify-self: start; font-weight: 700; font-size: 12px; letter-spacing: .08em; text-transform: uppercase; padding: 4px 10px; border-radius: 999px; background: var(--rc-line); color: var(--rc-ink); }
.veredito h3 { font-size: 1.25rem; }
.veredito p { margin: 0; font-size: 14px; color: var(--rc-muted); max-width: 72ch; }
.veredito.ruim { background: var(--rc-bad-soft); border-color: var(--rc-bad); }
.veredito.ruim .tag { background: var(--rc-bad); color: #fff; }
.veredito.atencao { background: var(--rc-warn-soft); border-color: var(--rc-warn); }
.veredito.atencao .tag { background: var(--rc-warn); color: #1b1300; }
.veredito.bom { background: var(--rc-great-soft); border-color: var(--rc-great); }
.veredito.bom .tag { background: var(--rc-great); color: #fff; }
.stats { display: flex; flex-wrap: wrap; gap: 8px 22px; font-size: 13.5px; }
.stats span { color: var(--rc-muted); }
.stats b { font-variant-numeric: tabular-nums; }
.mini-txt { font-size: 12.5px !important; }
.progresso { height: 10px; background: var(--rc-line); border-radius: 99px; overflow: hidden; }
.progresso div { height: 100%; background: var(--rc-accent); border-radius: 99px; transition: width .35s; }
.rodape { color: var(--rc-muted); font-size: 12.5px; margin: 0; }
.toast { position: fixed; left: 50%; bottom: 20px; transform: translateX(-50%); background: var(--rc-ink); color: var(--rc-surface); font-weight: 600; font-size: 14px; padding: 10px 16px; border-radius: 10px; z-index: 60; }
@media (prefers-reduced-motion: reduce) { .regua * { transition: none !important; } }
</style>
