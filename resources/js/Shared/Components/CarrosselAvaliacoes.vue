<script setup>
// Carrossel de avaliações reais do checkout (modelo Yampi, 2026-10-23).
// Modelo Mavo (2026-10-24): estrelas pretas, nome e estrelas na mesma linha,
// texto em até 3 linhas; UF real do cliente ao lado do nome (só quando a
// avaliação veio de um pedido nosso, ver reviews.uf).
// Trilho horizontal com scroll-snap: todas as avaliações lado a lado, então a
// altura é a da maior e nada abaixo pula quando a avaliação troca.
//
// Parado (pedido do Lira 2026-11-23): não roda sozinho e não tem mais o loop
// com a cópia da 1ª. Troca pelos bullets embaixo (clicáveis), pelo dedo no
// celular (o scroll-snap leva para a próxima), pelo arrasto do mouse e pelas
// setas discretas no computador. No máximo 4 (AvaliacoesDoCheckout::LIMITE).
//
// Sem rosto de banco de imagens (2026-11-23): círculo com as iniciais na cor
// do nome, a foto que o cliente mandou do produto em miniatura e o selo
// "Compra verificada" quando a avaliação veio do link do pedido.
import { ref } from 'vue';

const props = defineProps({
    avaliacoes: { type: Array, default: () => [] },
});

const trilho = ref(null);
const atual = ref(0);
const arrastando = ref(false);
let pressionado = false;
let moveu = false;
let xInicial = 0;
let scrollInicial = 0;

const largura = () => trilho.value?.clientWidth || 1;
const irPara = (indice) => {
    if (!trilho.value || !props.avaliacoes.length) return;
    const alvo = Math.max(0, Math.min(props.avaliacoes.length - 1, indice));
    trilho.value.scrollTo({ left: alvo * largura(), behavior: 'smooth' });
};
const aoRolar = () => {
    if (!trilho.value) return;
    atual.value = Math.max(0, Math.min(props.avaliacoes.length - 1, Math.round(trilho.value.scrollLeft / largura())));
};

// Arrasto com o mouse no computador; o dedo usa a rolagem nativa do celular.
const aoPressionar = (evento) => {
    if (evento.pointerType !== 'mouse' || evento.button !== 0) return;
    pressionado = true;
    moveu = false;
    xInicial = evento.clientX;
    scrollInicial = trilho.value.scrollLeft;
};
const aoMover = (evento) => {
    if (!pressionado) return;
    const delta = evento.clientX - xInicial;
    if (!moveu && Math.abs(delta) < 5) return;
    if (!moveu) {
        moveu = true;
        arrastando.value = true;
        trilho.value.setPointerCapture?.(evento.pointerId);
    }
    trilho.value.scrollLeft = scrollInicial - delta;
};
const aoSoltar = () => {
    if (!pressionado) return;
    pressionado = false;
    if (!moveu) return;
    const passou = trilho.value.scrollLeft - scrollInicial;
    // Arrasto de mais de 1/6 da largura vira o cartão do lado; senão volta ao atual.
    const indice = Math.round(scrollInicial / largura()) + (Math.abs(passou) > largura() / 6 ? Math.sign(passou) : 0);
    arrastando.value = false;
    irPara(indice);
};
// O arrasto não pode virar clique em link/imagem por baixo.
const aoClicar = (evento) => {
    if (moveu) { evento.preventDefault(); evento.stopPropagation(); moveu = false; }
};
const aoTeclar = (evento) => {
    if (evento.key === 'ArrowRight') { evento.preventDefault(); irPara(atual.value + 1); }
    if (evento.key === 'ArrowLeft') { evento.preventDefault(); irPara(atual.value - 1); }
};
</script>

<template>
    <section v-if="avaliacoes.length" class="group relative rounded-[24px] bg-white px-4 py-3.5 shadow-[0_4px_24px_rgba(15,23,42,0.07)]"
        aria-roledescription="carrossel" aria-label="Avaliações de clientes" @keydown="aoTeclar">
        <div ref="trilho" class="flex select-none overflow-x-auto overscroll-x-contain [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
            :class="arrastando ? 'cursor-grabbing' : 'cursor-grab snap-x snap-mandatory'"
            @scroll.passive="aoRolar" @pointerdown="aoPressionar" @pointermove="aoMover" @pointerup="aoSoltar" @pointercancel="aoSoltar" @click.capture="aoClicar" @dragstart.prevent>
            <article v-for="(avaliacao, indice) in avaliacoes" :key="avaliacao.id" class="flex w-full shrink-0 snap-start gap-3"
                aria-roledescription="slide" :aria-label="`Avaliação ${indice + 1} de ${avaliacoes.length}`">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-bold"
                    :style="{ backgroundColor: avaliacao.cor?.fundo || '#e2e8f0', color: avaliacao.cor?.texto || '#334155' }" aria-hidden="true">{{ avaliacao.iniciais }}</span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-x-2">
                        <p class="truncate text-xs font-bold text-slate-900">
                            {{ avaliacao.nome }}<span v-if="avaliacao.uf" class="font-semibold text-slate-500"> · {{ avaliacao.uf }}</span>
                        </p>
                        <p class="shrink-0 text-slate-900" role="img" :aria-label="`Nota ${avaliacao.nota} de 5`">
                            <i v-for="estrela in 5" :key="estrela" class="text-[11px]" :class="estrela <= avaliacao.nota ? 'fa-solid fa-star' : 'fa-regular fa-star text-slate-300'" aria-hidden="true"></i>
                        </p>
                    </div>
                    <p v-if="avaliacao.verificada" class="mt-0.5 inline-flex items-center gap-1 text-[11px] font-semibold text-[#00a650]">
                        <i class="fa-solid fa-circle-check" aria-hidden="true"></i>Compra verificada
                    </p>
                    <div class="mt-1 flex gap-2.5">
                        <p class="line-clamp-3 min-w-0 flex-1 text-[13px] leading-snug text-slate-700">{{ avaliacao.texto }}</p>
                        <img v-if="avaliacao.foto" :src="avaliacao.foto" :alt="`Foto enviada por ${avaliacao.nome}`" width="56" height="56" loading="lazy" decoding="async" draggable="false"
                            class="h-14 w-14 shrink-0 rounded-lg bg-slate-100 object-cover">
                    </div>
                </div>
            </article>
        </div>

        <!-- Setas discretas, só no computador e só ao passar o mouse. -->
        <template v-if="avaliacoes.length > 1">
            <button type="button" class="absolute left-1 top-1/2 hidden h-7 w-7 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 text-slate-500 opacity-0 shadow transition hover:text-slate-900 focus-visible:opacity-100 disabled:invisible group-hover:opacity-100 lg:flex"
                :disabled="atual === 0" aria-label="Avaliação anterior" @click="irPara(atual - 1)">
                <i class="fa-solid fa-chevron-left text-[11px]" aria-hidden="true"></i>
            </button>
            <button type="button" class="absolute right-1 top-1/2 hidden h-7 w-7 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 text-slate-500 opacity-0 shadow transition hover:text-slate-900 focus-visible:opacity-100 disabled:invisible group-hover:opacity-100 lg:flex"
                :disabled="atual === avaliacoes.length - 1" aria-label="Próxima avaliação" @click="irPara(atual + 1)">
                <i class="fa-solid fa-chevron-right text-[11px]" aria-hidden="true"></i>
            </button>

            <div class="mt-2.5 flex items-center justify-center gap-1">
                <button v-for="(avaliacao, indice) in avaliacoes" :key="`ponto-${avaliacao.id}`" type="button"
                    class="flex h-6 items-center px-0.5" :aria-label="`Ver avaliação ${indice + 1} de ${avaliacoes.length}`" :aria-current="indice === atual ? 'true' : undefined"
                    @click="irPara(indice)">
                    <span class="block h-2 rounded-full transition-all" :class="indice === atual ? 'w-5 bg-slate-800' : 'w-2 bg-slate-300 hover:bg-slate-400'"></span>
                </button>
            </div>
        </template>
    </section>
</template>
