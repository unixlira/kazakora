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
        <!-- Rodapé do checkout (pedido 2026-10-12): todo preto com fonte branca.
             Grade de 12 colunas, 4/4/4 no desktop: logo e dados da empresa,
             formas de pagamento e segurança (só os selos que a loja tem de
             verdade). As três colunas começam na mesma altura (cabeçalho de
             altura fixa, o logo é o cabeçalho da primeira). Tablet: empresa em
             cima e pagamento/segurança lado a lado (6/6). Celular: empilha com
             divisória. Embaixo, linha centralizada com os selos de confiança e o
             copyright. Só vale para o checkout; o rodapé da loja fica no AppLayout. -->
        <footer class="mt-auto bg-black text-white" aria-labelledby="rodape-checkout-titulo">
            <h2 id="rodape-checkout-titulo" class="sr-only">Informações da loja, pagamento e segurança</h2>
            <div class="mx-auto grid max-w-[1160px] grid-cols-12 px-5 py-10 sm:gap-x-8 sm:px-6 lg:gap-x-12 lg:py-12">
                <!-- Coluna 1: logo (versão clara, para fundo escuro) e dados da empresa. -->
                <div class="col-span-12 pb-8 lg:col-span-4 lg:pb-0">
                    <div class="flex h-8 items-center">
                        <img :src="$page.props.marca?.logoRodape ?? '/images/marca/logo-rodape.png'" :alt="COMPANY.nomeFantasia"
                            width="160" height="28" class="h-7 w-auto">
                    </div>
                    <address class="mt-5 space-y-3 text-[13px] not-italic leading-snug text-white">
                        <p>
                            <span class="font-semibold">{{ COMPANY.razaoSocial }}</span><br>
                            CNPJ {{ COMPANY.cnpj }}
                        </p>
                        <ul class="space-y-2">
                            <li class="flex gap-2.5">
                                <i class="fa-solid fa-location-dot mt-0.5 w-4 shrink-0 text-center text-[#0FB930]" aria-hidden="true"></i>
                                <span><template v-for="(linha, index) in enderecoEmLinhas" :key="index"><br v-if="index">{{ linha }}</template></span>
                            </li>
                            <li class="flex gap-2.5">
                                <i class="fa-brands fa-whatsapp mt-0.5 w-4 shrink-0 text-center text-[#0FB930]" aria-hidden="true"></i>
                                <span>{{ COMPANY.whatsappDisplay }}</span>
                            </li>
                            <li class="flex gap-2.5">
                                <i class="fa-solid fa-envelope mt-0.5 w-4 shrink-0 text-center text-[#0FB930]" aria-hidden="true"></i>
                                <span class="break-all">{{ COMPANY.email }}</span>
                            </li>
                        </ul>
                    </address>
                </div>

                <!-- Coluna 2: formas de pagamento (mesmas bandeiras do checkout), em
                     grade 3x2 para nenhuma bandeira ficar sozinha numa linha. -->
                <div class="col-span-12 border-t border-white/15 py-8 sm:col-span-6 lg:col-span-4 lg:border-t-0 lg:py-0">
                    <h3 class="flex h-8 items-center text-xs font-semibold uppercase tracking-[0.12em] text-white">Formas de pagamento</h3>
                    <ul class="mt-5 grid w-full max-w-[264px] grid-cols-3 gap-2" aria-label="Bandeiras aceitas">
                        <li v-for="bandeira in BANDEIRAS" :key="bandeira">
                            <img :src="`/images/payments/cartao-hd/${bandeira}.png`" :alt="bandeira" width="254" height="180"
                                class="aspect-[254/180] h-auto w-full object-contain">
                        </li>
                    </ul>
                </div>

                <!-- Coluna 3: segurança. Mesmos selos do rodapé da loja (Google Safe
                     Browsing e Reclame AQUI); nada de selo que a loja não tem. -->
                <div class="col-span-12 border-t border-white/15 pt-8 sm:col-span-6 sm:pb-8 lg:col-span-4 lg:border-t-0 lg:py-0">
                    <h3 class="flex h-8 items-center text-xs font-semibold uppercase tracking-[0.12em] text-white">Segurança</h3>
                    <div class="mt-5 grid w-full max-w-[320px] grid-cols-2 gap-2">
                        <a href="https://transparencyreport.google.com/safe-browsing/search?url=kazakora.com" target="_blank" rel="noopener"
                            class="flex h-[56px] min-w-0 items-center justify-center overflow-hidden rounded-lg bg-white px-2 transition hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                            title="Ver a verificação do Google Safe Browsing">
                            <img src="/images/selos/selo-google-safe.png" alt="Google Safe Browsing" width="139" height="45" class="h-auto max-h-[40px] w-auto max-w-full">
                        </a>
                        <a :href="$page.props.reclameAqui?.url || 'https://www.reclameaqui.com.br/busca/?q=kazakora'" target="_blank" rel="noopener"
                            class="flex h-[56px] min-w-0 flex-col items-center justify-center overflow-hidden rounded-lg bg-white px-2 leading-none transition hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                            title="Ver a KazaKora no Reclame AQUI">
                            <img v-if="$page.props.reclameAqui?.verificada" src="/images/selos/reclame-aqui-verificada-qrcode.png" alt="Verificada por Reclame AQUI, com QR Code de verificação" width="294" height="88"
                                class="h-auto max-h-[42px] w-auto max-w-full rounded-md">
                            <template v-else>
                                <span class="text-[9px] font-semibold uppercase tracking-wider text-slate-600">Estamos no</span>
                                <span class="mt-0.5 text-[20px] font-black tracking-tight text-[#1a1a1a]">Reclame <span class="text-[#4caf3c]">AQUI</span></span>
                            </template>
                        </a>
                    </div>
                    <p class="mt-4 flex items-center gap-2.5 text-[13px] text-white">
                        <i class="fa-solid fa-lock w-4 shrink-0 text-center text-[#0FB930]" aria-hidden="true"></i>Conexão criptografada (HTTPS/SSL)
                    </p>
                </div>
            </div>

            <!-- Linha 2: selos de confiança e copyright, centralizados. -->
            <div class="border-t border-white/15 px-5 py-6">
                <ul class="mx-auto flex max-w-[1160px] flex-wrap items-center justify-center gap-x-6 gap-y-2 text-xs font-medium text-white sm:gap-x-8 sm:text-sm">
                    <li class="whitespace-nowrap"><i class="fa-solid fa-lock mr-1.5 text-[#0FB930]" aria-hidden="true"></i>Compra segura</li>
                    <li class="whitespace-nowrap"><i class="fa-solid fa-fingerprint mr-1.5 text-[#0FB930]" aria-hidden="true"></i>Dados protegidos</li>
                    <li class="whitespace-nowrap"><i class="fa-solid fa-box-open mr-1.5 text-[#0FB930]" aria-hidden="true"></i>Entrega garantida</li>
                </ul>
                <p class="mt-3 text-center text-[11px] text-white sm:text-xs">© {{ new Date().getFullYear() }} {{ COMPANY.nomeFantasia }} | Grupo AlphaKora</p>
            </div>
        </footer>
    </div>
</template>
