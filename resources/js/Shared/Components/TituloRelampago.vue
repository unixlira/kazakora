<script setup>
// Título da Oferta Relâmpago no estilo que a FerraKora tinha (pedido do Lira
// em 27/11/2026: "o título, reloginho temporizador, seja igual do ferrakora era
// antes"), copiado do TituloRelampago.jsx da FerraKora (14 e 15/10/2026, modelo
// da Shopee): "OFERTAS" fino e "RELÂMPAGO" em negrito itálico, o despertador
// amarelo com % tremendo no meio, relógio de ponteiros, "TERMINA EM" e o
// cronômetro em caixas pretas com números brancos, sem dois-pontos. Cor da
// KazaKora (laranja #F2691C) no lugar do laranja da Shopee.
//
// Contagem de 24 h até a meia-noite de São Paulo, recomeçando todo dia (a mesma
// regra do antigo relógio da home, pedido 2026-10-18). Só este componente redesenha a
// cada segundo (PageSpeed).
//
// centro: no celular o título fica no meio e o "TERMINA EM" com o cronômetro
// logo abaixo, também no meio.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

defineProps({
    tag: { type: String, default: 'h2' },
    centro: { type: Boolean, default: false },
});

const horaSaoPaulo = new Intl.DateTimeFormat('en-GB', { timeZone: 'America/Sao_Paulo', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
const segundosAteMeiaNoite = () => {
    const [h, m, s] = horaSaoPaulo.format(new Date()).split(':').map(Number);
    return Math.max(0, 86399 - (h * 3600 + m * 60 + s));
};

const restante = ref(segundosAteMeiaNoite());
let relogio = null;
onMounted(() => { relogio = setInterval(() => { restante.value = segundosAteMeiaNoite(); }, 1000); });
onBeforeUnmount(() => clearInterval(relogio));

const dois = (valor) => String(valor).padStart(2, '0');
const partes = computed(() => [Math.floor(restante.value / 3600), Math.floor((restante.value % 3600) / 60), restante.value % 60].map(dois));
const rotulo = computed(() => `${Number(partes.value[0])} horas, ${Number(partes.value[1])} minutos e ${Number(partes.value[2])} segundos`);
</script>

<template>
    <component :is="tag" class="titulo-relampago flex flex-wrap items-center gap-x-6 gap-y-2 font-sans normal-case not-italic"
        :class="{ 'max-md:flex-col max-md:justify-center': centro }">
        <span class="flex items-center text-2xl uppercase leading-none text-[#F2691C] md:text-[2rem]">
            <span class="font-normal tracking-tight">Ofertas</span>
            <!-- Despertador amarelo com % (tremendo), entre OFERTAS e RELÂMPAGO. -->
            <svg class="despertador-toca mx-[0.08em] h-[1.05em] w-[1.05em] shrink-0" viewBox="0 0 24 24" aria-hidden="true">
                <g fill="#facc15">
                    <path d="M2.2 6.6a4.2 4.2 0 0 1 5.6-4.4L2.9 7.4z" />
                    <path d="M21.8 6.6a4.2 4.2 0 0 0-5.6-4.4l4.9 5.2z" />
                    <circle cx="12" cy="13" r="8.6" />
                    <path d="m5.4 18.6-2 2.6a1 1 0 0 0 1.6 1.2l2-2.6zM18.6 18.6l2 2.6a1 1 0 0 1-1.6 1.2l-2-2.6z" />
                </g>
                <g fill="none" stroke="#F2691C" stroke-width="1.9" stroke-linecap="round">
                    <path d="m9 16.6 6-7.2" />
                    <circle cx="9.3" cy="10" r="1.25" />
                    <circle cx="14.7" cy="16" r="1.25" />
                </g>
            </svg>
            <span class="font-extrabold italic tracking-tight">Relâmpago</span>
        </span>
        <span class="flex items-center gap-x-5 text-base font-normal uppercase leading-none text-[#333] dark:text-slate-200 md:text-xl">
            <span class="flex items-center gap-1.5">
                <!-- Relógio de ponteiros na cor do texto. -->
                <svg class="h-[1.1em] w-[1.1em] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9.5" />
                    <path d="M12 6.5V12l3.8 2.4" />
                </svg>
                Termina em
            </span>
            <span class="flex items-center gap-1.5 text-base font-bold md:text-xl" role="timer" :aria-label="rotulo">
                <span v-for="(parte, i) in partes" :key="i"
                    class="inline-flex h-[1.45em] min-w-[1.75em] items-center justify-center rounded-[2px] bg-black px-[0.2em] tabular-nums leading-none text-white">{{ parte }}</span>
            </span>
        </span>
    </component>
</template>

<style scoped>
/* Despertador tremendo (mesma animação da FerraKora), também com "reduzir movimento". */
.despertador-toca {
    display: inline-block;
    transform-origin: 50% 60%;
    animation: despertador-toca 1.2s ease-in-out infinite;
}

@keyframes despertador-toca {
    0%, 50%, 100% { transform: rotate(0); }
    5%, 15%, 25%, 35% { transform: rotate(-14deg); }
    10%, 20%, 30%, 40% { transform: rotate(14deg); }
}
</style>
