<script setup>
// Layout do checkout no modelo Yampi (pedido 2026-10-23, referência
// seguro.mavoloja.com.br). Desde 2026-10-24 o topo segue o print da Mavo:
// uma faixa escura só, com a logo, o CNPJ e o endereço, e abaixo a barra
// colorida "Oferta termina em". O relógio conta até o FIM REAL da oferta do
// carrinho (Oferta Relâmpago ou Oferta do Dia, vindo da página); sem oferta
// no ar a barra não aparece e fica só a linha colorida. Nada de contador que
// recomeça ao atualizar (regra do Lira, nada de publicidade enganosa).
// Rodapé curto com as formas de pagamento, os selos e 4 linhas com os dados
// da loja. Sem menu, busca e rodapé da loja. Sempre claro (cores fixas).
import { COMPANY } from '@/Shared/company.js';
import SeloReclameAqui from '@/Shared/Components/SeloReclameAqui.vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    // ISO 8601 do fim da oferta que vale para o carrinho (null = sem oferta).
    ofertaFim: { type: String, default: null },
});

const BANDEIRAS = ['amex', 'visa', 'mastercard', 'elo', 'hiper', 'boleto', 'pix'];
const ano = new Date().getFullYear();

const agora = ref(Date.now());
let relogio = null;
onMounted(() => { relogio = setInterval(() => { agora.value = Date.now(); }, 1000); });
onBeforeUnmount(() => clearInterval(relogio));

const restante = computed(() => {
    const fim = props.ofertaFim ? new Date(props.ofertaFim).getTime() : NaN;

    return Number.isFinite(fim) ? Math.max(0, Math.floor((fim - agora.value) / 1000)) : 0;
});
const tempo = computed(() => [Math.floor(restante.value / 3600), Math.floor((restante.value % 3600) / 60), restante.value % 60]
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
            <!-- Razão social, CNPJ e endereço (os mesmos dos Termos de Uso). -->
            <p class="mt-2 text-[11px] font-semibold leading-snug sm:text-[13px]">{{ COMPANY.nomeFantasia }} · <span class="whitespace-nowrap">{{ COMPANY.razaoSocial }}</span> · <span class="whitespace-nowrap">CNPJ: {{ COMPANY.cnpj }}</span></p>
            <p class="text-[11px] leading-snug text-white/75 sm:text-[13px]">{{ COMPANY.enderecoResumido }}</p>
        </header>
        <div v-if="restante > 0" role="timer" aria-live="off" class="checkout-oferta flex items-center justify-center gap-2 whitespace-nowrap px-3 py-2 text-[13px] font-semibold text-white sm:text-[15px]">
            <i class="fa-solid fa-stopwatch animate-pulse" aria-hidden="true"></i>
            <span>Oferta termina em</span>
            <span class="font-mono text-[15px] font-extrabold tabular-nums sm:text-lg">{{ tempo }}</span>
        </div>
        <div v-else class="checkout-oferta h-[3px]" aria-hidden="true"></div>

        <slot />

        <footer class="mt-auto px-4 pb-10 pt-2">
            <div class="mx-auto max-w-[1200px] border-t border-slate-300/70 pt-8 text-center">
                <p class="text-sm font-medium text-slate-600">Formas de pagamento</p>
                <ul class="mx-auto mt-3 flex max-w-[420px] flex-nowrap items-center justify-center gap-1.5" aria-label="Bandeiras aceitas">
                    <li v-for="bandeira in BANDEIRAS" :key="bandeira" class="min-w-0 flex-1">
                        <img :src="`/images/payments/novas/${bandeira}.webp`" :alt="bandeira" width="249" height="167" loading="lazy" class="h-auto w-full rounded-[6px] border border-slate-200 bg-white">
                    </li>
                </ul>

                <!-- Selos do checkout. RA Verificada: o mesmo selo oficial do rodapé da loja (2026-10-24, no lugar da imagem com QR Code); abre em outra aba, o cliente não sai do pagamento. -->
                <div class="mt-5 flex items-center justify-center gap-2">
                    <div class="flex h-9 items-center justify-center rounded-[8px] border border-slate-200 bg-white px-2">
                        <img src="/images/selos/selo-google-safe.webp" alt="Google Safe Browsing" width="139" height="45" class="h-7 w-auto">
                    </div>
                    <div class="flex h-9 items-center justify-center rounded-[8px] border border-slate-200 bg-white px-2">
                        <SeloReclameAqui :altura="28" />
                    </div>
                    <div class="flex h-9 items-center justify-center rounded-[8px] border border-slate-200 bg-white px-2">
                        <img src="/images/selos/selo-norton.svg" alt="Norton Safe Web" width="104" height="40" loading="lazy" class="h-6 w-auto">
                    </div>
                </div>

                <div class="mt-6 space-y-1 text-xs text-slate-600">
                    <p>{{ COMPANY.nomeFantasia }}: kazakora.com</p>
                    <p>{{ COMPANY.enderecoCompleto }}</p>
                    <p>© {{ ano }} {{ COMPANY.nomeFantasia }} | Grupo AlphaKora · CNPJ: {{ COMPANY.cnpj }}</p>
                    <p>WhatsApp: {{ COMPANY.whatsappDisplay }} / E-mail: {{ COMPANY.email }}</p>
                </div>
            </div>
        </footer>
    </div>
</template>

<style scoped>
/* Barra colorida do topo (modelo Mavo, nas cores da KazaKora). */
.checkout-oferta {
    background: linear-gradient(90deg, #F44D00 0%, #E0247A 55%, #6D28D9 100%);
}
</style>
