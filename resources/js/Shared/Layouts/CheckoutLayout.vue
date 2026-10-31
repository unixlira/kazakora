<script setup>
// Layout do checkout no modelo Yampi (pedido 2026-10-23, referência
// seguro.mavoloja.com.br). Desde 2026-10-24 o topo segue o print da Mavo:
// uma faixa escura só, com a logo, o CNPJ e o endereço (sem nome nem razão
// social, ajuste de 2026-10-24), e abaixo a barra roxa "Oferta termina em".
// O relógio é sempre de 30 minutos a partir da primeira visita ao checkout
// (pedido do Lira 2026-10-24, modelo Mavo), guardados no navegador para não
// recomeçar ao atualizar. Zerou na tela, a barra some; na próxima visita recomeça. Desde 2026-10-25 não usa mais o
// fim da Oferta Relâmpago do carrinho: mostrava 568:47:51 (oferta de 23 dias).
// Rodapé TOTALMENTE estático desde 2026-10-25 (pedido do Lira): só texto e
// imagens, sem link nem sanfona, igual em todas as telas. No celular as
// bandeiras e os selos ficam sempre centralizados. Sem menu e busca da loja.
// Desde 2026-10-31 (pedido do Lira) o rodapé é o preto da KazaKora, sem links
// e sem o selo do Reclame Aqui com QR Code. O resto da tela segue sempre claro
// (cores fixas).
import { COMPANY } from '@/Shared/company.js';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    // Pedido já criado (pagando a 2ª parte, Pix aberto): sem relógio.
    semRelogio: { type: Boolean, default: false },
});

const BANDEIRAS = ['amex', 'visa', 'mastercard', 'elo', 'hiper', 'boleto', 'pix'];
const ano = new Date().getFullYear();

const RELOGIO_MINUTOS = 30;
const RELOGIO_CHAVE = 'kz_checkout_relogio_fim';
const agora = ref(Date.now());
const fimDoRelogio = ref(NaN);
let relogio = null;
onMounted(() => {
    let fim = NaN;
    try {
        fim = Number(localStorage.getItem(RELOGIO_CHAVE));
        // Relógio anterior já zerou: começa outros 30 minutos (2026-10-25: antes
        // esperava um dia, e quem voltou ao checkout ficava sem a barra até lá).
        // Fim além de 30 minutos à frente (valor estranho guardado) também recomeça.
        if (!Number.isFinite(fim) || fim <= Date.now()
            || fim - Date.now() > RELOGIO_MINUTOS * 60000) {
            fim = Date.now() + RELOGIO_MINUTOS * 60000;
            localStorage.setItem(RELOGIO_CHAVE, String(fim));
        }
    } catch {
        fim = Date.now() + RELOGIO_MINUTOS * 60000;
    }
    fimDoRelogio.value = fim;
    relogio = setInterval(() => { agora.value = Date.now(); }, 1000);
});
onBeforeUnmount(() => clearInterval(relogio));

const restante = computed(() => {
    if (props.semRelogio) return 0;
    const fim = fimDoRelogio.value;

    return Number.isFinite(fim) ? Math.max(0, Math.floor((fim - agora.value) / 1000)) : 0;
});
// MM:SS (o relógio nunca passa de 30 minutos).
const tempo = computed(() => [Math.floor(restante.value / 60), restante.value % 60]
    .map((parte) => String(parte).padStart(2, '0'))
    .join(':'));
</script>

<template>
    <div class="flex min-h-screen flex-col bg-[#F4F5F7] font-store text-slate-800 [color-scheme:light]">
        <!-- Mercado Pago em modo teste (sandbox): nada é cobrado de verdade. -->
        <div v-if="$page.props.pagamentoTeste" class="bg-yellow-300 px-3 py-1.5 text-center text-xs font-bold uppercase tracking-wide text-yellow-950">
            <i class="fa-solid fa-flask mr-1"></i>Modo teste do Mercado Pago: nenhum valor é cobrado
        </div>

        <header class="bg-[#141414] px-4 py-3 text-center text-white">
            <img :src="$page.props.marca?.logoRodape || '/images/marca/logo-rodape.png'" alt="KazaKora" width="160" height="28" class="mx-auto h-7 w-auto md:h-8">
            <!-- Só logo, CNPJ e endereço (os mesmos dos Termos de Uso). -->
            <p class="mt-2 text-[11px] font-semibold leading-snug sm:text-[13px]">CNPJ: {{ COMPANY.cnpj }}</p>
            <p class="text-[11px] leading-snug text-white/75 sm:text-[13px]">{{ COMPANY.enderecoResumido }}</p>
        </header>
        <div class="checkout-oferta h-[3px]" aria-hidden="true"></div>
        <div v-if="restante > 0" role="timer" aria-live="off" class="checkout-oferta-relogio flex items-center justify-center gap-2 whitespace-nowrap px-3 py-2 text-[13px] font-semibold text-white sm:text-[15px]">
            <i class="fa-solid fa-stopwatch animate-pulse" aria-hidden="true"></i>
            <span>Oferta termina em</span>
            <span class="font-mono text-[15px] font-extrabold tabular-nums sm:text-lg">{{ tempo }}</span>
        </div>

        <slot />

        <footer class="mt-auto bg-[#0b0b0b] text-white" data-testid="checkout-rodape">
            <!-- Rodapé estático (2026-10-25): nada clicável, só texto e imagens. -->
            <div class="mx-auto max-w-[1320px] px-4 py-10 text-center md:px-6">
                <img :src="$page.props.marca?.logoRodape || '/images/marca/logo-rodape.png'" alt="KazaKora" width="160" height="28" loading="lazy" class="mx-auto h-8 w-auto">

                <div class="mt-8 flex flex-col items-center gap-6 lg:grid lg:grid-cols-2 lg:items-center lg:gap-8 lg:text-left">
                    <div>
                        <p class="font-store-mono mb-3 text-xs uppercase tracking-wider opacity-60">Pagamentos</p>
                        <ul class="flex flex-wrap items-center justify-center gap-1.5 sm:gap-2 lg:justify-start" aria-label="Bandeiras aceitas">
                            <li v-for="bandeira in BANDEIRAS" :key="bandeira">
                                <img :src="`/images/payments/novas/${bandeira}.webp`" :alt="bandeira" width="249" height="167" loading="lazy" class="h-[30px] w-auto rounded-[8px] sm:h-[44px]">
                            </li>
                        </ul>
                    </div>

                    <!-- Selos sem link. -->
                    <div class="lg:text-right">
                        <p class="font-store-mono mb-3 text-xs uppercase tracking-wider opacity-60">Segurança</p>
                        <div class="flex flex-wrap items-center justify-center gap-1.5 sm:gap-2 lg:justify-end">
                            <div class="flex h-[30px] items-center justify-center rounded-[8px] bg-white px-2.5 sm:h-[44px] sm:px-3">
                                <img src="/images/selos/selo-google-safe.webp" alt="Google Safe Browsing" width="139" height="45" class="h-[22px] w-auto sm:h-[34px]">
                            </div>
                            <div class="flex h-[30px] items-center justify-center rounded-[8px] bg-white px-2.5 sm:h-[44px] sm:px-3">
                                <img src="/images/selos/selo-norton.svg" alt="Norton Safe Web" width="104" height="40" loading="lazy" class="h-[22px] w-auto sm:h-[30px]">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-8 space-y-1 border-t border-white/10 pt-6 text-xs">
                    <p class="opacity-70">{{ COMPANY.nomeFantasia }}: kazakora.com</p>
                    <p class="opacity-70">{{ COMPANY.enderecoCompleto }}</p>
                    <p class="opacity-70">© {{ ano }} {{ COMPANY.nomeFantasia }} | Grupo AlphaKora · CNPJ: {{ COMPANY.cnpj }}</p>
                    <p class="opacity-70">WhatsApp: {{ COMPANY.whatsappDisplay }} / E-mail: {{ COMPANY.email }}</p>
                </div>
            </div>
        </footer>
    </div>
</template>

<style scoped>
/* Barra do relógio: roxa (pedido 2026-10-24). */
.checkout-oferta-relogio {
    background: #6D28D9;
}
/* Linha colorida do topo (modelo Mavo, nas cores da KazaKora). */
.checkout-oferta {
    background: linear-gradient(90deg, #F44D00 0%, #E0247A 55%, #6D28D9 100%);
}
</style>
