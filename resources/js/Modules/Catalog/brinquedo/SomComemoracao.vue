<script setup>
// "Aeeee!" ao abrir a página de brinquedo (pedido do Lira 24/11/2026, "igual nos
// vídeos da furadeira"). Regras:
// - toca UMA vez por visita da página (não repete a cada clique);
// - o navegador bloqueia som antes de a pessoa mexer na página: tenta ao abrir e,
//   se for barrado, toca no primeiro toque, clique, tecla ou rolagem;
// - botão discreto de mudo; o mudo fica guardado no aparelho (localStorage) e,
//   com ele ligado, a página não toca nada nas próximas visitas.
import { onMounted, onUnmounted, ref } from 'vue';
import Icone from './Icone.vue';

const props = defineProps({
    src: { type: String, default: '/sons/aeee.mp3' },
    volume: { type: Number, default: 0.55 },
});

const CHAVE = 'kz-brinquedo-som-mudo';
const lerMudo = () => { try { return window.localStorage.getItem(CHAVE) === '1'; } catch { return false; } };
const gravarMudo = (valor) => { try { window.localStorage.setItem(CHAVE, valor ? '1' : '0'); } catch { /* sem armazenamento: vale só nesta visita */ } };

const mudo = ref(false);
let audio = null;
let tocou = false;
const EVENTOS = ['pointerdown', 'touchstart', 'keydown', 'scroll', 'click'];

const soltarEventos = () => EVENTOS.forEach((evento) => window.removeEventListener(evento, tentar, true));
function tentar(evento) {
    // O toque no próprio botão de mudo não dispara o som.
    if (evento?.target?.closest?.('.pb-som')) return;
    if (tocou || mudo.value || !audio) { soltarEventos(); return; }
    const promessa = audio.play();
    if (promessa?.then) {
        promessa.then(() => { tocou = true; soltarEventos(); }).catch(() => { /* ainda bloqueado: espera o próximo gesto */ });
    } else {
        tocou = true;
        soltarEventos();
    }
}

onMounted(() => {
    mudo.value = lerMudo();
    if (mudo.value || typeof Audio === 'undefined') return;
    audio = new Audio(props.src);
    audio.preload = 'auto';
    audio.volume = props.volume;
    EVENTOS.forEach((evento) => window.addEventListener(evento, tentar, { capture: true, passive: true }));
    tentar();
});
onUnmounted(() => {
    soltarEventos();
    audio?.pause();
    audio = null;
});

const alternar = () => {
    mudo.value = !mudo.value;
    gravarMudo(mudo.value);
    if (mudo.value) {
        audio?.pause();
        soltarEventos();
    }
};
</script>

<template>
    <button type="button" class="pb-som" :aria-pressed="mudo" :aria-label="mudo ? 'Som da página desligado. Ligar som' : 'Desligar o som da página'"
        :title="mudo ? 'Ligar som' : 'Sem som'" data-kzm="som-brinquedo" @click.stop="alternar">
        <Icone :nome="mudo ? 'volume-x' : 'volume-2'" :tamanho="18" />
    </button>
</template>
