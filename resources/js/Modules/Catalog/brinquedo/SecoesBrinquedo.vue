<script setup>
// Seções da página de brinquedo (pedido do Lira 24/11/2026, "quero que páginas de
// brinquedo sejam assim"): a estrutura de loja infantil que ele mandou de referência,
// com a cara e os textos da KazaKora. Todo texto do produto sai do conteúdo do
// anúncio (product_ad_contents); os textos fixos aqui são da loja (entrega,
// garantia, pagamento) e valem para qualquer brinquedo.
import { computed } from 'vue';
import KitsBrinquedo from './KitsBrinquedo.vue';
import { semEmoji, tituloComMarcador } from './kits';

const props = defineProps({
    product: { type: Object, required: true },
    anuncio: { type: Object, default: null },
    opcoes: { type: Array, default: () => [] },
    escolhidaId: { type: Number, default: null },
    descriptionSections: { type: Array, default: () => [] },
    dimensoes: { type: String, default: null },
    faqLoja: { type: Array, default: () => [] },
    parcelas: { type: Number, default: 12 },
});
const emit = defineEmits(['escolher', 'comprar']);

const CORES = ['amarelo', 'azul', 'verde', 'laranja'];
const SOBRETITULOS = ['Diversão de verdade', 'Brincadeira que ensina', 'Feito para a criançada', 'Pensado nos detalhes', 'Hora de brincar junto', 'Tudo o que vem na caixa'];
const BOTOES = ['Quero o meu kit', 'Escolher meu kit', 'Quero esse presente', 'Garantir o meu', 'Quero ver brincando', 'Escolher agora'];

const nomeCurto = computed(() => semEmoji(props.product.name).split(/[,–\-|]/)[0].split(' ').slice(0, 3).join(' '));

const imagem = (indice) => {
    if (indice === null || indice === undefined) return null;
    const foto = props.product.images?.[indice];
    return foto ? (foto.media_url ?? foto.url) : null;
};

const blocos = computed(() => (props.anuncio?.blocos ?? []).slice(0, 6).map((bloco, i) => ({
    ...bloco,
    titulo: tituloComMarcador(bloco.titulo),
    texto: semEmoji(bloco.texto),
    foto: imagem(bloco.imagem),
    cor: CORES[i % CORES.length],
    sobre: SOBRETITULOS[i % SOBRETITULOS.length],
    botao: BOTOES[i % BOTOES.length],
    fundo: i % 2 === 0 ? 'creme' : 'branco',
})));

const ICONES = ['fa-star', 'fa-heart', 'fa-face-smile', 'fa-wand-magic-sparkles', 'fa-puzzle-piece', 'fa-gift'];
const beneficios = computed(() => (props.anuncio?.beneficios ?? []).slice(0, 6).map((item, i) => ({
    titulo: semEmoji(item[0]), texto: semEmoji(item[1] ?? ''), cor: CORES[i % CORES.length], icone: ICONES[i % ICONES.length],
})));
const habilidades = computed(() => (props.anuncio?.comparativo?.habilidades ?? []).map(semEmoji).filter(Boolean).slice(0, 8));

const comparativo = computed(() => {
    const c = props.anuncio?.comparativo;
    if (!c?.linhas?.length) return null;
    const rotulos = Array.isArray(c.rotulos) && c.rotulos.length === 2 ? c.rotulos : ['Brinquedo comum', nomeCurto.value];
    return {
        titulo: tituloComMarcador(c.titulo || `Por que escolher ${nomeCurto.value}`),
        intro: semEmoji(c.intro),
        rotulos,
        linhas: c.linhas.map((linha) => ({ criterio: semEmoji(linha[0]), ruim: semEmoji(linha[1]), bom: semEmoji(linha[2]) })),
    };
});

const letreiro = computed(() => {
    const itens = [...(props.anuncio?.destaques ?? []), ...(props.anuncio?.beneficios ?? []).map((b) => b[0])]
        .map(semEmoji).filter(Boolean);
    const unicos = [...new Set(itens)].slice(0, 8);
    return unicos.length ? unicos : ['Frete grátis', 'Compra segura', `Até ${props.parcelas}x no cartão`, 'Garantia de 30 dias'];
});

const duvidas = computed(() => [
    ...(props.anuncio?.duvidas ?? []).map((d) => [semEmoji(d[0]), semEmoji(d[1])]),
    ...props.faqLoja,
]);

const PASSOS = [
    { icone: 'fa-gift', titulo: 'Escolha o kit', texto: 'Do kit para começar ao mais completo: é só escolher o tamanho da diversão.' },
    { icone: 'fa-truck-fast', titulo: 'Enviamos com rastreio', texto: 'O código aparece em Meus Pedidos assim que o pedido é postado.' },
    { icone: 'fa-box-open', titulo: 'Chegou a caixa', texto: 'A criança abre, descobre o brinquedo e os olhos brilham.' },
    { icone: 'fa-face-smile', titulo: 'Hora de brincar', texto: 'Brincadeira de verdade, longe das telas, para aprender se divertindo.' },
];
const GARANTIA = [
    { cor: 'laranja', texto: 'Criança mais feliz' },
    { cor: 'azul', texto: 'Mais tempo longe das telas' },
    { cor: 'amarelo', texto: 'Brincando e aprendendo' },
    { cor: 'verde', texto: 'Família brincando junto' },
];
</script>

<template>
    <div class="pb-secoes">
        <!-- Letreiro com os destaques do produto -->
        <div class="pb-letreiro" aria-hidden="true">
            <div class="pb-letreiro-faixa">
                <template v-for="volta in 2" :key="volta">
                    <span v-for="(item, i) in letreiro" :key="`${volta}-${i}`"><i class="pb-quadradinho" :class="`cor-${CORES[i % 4]}`"></i>{{ item }}</span>
                </template>
            </div>
        </div>

        <template v-for="(bloco, i) in blocos" :key="`bloco-${i}`">
            <section class="pb-sec" :class="`fundo-${bloco.fundo}`">
                <div class="pb-centro">
                    <p class="pb-sobre"><span class="pb-cubos" aria-hidden="true"><i></i><i></i><i></i></span>{{ bloco.sobre }}</p>
                    <h2 class="pb-h2">{{ bloco.titulo.antes }} <mark :class="`cor-${bloco.cor}`">{{ bloco.titulo.marcado }}</mark></h2>
                    <p class="pb-texto">{{ bloco.texto }}</p>
                    <figure v-if="bloco.foto" class="pb-moldura">
                        <img :src="bloco.foto" :alt="bloco.titulo.antes + ' ' + bloco.titulo.marcado" loading="lazy" decoding="async">
                    </figure>
                    <button type="button" class="pb-btn" @click="emit('comprar')">{{ bloco.botao }} <i class="fas fa-arrow-right" aria-hidden="true"></i></button>
                </div>
            </section>

            <!-- Depois da primeira história: os benefícios em cartões e as habilidades -->
            <section v-if="i === 0 && (beneficios.length || habilidades.length)" class="pb-sec fundo-branco">
                <div class="pb-centro">
                    <p class="pb-sobre"><span class="pb-cubos" aria-hidden="true"><i></i><i></i><i></i></span>O segredo da brincadeira</p>
                    <h2 class="pb-h2">Por que a criançada <mark class="cor-azul">não larga</mark></h2>
                    <ul v-if="beneficios.length" class="pb-cartoes-icone">
                        <li v-for="(b, j) in beneficios" :key="j">
                            <span class="pb-icone" :class="`cor-${b.cor}`"><i class="fas" :class="b.icone" aria-hidden="true"></i></span>
                            <strong>{{ b.titulo }}</strong>
                            <span>{{ b.texto }}</span>
                        </li>
                    </ul>
                    <template v-if="habilidades.length">
                        <h3 class="pb-h3">Enquanto brinca, a criança treina:</h3>
                        <ul class="pb-habilidades">
                            <li v-for="(h, j) in habilidades" :key="j"><i class="pb-quadradinho" :class="`cor-${CORES[j % 4]}`"></i>{{ h }}</li>
                        </ul>
                    </template>
                </div>
            </section>
        </template>

        <!-- Comparativo no fundo escuro -->
        <section v-if="comparativo" class="pb-sec fundo-tinta">
            <div class="pb-centro">
                <p class="pb-sobre claro"><span class="pb-cubos" aria-hidden="true"><i></i><i></i><i></i></span>A troca que vale a pena</p>
                <h2 class="pb-h2">{{ comparativo.titulo.antes }} <mark class="cor-amarelo">{{ comparativo.titulo.marcado }}</mark></h2>
                <p v-if="comparativo.intro" class="pb-texto">{{ comparativo.intro }}</p>
                <div class="pb-tabela">
                    <div class="pb-tabela-cab" aria-hidden="true">
                        <span></span><span class="bom">{{ comparativo.rotulos[1] }}</span><span class="ruim">{{ comparativo.rotulos[0] }}</span>
                    </div>
                    <div v-for="(linha, j) in comparativo.linhas" :key="j" class="pb-tabela-linha">
                        <strong>{{ linha.criterio }}</strong>
                        <span class="bom"><i class="fas fa-check" aria-hidden="true"></i><span><span class="sr-only">{{ comparativo.rotulos[1] }}: </span>{{ linha.bom }}</span></span>
                        <span class="ruim"><i class="fas fa-xmark" aria-hidden="true"></i><span><span class="sr-only">{{ comparativo.rotulos[0] }}: </span>{{ linha.ruim }}</span></span>
                    </div>
                </div>
                <button type="button" class="pb-btn" @click="emit('comprar')">Quero trocar a tela pelo brinquedo <i class="fas fa-arrow-right" aria-hidden="true"></i></button>
            </div>
        </section>

        <!-- Os kits de novo, em cartões com foto -->
        <section v-if="opcoes.length > 1" id="kits" class="pb-sec fundo-creme">
            <div class="pb-centro largo">
                <p class="pb-sobre"><span class="pb-cubos" aria-hidden="true"><i></i><i></i><i></i></span>Monte a sua diversão</p>
                <h2 class="pb-h2">Escolha o tamanho <mark class="cor-amarelo">da diversão</mark></h2>
                <p class="pb-texto">Quanto mais brinquedos no kit, menor o preço de cada um.</p>
                <KitsBrinquedo :opcoes="opcoes" :escolhida-id="escolhidaId" formato="cartoes" @escolher="(kit) => emit('escolher', kit, true)" />
            </div>
        </section>

        <!-- Como funciona -->
        <section class="pb-sec fundo-creme">
            <div class="pb-centro largo">
                <p class="pb-sobre"><span class="pb-cubos" aria-hidden="true"><i></i><i></i><i></i></span>Como funciona</p>
                <h2 class="pb-h2">Do clique na loja à <mark class="cor-azul">brincadeira</mark></h2>
                <ol class="pb-passos">
                    <li v-for="(passo, j) in PASSOS" :key="j">
                        <span class="pb-num" :class="`cor-${CORES[(j + 3) % 4]}`">{{ j + 1 }}</span>
                        <i class="fas pb-passo-icone" :class="passo.icone" aria-hidden="true"></i>
                        <strong>{{ passo.titulo }}</strong>
                        <span>{{ passo.texto }}</span>
                    </li>
                </ol>
                <button type="button" class="pb-btn" @click="emit('comprar')">Começar a diversão <i class="fas fa-arrow-right" aria-hidden="true"></i></button>
            </div>
        </section>

        <!-- Garantia -->
        <section class="pb-sec fundo-creme">
            <div class="pb-garantia">
                <div class="pb-garantia-selo" aria-hidden="true"><b>30</b><span>dias</span></div>
                <p class="pb-sobre"><span class="pb-cubos" aria-hidden="true"><i></i><i></i><i></i></span>Garantia KazaKora de 30 dias</p>
                <h2 class="pb-h2">Mais diversão <mark class="cor-verde">ou seu dinheiro de volta</mark></h2>
                <p class="pb-texto">Você tem <b>30 dias</b> depois de receber para ver a criança brincando:</p>
                <ul class="pb-garantia-itens">
                    <li v-for="(g, j) in GARANTIA" :key="j"><i class="pb-quadradinho grande" :class="`cor-${g.cor}`"></i>{{ g.texto }}</li>
                </ul>
                <p class="pb-garantia-frase">Se não gostar, <mark class="cor-amarelo">devolvemos 100%</mark> do seu dinheiro.</p>
                <p class="pb-pequeno">É só falar com a gente em até 30 dias após o recebimento.</p>
                <button type="button" class="pb-btn" @click="emit('comprar')">Experimentar sem risco <i class="fas fa-arrow-right" aria-hidden="true"></i></button>
            </div>
        </section>

        <!-- Perguntas frequentes -->
        <section v-if="duvidas.length" class="pb-sec fundo-branco">
            <div class="pb-centro">
                <p class="pb-sobre"><span class="pb-cubos" aria-hidden="true"><i></i><i></i><i></i></span>Tire suas dúvidas</p>
                <h2 class="pb-h2">Perguntas <mark class="cor-azul">frequentes</mark></h2>
                <div class="pb-faq">
                    <details v-for="(d, j) in duvidas" :key="j" :open="j === 0">
                        <summary><span>{{ d[0] }}</span><i class="fas fa-chevron-down" aria-hidden="true"></i></summary>
                        <p>{{ d[1] }}</p>
                    </details>
                </div>
            </div>
        </section>

        <!-- Detalhes completos do produto (a descrição de sempre, recolhida) -->
        <section v-if="descriptionSections.length || dimensoes" class="pb-sec fundo-branco pb-sec-detalhes">
            <div class="pb-centro">
                <details class="pb-detalhes">
                    <summary><span>Descrição completa e ficha técnica</span><i class="fas fa-chevron-down" aria-hidden="true"></i></summary>
                    <div class="pb-detalhes-corpo">
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
        </section>

        <!-- Chamada final -->
        <section class="pb-sec fundo-tinta pb-final">
            <i class="pb-confete c1" aria-hidden="true"></i><i class="pb-confete c2" aria-hidden="true"></i>
            <i class="pb-confete c3" aria-hidden="true"></i><i class="pb-confete c4" aria-hidden="true"></i>
            <div class="pb-centro">
                <p class="pb-sobre claro"><span class="pb-cubos" aria-hidden="true"><i></i><i></i><i></i></span>Bora brincar?</p>
                <h2 class="pb-h2">Dê a eles um presente <mark class="cor-amarelo">pra lembrar</mark></h2>
                <p class="pb-texto">Menos tela, mais imaginação, e um brinquedo que cresce junto com a criança.</p>
                <button type="button" class="pb-btn" @click="emit('comprar')">Escolher meu kit <i class="fas fa-arrow-right" aria-hidden="true"></i></button>
                <ul class="pb-confianca">
                    <li><i class="fas fa-shield-halved" aria-hidden="true"></i> Garantia de 30 dias</li>
                    <li><i class="fas fa-truck-fast" aria-hidden="true"></i> Envio com rastreio</li>
                    <li><i class="fas fa-lock" aria-hidden="true"></i> Compra segura</li>
                    <li><i class="far fa-credit-card" aria-hidden="true"></i> Pix ou até {{ parcelas }}x</li>
                </ul>
            </div>
        </section>
    </div>
</template>
