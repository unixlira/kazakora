<script setup>
// Layout do checkout v2 (pedido 2026-10-10): sem topo, menu e rodapé da
// loja — só a faixa de segurança e o conteúdo, pra o cliente fechar a compra
// sem distração. Sempre claro (as cores do checkout são fixas).
import { COMPANY } from '@/Shared/company.js';
import AvisoCookies from '@/Shared/Components/AvisoCookies.vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

// Faixa vermelha de escassez: 15 minutos regressivos. Ao acabar fica em
// 00:00; atualizar a página recomeça dos 15 (pedido do Lira, sem guardar).
const BANDEIRAS = ['pix', 'visa', 'mastercard', 'elo', 'amex', 'diners'];

// Endereço em duas linhas (rua/bairro e cidade/CEP), como na referência.
const enderecoEmLinhas = COMPANY.enderecoCompleto.split(/,\s*(?=[^,]+\/[A-Z]{2})/);

const restante = ref(15 * 60);
let relogio = null;

onMounted(() => {
    const fim = Date.now() + restante.value * 1000;
    relogio = setInterval(() => {
        restante.value = Math.max(0, Math.round((fim - Date.now()) / 1000));
        if (restante.value === 0) clearInterval(relogio);
    }, 1000);
});
onBeforeUnmount(() => clearInterval(relogio));

const tempo = computed(() => {
    const minutos = String(Math.floor(restante.value / 60)).padStart(2, '0');
    const segundos = String(restante.value % 60).padStart(2, '0');

    return `${minutos}:${segundos}`;
});
</script>

<template>
    <div class="flex min-h-screen flex-col bg-[#FAFAFA] font-store text-slate-800 [color-scheme:light]">
        <!-- Mercado Pago em modo teste (sandbox): nada é cobrado de verdade. -->
        <div v-if="$page.props.pagamentoTeste" class="bg-yellow-300 px-3 py-1.5 text-center text-xs font-bold uppercase tracking-wide text-yellow-950">
            <i class="fa-solid fa-flask mr-1"></i>Modo teste do Mercado Pago — nenhum valor é cobrado
        </div>
        <!-- Tarjas numa linha só também no celular (pedido 2026-10-10): letra menor lá. -->
        <div class="flex flex-nowrap items-center justify-center gap-1.5 whitespace-nowrap bg-[#E02424] px-2 py-2 text-center text-[11px] font-bold uppercase tracking-tight text-white sm:gap-2 sm:px-4 sm:py-2.5 sm:text-base sm:tracking-wide md:text-lg">
            <i class="fa-solid fa-stopwatch" :class="{ 'animate-pulse': restante > 0 }"></i>
            <span>Frete grátis <span class="selo-full selo-full--claro"><i class="fa-solid fa-bolt"></i>FULL</span> por tempo limitado:</span>
            <span class="inline-block rounded-md bg-white/20 px-1.5 py-0.5 font-mono text-sm tabular-nums sm:min-w-[4.5rem] sm:px-2 sm:text-xl md:text-2xl">{{ tempo }}</span>
        </div>
        <div class="flex flex-nowrap items-center justify-center gap-1.5 whitespace-nowrap bg-[#0FB930] px-2 py-2 text-center text-[11px] font-semibold tracking-tight text-white sm:gap-2 sm:px-4 sm:text-sm sm:tracking-wide">
            <span><i class="fa-solid fa-lock mr-1"></i>COMPRA 100% SEGURA</span>
            <span class="opacity-60">·</span>
            <span><i class="fa-solid fa-fingerprint mr-1"></i>DADOS PROTEGIDOS</span>
            <!-- Celular estreito: só os dois primeiros, pra caber numa linha. -->
            <span class="opacity-60 max-[429px]:hidden">·</span>
            <span class="max-[429px]:hidden"><i class="fa-solid fa-box-open mr-1"></i>ENTREGA GARANTIDA</span>
        </div>
        <slot />
        <AvisoCookies />
        <!-- Rodapé do checkout (pedido 2026-10-10, modelo izeshop): formas de
             pagamento + dados da empresa, selos do Google e do Reclame AQUI à direita e a faixa
             preta com a marca e os selos de confiança. Sem links pra fora. -->
        <footer class="mt-auto">
            <div class="border-t border-slate-200 bg-white px-4 py-8">
                <!-- Formas de pagamento numa linha própria em cima; embaixo, dados à
                     esquerda e selos à direita. -->
                <div class="mx-auto max-w-[1160px] border-b border-slate-100 pb-6 text-center">
                    <p class="text-sm font-semibold text-slate-700">Formas de pagamento:</p>
                    <div class="mt-2 flex flex-wrap items-center justify-center gap-2">
                        <img v-for="bandeira in BANDEIRAS" :key="bandeira" :src="`/images/payments/cartao-hd/${bandeira}.png`" :alt="bandeira"
                            class="h-[51px] w-auto">
                    </div>
                </div>
                <div class="mx-auto mt-6 flex max-w-[1160px] flex-col items-center gap-6 text-center md:flex-row md:justify-between md:text-left">
                    <p class="text-xs leading-relaxed text-slate-500">
                        © {{ new Date().getFullYear() }} Kazakora | Grupo AlphaKora<br>
                        CNPJ: {{ COMPANY.cnpj }}<br>
                        WhatsApp: {{ COMPANY.whatsappDisplay }}<br>
                        E-mail: {{ COMPANY.email }}<br>
                        <template v-for="(linha, index) in enderecoEmLinhas" :key="index"><br v-if="index">{{ linha }}</template>
                    </p>
                    <!-- Mesmos selos do rodapé da loja (pedido 2026-10-12, no lugar da
                         imagem "Site 100% Seguro"). -->
                    <div class="flex flex-wrap items-center justify-center gap-2 md:justify-end">
                        <a href="https://transparencyreport.google.com/safe-browsing/search?url=kazakora.com" target="_blank" rel="noopener"
                            class="flex h-[51px] items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-white px-3 transition hover:opacity-90"
                            title="Ver a verificação do Google Safe Browsing">
                            <img src="/images/selos/selo-google-safe.png" alt="Google Safe Browsing" width="139" height="45" class="h-[40px] w-auto">
                        </a>
                        <a :href="$page.props.reclameAqui?.url || 'https://www.reclameaqui.com.br/busca/?q=kazakora'" target="_blank" rel="noopener"
                            class="flex h-[51px] flex-col items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-white leading-none transition hover:opacity-90"
                            :class="$page.props.reclameAqui?.verificada ? 'px-2' : 'px-4'"
                            title="Ver a KazaKora no Reclame AQUI">
                            <img v-if="$page.props.reclameAqui?.verificada" src="/images/selos/reclame-aqui-verificada-qrcode.png" alt="Verificada por Reclame AQUI, com QR Code de verificação" width="294" height="88"
                                class="h-[42px] w-auto rounded-md">
                            <template v-else>
                                <span class="text-[9px] font-semibold uppercase tracking-wider text-slate-500">Estamos no</span>
                                <span class="mt-0.5 text-[20px] font-black tracking-tight text-[#1a1a1a]">Reclame <span class="text-[#4caf3c]">AQUI</span></span>
                            </template>
                        </a>
                    </div>
                </div>
            </div>

            <div class="bg-black px-4 py-5 text-white">
                <div class="mx-auto flex max-w-[1160px] flex-col items-center gap-4 md:flex-row md:justify-between">
                    <img :src="$page.props.marca?.logoRodape ?? '/images/marca/logo-rodape.png'" :alt="COMPANY.nomeFantasia" class="h-8 w-auto">
                    <ul class="flex flex-row flex-wrap items-center justify-center gap-x-4 gap-y-1 text-xs font-medium sm:text-sm md:gap-6">
                        <li class="whitespace-nowrap"><i class="fa-solid fa-lock mr-1.5 text-[#0FB930]"></i>Compra Segura</li>
                        <li class="whitespace-nowrap"><i class="fa-solid fa-fingerprint mr-1.5 text-[#0FB930]"></i>Dados Protegidos</li>
                        <li class="whitespace-nowrap"><i class="fa-solid fa-box-open mr-1.5 text-[#0FB930]"></i>Entrega Garantida</li>
                    </ul>
                </div>
            </div>
        </footer>
    </div>
</template>
