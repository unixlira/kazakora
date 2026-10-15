// Contagem regressiva da Oferta Relâmpago (pedido 2026-10-15): "HH:MM:SS"
// até o fim (ISO com fuso, vindo de oferta_relampago.fim). Um relógio só
// para a página inteira, ligado enquanto houver contagem na tela.
import { computed, onBeforeUnmount, onMounted, ref, unref } from 'vue';

const agora = ref(Date.now());
let usuarios = 0;
let relogio = null;

const pad = (n) => String(n).padStart(2, '0');

export function useContagemRegressiva(fim) {
    onMounted(() => {
        agora.value = Date.now();
        if (usuarios++ === 0) relogio = setInterval(() => { agora.value = Date.now(); }, 1000);
    });
    onBeforeUnmount(() => {
        if (--usuarios === 0) clearInterval(relogio);
    });

    const restante = computed(() => {
        const alvo = unref(fim);
        return alvo ? Math.max(0, Math.floor((new Date(alvo).getTime() - agora.value) / 1000)) : 0;
    });

    const texto = computed(() => {
        const s = restante.value;
        return `${pad(Math.floor(s / 3600))}:${pad(Math.floor((s % 3600) / 60))}:${pad(s % 60)}`;
    });

    return { restante, texto, acabou: computed(() => restante.value <= 0) };
}
