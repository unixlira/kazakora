<script setup>
// Descrição no formato de página de oferta (pedido 2026-10-20, tarefa 2031):
// visual do modelo que veio da Nuvemshop (hero escuro, "Por que você vai
// amar", "Veja na prática", caixa de oferta e confiança), preenchido pelo
// conteúdo do anúncio do próprio produto (ad_content) e pelas fotos dele.
// Preço, parcelas e Pix vêm da página (mesma conta do box de compra); nada
// de valor fixo aqui. O desconto do Pix aparece só em % (o valor final com
// Pix continua só no checkout, pedido 2026-10-18).
import { COMPANY } from '@/Shared/company';
import { formatPrice } from '@/Shared/productCard';
import { computed } from 'vue';

const props = defineProps({
    anuncio: { type: Object, required: true },
    nome: { type: String, required: true },
    imagens: { type: Array, default: () => [] },
    precoPor: { type: Number, required: true },
    precoDe: { type: Number, default: null },
    percentual: { type: Number, default: 0 },
    aPartirDe: { type: Boolean, default: false },
    parcelas: { type: Number, default: 12 },
    valorParcela: { type: Number, required: true },
    descontoPix: { type: String, default: '' },
    freteGratis: { type: Boolean, default: false },
    notaMedia: { type: Number, default: 0 },
    totalAvaliacoes: { type: Number, default: 0 },
    vendidos: { type: String, default: null },
    estoque: { type: Number, default: 0 },
    emEstoque: { type: Boolean, default: true },
    comprando: { type: Boolean, default: false },
});

defineEmits(['comprar']);

const urlDaImagem = (imagem) => imagem?.media_url ?? imagem?.url ?? null;
const imagemDoBloco = (indice) => (indice === null || indice === undefined ? null : urlDaImagem(props.imagens[indice]));
const fotoPrincipal = computed(() => urlDaImagem(props.imagens.find((i) => i.is_primary) ?? props.imagens[0]));

const blocos = computed(() => props.anuncio.blocos ?? []);
const beneficios = computed(() => props.anuncio.beneficios ?? []);
const destaques = computed(() => props.anuncio.destaques ?? []);
const comparativo = computed(() => (props.anuncio.comparativo?.linhas?.length ? props.anuncio.comparativo : null));
const duvidas = computed(() => props.anuncio.duvidas ?? []);

// Ícones da grade de benefícios, em rodízio (o conteúdo não traz ícone).
const ICONES = ['fa-bolt', 'fa-hand-sparkles', 'fa-shield-heart', 'fa-clock', 'fa-star', 'fa-leaf', 'fa-gem', 'fa-thumbs-up'];

// Escassez só com número real: estoque baixo de verdade (até 5).
const estoqueBaixo = computed(() => props.emEstoque && props.estoque > 0 && props.estoque <= 5);
// Nota só com 3 avaliações ou mais (revisão do Jonathan); vendidos sempre.
const mostraNota = computed(() => props.totalAvaliacoes >= 3);
const notaTexto = computed(() => props.notaMedia.toFixed(1).replace('.', ','));
const economia = computed(() => (props.precoDe ? props.precoDe - props.precoPor : 0));
</script>

<template>
    <section class="oferta-desc" aria-label="Descrição do produto">
        <!-- HERO -->
        <header class="od-hero">
            <span class="od-circulo od-circulo-a" aria-hidden="true"></span>
            <span class="od-circulo od-circulo-b" aria-hidden="true"></span>
            <p class="od-eyebrow"><span class="od-icone"><i class="fas fa-wand-magic-sparkles"></i></span> Por que esse produto merece atenção?</p>
            <h2>{{ anuncio.chamada || nome }}</h2>
            <p v-if="anuncio.chamada" class="od-sub">{{ nome }}</p>
            <p v-if="mostraNota || vendidos" class="od-prova">
                <template v-if="mostraNota">
                    <span class="od-estrelas" aria-hidden="true"><i v-for="n in 5" :key="n" :class="n <= Math.round(notaMedia) ? 'fas fa-star' : 'far fa-star'"></i></span>
                    <strong>{{ notaTexto }}</strong> de 5 em {{ totalAvaliacoes }} avaliaç{{ totalAvaliacoes === 1 ? 'ão' : 'ões' }}
                </template>
                <span v-if="mostraNota && vendidos" class="od-sep">·</span>
                <template v-if="vendidos">{{ vendidos }}</template>
            </p>
            <ul v-if="destaques.length" class="od-pilulas">
                <li v-for="(item, index) in destaques" :key="index"><i class="fas fa-check"></i> {{ item }}</li>
            </ul>
            <!-- CTA 1: preço e botão já no topo -->
            <div class="od-hero-compra">
                <p><strong>{{ aPartirDe ? 'A partir de ' : '' }}{{ formatPrice(precoPor) }}</strong> <span>ou {{ parcelas }}x de {{ formatPrice(valorParcela) }} sem juros</span></p>
                <button type="button" class="od-botao" :disabled="!emEstoque || comprando" @click="$emit('comprar')">
                    {{ !emEstoque ? 'Esgotado' : (comprando ? 'Abrindo o pagamento…' : 'Comprar agora') }}
                </button>
            </div>
            <div v-if="fotoPrincipal" class="od-hero-foto">
                <img :src="fotoPrincipal" :alt="nome" loading="lazy">
            </div>
        </header>

        <!-- POR QUE VOCÊ VAI AMAR -->
        <div v-if="beneficios.length" class="od-secao">
            <p class="od-rotulo">Por que você vai amar</p>
            <div class="od-grade">
                <div v-for="(item, index) in beneficios" :key="index" class="od-beneficio">
                    <span class="od-beneficio-icone"><i class="fas" :class="ICONES[index % ICONES.length]"></i></span>
                    <h3>{{ item[0] }}</h3>
                    <p v-if="item[1]">{{ item[1] }}</p>
                </div>
            </div>
        </div>

        <!-- VEJA NA PRÁTICA: um cartão por bloco, com a foto real escolhida para ele -->
        <div v-if="blocos.length" class="od-secao">
            <p class="od-rotulo">Veja na prática</p>
            <article v-for="(bloco, index) in blocos" :key="index" class="od-cartao" :class="{ 'sem-foto': !imagemDoBloco(bloco.imagem) }">
                <img v-if="imagemDoBloco(bloco.imagem)" :src="imagemDoBloco(bloco.imagem)" :alt="bloco.titulo" loading="lazy">
                <div class="od-cartao-texto">
                    <h3>{{ bloco.titulo }}</h3>
                    <p>{{ bloco.texto }}</p>
                </div>
            </article>
        </div>

        <!-- COMPARATIVO -->
        <div v-if="comparativo" class="od-secao">
            <p class="od-rotulo">{{ comparativo.titulo }}</p>
            <p v-if="comparativo.intro" class="od-intro">{{ comparativo.intro }}</p>
            <div class="od-tabela-wrap">
                <table class="od-tabela">
                    <thead><tr><th scope="col">Critério</th><th scope="col">Solução comum</th><th scope="col">Com este produto</th></tr></thead>
                    <tbody>
                        <tr v-for="(linha, index) in comparativo.linhas" :key="index">
                            <th scope="row">{{ linha[0] }}</th>
                            <td class="ruim"><i class="fas fa-xmark" aria-hidden="true"></i> {{ linha[1] }}</td>
                            <td class="bom"><i class="fas fa-check" aria-hidden="true"></i> {{ linha[2] }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- OFERTA: preço, parcelas e Pix do cadastro; o botão usa o mesmo "Comprar" do box -->
        <div class="od-secao">
            <div class="od-oferta">
                <div class="od-oferta-preco">
                    <p class="od-oferta-rotulo">{{ precoDe ? 'Oferta' : 'Preço KazaKora' }}{{ aPartirDe ? ' · a partir de' : '' }}</p>
                    <p v-if="precoDe" class="od-de">
                        <s>{{ formatPrice(precoDe) }}</s>
                        <span v-if="percentual > 0" class="od-selo">−{{ percentual }}%</span>
                    </p>
                    <p class="od-por">{{ formatPrice(precoPor) }}</p>
                    <p class="od-cond"><i class="fas fa-credit-card"></i> ou {{ parcelas }}x de {{ formatPrice(valorParcela) }} sem juros</p>
                    <p v-if="descontoPix" class="od-cond od-pix"><i class="fa-brands fa-pix"></i> {{ descontoPix }} de desconto no Pix, aplicado no checkout</p>
                    <p v-if="economia >= 1" class="od-cond od-economia">Você economiza {{ formatPrice(economia) }}</p>
                </div>
                <div class="od-oferta-acao">
                    <ul class="od-oferta-lista">
                        <li v-if="freteGratis"><i class="fas fa-truck-fast"></i> Frete grátis</li>
                        <li><i class="fas fa-file-invoice"></i> Nota fiscal em toda compra</li>
                    </ul>
                    <button type="button" class="od-botao" :disabled="!emEstoque || comprando" @click="$emit('comprar')">
                        <template v-if="!emEstoque">Esgotado</template>
                        <template v-else-if="comprando">Abrindo o pagamento…</template>
                        <template v-else>Comprar agora <i class="fas fa-arrow-right"></i></template>
                    </button>
                    <p v-if="estoqueBaixo" class="od-estoque"><i class="fas fa-fire"></i> Restam {{ estoque }} unidade{{ estoque === 1 ? '' : 's' }}</p>
                    <p class="od-garantia"><i class="fas fa-shield-halved"></i> Não gostou? Você tem 7 dias para desistir e 30 dias para troca por defeito.</p>
                </div>
            </div>
        </div>

        <!-- DÚVIDAS -->
        <div v-if="duvidas.length" class="od-secao">
            <p class="od-rotulo">Ficou alguma dúvida?</p>
            <details v-for="(duvida, index) in duvidas" :key="index" class="od-duvida">
                <summary>{{ duvida[0] }}</summary>
                <p>{{ duvida[1] }}</p>
            </details>
        </div>

        <!-- CONFIANÇA KAZAKORA: só o que a loja tem de verdade -->
        <div class="od-secao od-ultima">
            <div class="od-confianca">
                <p class="od-rotulo">Compre com tranquilidade</p>
                <ul>
                    <li><i class="fas fa-circle-check"></i> Verificada no Reclame Aqui</li>
                    <li><i class="fas fa-circle-check"></i> CNPJ {{ COMPANY.cnpj }}</li>
                    <li><i class="fas fa-circle-check"></i> Nota fiscal em toda compra</li>
                    <li><i class="fas fa-circle-check"></i> 7 dias para desistir</li>
                    <li><i class="fas fa-circle-check"></i> Atendimento no WhatsApp</li>
                    <li v-if="freteGratis"><i class="fas fa-circle-check"></i> Frete grátis</li>
                </ul>
            </div>
        </div>
    </section>
</template>

<style scoped>
/* Laranja oficial do logo (#F1691B, definição do Lira) e o preto do modelo. */
.oferta-desc {
    --od-laranja: #f1691b; --od-laranja-claro: #fff3ec; --od-preto: #111; --od-borda: var(--line, #eee);
    --od-texto: var(--text, #555); --od-titulo: var(--ink, #111); --od-card: var(--card, #fff);
    background: var(--od-card); border-radius: 20px; overflow: hidden; box-shadow: var(--shadow, none); color: var(--od-texto);
}
.oferta-desc h2, .oferta-desc h3 { margin: 0; line-height: 1.2; }
.oferta-desc p { margin: 0; }

/* HERO */
.od-hero { position: relative; overflow: hidden; padding: 34px 28px 0; border-radius: 0 0 26px 26px; background: linear-gradient(135deg, #0d0d0d 0%, #1f1f1f 55%, #3a1f12 100%); color: #f3f3f3; }
.od-circulo { position: absolute; border-radius: 50%; pointer-events: none; }
.od-circulo-a { top: -60px; right: -60px; width: 190px; height: 190px; background: rgba(255, 255, 255, .08); }
.od-circulo-b { bottom: -50px; left: -45px; width: 160px; height: 160px; background: rgba(241, 105, 27, .14); }
.od-hero > :not(.od-circulo) { position: relative; }
.od-eyebrow { display: flex; align-items: center; gap: 10px; font-size: 12px; font-weight: 800; letter-spacing: .18em; text-transform: uppercase; color: var(--od-laranja); margin-bottom: 14px !important; }
.od-icone { display: inline-flex; flex: 0 0 auto; width: 32px; height: 32px; border-radius: 50%; align-items: center; justify-content: center; background: #f59a62; color: #fff; font-size: 13px; letter-spacing: 0; }
.od-hero h2 { font-size: 32px; font-weight: 700; color: #fff; max-width: 620px; margin-bottom: 10px; }
.od-sub { font-size: 15px; color: rgba(255, 255, 255, .75); max-width: 560px; margin-bottom: 14px !important; }
.od-prova { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; font-size: 14px; color: rgba(255, 255, 255, .85); margin-bottom: 16px !important; }
.od-prova strong { color: #fff; }
.od-estrelas { color: #fcbd10; display: inline-flex; gap: 2px; }
.od-sep { opacity: .5; }
.od-pilulas { list-style: none; margin: 0 0 24px; padding: 0; display: flex; flex-wrap: wrap; gap: 8px; }
.od-pilulas li { background: #fff; color: var(--od-laranja); border-radius: 999px; padding: 7px 14px; font-size: 13px; font-weight: 700; line-height: 1.3; }
.od-pilulas i { margin-right: 4px; }
.od-hero-compra { display: flex; flex-wrap: wrap; align-items: center; gap: 12px 18px; margin-bottom: 24px; }
.od-hero-compra p { font-size: 14px; color: rgba(255, 255, 255, .8); }
.od-hero-compra strong { font-size: 24px; font-weight: 800; color: #fff; margin-right: 4px; }
.od-hero-compra .od-botao { min-height: 46px; font-size: 16px; }
.od-hero-foto { background: rgba(255, 255, 255, .08); border-radius: 20px 20px 0 0; padding: 14px 14px 0; }
.od-hero-foto img { display: block; width: 100%; max-width: 520px; aspect-ratio: 1; object-fit: contain; margin: 0 auto; border-radius: 16px 16px 0 0; background: #fff; }

/* SEÇÕES */
.od-secao { padding: 28px 28px 0; }
.od-ultima { padding-bottom: 28px; }
.od-rotulo { font-size: 12px; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; color: var(--od-laranja); margin-bottom: 14px !important; }
.od-intro { font-size: 15px; margin-bottom: 12px !important; }

.od-grade { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.od-beneficio { border: 1px solid var(--od-borda); border-radius: 16px; padding: 18px; background: var(--od-card); }
.od-beneficio-icone { display: inline-flex; width: 40px; height: 40px; border-radius: 12px; align-items: center; justify-content: center; background: var(--od-laranja-claro); color: var(--od-laranja); font-size: 17px; margin-bottom: 10px; }
.od-beneficio h3 { font-size: 15px; font-weight: 700; color: var(--od-titulo); margin-bottom: 6px; }
.od-beneficio p { font-size: 14px; line-height: 1.55; }

.od-cartao { border: 1px solid var(--od-borda); border-radius: 20px; overflow: hidden; background: var(--od-card); }
.od-cartao + .od-cartao { margin-top: 18px; }
.od-cartao img { display: block; width: 100%; max-height: 560px; object-fit: contain; background: #fff; }
.od-cartao-texto { padding: 18px 20px 20px; }
.od-cartao h3 { font-size: 20px; font-weight: 700; color: var(--od-titulo); margin-bottom: 8px; }
.od-cartao p { font-size: 15px; line-height: 1.7; }
.od-cartao.sem-foto { border-left: 4px solid var(--od-laranja); background: var(--od-laranja-claro); border-radius: 0 16px 16px 0; }

.od-tabela-wrap { overflow-x: auto; border: 1px solid var(--od-borda); border-radius: 14px; }
.od-tabela { width: 100%; border-collapse: collapse; font-size: 14px; }
.od-tabela th, .od-tabela td { padding: 11px 12px; text-align: left; vertical-align: top; border-bottom: 1px solid var(--od-borda); }
.od-tabela tr:last-child th, .od-tabela tr:last-child td { border-bottom: 0; }
.od-tabela thead th { background: var(--soft, #f9f9f9); color: var(--od-titulo); font-size: 13px; }
.od-tabela tbody th { color: var(--od-titulo); font-weight: 700; }
.od-tabela .ruim { color: #b42318; }
.od-tabela .bom { color: #16803c; font-weight: 700; }

/* OFERTA */
.od-oferta { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 20px; background: var(--od-preto); color: #fff; border-radius: 20px; padding: 24px; }
.od-oferta-rotulo { font-size: 11px; letter-spacing: .16em; text-transform: uppercase; color: rgba(255, 255, 255, .7); margin-bottom: 6px !important; }
.od-de { display: flex; align-items: center; gap: 8px; font-size: 15px; color: rgba(255, 255, 255, .55); }
.od-selo { background: var(--od-laranja); color: #fff; border-radius: 6px; padding: 1px 7px; font-size: 12px; font-weight: 800; }
.od-por { font-size: 36px; line-height: 1.1; font-weight: 800; color: #fff; margin: 2px 0 6px !important; }
.od-cond { font-size: 14px; color: rgba(255, 255, 255, .85); }
.od-cond i { width: 18px; color: rgba(255, 255, 255, .6); }
.od-pix { color: #4ade80; font-weight: 700; }
.od-pix i { color: #4ade80; }
.od-economia { margin-top: 4px !important; color: #fdba8c; font-weight: 700; }
.od-oferta-acao { display: flex; flex-direction: column; gap: 12px; min-width: 240px; }
.od-oferta-lista { list-style: none; margin: 0; padding: 0; display: grid; gap: 4px; font-size: 13px; color: rgba(255, 255, 255, .8); }
.od-oferta-lista i { width: 20px; color: var(--od-laranja); }
.od-botao { display: inline-flex; align-items: center; justify-content: center; gap: 10px; min-height: 52px; padding: 12px 24px; border: 0; border-radius: 12px; background: var(--od-laranja); color: #fff; font: inherit; font-size: 17px; font-weight: 800; cursor: pointer; transition: transform .15s, background .15s; }
.od-botao:hover:not(:disabled) { background: #d9570f; transform: translateY(-1px); }
.od-botao:disabled { opacity: .6; cursor: not-allowed; }
.od-botao:focus-visible { outline: 3px solid #fff; outline-offset: 2px; }
.od-garantia { font-size: 12.5px; line-height: 1.5; color: rgba(255, 255, 255, .75); max-width: 300px; }
.od-garantia i { color: #22c55e; margin-right: 4px; }
.od-estoque { font-size: 13px; font-weight: 700; color: #fdba8c; text-align: center; }

/* DÚVIDAS */
.od-duvida { border: 1px solid var(--od-borda); border-radius: 14px; padding: 0 16px; }
.od-duvida + .od-duvida { margin-top: 10px; }
.od-duvida summary { cursor: pointer; padding: 14px 0; font-weight: 700; color: var(--od-titulo); list-style: none; display: flex; justify-content: space-between; gap: 12px; }
.od-duvida summary::-webkit-details-marker { display: none; }
.od-duvida summary::after { content: '+'; color: var(--od-laranja); font-size: 20px; line-height: 1; }
.od-duvida[open] summary::after { content: '−'; }
.od-duvida p { padding-bottom: 14px; font-size: 15px; line-height: 1.65; }

/* CONFIANÇA */
.od-confianca { background: var(--od-preto); color: #fff; border-radius: 18px; padding: 20px 22px; }
.od-confianca ul { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px 18px; font-size: 14px; }
.od-confianca i { color: #22c55e; margin-right: 6px; }

/* Tema escuro da loja */
:global(.dark) .oferta-desc { --od-laranja-claro: rgba(241, 105, 27, .14); --od-preto: #05070b; }
:global(.dark) .od-tabela .ruim { color: #f87171; }
:global(.dark) .od-tabela .bom { color: #4ade80; }
:global(.dark) .od-oferta, :global(.dark) .od-confianca { border: 1px solid var(--od-borda); }

/* Celular */
@media (max-width: 767px) {
    .od-hero { padding: 26px 18px 0; }
    .od-hero h2 { font-size: 25px; }
    .od-eyebrow { font-size: 11px; letter-spacing: .12em; }
    .od-secao { padding: 22px 16px 0; }
    .od-ultima { padding-bottom: 20px; }
    .od-beneficio { padding: 14px; }
    .od-cartao h3 { font-size: 18px; }
    .od-oferta { flex-direction: column; align-items: stretch; padding: 20px; }
    .od-oferta-acao { min-width: 0; }
    .od-botao { width: 100%; }
    .od-hero-compra { flex-direction: column; align-items: stretch; }
    .od-garantia { max-width: none; }
    .od-por { font-size: 32px; }
}
@media (max-width: 480px) {
    .od-grade, .od-confianca ul { grid-template-columns: minmax(0, 1fr); }
    .od-pilulas li { font-size: 12px; }
}
@media (prefers-reduced-motion: reduce) {
    .od-botao { transition: none; }
}
</style>
