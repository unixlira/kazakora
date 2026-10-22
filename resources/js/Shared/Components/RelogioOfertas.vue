<script setup>
// Ofertas do dia: contagem visual de 24h (23:59:59 → 00:00:00) até a meia-noite
// de São Paulo, reiniciando todo dia (pedido 2026-10-18). Só o relógio: quais
// ofertas aparecem continua pelo início/fim configurados no back-end.
// Componente próprio (PageSpeed 2026-10-21): a cada segundo só este texto é
// redesenhado, não a home inteira.
import { onBeforeUnmount, onMounted, ref } from 'vue';

const restante = ref('');
let relogio = null;
const horaSaoPaulo = new Intl.DateTimeFormat('en-GB', { timeZone: 'America/Sao_Paulo', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
const atualizar = () => {
    const [h, m, s] = horaSaoPaulo.format(new Date()).split(':').map(Number);
    const segundos = Math.max(0, 86399 - (h * 3600 + m * 60 + s));
    const dois = (valor) => String(valor).padStart(2, '0');
    restante.value = `${dois(Math.floor(segundos / 3600))}:${dois(Math.floor((segundos % 3600) / 60))}:${dois(segundos % 60)}`;
};
atualizar();
onMounted(() => { relogio = setInterval(atualizar, 1000); });
onBeforeUnmount(() => clearInterval(relogio));
</script>

<template>
    <span class="font-mono tabular-nums">{{ restante }}</span>
</template>
