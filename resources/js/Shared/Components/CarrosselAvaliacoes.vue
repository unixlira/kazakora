<script setup>
// Carrossel de avaliações reais do checkout (modelo Yampi, 2026-10-23).
// Modelo Mavo (2026-10-24): foto redonda, estrelas pretas, texto em até 4 linhas.
// Altura fixa (2026-10-24): todas as avaliações ocupam a mesma célula da grade
// e só a atual fica visível, então a caixa tem a altura da maior e nada abaixo
// dela pula quando a avaliação troca sozinha (antes ia de 192 a 249px a cada 6s
// e, no celular, empurrava o formulário enquanto a pessoa digitava).
import { miniaturaAvaliacao } from '@/Shared/fotoAvaliacao';
import { onMounted, onUnmounted, ref } from 'vue';

const props = defineProps({
    avaliacoes: { type: Array, default: () => [] },
});

const atual = ref(0);
let timer = null;
const passar = (passo = 1) => {
    const total = props.avaliacoes.length;
    if (total) atual.value = (atual.value + passo + total) % total;
};
const parar = () => { clearInterval(timer); timer = null; };
const iniciar = () => {
    parar();
    if (props.avaliacoes.length > 1) timer = setInterval(() => passar(1), 6000);
};
onMounted(iniciar);
onUnmounted(parar);
</script>

<template>
    <section v-if="avaliacoes.length" class="rounded-[24px] bg-white p-5 shadow-[0_4px_24px_rgba(15,23,42,0.07)]"
        aria-roledescription="carrossel" aria-label="Avaliações de clientes" @mouseenter="parar" @mouseleave="iniciar" @focusin="parar" @focusout="iniciar">
        <div class="grid">
            <article v-for="(avaliacao, indice) in avaliacoes" :key="avaliacao.id" class="flex gap-3 [grid-area:1/1]"
                :class="indice === atual ? 'visible' : 'invisible'" :aria-hidden="indice !== atual"
                :aria-label="`Avaliação ${indice + 1} de ${avaliacoes.length}`">
                <div class="min-w-0 flex-1">
                    <p class="text-slate-900" role="img" :aria-label="`Nota ${avaliacao.nota} de 5`">
                        <i v-for="estrela in 5" :key="estrela" class="text-sm" :class="estrela <= avaliacao.nota ? 'fa-solid fa-star' : 'fa-regular fa-star text-slate-300'" aria-hidden="true"></i>
                    </p>
                    <p class="mt-1 text-xs font-bold text-slate-900">{{ avaliacao.nome }}</p>
                    <p class="mt-2 line-clamp-4 text-sm leading-relaxed text-slate-700">{{ avaliacao.texto }}</p>
                </div>
                <img v-if="avaliacao.foto" :src="miniaturaAvaliacao(avaliacao.foto)" alt="Foto enviada pelo cliente" width="64" height="64" loading="lazy" decoding="async" class="h-16 w-16 shrink-0 rounded-full object-cover">
            </article>
        </div>
        <div v-if="avaliacoes.length > 1" class="mt-3 flex items-center justify-center gap-1.5">
            <button v-for="(avaliacao, indice) in avaliacoes" :key="`ponto-${avaliacao.id}`" type="button"
                class="h-2 rounded-full transition-all" :class="indice === atual ? 'w-5 bg-slate-800' : 'w-2 bg-slate-300 hover:bg-slate-400'"
                :aria-label="`Ver avaliação ${indice + 1}`" :aria-current="indice === atual" @click="atual = indice"></button>
        </div>
    </section>
</template>
