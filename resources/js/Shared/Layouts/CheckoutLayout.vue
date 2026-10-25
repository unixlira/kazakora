<script setup>
// Layout do checkout no modelo Yampi (pedido 2026-10-23, referência
// seguro.mavoloja.com.br). Desde 2026-10-24 o topo segue o print da Mavo:
// uma faixa escura só, com a logo, o CNPJ e o endereço (sem nome nem razão
// social, ajuste de 2026-10-24), e abaixo a barra roxa "Oferta termina em".
// O relógio conta até o fim real da oferta do carrinho (Oferta Relâmpago ou
// Oferta do Dia); sem oferta no ar, 30 minutos a partir da primeira visita
// ao checkout (pedido do Lira 2026-10-24, modelo Mavo), guardados no
// navegador para não recomeçar ao atualizar. Zerou, a barra some.
// Rodapé curto com as formas de pagamento, os selos e 4 linhas com os dados
// da loja (no celular, desde 2026-10-24: selos e depois sanfonas de pagamento
// e dados da empresa). Sem menu, busca e rodapé da loja. Sempre claro (cores fixas).
import { COMPANY } from '@/Shared/company.js';
import SeloReclameAqui from '@/Shared/Components/SeloReclameAqui.vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    // ISO 8601 do fim da oferta que vale para o carrinho (null = sem oferta).
    ofertaFim: { type: String, default: null },
    // Pedido já criado (pagando a 2ª parte, Pix aberto): sem relógio.
    semRelogio: { type: Boolean, default: false },
});

const BANDEIRAS = ['amex', 'visa', 'mastercard', 'elo', 'hiper', 'boleto', 'pix'];
const ano = new Date().getFullYear();

// Menus do rodapé no celular (pedido 2026-10-24). Abrem em outra aba para o
// cliente não perder o pagamento em andamento.
const MENUS_RODAPE = [
    {
        titulo: 'Institucional',
        links: [
            { texto: 'Termos de Uso', href: '/termos-de-uso' },
            { texto: 'Política de Privacidade', href: '/politica-de-privacidade' },
            { texto: 'Política de Cookies', href: '/politica-de-cookies' },
            { texto: 'Política de Entrega', href: '/politica-de-entrega' },
            { texto: 'Trocas e Devoluções', href: '/trocas-e-devolucoes' },
        ],
    },
    {
        titulo: 'Atendimento',
        links: [
            { texto: 'Fale Conosco', href: '/fale-conosco' },
            { texto: 'Rastrear pedido', href: '/rastreio' },
            { texto: 'WhatsApp', href: COMPANY.whatsappLink },
            { texto: 'E-mail', href: `mailto:${COMPANY.email}`, mesmaAba: true },
        ],
    },
];

const RELOGIO_MINUTOS = 30;
const RELOGIO_CHAVE = 'kz_checkout_relogio_fim';
const agora = ref(Date.now());
const fimDoRelogio = ref(NaN);
let relogio = null;
onMounted(() => {
    let fim = NaN;
    try {
        fim = Number(localStorage.getItem(RELOGIO_CHAVE));
        // Passou mais de um dia desde o relógio anterior: é outra compra, começa de novo.
        if (!Number.isFinite(fim) || fim <= 0 || Date.now() - fim > 86400000) {
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
    const fimOferta = props.ofertaFim ? new Date(props.ofertaFim).getTime() : NaN;
    const fim = Number.isFinite(fimOferta) && fimOferta > agora.value ? fimOferta : fimDoRelogio.value;

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

        <footer class="mt-auto px-4 pb-10 pt-2">
            <div class="mx-auto max-w-[1200px] border-t border-slate-300/70 pt-8 text-center">
                <!-- No computador as bandeiras ficam abertas; no celular vão para a sanfona "Formas de Pagamento". -->
                <div class="hidden md:block">
                    <!-- Link discreto de ajuda (pedido 2026-10-24): abre o WhatsApp da loja em outra aba, o pagamento fica aberto. -->
                    <a :href="`${COMPANY.whatsappLink}?text=${encodeURIComponent('Olá! Estou com problemas no pagamento.')}`" target="_blank" rel="noopener" class="mb-4 inline-block text-xs text-slate-500 underline underline-offset-2 hover:text-slate-700">Problemas no pagamento?</a>
                    <p class="text-sm font-medium text-slate-600">Formas de pagamento</p>
                    <ul class="mx-auto mt-3 flex max-w-[420px] flex-nowrap items-center justify-center gap-1.5" aria-label="Bandeiras aceitas">
                        <li v-for="bandeira in BANDEIRAS" :key="bandeira" class="min-w-0 flex-1">
                            <img :src="`/images/payments/novas/${bandeira}.webp`" :alt="bandeira" width="249" height="167" loading="lazy" class="h-auto w-full rounded-[6px] border border-slate-200 bg-white">
                        </li>
                    </ul>
                </div>

                <!-- Selos do checkout (no celular são a primeira linha do rodapé). RA Verificada: o mesmo selo oficial do rodapé da loja (2026-10-24, no lugar da imagem com QR Code); abre em outra aba, o cliente não sai do pagamento. -->
                <div class="flex items-center justify-center gap-2 md:mt-5">
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

                <!-- Só no celular: menus sanfona com <details> nativo (teclado e leitor de tela de graça).
                     Ordem pedida em 2026-10-24: selos, Formas de Pagamento, Dados da Empresa, depois Institucional e Atendimento. -->
                <nav class="mx-auto mt-6 max-w-[420px] space-y-2 text-left md:hidden" aria-label="Pagamento, empresa, institucional e atendimento">
                    <details class="rodape-menu rounded-[8px] border border-slate-200 bg-white">
                        <summary class="flex min-h-[44px] cursor-pointer list-none items-center justify-between px-4 text-sm font-semibold text-slate-700">
                            Formas de Pagamento
                            <i class="fa-solid fa-chevron-down rodape-menu-seta text-xs text-slate-500 transition-transform" aria-hidden="true"></i>
                        </summary>
                        <ul class="grid grid-cols-4 gap-1.5 border-t border-slate-200 p-3" aria-label="Bandeiras aceitas">
                            <li v-for="bandeira in BANDEIRAS" :key="bandeira">
                                <img :src="`/images/payments/novas/${bandeira}.webp`" :alt="bandeira" width="249" height="167" loading="lazy" class="h-auto w-full rounded-[6px] border border-slate-200 bg-white">
                            </li>
                        </ul>
                    </details>
                    <details class="rodape-menu rounded-[8px] border border-slate-200 bg-white">
                        <summary class="flex min-h-[44px] cursor-pointer list-none items-center justify-between px-4 text-sm font-semibold text-slate-700">
                            Dados da Empresa
                            <i class="fa-solid fa-chevron-down rodape-menu-seta text-xs text-slate-500 transition-transform" aria-hidden="true"></i>
                        </summary>
                        <dl class="space-y-2 border-t border-slate-200 px-4 py-3 text-xs text-slate-600">
                            <div><dt class="font-semibold text-slate-700">Loja</dt><dd>{{ COMPANY.nomeFantasia }} | Grupo AlphaKora: kazakora.com</dd></div>
                            <div><dt class="font-semibold text-slate-700">CNPJ</dt><dd>{{ COMPANY.cnpj }}</dd></div>
                            <div><dt class="font-semibold text-slate-700">Endereço</dt><dd>{{ COMPANY.enderecoCompleto }}</dd></div>
                            <div><dt class="font-semibold text-slate-700">Contato</dt><dd>WhatsApp: {{ COMPANY.whatsappDisplay }} / E-mail: {{ COMPANY.email }}</dd></div>
                            <div><dt class="font-semibold text-slate-700">Horário</dt><dd>{{ COMPANY.horario }}</dd></div>
                        </dl>
                    </details>
                    <details v-for="menu in MENUS_RODAPE" :key="menu.titulo" class="rodape-menu rounded-[8px] border border-slate-200 bg-white">
                        <summary class="flex min-h-[44px] cursor-pointer list-none items-center justify-between px-4 text-sm font-semibold text-slate-700">
                            {{ menu.titulo }}
                            <i class="fa-solid fa-chevron-down rodape-menu-seta text-xs text-slate-500 transition-transform" aria-hidden="true"></i>
                        </summary>
                        <ul class="border-t border-slate-200 px-4 py-1 text-sm">
                            <li v-for="link in menu.links" :key="link.href">
                                <a :href="link.href" :target="link.mesmaAba ? null : '_blank'" rel="noopener" class="block py-2.5 text-slate-600 hover:text-slate-900">{{ link.texto }}</a>
                            </li>
                        </ul>
                    </details>
                </nav>

                <div class="mt-6 hidden space-y-1 text-xs text-slate-600 md:block">
                    <p>{{ COMPANY.nomeFantasia }}: kazakora.com</p>
                    <p>{{ COMPANY.enderecoCompleto }}</p>
                    <p>© {{ ano }} {{ COMPANY.nomeFantasia }} | Grupo AlphaKora · CNPJ: {{ COMPANY.cnpj }}</p>
                    <p>WhatsApp: {{ COMPANY.whatsappDisplay }} / E-mail: {{ COMPANY.email }}</p>
                </div>
                <p class="mt-5 text-xs text-slate-500 md:hidden">© {{ ano }} {{ COMPANY.nomeFantasia }} | Grupo AlphaKora</p>
            </div>
        </footer>
    </div>
</template>

<style scoped>
.rodape-menu > summary::-webkit-details-marker {
    display: none;
}
.rodape-menu[open] .rodape-menu-seta {
    transform: rotate(180deg);
}
/* Barra do relógio: roxa (pedido 2026-10-24). */
.checkout-oferta-relogio {
    background: #6D28D9;
}
/* Linha colorida do topo (modelo Mavo, nas cores da KazaKora). */
.checkout-oferta {
    background: linear-gradient(90deg, #F44D00 0%, #E0247A 55%, #6D28D9 100%);
}
</style>
