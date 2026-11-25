<script setup>
// Seções da página de brinquedo (pedido do Lira 24/11/2026: "clone idêntico da página de referência,
// menos o rodapé e o nav"). Mesma ordem, medidas e efeitos da referência (medidos em 1440 e 390 px).
//
// Conteúdo (25/11/2026, todos os brinquedos no formato do Livro Falante): vem primeiro do conteúdo
// próprio da página (products.pagina_brinquedo, ver App\Modules\Catalog\Support\PaginaBrinquedo), com
// texto, ícones, kits, passos e garantia de cada produto; o que faltar cai no conteúdo do anúncio
// (product_ad_contents) e, sem nada, a seção some. Os textos fixos daqui são da loja e valem para
// qualquer brinquedo.
//
// Ordem: letreiro → história com vídeo → por que adora (foto + 4 cartões) → habilidades (6 pílulas +
// foto) → detalhes (foto + 4 cartões) → comparativo → família (colagem + citação) → kits → como funciona
// → garantia → perguntas → descrição completa → chamada final.
import { computed, onMounted, onUnmounted, ref } from 'vue';
import Cubo from './Cubo.vue';
import Icone from './Icone.vue';
import KitsBrinquedo from './KitsBrinquedo.vue';
import { ICONES } from './icones';
import { semEmoji, tituloComMarcador } from './kits';

const props = defineProps({
    product: { type: Object, required: true },
    anuncio: { type: Object, default: null },
    pagina: { type: Object, default: null },
    opcoes: { type: Array, default: () => [] },
    escolhidaId: { type: Number, default: null },
    descriptionSections: { type: Array, default: () => [] },
    dimensoes: { type: String, default: null },
    faqLoja: { type: Array, default: () => [] },
    parcelas: { type: Number, default: 12 },
});
const emit = defineEmits(['escolher', 'comprar']);

const CORES = ['amarelo', 'azul', 'verde', 'vermelho'];
const pg = computed(() => props.pagina ?? {});
const lista = (valor) => (Array.isArray(valor) ? valor : []);
const iconeValido = (nome, reserva) => (nome && ICONES[nome] ? nome : reserva);
const foto = (indice) => {
    if (indice === null || indice === undefined) return null;
    const imagem = props.product.images?.[indice];
    return imagem ? (imagem.media_url ?? imagem.url) : null;
};
// Desempenho (pedido do Lira 24/11/2026): cada foto com a versão de 512 px e a de 1200 px; o navegador
// escolhe pela largura da tela (no celular quase sempre a de 512 px, que pesa um terço).
const srcsetDe = (src) => {
    const imagem = (props.product.images ?? []).find((i) => (i.media_url ?? i.url) === src);
    return imagem?.thumb_url && imagem.media_url && imagem.thumb_url !== imagem.media_url ? `${imagem.thumb_url} 512w, ${imagem.media_url} 1200w` : null;
};
const capa = computed(() => foto(0));
const montarBloco = (b) => (b?.titulo
    ? { titulo: tituloComMarcador(b.titulo), texto: semEmoji(b.texto ?? ''), sub: semEmoji(b.subtitulo ?? ''), foto: foto(b.imagem), imagem: b.imagem }
    : null);
// Seção: do conteúdo da página; senão, o bloco i do anúncio.
const bloco = (chave, i) => montarBloco(pg.value[chave]) ?? montarBloco(props.anuncio?.blocos?.[i]);
const historia = computed(() => bloco('historia', 0));
const porque = computed(() => bloco('porque', 1));
const ensina = computed(() => bloco('ensina', 2));
const detalhe = computed(() => bloco('detalhes', 3));
const familia = computed(() => bloco('familia', 4));
// Segunda foto da colagem da família: a primeira foto do produto que ainda não apareceu nas seções.
const fotoExtra = computed(() => {
    const usadas = new Set([historia, porque, ensina, detalhe, familia].map((b) => b.value?.imagem));
    const indice = (props.product.images ?? []).findIndex((_, i) => i > 0 && !usadas.has(i));
    return indice > 0 ? foto(indice) : null;
});
// Ícones de "longe das telas" na história: só quando o brinquedo é para largar a tela (não num videogame).
const semTelas = computed(() => pg.value.sem_telas !== false);

// Vídeo da história: o vídeo do produto (admin › Produto › Vídeo). Toca mudo, em loop, só quando
// aparece na tela (como na referência); sem vídeo, fica a foto no mesmo quadro 9:16 (celular em pé).
const videoUrl = computed(() => props.product.video_url || null);
const videoEl = ref(null);
let observadorVideo = null;
onMounted(() => {
    const v = videoEl.value;
    if (!v) return;
    if (!('IntersectionObserver' in window)) { v.play?.().catch(() => {}); return; }
    observadorVideo = new IntersectionObserver(([e]) => {
        if (e.isIntersecting) v.play?.().catch(() => {}); else v.pause?.();
    }, { threshold: 0.25 });
    observadorVideo.observe(v);
});
onUnmounted(() => observadorVideo?.disconnect());

const ICONES_BENEFICIO = ['sparkles', 'palette', 'lightbulb', 'smile'];
const cartoes = (proprios, doAnuncio, reservas, deduzir) => {
    if (lista(proprios).length) {
        return lista(proprios).slice(0, 4).map((c, i) => ({
            titulo: semEmoji(c.titulo), texto: semEmoji(c.texto ?? ''), cor: ['vermelho', 'azul', 'verde', 'amarelo'][i % 4], icone: iconeValido(c.icone, reservas[i % 4]),
        }));
    }
    return lista(doAnuncio).slice(0, 4).map((item, i) => ({
        titulo: semEmoji(item[0]), texto: semEmoji(item[1] ?? ''), cor: ['vermelho', 'azul', 'verde', 'amarelo'][i % 4], icone: deduzir ? deduzir(item, i) : reservas[i % 4],
    }));
};
const beneficios = computed(() => cartoes(pg.value.porque?.cards, props.anuncio?.beneficios, ICONES_BENEFICIO));
const ICONES_HABILIDADE = [
    [/ingl|idioma|bil[ií]ng/i, 'languages'], [/portugu|vocabul|palavra|leitura/i, 'book-open'], [/mem[oó]ria|associa/i, 'brain'],
    [/pron[uú]ncia|sonor|ouvi|audi|som/i, 'ear'], [/coordena|motor/i, 'hand'], [/curios|descobr/i, 'search'],
    [/racioc|l[oó]gic|problema/i, 'lightbulb'], [/criativ|imagina/i, 'palette'], [/concentra|aten/i, 'target'],
];
const CORES_HABILIDADE = ['amarelo', 'vermelho', 'azul', 'verde', 'azul', 'vermelho'];
const habilidades = computed(() => {
    const proprias = lista(pg.value.ensina?.habilidades);
    if (proprias.length) {
        return proprias.slice(0, 6).map((h, j) => ({ texto: semEmoji(h.texto), cor: CORES_HABILIDADE[j % 6], icone: iconeValido(h.icone, 'star') }));
    }
    return lista(props.anuncio?.comparativo?.habilidades).map(semEmoji).filter(Boolean).slice(0, 6)
        .map((texto, j) => ({ texto, cor: CORES_HABILIDADE[j % 6], icone: ICONES_HABILIDADE.find(([r]) => r.test(texto))?.[1] ?? 'star' }));
});
// Ícone de cada cartão pelo assunto (livro/ouvir, lousa/desenhar, ímã/montar, telas); senão, um da lista.
const ICONES_CARTAO = [[/ouv|fala|palavra|idioma|livro/i, 'ear'], [/desenh|luz|lousa|cria/i, 'palette'], [/ím[aã]|monta|encaix|quebra/i, 'magnet'], [/tela/i, 'heart']];
const ICONES_DETALHE = ['ear', 'palette', 'magnet', 'heart'];
const detalhes = computed(() => cartoes(pg.value.detalhes?.cards, props.anuncio?.comparativo?.detalhes, ICONES_DETALHE,
    (d, i) => ICONES_CARTAO.find(([r]) => r.test(`${d[0]} ${d[1] ?? ''}`))?.[1] ?? ICONES_DETALHE[i % 4]));

const comparativo = computed(() => {
    const proprio = pg.value.comparativo;
    if (lista(proprio?.linhas).length) {
        const rotulos = lista(proprio.rotulos).length === 2 ? proprio.rotulos : ['Comum', 'Este'];
        return {
            titulo: tituloComMarcador(proprio.titulo || 'Por que escolher'),
            intro: semEmoji(proprio.intro),
            rotulos,
            linhas: proprio.linhas.map((l) => ({ texto: semEmoji(l.texto), comum: Boolean(l.comum) })),
        };
    }
    const c = props.anuncio?.comparativo;
    if (!c?.linhas?.length) return null;
    const rotulos = Array.isArray(c.rotulos) && c.rotulos.length === 2 ? c.rotulos : ['Comum', 'Este'];
    return {
        titulo: tituloComMarcador(c.titulo || 'Por que escolher'),
        intro: semEmoji(c.intro),
        rotulos,
        // Cada linha: o que o produto faz (✓) e o comum não (✗); a linha pode dizer que os dois fazem.
        linhas: c.linhas.map((l) => ({ texto: semEmoji(l[2] || l[0]), comum: Boolean(l[3]) })),
    };
});

const LETREIRO_LOJA = ['Compra segura', `Até ${props.parcelas}x no cartão`, 'Garantia de 30 dias', 'Envio com rastreio'];
const letreiro = computed(() => {
    const proprios = lista(pg.value.letreiro).map(semEmoji).filter(Boolean);
    const itens = proprios.length ? proprios : [...lista(props.anuncio?.destaques), ...lista(props.anuncio?.beneficios).map((b) => b[0])].map(semEmoji).filter(Boolean);
    const unicos = [...new Set(itens)].slice(0, 8);
    return unicos.length ? unicos : LETREIRO_LOJA;
});
const duvidas = computed(() => {
    const proprias = lista(pg.value.duvidas);
    const doProduto = proprias.length ? proprias : lista(props.anuncio?.duvidas);
    return [...doProduto.map((d) => [semEmoji(d[0]), semEmoji(d[1])]), ...props.faqLoja];
});
const abertaFaq = ref(0);
// Chamadas por cima das fotos (como as etiquetas da referência), em HTML sobre as nossas fotos.
const chamadasPorque = computed(() => (lista(pg.value.porque?.chamadas).length ? pg.value.porque.chamadas : lista(props.anuncio?.destaques))
    .map(semEmoji).filter(Boolean).slice(0, 2));
// Etiquetas da foto das habilidades: do conteúdo da página; sem ele, as do Livro Falante (o que os kits têm).
const chamadasKit = computed(() => {
    if (lista(pg.value.ensina?.chamadas).length) return pg.value.ensina.chamadas.map(semEmoji).filter(Boolean).slice(0, 4);
    if (props.pagina) return [];
    const todos = props.opcoes.map((o) => String(o.variation || '')).join(' ');
    return ['Português e Inglês', /lousa/i.test(todos) ? 'Desenha com luz' : null, /quebra/i.test(todos) ? 'Peças com ímã' : null, '300+ palavras'].filter(Boolean).slice(0, 4);
});
const citacao = computed(() => semEmoji(pg.value.familia?.citacao ?? props.anuncio?.chamada ?? ''));

// Kits: seção com os cartões; com uma variação só (ou nenhuma), um cartão com a oferta única.
const kitsTitulo = computed(() => tituloComMarcador(pg.value.kits?.titulo || 'Escolha o tamanho da diversão'));
const kitsTexto = computed(() => semEmoji(pg.value.kits?.texto ?? ''));
const mostrarKits = computed(() => props.opcoes.length > 1 || (props.opcoes.length === 1 && !!props.pagina));

const TELAS = ['smartphone', 'tv', 'gamepad-2', 'tablet'];
const CORES_PASSO = ['vermelho', 'azul', 'amarelo', 'verde'];
const PASSOS_LOJA = [
    { icone: 'gift', titulo: 'Escolha o kit', texto: 'Veja as opções e fique com a que combina com a sua casa.' },
    { icone: 'truck', titulo: 'Enviamos com rastreio', texto: 'Acompanhe cada passo em Meus Pedidos.' },
    { icone: 'sparkles', titulo: 'Olhos brilhando', texto: 'Chegou! A caixa abre e a brincadeira começa.' },
    { icone: 'blocks', titulo: 'Hora de brincar', texto: 'Diversão de verdade, todo dia, longe das telas.' },
];
// Passos do Livro Falante (o modelo), enquanto ele não tiver conteúdo próprio.
const PASSOS_LIVRO = [
    { icone: 'gift', titulo: 'Escolha o kit', texto: 'Só o livro ou com mais um brinquedo: você decide.' },
    PASSOS_LOJA[1],
    { icone: 'sparkles', titulo: 'Olhos brilhando', texto: 'Chegou! Primeiro toque, primeira palavra.' },
    { icone: 'blocks', titulo: 'Aprender brincando', texto: 'Português e inglês todo dia, longe das telas.' },
];
const passos = computed(() => {
    const proprios = lista(pg.value.passos);
    const base = proprios.length === 4 ? proprios : (props.pagina ? PASSOS_LOJA : PASSOS_LIVRO);
    return base.map((p, j) => ({ titulo: semEmoji(p.titulo), texto: semEmoji(p.texto), icone: iconeValido(p.icone, PASSOS_LOJA[j].icone), cor: CORES_PASSO[j % 4] }));
});
const GARANTIA_LOJA = ['Brincando mais', 'Mais longe das telas', 'Usando a imaginação', 'Chamando você para brincar'];
const GARANTIA_LIVRO = ['Falando palavras novas', 'Mais longe das telas', 'Brincando e aprendendo', 'Arriscando o inglês'];
const garantia = computed(() => {
    const proprios = lista(pg.value.garantia).map(semEmoji).filter(Boolean);
    const base = proprios.length === 4 ? proprios : (props.pagina ? GARANTIA_LOJA : GARANTIA_LIVRO);
    return base.map((texto, j) => ({ texto, cor: CORES_PASSO[j % 4] }));
});
const final = computed(() => ({
    titulo: tituloComMarcador(pg.value.final?.titulo || 'Dê a eles um presente pra lembrar'),
    texto: semEmoji(pg.value.final?.texto || 'Menos tela, mais imaginação, e um brinquedo que cresce junto com a criança.'),
}));
// Texto dos botões de cada seção: do conteúdo da página (ctas.<seção>); senão, o padrão.
const cta = (chave, padrao) => semEmoji(pg.value.ctas?.[chave] ?? '') || padrao;
const corCubo = (cor) => ({ vermelho: 'laranja' }[cor] ?? cor);
</script>

<template>
    <div class="pb-secoes">
        <!-- Letreiro -->
        <div class="pb-mq" aria-hidden="true">
            <div class="pb-mq-trilho">
                <div v-for="volta in 2" :key="volta" class="pb-mq-linha">
                    <template v-for="(item, i) in letreiro" :key="`${volta}-${i}`"><span>{{ item }}</span><i :class="`pb-q cor-${CORES[i % 4]}`"></i></template>
                </div>
            </div>
        </div>

        <!-- História, com o vídeo do produto -->
        <section v-if="historia" class="pb-sec fundo-creme">
            <div class="pb-wrap">
                <div class="pb-head pb-narrow pb-reveal">
                    <div v-if="semTelas" class="pb-semtelas" aria-label="Longe das telas">
                        <span v-for="tela in TELAS" :key="tela"><Icone :nome="tela" /></span>
                    </div>
                    <h2 class="pb-h2">{{ historia.titulo.antes }} <em class="cor-amarelo">{{ historia.titulo.marcado }}</em></h2>
                    <p v-if="historia.sub" class="pb-sub">{{ historia.sub }}</p>
                    <p class="pb-lead">{{ historia.texto }}</p>
                </div>
                <div class="pb-historia-midia pb-reveal">
                    <div class="pb-snap" aria-hidden="true"><Cubo cor="laranja" :tamanho="42" /><Cubo cor="azul" :tamanho="42" /></div>
                    <div class="pb-media pb-media-video">
                        <video v-if="videoUrl" ref="videoEl" :src="videoUrl" :poster="historia.foto || capa" muted loop playsinline preload="none" :aria-label="product.name"></video>
                        <img v-else :src="historia.foto || capa" :srcset="srcsetDe(historia.foto || capa)" sizes="(max-width: 767px) 340px, 360px" width="720" height="1280" :alt="product.name" loading="lazy" decoding="async">
                    </div>
                    <span class="pb-adesivo" aria-hidden="true">Uau!</span>
                </div>
                <div class="pb-cta pb-reveal"><button type="button" class="pb-btn" @click="emit('comprar')">{{ cta('historia', 'Quero o meu kit') }} <Icone nome="arrow-right" :tamanho="22" /></button></div>
            </div>
        </section>

        <!-- Por que a criançada não larga -->
        <section v-if="porque" class="pb-sec fundo-branco">
            <div class="pb-wrap">
                <div class="pb-head pb-narrow pb-reveal">
                    <p class="pb-eyebrow"><span class="pb-cubos" aria-hidden="true"><Cubo cor="laranja" :tamanho="16" /><Cubo cor="amarelo" :tamanho="16" /><Cubo cor="azul" :tamanho="16" /></span>O segredo da brincadeira</p>
                    <h2 class="pb-h2">{{ porque.titulo.antes }} <em class="cor-azul">{{ porque.titulo.marcado }}</em></h2>
                </div>
                <div v-if="porque.foto" class="pb-media-chamadas pb-media-560 pb-reveal">
                    <div class="pb-media"><img :src="porque.foto" :srcset="srcsetDe(porque.foto)" sizes="(max-width: 767px) 92vw, 560px" width="1200" height="1200" :alt="porque.titulo.antes + ' ' + porque.titulo.marcado" loading="lazy" decoding="async"></div>
                    <span v-for="(c, j) in chamadasPorque" :key="j" class="pb-chamada" :class="`pos-${j}`">{{ c }}</span>
                </div>
                <div v-if="beneficios.length" class="pb-why-cards pb-reveal">
                    <div v-for="(b, j) in beneficios" :key="j" class="pb-why-card">
                        <span class="pb-why-ico" :class="`cor-${b.cor}`"><Icone :nome="b.icone" :tamanho="28" :traco="2.4" /></span>
                        <h3 class="pb-h3">{{ b.titulo }}</h3>
                        <p>{{ b.texto }}</p>
                    </div>
                </div>
                <div class="pb-cta pb-reveal"><button type="button" class="pb-btn" @click="emit('comprar')">{{ cta('porque', 'Quero ver brincando') }} <Icone nome="arrow-right" :tamanho="22" /></button></div>
            </div>
        </section>

        <!-- Brincadeira que ensina -->
        <section v-if="ensina" class="pb-sec fundo-creme">
            <div class="pb-wrap">
                <div class="pb-head pb-narrow pb-reveal">
                    <p class="pb-eyebrow"><span class="pb-cubos" aria-hidden="true"><Cubo cor="laranja" :tamanho="16" /><Cubo cor="amarelo" :tamanho="16" /><Cubo cor="azul" :tamanho="16" /></span>Brincadeira que ensina</p>
                    <h2 class="pb-h2">{{ ensina.titulo.antes }} <em class="cor-verde">{{ ensina.titulo.marcado }}</em></h2>
                    <p class="pb-lead">{{ ensina.texto }}</p>
                </div>
                <div v-if="habilidades.length" class="pb-sk-list pb-reveal">
                    <div v-for="(h, j) in habilidades" :key="j" class="pb-sk-item"><span class="pb-sk-ico" :class="`cor-${h.cor}`"><Icone :nome="h.icone" :tamanho="22" :traco="2.4" /></span>{{ h.texto }}</div>
                </div>
                <div v-if="ensina.foto" class="pb-media-chamadas pb-media-620 pb-reveal">
                    <div class="pb-media"><img :src="ensina.foto" :srcset="srcsetDe(ensina.foto)" sizes="(max-width: 767px) 92vw, 414px" width="828" height="1088" :alt="ensina.titulo.antes + ' ' + ensina.titulo.marcado" loading="lazy" decoding="async"></div>
                    <span v-for="(c, j) in chamadasKit" :key="j" class="pb-chamada" :class="`pos-${j}`">{{ c }}</span>
                </div>
                <div class="pb-cta pb-reveal"><button type="button" class="pb-btn" @click="emit('comprar')">{{ cta('ensina', 'Quero esse presente') }} <Icone nome="arrow-right" :tamanho="22" /></button></div>
            </div>
        </section>

        <!-- Detalhes -->
        <section v-if="detalhe" class="pb-sec fundo-branco">
            <div class="pb-wrap">
                <div class="pb-head pb-narrow pb-reveal">
                    <p class="pb-eyebrow"><span class="pb-cubos" aria-hidden="true"><Cubo cor="laranja" :tamanho="16" /><Cubo cor="amarelo" :tamanho="16" /><Cubo cor="azul" :tamanho="16" /></span>Feito para durar</p>
                    <h2 class="pb-h2">{{ detalhe.titulo.antes }} <em class="cor-vermelho">{{ detalhe.titulo.marcado }}</em></h2>
                    <p class="pb-lead">{{ detalhe.texto }}</p>
                </div>
                <div v-if="detalhe.foto" class="pb-media-chamadas pb-media-520 pb-reveal">
                    <div class="pb-media"><img :src="detalhe.foto" :srcset="srcsetDe(detalhe.foto)" sizes="(max-width: 767px) 92vw, 520px" width="1200" height="1200" :alt="detalhe.titulo.antes + ' ' + detalhe.titulo.marcado" loading="lazy" decoding="async"></div>
                    <span v-for="(d, j) in detalhes" :key="j" class="pb-chamada com-linha" :class="`pos-${j}`">{{ d.titulo }}</span>
                </div>
                <!-- Mesmo cartão centralizado da referência (quadrado colorido com borda e sombra, ícone branco). -->
                <div v-if="detalhes.length" class="pb-why-cards pb-reveal">
                    <div v-for="(d, j) in detalhes" :key="j" class="pb-why-card">
                        <span class="pb-why-ico" :class="`cor-${d.cor}`"><Icone :nome="d.icone" :tamanho="28" :traco="2.4" /></span>
                        <h3 class="pb-h3">{{ d.titulo }}</h3>
                        <p>{{ d.texto }}</p>
                    </div>
                </div>
                <div class="pb-cta pb-reveal"><button type="button" class="pb-btn" @click="emit('comprar')">{{ cta('detalhes', 'Escolher meu kit') }} <Icone nome="arrow-right" :tamanho="22" /></button></div>
            </div>
        </section>

        <!-- Comparativo -->
        <section v-if="comparativo" class="pb-sec fundo-tinta">
            <div class="pb-wrap">
                <div class="pb-head pb-narrow pb-reveal">
                    <p class="pb-eyebrow claro"><span class="pb-cubos" aria-hidden="true"><Cubo cor="laranja" :tamanho="16" /><Cubo cor="amarelo" :tamanho="16" /><Cubo cor="azul" :tamanho="16" /></span>A troca que vale a pena</p>
                    <h2 class="pb-h2">{{ comparativo.titulo.antes }} <em class="cor-amarelo">{{ comparativo.titulo.marcado }}</em></h2>
                    <p v-if="comparativo.intro" class="pb-lead">{{ comparativo.intro }}</p>
                </div>
                <div class="pb-vs-table pb-reveal">
                    <div class="pb-vs-row pb-vs-top"><span></span><span class="pb-vs-col pb-vs-us">{{ comparativo.rotulos[1] }}</span><span class="pb-vs-col pb-vs-them">{{ comparativo.rotulos[0] }}</span></div>
                    <div v-for="(linha, j) in comparativo.linhas" :key="j" class="pb-vs-row" :style="{ '--i': j }">
                        <span class="pb-vs-txt">{{ linha.texto }}</span>
                        <i class="pb-vs-mark sim" :aria-label="`${comparativo.rotulos[1]}: sim`"><Icone nome="check" :tamanho="20" :traco="3" /></i>
                        <i class="pb-vs-mark" :class="linha.comum ? 'sim' : 'nao'" :aria-label="`${comparativo.rotulos[0]}: ${linha.comum ? 'sim' : 'não'}`"><Icone :nome="linha.comum ? 'check' : 'x'" :tamanho="20" :traco="3" /></i>
                    </div>
                </div>
                <div class="pb-cta pb-reveal"><button type="button" class="pb-btn" @click="emit('comprar')">{{ cta('comparativo', 'Quero trocar a tela pelo brinquedo') }} <Icone nome="arrow-right" :tamanho="22" /></button></div>
            </div>
        </section>

        <!-- Família -->
        <section v-if="familia" class="pb-sec fundo-branco">
            <div class="pb-wrap">
                <div class="pb-head pb-narrow pb-reveal">
                    <p class="pb-eyebrow"><span class="pb-cubos" aria-hidden="true"><Cubo cor="laranja" :tamanho="16" /><Cubo cor="amarelo" :tamanho="16" /><Cubo cor="azul" :tamanho="16" /></span>Hora de brincar junto</p>
                    <h2 class="pb-h2">{{ familia.titulo.antes }} <em class="cor-amarelo">{{ familia.titulo.marcado }}</em></h2>
                    <p class="pb-lead">{{ familia.texto }}</p>
                </div>
                <div class="pb-fm-collage pb-reveal">
                    <div class="pb-fm-a"><img :src="familia.foto || capa" :srcset="srcsetDe(familia.foto || capa)" sizes="(max-width: 767px) 85vw, 446px" width="1200" height="1200" :alt="familia.titulo.antes + ' ' + familia.titulo.marcado" loading="lazy" decoding="async"></div>
                    <div v-if="fotoExtra" class="pb-fm-b"><img :src="fotoExtra" :srcset="srcsetDe(fotoExtra)" sizes="220px" width="512" height="512" alt="" loading="lazy" decoding="async"></div>
                    <span class="pb-fm-heart" aria-hidden="true"><Icone nome="heart" :tamanho="28" :traco="2.4" /></span>
                </div>
                <p v-if="citacao" class="pb-fm-quote pb-reveal">{{ citacao }}</p>
                <div class="pb-cta pb-reveal"><button type="button" class="pb-btn" @click="emit('comprar')">{{ cta('familia', 'Quero brincar junto') }} <Icone nome="arrow-right" :tamanho="22" /></button></div>
            </div>
        </section>

        <!-- Kits -->
        <section v-if="mostrarKits" id="kits" class="pb-sec fundo-creme">
            <div class="pb-wrap">
                <div class="pb-head pb-narrow pb-reveal">
                    <p class="pb-eyebrow"><span class="pb-cubos" aria-hidden="true"><Cubo cor="laranja" :tamanho="16" /><Cubo cor="amarelo" :tamanho="16" /><Cubo cor="azul" :tamanho="16" /></span>Monte a sua diversão</p>
                    <h2 class="pb-h2">{{ kitsTitulo.antes }} <em class="cor-amarelo">{{ kitsTitulo.marcado }}</em></h2>
                    <p v-if="kitsTexto" class="pb-lead">{{ kitsTexto }}</p>
                    <p v-else-if="opcoes.length > 1" class="pb-lead">Quanto mais brinquedos no kit, <b>menor o preço de cada um</b>.</p>
                </div>
                <KitsBrinquedo :opcoes="opcoes" :escolhida-id="escolhidaId" formato="cartoes" @escolher="(kit) => emit('escolher', kit, true)" />
            </div>
        </section>

        <!-- Como funciona -->
        <section class="pb-sec fundo-creme pb-sec-colada">
            <div class="pb-wrap">
                <div class="pb-head pb-narrow pb-reveal">
                    <p class="pb-eyebrow"><span class="pb-cubos" aria-hidden="true"><Cubo cor="laranja" :tamanho="16" /><Cubo cor="amarelo" :tamanho="16" /><Cubo cor="azul" :tamanho="16" /></span>Como funciona</p>
                    <h2 class="pb-h2">Do clique na loja à <em class="cor-azul">brincadeira</em></h2>
                </div>
                <div class="pb-st-grid pb-reveal">
                    <div v-for="(passo, j) in passos" :key="j" class="pb-st">
                        <div class="pb-st-n"><i :class="`cor-${passo.cor}`">{{ j + 1 }}</i><Icone :nome="passo.icone" :tamanho="28" /></div>
                        <div><h3 class="pb-h3">{{ passo.titulo }}</h3><p>{{ passo.texto }}</p></div>
                    </div>
                </div>
                <div class="pb-cta pb-reveal"><button type="button" class="pb-btn" @click="emit('comprar')">{{ cta('passos', 'Começar a diversão') }} <Icone nome="arrow-right" :tamanho="22" /></button></div>
            </div>
        </section>

        <!-- Garantia -->
        <section class="pb-sec fundo-creme pb-sec-colada">
            <div class="pb-wrap">
                <div class="pb-gt-box pb-reveal">
                    <div class="pb-gt-seal" aria-hidden="true">
                        <svg viewBox="0 0 150 150" class="pb-gt-ring">
                            <defs><path id="pb-anel" d="M75,75 m-58,0 a58,58 0 1,1 116,0 a58,58 0 1,1 -116,0" /></defs>
                            <circle cx="75" cy="75" r="72" fill="#ffc21a" stroke="#17182c" stroke-width="3" />
                            <text font-size="12.5" font-weight="800" letter-spacing="2.2" fill="#17182c" font-family="Nunito, sans-serif"><textPath href="#pb-anel">GARANTIA KAZAKORA • 30 DIAS • GARANTIA KAZAKORA • 30 DIAS •</textPath></text>
                            <circle cx="75" cy="75" r="44" fill="#21a346" stroke="#17182c" stroke-width="3" />
                        </svg>
                        <b>30</b><span>dias</span>
                    </div>
                    <p class="pb-eyebrow"><span class="pb-cubos" aria-hidden="true"><Cubo cor="laranja" :tamanho="16" /><Cubo cor="amarelo" :tamanho="16" /><Cubo cor="azul" :tamanho="16" /></span>Garantia KazaKora de 30 dias</p>
                    <h2 class="pb-h2">Mais diversão <em class="cor-verde">ou seu dinheiro de volta</em></h2>
                    <p class="pb-lead">Teste por <b>30 dias</b>. A gente tem certeza de que a criança vai ficar:</p>
                    <div class="pb-gt-ps">
                        <div v-for="(g, j) in garantia" :key="j" class="pb-gt-p"><Cubo :cor="corCubo(g.cor)" :tamanho="30" />{{ g.texto }}</div>
                    </div>
                    <p class="pb-gt-or">Se não for assim, <em>devolvemos 100%</em> do seu dinheiro.</p>
                    <p class="pb-gt-fine">É só falar com a gente em até 30 dias após o recebimento.</p>
                    <div class="pb-cta"><button type="button" class="pb-btn" @click="emit('comprar')">{{ cta('garantia', 'Experimentar sem risco') }} <Icone nome="arrow-right" :tamanho="22" /></button></div>
                </div>
            </div>
        </section>

        <!-- Perguntas frequentes -->
        <section v-if="duvidas.length" class="pb-sec fundo-branco">
            <div class="pb-wrap">
                <div class="pb-head pb-faq-head pb-reveal">
                    <p class="pb-eyebrow"><span class="pb-cubos" aria-hidden="true"><Cubo cor="laranja" :tamanho="16" /><Cubo cor="amarelo" :tamanho="16" /><Cubo cor="azul" :tamanho="16" /></span>Tire suas dúvidas</p>
                    <h2 class="pb-h2">Perguntas <em class="cor-azul">frequentes</em></h2>
                </div>
                <div class="pb-faq pb-reveal">
                    <div v-for="(d, j) in duvidas" :key="j" class="pb-faq-item" :class="{ aberto: abertaFaq === j }">
                        <button type="button" class="pb-faq-toggle" :aria-expanded="abertaFaq === j" @click="abertaFaq = abertaFaq === j ? -1 : j">
                            <span>{{ d[0] }}</span><i class="pb-faq-seta" aria-hidden="true"><Icone nome="chevron-down" :tamanho="16" :traco="3" /></i>
                        </button>
                        <div v-show="abertaFaq === j" class="pb-faq-body">{{ d[1] }}</div>
                    </div>
                    <details v-if="descriptionSections.length || dimensoes" class="pb-faq-item pb-detalhes">
                        <summary class="pb-faq-toggle"><span>Descrição completa e ficha técnica</span><i class="pb-faq-seta" aria-hidden="true"><Icone nome="chevron-down" :tamanho="16" :traco="3" /></i></summary>
                        <div class="pb-faq-body">
                            <div v-for="(section, j) in descriptionSections" :key="j">
                                <h3 v-if="section.heading">{{ section.heading }}</h3>
                                <template v-for="(part, k) in section.parts" :key="k">
                                    <ul v-if="part.type === 'ul' || part.type === 'linhas'"><li v-for="(item, n) in part.items" :key="n">{{ item }}</li></ul>
                                    <p v-else>{{ part.items[0] }}</p>
                                </template>
                            </div>
                            <p v-if="dimensoes"><strong>Dimensões da embalagem:</strong> {{ dimensoes }}</p>
                        </div>
                    </details>
                </div>
            </div>
        </section>

        <!-- Chamada final -->
        <section class="pb-sec fundo-tinta pb-fn">
            <div class="pb-fn-cubes" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
            <div class="pb-wrap">
                <div class="pb-fn-box pb-reveal">
                    <p class="pb-eyebrow claro"><span class="pb-cubos" aria-hidden="true"><Cubo cor="laranja" :tamanho="16" /><Cubo cor="amarelo" :tamanho="16" /><Cubo cor="azul" :tamanho="16" /></span>Bora brincar?</p>
                    <h2 class="pb-h2">{{ final.titulo.antes }} <em class="cor-amarelo">{{ final.titulo.marcado }}</em></h2>
                    <p class="pb-lead">{{ final.texto }}</p>
                    <div class="pb-cta"><button type="button" class="pb-btn" @click="emit('comprar')">{{ cta('final', 'Escolher meu kit') }} <Icone nome="arrow-right" :tamanho="22" /></button></div>
                    <div class="pb-fn-badges">
                        <span><Icone nome="shield-check" :tamanho="18" /> Garantia de 30 dias</span>
                        <span><Icone nome="truck" :tamanho="18" /> Envio com rastreio</span>
                        <span><Icone nome="lock" :tamanho="18" /> Compra segura</span>
                        <span><Icone nome="credit-card" :tamanho="18" /> Pix ou até {{ parcelas }}x</span>
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>
