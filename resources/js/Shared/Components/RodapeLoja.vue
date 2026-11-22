<script setup>
// Rodapé da loja. Usado pelo AppLayout (todas as páginas da loja) e pelo
// BlogLayout, para o blog ter exatamente o mesmo rodapé (pedido 2026-11-22).
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { imagensLiberadas } from '@/Shared/imagensDepoisDoTopo';
import { COMPANY } from '@/Shared/company';

const page = usePage();
const marca = computed(() => page.props.marca ?? { logoNav: '/images/marca/logo-nav.webp', logoRodape: '/images/marca/logo-rodape.webp' });
const user = computed(() => page.props.auth?.user);

// Bandeiras novas enviadas pelo Lira (2026-10-14), no formato dos cartões antigos (249x167).
const PAYMENT_BRANDS = ['mastercard', 'visa', 'elo', 'amex', 'hiper', 'boleto', 'pix'];

// Ao passar o mouse, cada ícone fica na cor oficial da rede (pedido 2026-10-10).
const REDES = [
    { nome: 'Instagram', icone: 'fab fa-instagram', link: COMPANY.redes?.instagram, hover: 'hover:bg-gradient-to-tr hover:from-[#feda75] hover:via-[#d62976] hover:to-[#4f5bd5] hover:text-white' },
    { nome: 'Threads', icone: 'fab fa-threads', link: COMPANY.redes?.threads, hover: 'hover:bg-white hover:text-black' },
    { nome: 'Facebook', icone: 'fab fa-facebook-f', link: COMPANY.redes?.facebook, hover: 'hover:bg-[#1877F2] hover:text-white' },
    { nome: 'TikTok', icone: 'fab fa-tiktok', link: COMPANY.redes?.tiktok, hover: 'hover:bg-[#FE2C55] hover:text-white' },
    { nome: 'YouTube', icone: 'fab fa-youtube', link: COMPANY.redes?.youtube, hover: 'hover:bg-[#FF0000] hover:text-white' },
].filter((rede) => rede.link);
</script>

<template>
<footer class="mt-16 bg-[#0b0b0b] text-white">
    <div class="mx-auto max-w-[1320px] px-4 py-14 md:px-6">
        <div class="flex flex-col items-center gap-10 text-center lg:flex-row lg:items-start lg:justify-between lg:text-left">
            <div class="lg:max-w-xs lg:shrink-0">
                <img :src="imagensLiberadas ? marca.logoRodape : undefined" :alt="imagensLiberadas ? 'KazaKora' : ''" class="mx-auto h-8 w-auto rounded-[8px] lg:mx-0" loading="lazy">
                <p class="mt-3 text-sm opacity-80 lg:max-w-[28ch]">
                    Curadoria de eletrônicos, gadgets e utensílios de cozinha, com entrega para todo o Brasil.
                </p>
                <!-- Redes sociais (pedido 2026-10-10) -->
                <div class="mt-5 flex justify-center gap-3 lg:justify-start">
                    <a v-for="rede in REDES" :key="rede.nome" :href="rede.link" target="_blank" rel="noopener" :aria-label="rede.nome"
                        class="flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-lg transition" :class="rede.hover">
                        <i :class="rede.icone"></i>
                    </a>
                </div>
            </div>

            <div class="grid w-full grid-cols-2 gap-x-8 gap-y-10 sm:w-auto lg:grid-cols-4 lg:gap-x-12">
                <div>
                    <h2 class="font-store-mono mb-4 text-xs uppercase tracking-wider opacity-60">Comprar</h2>
                    <ul class="flex flex-col gap-2 text-sm opacity-80">
                        <li><a href="/#categorias" class="hover:opacity-100">Departamentos</a></li>
                        <li><a href="/?todos=1#produtos" class="hover:opacity-100">Produtos</a></li>
                        <li><Link href="/carrinho" class="hover:opacity-100">Meu carrinho</Link></li>
                    </ul>
                </div>
                <div id="contato" class="scroll-mt-24">
                    <h2 class="font-store-mono mb-4 text-xs uppercase tracking-wider opacity-60">Atendimento</h2>
                    <ul class="flex flex-col gap-2 text-sm opacity-80">
                        <li><a :href="`mailto:${COMPANY.email}`" class="hover:opacity-100">{{ COMPANY.email }}</a></li>
                        <li><a :href="COMPANY.whatsappLink" target="_blank" class="hover:opacity-100">{{ COMPANY.whatsappDisplay }}</a></li>
                        <li>São Paulo - SP</li>
                    </ul>
                </div>
                <div>
                    <h2 class="font-store-mono mb-4 text-xs uppercase tracking-wider opacity-60">Institucional</h2>
                    <ul class="flex flex-col gap-2 text-sm opacity-80">
                        <li><Link href="/quem-somos" class="hover:opacity-100">Quem Somos</Link></li>
                        <li><Link href="/politica-de-privacidade" class="hover:opacity-100">Política de Privacidade</Link></li>
                        <li><Link href="/politica-de-cookies" class="hover:opacity-100">Política de Cookies</Link></li>
                        <li><Link href="/termos-de-uso" class="hover:opacity-100">Termos de Uso</Link></li>
                        <li><Link href="/trocas-e-devolucoes" class="hover:opacity-100">Trocas e Devoluções</Link></li>
                        <li><Link href="/politica-de-entrega" class="hover:opacity-100">Política de Entrega</Link></li>
                    </ul>
                </div>
                <div>
                    <h2 class="font-store-mono mb-4 text-xs uppercase tracking-wider opacity-60">Minha conta</h2>
                    <ul class="flex flex-col gap-2 text-sm opacity-80">
                        <template v-if="!user">
                            <li><Link href="/entrar" class="hover:opacity-100">Entrar</Link></li>
                            <li><Link href="/cadastro" class="hover:opacity-100">Cadastrar</Link></li>
                        </template>
                        <template v-else>
                            <li><Link href="/perfil" class="hover:opacity-100">Meu perfil</Link></li>
                            <li><Link href="/pedidos" class="hover:opacity-100">Meus Pedidos</Link></li>
                        </template>
                        <li><Link href="/rastreio" class="hover:opacity-100">Rastrear pedido</Link></li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="mt-10 flex flex-col items-center gap-6 text-center lg:mt-12 lg:grid lg:grid-cols-2 lg:items-center lg:gap-8 lg:text-left">
            <div>
                <h2 class="font-store-mono mb-3 text-xs uppercase tracking-wider opacity-60">Pagamentos</h2>
                <!-- Celular: bandeiras menores, todas numa linha só (sem arrastar). -->
                <div class="flex flex-wrap items-center justify-center gap-1.5 sm:gap-2 lg:justify-start">
                    <!-- Bandeiras sem a sombra embutida (era a linha à direita) e cantos
                         retos no arquivo; o arredondamento leve vem do CSS (pedido 2026-10-14). -->
                    <img v-for="brand in PAYMENT_BRANDS" :key="brand" :src="imagensLiberadas ? `/images/payments/novas/${brand}.webp` : undefined" :alt="imagensLiberadas ? brand : ''"
                        loading="lazy" decoding="async" width="249" height="167"
                        class="h-[30px] w-auto shrink-0 rounded-[8px] sm:h-[44px] lg:h-[51px]">
                </div>
            </div>

            <!-- Selos (2026-10-11): mesma altura das bandeiras de pagamento, simétrico
                 a elas. Reclame AQUI: selo oficial RA Verificada (2026-10-14).
                 Tamanho original (grande), sem a redução de 5px (pedido 2026-10-14). -->
            <div class="lg:text-right">
                <h2 class="font-store-mono mb-3 text-xs uppercase tracking-wider opacity-60">Segurança</h2>
                <div class="flex flex-wrap items-center justify-center gap-1.5 sm:gap-2 lg:justify-end">
                    <a href="https://transparencyreport.google.com/safe-browsing/search?url=kazakora.com" target="_blank" rel="noopener"
                        class="flex h-[30px] cursor-pointer items-center justify-center overflow-hidden rounded-[8px] bg-white px-2.5 transition hover:opacity-90 sm:h-[44px] sm:px-3 lg:h-[51px]"
                        title="Ver a verificação do Google Safe Browsing">
                        <img :src="imagensLiberadas ? '/images/selos/selo-google-safe.webp' : undefined" :alt="imagensLiberadas ? 'Google Safe Browsing' : ''" width="139" height="45" loading="lazy" decoding="async"
                            class="h-[22px] w-auto sm:h-[34px] lg:h-[40px]">
                    </a>
                    <!-- Selo RA Verificada: imagem "Verificada por Reclame AQUI", sem QR Code (pedido
                         2026-11-02). O widget oficial passou a desenhar "Estamos há mais de 1 mês" e
                         saía cortado. Na mesma escala do Google (pedido 2026-10-14). -->
                    <a :href="page.props.reclameAqui?.url || 'https://www.reclameaqui.com.br/rav/F68R'" target="_blank" rel="noopener"
                        class="flex h-[30px] cursor-pointer items-center justify-center overflow-hidden rounded-[8px] bg-white px-2.5 transition hover:opacity-90 sm:h-[44px] sm:px-3 lg:h-[51px]"
                        title="Verificada por Reclame AQUI">
                        <img :src="imagensLiberadas ? '/images/selos/reclame-aqui-verificada.webp' : undefined" :alt="imagensLiberadas ? 'Verificada por Reclame AQUI' : ''" width="578" height="158" loading="lazy" decoding="async"
                            class="h-[22px] w-auto sm:h-[34px] lg:h-[40px]">
                    </a>
                    <!-- Norton Safe Web (2026-10-23): classificação "Seguro" para kazakora.com.
                         SVG vetorial de 5 KB, mesma caixa branca e altura dos outros selos. -->
                    <a href="https://safeweb.norton.com/report?url=kazakora.com" target="_blank" rel="noopener"
                        class="flex h-[30px] cursor-pointer items-center justify-center overflow-hidden rounded-[8px] bg-white px-2.5 transition hover:opacity-90 sm:h-[44px] sm:px-3 lg:h-[51px]"
                        title="Ver a classificação do Norton Safe Web">
                        <img :src="imagensLiberadas ? '/images/selos/selo-norton.svg' : undefined" :alt="imagensLiberadas ? 'Norton Safe Web' : ''" width="104" height="40" loading="lazy" decoding="async"
                            class="h-[22px] w-auto sm:h-[30px] lg:h-[34px]">
                    </a>
                </div>
            </div>
        </div>

        <div class="mt-12 border-t border-white/10 pt-6 text-center text-xs">
            <span class="opacity-70">© 2026 KazaKora · CNPJ {{ COMPANY.cnpj }} · {{ COMPANY.enderecoResumido }}</span>
            <!-- Crédito do desenvolvimento (2026-10-11). -->
            <p class="mt-2">
                <span class="opacity-70">Desenvolvido por</span>
                <a href="https://tech.alphakora.com.br/" target="_blank" rel="noopener"
                    class="ml-1 font-extrabold tracking-wide no-underline transition hover:brightness-125"><span class="text-[#F27A2A]">Kora</span><span class="koratech-brilho">Tech</span></a>
            </p>
        </div>
    </div>
</footer>
</template>

<style>
/* "Tech" do crédito do rodapé: brilho indo e vindo só na palavra (2026-10-11). */
.koratech-brilho {
    background: linear-gradient(90deg, #22c55e 0%, #ffffff 45%, #F27A2A 55%, #22c55e 100%);
    background-size: 250% 100%;
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    animation: koratech-vai-vem 2.6s ease-in-out infinite alternate;
}
@keyframes koratech-vai-vem {
    from { background-position: 100% 0; }
    to { background-position: 0 0; }
}
@media (prefers-reduced-motion: reduce) {
    .koratech-brilho { animation: none; }
}
</style>
