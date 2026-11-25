<script setup>
// "Aeeee!" ao abrir a página de brinquedo (pedido do Lira 24/11/2026, "igual nos
// vídeos da furadeira"). Regras:
// - toca UMA vez por visita da página (não repete a cada clique);
// - o navegador bloqueia som antes de a pessoa mexer na página: tenta ao abrir e,
//   se for barrado, toca no primeiro toque, clique, tecla ou rolagem;
// - desde 25/11/2026 o botão fixo é do som do VÍDEO (somVideo.js); o "Aeeee!" não tem botão.
//   O mudo antigo guardado no aparelho (localStorage) continua valendo para o "Aeeee!".
import { onMounted, onUnmounted, ref } from 'vue';
import Icone from './Icone.vue';
import { somVideo } from './somVideo';

const props = defineProps({
    src: { type: String, default: '/sons/aeee.mp3?v=2' },
    volume: { type: Number, default: 0.55 },
});

// Chave nova em 25/11/2026: o botão antigo de mudo deixou de ser do "Aeeee!", então quem o desligou volta a ouvir.
const CHAVE = 'kz-brinquedo-aeee-mudo';
const lerMudo = () => { try { return window.localStorage.getItem(CHAVE) === '1'; } catch { return false; } };

const mudo = ref(false);
let audio = null;
let tocou = false;
let tentando = false; // um gesto dispara vários eventos (pointerdown, mousedown, click…): uma tentativa por vez
// O navegador só libera som depois de um gesto de verdade; no celular o toque para rolar (touchend/pointerup)
// já conta, então o "Aeeee!" entra logo no primeiro toque na tela, mesmo sem clicar em nada.
const EVENTOS = ['pointerdown', 'pointerup', 'touchstart', 'touchend', 'mousedown', 'keydown', 'scroll', 'click'];

const soltarEventos = () => EVENTOS.forEach((evento) => window.removeEventListener(evento, tentar, true));
function tentar(evento) {
    // O toque no próprio botão de mudo não dispara o som.
    // Nem o toque nos botões/no vídeo (são do som do vídeo, não do "Aeeee!").
    if (evento?.target?.closest?.('.pb-som, .pb-video-ctrl, .pb-media-video')) return;
    // Vídeo já tocando com som: o "Aeeee!" não entra por cima.
    if (tocou || mudo.value || !somVideo.mudo) { soltarEventos(); return; }
    if (tentando) return;
    criarAudio();
    if (!audio) { soltarEventos(); return; }
    tentando = true;
    const promessa = audio.play();
    if (promessa?.then) {
        promessa.then(() => { tocou = true; soltarEventos(); }).catch(() => { /* ainda bloqueado: espera o próximo gesto */ }).finally(() => { tentando = false; });
    } else {
        tocou = true;
        soltarEventos();
    }
}

// Desempenho (24/11/2026): o arquivo de som só é baixado no primeiro toque (antes de um gesto o navegador
// bloqueia o som de qualquer jeito). Quem já tocou na loja (veio por um clique) ouve ao abrir.
const criarAudio = () => {
    if (audio || typeof Audio === 'undefined') return;
    audio = new Audio(props.src);
    audio.preload = 'auto';
    audio.volume = props.volume;
};
onMounted(() => {
    mudo.value = lerMudo();
    if (mudo.value || typeof Audio === 'undefined') return;
    EVENTOS.forEach((evento) => window.addEventListener(evento, tentar, { capture: true, passive: true }));
    if (navigator.userActivation?.hasBeenActive) tentar();
});
onUnmounted(() => {
    soltarEventos();
    audio?.pause();
    audio = null;
});

</script>

<template>
    <!-- Botão fixo: som do VÍDEO da página (o "Aeeee!" é separado e não tem botão). -->
    <button v-if="somVideo.existe" type="button" class="pb-som" :aria-pressed="somVideo.mudo" :aria-label="somVideo.mudo ? 'Ligar o som do vídeo' : 'Desligar o som do vídeo'"
        :title="somVideo.mudo ? 'Ligar som do vídeo' : 'Sem som no vídeo'" data-kzm="som-video" @click.stop="somVideo.alternar(); somVideo.mudo || audio?.pause()">
        <Icone :nome="somVideo.mudo ? 'volume-x' : 'volume-2'" :tamanho="18" />
    </button>
</template>
