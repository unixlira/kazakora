<script setup>
// Carrossel de avaliações reais do checkout (modelo Yampi, 2026-10-23).
// Modelo Mavo (2026-10-24): foto redonda, estrelas pretas, texto em até 4 linhas.
// Deslize (2026-10-24): trilho horizontal com scroll-snap; no celular o dedo
// desliza nativamente e no desktop o mouse arrasta (pointer events). Sem setas;
// as bolinhas só mostram a posição. Todas as avaliações ficam lado a lado, então
// a altura é a da maior e nada abaixo pula quando a avaliação troca.
// Card mais baixo (2026-10-24): foto de 40px, nome e estrelas na mesma linha,
// texto em até 3 linhas e menos respiro; UF real do cliente ao lado do nome
// (só quando a avaliação veio de um pedido nosso, ver reviews.uf).
import { onMounted, onUnmounted, ref } from 'vue';

const props = defineProps({
    avaliacoes: { type: Array, default: () => [] },
});

const trilho = ref(null);
const atual = ref(0);
const arrastando = ref(false);
let timer = null;
let pressionado = false;
let moveu = false;
let xInicial = 0;
let scrollInicial = 0;

const largura = () => trilho.value?.clientWidth || 1;
const irPara = (indice) => {
    const total = props.avaliacoes.length;
    if (!trilho.value || !total) return;
    const alvo = (indice + total) % total;
    trilho.value.scrollTo({ left: alvo * largura(), behavior: 'smooth' });
};
const aoRolar = () => {
    if (!trilho.value) return;
    atual.value = Math.min(props.avaliacoes.length - 1, Math.round(trilho.value.scrollLeft / largura()));
};

const parar = () => { clearInterval(timer); timer = null; };
const iniciar = () => {
    parar();
    if (props.avaliacoes.length > 1) timer = setInterval(() => irPara(atual.value + 1), 6000);
};

const aoPressionar = (evento) => {
    parar();
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
    const total = props.avaliacoes.length;
    // Arrasto de mais de 1/6 da largura vira o cartão do lado; senão volta ao atual.
    const indice = Math.round(scrollInicial / largura()) + (Math.abs(passou) > largura() / 6 ? Math.sign(passou) : 0);
    arrastando.value = false;
    trilho.value.scrollTo({ left: Math.max(0, Math.min(total - 1, indice)) * largura(), behavior: 'smooth' });
};
// O arrasto não pode virar clique em link/imagem por baixo.
const aoClicar = (evento) => {
    if (moveu) { evento.preventDefault(); evento.stopPropagation(); moveu = false; }
};

onMounted(iniciar);
onUnmounted(parar);
</script>

<template>
    <section v-if="avaliacoes.length" class="rounded-[24px] bg-white px-4 py-3.5 shadow-[0_4px_24px_rgba(15,23,42,0.07)]"
        aria-roledescription="carrossel" aria-label="Avaliações de clientes" @mouseenter="parar" @mouseleave="iniciar" @focusin="parar" @focusout="iniciar" @touchend="iniciar">
        <div ref="trilho" class="flex select-none overflow-x-auto overscroll-x-contain [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
            :class="arrastando ? 'cursor-grabbing' : 'cursor-grab snap-x snap-mandatory'"
            @scroll.passive="aoRolar" @pointerdown="aoPressionar" @pointermove="aoMover" @pointerup="aoSoltar" @pointercancel="aoSoltar" @click.capture="aoClicar" @dragstart.prevent>
            <article v-for="(avaliacao, indice) in avaliacoes" :key="avaliacao.id" class="flex w-full shrink-0 snap-start gap-3"
                :aria-label="`Avaliação ${indice + 1} de ${avaliacoes.length}`">
                <img :src="avaliacao.avatar" alt="" width="40" height="40" loading="lazy" decoding="async" draggable="false" class="h-10 w-10 shrink-0 rounded-full bg-slate-100 object-cover">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-x-2">
                        <p class="truncate text-xs font-bold text-slate-900">
                            {{ avaliacao.nome }}<span v-if="avaliacao.uf" class="font-semibold text-slate-500"> · {{ avaliacao.uf }}</span>
                        </p>
                        <p class="shrink-0 text-slate-900" role="img" :aria-label="`Nota ${avaliacao.nota} de 5`">
                            <i v-for="estrela in 5" :key="estrela" class="text-[11px]" :class="estrela <= avaliacao.nota ? 'fa-solid fa-star' : 'fa-regular fa-star text-slate-300'" aria-hidden="true"></i>
                        </p>
                    </div>
                    <p class="mt-1 line-clamp-3 text-[13px] leading-snug text-slate-700">{{ avaliacao.texto }}</p>
                </div>
            </article>
        </div>
        <div v-if="avaliacoes.length > 1" class="mt-2.5 flex items-center justify-center gap-1.5" aria-hidden="true">
            <span v-for="(avaliacao, indice) in avaliacoes" :key="`ponto-${avaliacao.id}`"
                class="h-2 rounded-full transition-all" :class="indice === atual ? 'w-5 bg-slate-800' : 'w-2 bg-slate-300'"></span>
        </div>
    </section>
</template>
