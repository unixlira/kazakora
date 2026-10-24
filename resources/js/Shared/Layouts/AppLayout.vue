<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { notifyError, notifySuccess, notifyWarning } from '@/Shared/notify';
import { atualizarFavoritos, totalFavoritos } from '@/Shared/favoritos';
import { useClickOutside } from '@/Shared/useClickOutside';
import { imagensLiberadas } from '@/Shared/imagensDepoisDoTopo';
import { COMPANY } from '@/Shared/company';
import FaixaPromo from '@/Shared/Components/FaixaPromo.vue';
import TrocarSenhaModal from '@/Shared/Components/TrocarSenhaModal.vue';
import ModalAvaliacao from '@/Shared/Components/ModalAvaliacao.vue';
import SeloReclameAqui from '@/Shared/Components/SeloReclameAqui.vue';
import BuscaInstantanea from '@/Shared/Components/BuscaInstantanea.vue';

const page = usePage();
const marca = computed(() => page.props.marca ?? { logoNav: '/images/marca/logo-nav.webp', logoRodape: '/images/marca/logo-rodape.webp' });
const cartCount = computed(() => page.props.cart?.count ?? 0);
const favoritesCount = computed(() => totalFavoritos());
const user = computed(() => page.props.auth?.user);

// Favoritos sem login ficam no navegador; com login, vêm da conta (2026-10-15).
watch(
    () => [Boolean(user.value), page.props.favorites?.ids],
    ([logado, ids]) => atualizarFavoritos(logado, ids ?? []),
    { immediate: true },
);
const notifications = computed(() => page.props.notifications?.items ?? []);
const unreadNotifications = computed(() => page.props.notifications?.unreadCount ?? 0);

watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.success) notifySuccess(flash.success);
        if (flash?.error) notifyError(flash.error);
        if (flash?.warning) notifyWarning(flash.warning);
    },
    { immediate: true, deep: true },
);



const logout = () => router.post('/sair');

const userMenuOpen = ref(false);
const userMenuRef = ref(null);
useClickOutside(userMenuRef, () => (userMenuOpen.value = false));

const notifMenuOpen = ref(false);
const notifMenuRef = ref(null);
useClickOutside(notifMenuRef, () => (notifMenuOpen.value = false));

// Clique no sino abre a tela da notificação, que marca como lida e tem o
// botão certo (pedido, senha, promoção) — 2026-10-11.
const markNotificationRead = (item) => {
    notifMenuOpen.value = false;
    router.visit(`/notificacoes/${item.id}`);
};

const marcandoTodas = ref(false);
const markAllNotificationsRead = () => {
    marcandoTodas.value = true;
    router.post('/notificacoes/ler-todas', {}, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { marcandoTodas.value = false; },
    });
};

const mobileMenuOpen = ref(false);
const contaMobileAberta = ref(false);

// Mega menu de Departamentos e menu do Fale conosco (pedido 2026-10-10).
// Abrem ao passar o mouse (com uma folguinha pra não fechar no caminho) e
// também no clique/teclado.
const departamentos = computed(() => page.props.menuLoja?.departamentos ?? []);
const utilidadesLink = computed(() => page.props.menuLoja?.utilidadesLink ?? null);
const menuAberto = ref(null);
let fecharTimer = null;
const abrirMenu = (nome) => {
    clearTimeout(fecharTimer);
    menuAberto.value = nome;
};
const fecharMenu = () => {
    clearTimeout(fecharTimer);
    fecharTimer = setTimeout(() => { menuAberto.value = null; }, 150);
};
const alternarMenu = (nome) => {
    clearTimeout(fecharTimer);
    menuAberto.value = menuAberto.value === nome ? null : nome;
};
const navRef = ref(null);
useClickOutside(navRef, () => (menuAberto.value = null));
const pararDeOuvir = router.on('start', () => {
    menuAberto.value = null;
    mobileMenuOpen.value = false;
    contaMobileAberta.value = false;
});
onUnmounted(pararDeOuvir);

// Busca (pedido 2026-10-10): o termo continua no campo depois de buscar e
// a lista de resultados abre logo no topo. Ao digitar, a BuscaInstantanea
// mostra foto e nome dos produtos (2026-10-15).
const termoDaUrl = () => new URLSearchParams(window.location.search).get('search') ?? '';
const search = ref(termoDaUrl());
const submitSearch = () => {
    const termo = search.value.trim();
    mobileMenuOpen.value = false;
    router.get('/', termo ? { search: termo } : { todos: 1 });
};

// Busca do celular recolhida atrás da lupa, dentro do menu (pedido 2026-10-15):
// a lupa abre a barra e vira "X"; o "X" fecha, limpa o termo e, se havia busca
// na tela, volta para a vitrine sem filtro. Já abre aberta quando a página veio
// de uma busca (aparece ao abrir o menu).
const buscaMobileAberta = ref(termoDaUrl() !== '');
const buscaMobilePronta = ref(buscaMobileAberta.value);
const buscaMobileInput = ref(null);
const alternarBuscaMobile = () => {
    if (!buscaMobileAberta.value) {
        buscaMobileAberta.value = true;
        nextTick(() => buscaMobileInput.value?.focus());
        return;
    }
    buscaMobileAberta.value = false;
    buscaMobilePronta.value = false;
    search.value = '';
    if (termoDaUrl() !== '') router.get('/');
};

const discountModalOpen = ref(false);
const closeDiscountModal = () => {
    discountModalOpen.value = false;
    window.sessionStorage?.setItem('kazakora_discount_modal_seen', '1');
};

onMounted(() => {
    // Popup de desconto removido da abertura automática: a vitrine precisa
    // aparecer limpa primeiro. Ofertas já ficam visíveis nos cards e CTAs.
});

// Pedido do Lira 2026-10-12: o topo é branco parado no lugar e fica
// translúcido e fumê quando passa a flutuar (a marcação logo acima dele
// sumiu da tela). Estilo em .loja-topo-fume (app.css).
const marcaDoTopo = ref(null);
const topoFlutuando = ref(false);
const medirRolagem = () => {
    topoFlutuando.value = (marcaDoTopo.value?.getBoundingClientRect().top ?? 0) < 0;
};
onMounted(() => {
    medirRolagem();
    window.addEventListener('scroll', medirRolagem, { passive: true });
    window.addEventListener('resize', medirRolagem, { passive: true });
});
onUnmounted(() => {
    window.removeEventListener('scroll', medirRolagem);
    window.removeEventListener('resize', medirRolagem);
});

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
    <div class="storefront-shell min-h-screen bg-store-bg font-store text-store-fg">
        <!-- Faixa promocional com "X" para fechar (pedido 2026-10-10). -->
        <FaixaPromo />
        <TrocarSenhaModal />
        <ModalAvaliacao />
        <!-- Marquee. "relative" pinta a faixa por cima das formas desfocadas da FaixaPromo
             (o PageSpeed lia o laranja delas atrás do "Full" verde). -->
        <div class="relative overflow-hidden whitespace-nowrap bg-store-accent-strong text-store-accent-contrast">
            <!-- Faixa contínua (pedido 2026-10-10): 6 cópias e a animação anda metade
                 (3 cópias, mais largo que qualquer tela) — nunca sobra espaço em branco.
                 Roda também com "reduzir movimento" ligado (pedido 2026-10-15): no celular
                 essa opção liga sozinha com a economia de bateria e a faixa ficava parada. -->
            <div class="inline-flex animate-[scroll-left_60s_linear_infinite] items-center py-2">
                <span v-for="n in 6" :key="n" class="contents">
                    <span class="font-store-mono px-5 text-[0.68rem] uppercase tracking-wider opacity-90 after:ml-5 after:content-['·']">Frete grátis em todos os produtos</span>
                    <!-- Full na faixa do topo (pedido 2026-10-10). -->
                    <span class="font-store-mono px-5 text-[0.68rem] uppercase tracking-wider opacity-90 after:ml-5 after:content-['·']"><span class="font-extrabold italic text-[#22c55e]"><i class="fa-solid fa-bolt"></i>Full</span> Receba no mesmo dia</span>
                    <span class="font-store-mono px-5 text-[0.68rem] uppercase tracking-wider opacity-90 after:ml-5 after:content-['·']">Pague no PIX e economize</span>
                    <span class="font-store-mono px-5 text-[0.68rem] uppercase tracking-wider opacity-90 after:ml-5 after:content-['·']">Suporte via WhatsApp</span>
                </span>
            </div>
        </div>

        <!-- Header -->
        <div ref="marcaDoTopo" aria-hidden="true"></div>
        <header class="loja-topo sticky top-0 z-40 border-b border-store-border bg-store-bg-raised"
            :class="{ 'loja-topo-fume': topoFlutuando }">
            <!-- Topo mais baixo no celular (pedido 2026-10-15): py-2 e ícones de 36px abaixo do lg. -->
            <!-- max-lg:relative: no celular o logo fica centralizado em cima da linha (pedido do Lira 2026-10-23); no computador não, para o mega menu seguir com a largura do topo. -->
            <div class="mx-auto flex max-w-[1320px] items-center gap-2 px-3 py-2 max-lg:relative md:px-6 lg:gap-8 lg:py-4">
                <!-- Celular: menu (três listras) à esquerda do logo (pedido do Lira 2026-10-23). -->
                <button type="button" class="-ml-1.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-store-fg hover:bg-store-bg-sunken lg:hidden" aria-label="Menu" :aria-expanded="mobileMenuOpen" aria-controls="menu-mobile" @click="mobileMenuOpen = !mobileMenuOpen">
                    <i class="fas text-base" :class="mobileMenuOpen ? 'fa-xmark' : 'fa-bars'"></i>
                </button>

                <!-- Logo (Admin > Banners > Logos e favicon); no modo escuro usa a clara do rodapé. Centralizado no celular (2026-10-23). -->
                <Link href="/" class="shrink-0 no-underline max-lg:absolute max-lg:left-1/2 max-lg:-translate-x-1/2" aria-label="KazaKora — início">
                    <picture>
                        <source media="(prefers-color-scheme: dark)" :srcset="marca.logoRodape">
                        <img :src="marca.logoNav" alt="KazaKora" class="h-6 w-auto md:h-8" width="160" height="28" fetchpriority="high">
                    </picture>
                </Link>

                <nav ref="navRef" class="ml-auto hidden items-center gap-7 lg:flex" @keydown.esc="menuAberto = null">
                    <div @mouseenter="abrirMenu('departamentos')" @mouseleave="fecharMenu">
                        <button type="button" class="flex items-center gap-1.5 text-sm font-medium hover:text-store-fg"
                            :class="menuAberto === 'departamentos' ? 'text-store-fg' : 'text-store-fg-muted'"
                            :aria-expanded="menuAberto === 'departamentos'" @click="alternarMenu('departamentos')">
                            Departamentos
                            <i class="fa-solid fa-chevron-down text-[0.6rem] transition-transform" :class="{ 'rotate-180': menuAberto === 'departamentos' }"></i>
                        </button>

                        <!-- Mega menu: ocupa a largura do topo, logo abaixo dele. -->
                        <Transition enter-from-class="opacity-0 -translate-y-1" enter-active-class="transition duration-150" leave-to-class="opacity-0 -translate-y-1" leave-active-class="transition duration-100">
                            <div v-if="menuAberto === 'departamentos'" class="topo-menu absolute inset-x-0 top-full z-50 border-b border-store-border bg-store-bg-raised shadow-2xl">
                                <div class="mx-auto grid max-w-[1320px] grid-cols-[1fr_280px] gap-8 px-6 py-7">
                                    <div>
                                        <p class="font-store-mono text-xs uppercase tracking-wider text-store-fg-faint">Compre por departamento</p>
                                        <div class="mt-4 grid grid-cols-3 gap-2 xl:grid-cols-4">
                                            <Link v-for="dep in departamentos" :key="dep.id" :href="`/?categoria=${dep.slug}#produtos`"
                                                class="group flex items-center gap-3 rounded-xl p-2 no-underline transition hover:bg-store-bg-sunken">
                                                <span class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-full bg-white ring-1 ring-store-border transition group-hover:ring-2 group-hover:ring-[#f27a2a]">
                                                    <img v-if="dep.image_url" :src="dep.image_url" :alt="dep.name" class="h-full w-full object-cover" loading="lazy">
                                                    <i v-else class="fa-solid fa-tag text-store-fg-faint"></i>
                                                </span>
                                                <span class="min-w-0">
                                                    <span class="block truncate text-sm font-semibold text-store-fg group-hover:text-store-link-hover">{{ dep.name }}</span>
                                                    <span class="block text-xs text-store-fg-faint">{{ dep.total }} {{ dep.total === 1 ? 'produto' : 'produtos' }}</span>
                                                </span>
                                            </Link>
                                        </div>
                                        <p v-if="!departamentos.length" class="mt-4 text-sm text-store-fg-muted">Nenhum departamento por aqui ainda.</p>
                                    </div>
                                    <div class="relative isolate flex flex-col justify-between overflow-hidden rounded-2xl bg-[#111] p-6 text-white">
                                        <span aria-hidden="true" class="absolute -right-10 -top-10 -z-10 h-36 w-36 rounded-full bg-[#f27a2a] opacity-60 blur-2xl"></span>
                                        <span aria-hidden="true" class="absolute -bottom-12 -left-8 -z-10 h-32 w-32 rounded-full bg-[#0FB930] opacity-40 blur-2xl"></span>
                                        <div>
                                            <p class="text-xs font-bold uppercase tracking-widest text-[#f6c343]"><i class="fa-solid fa-bolt mr-1"></i>Full</p>
                                            <p class="mt-2 text-xl font-bold leading-tight">Frete grátis em todos os produtos</p>
                                            <p class="mt-2 text-sm opacity-80">E com o Full você recebe no mesmo dia.</p>
                                        </div>
                                        <a href="/?todos=1#produtos" class="mt-5 inline-flex items-center justify-center gap-2 rounded-full bg-white px-4 py-2.5 text-sm font-bold text-black no-underline transition hover:bg-store-laranja hover:text-white">
                                            Ver todos os produtos <i class="fa-solid fa-arrow-right text-xs"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </Transition>
                    </div>
                    <a href="/?todos=1#produtos" class="text-sm font-medium text-store-fg-muted hover:text-store-fg">Produtos</a>
                    <!-- Sem o texto "Favoritos": o coração vermelho no topo já leva à página (pedido 2026-10-15). -->
                    <Link href="/rastreio" class="text-sm font-medium text-store-fg-muted hover:text-store-fg">Rastrear pedido</Link>
                    <div class="relative" @mouseenter="abrirMenu('contato')" @mouseleave="fecharMenu">
                        <button type="button" class="flex items-center gap-1.5 text-sm font-medium hover:text-store-fg"
                            :class="menuAberto === 'contato' ? 'text-store-fg' : 'text-store-fg-muted'"
                            :aria-expanded="menuAberto === 'contato'" @click="alternarMenu('contato')">
                            Fale conosco
                            <i class="fa-solid fa-chevron-down text-[0.6rem] transition-transform" :class="{ 'rotate-180': menuAberto === 'contato' }"></i>
                        </button>
                        <Transition enter-from-class="opacity-0 -translate-y-1" enter-active-class="transition duration-150" leave-to-class="opacity-0 -translate-y-1" leave-active-class="transition duration-100">
                            <div v-if="menuAberto === 'contato'" class="topo-menu absolute right-0 top-full z-50 pt-3">
                                <div class="w-72 rounded-2xl border border-store-border bg-store-bg-raised p-2 shadow-2xl">
                                    <a :href="COMPANY.whatsappLink" target="_blank" rel="noopener"
                                        class="flex items-center gap-3 rounded-xl p-3 no-underline transition hover:bg-store-bg-sunken">
                                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#25D366] text-xl text-white"><i class="fa-brands fa-whatsapp"></i></span>
                                        <span>
                                            <span class="block text-sm font-semibold text-store-fg">WhatsApp</span>
                                            <span class="block text-xs text-store-fg-muted">{{ COMPANY.whatsappDisplay }} · resposta rápida</span>
                                        </span>
                                    </a>
                                    <Link href="/fale-conosco"
                                        class="flex items-center gap-3 rounded-xl p-3 no-underline transition hover:bg-store-bg-sunken">
                                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#f27a2a] text-base text-white"><i class="fa-regular fa-envelope"></i></span>
                                        <span>
                                            <span class="block text-sm font-semibold text-store-fg">Mandar mensagem</span>
                                            <span class="block text-xs text-store-fg-muted">Respondemos no seu e-mail</span>
                                        </span>
                                    </Link>
                                </div>
                            </div>
                        </Transition>
                    </div>
                </nav>

                <BuscaInstantanea v-model="search" class="hidden max-w-xs flex-1 lg:block" @buscar="submitSearch" />

                <div class="ml-auto flex items-center gap-0.5 lg:ml-0 lg:gap-1">
                    <!-- Celular: lupa e conta ficam dentro do menu (pedido 2026-10-15); o carrinho fica aqui no topo (2026-10-23). -->
                    <!-- Favoritos desligados (pedido do Lira 2026-10-23); para voltar, descomente.
                    <Link href="/favoritos" class="relative hidden h-9 w-9 lg:flex items-center justify-center rounded-full lg:h-10 lg:w-10 text-store-fg hover:bg-store-bg-sunken" aria-label="Favoritos" preserve-scroll>
                        <i class="fas fa-heart text-base text-[#ef4444]"></i>
                        <span v-if="favoritesCount > 0" class="absolute right-0.5 top-0.5 rounded-full bg-red-600 px-1 text-[0.6rem] font-store-mono leading-tight text-white">{{ favoritesCount }}</span>
                    </Link>
                    -->
                    <!-- Celular: carrinho no topo, à direita, onde ficava o botão do menu (pedido do Lira 2026-10-23).
                         Sacolinha de mão no lugar do carrinho de supermercado, o mesmo desenho da Mavo (feather shopping-bag, 2026-10-23). -->
                    <Link href="/carrinho" class="relative flex h-9 w-9 items-center justify-center rounded-full lg:h-10 lg:w-10 text-store-fg hover:bg-store-bg-sunken" aria-label="Carrinho">
                        <!-- Bolinha laranja ao lado da sacola, só no celular, com o "0" dentro quando o carrinho está vazio; some quando o contador aparece (pedido 2026-10-23). -->
                        <span class="relative"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="h-[22px] w-[22px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                            <span v-if="cartCount === 0" class="absolute -right-1.5 -top-1.5 flex h-4 w-4 items-center justify-center rounded-full bg-[#f27a2a] text-[0.6rem] font-store-mono font-semibold leading-none text-white lg:hidden" aria-hidden="true">0</span></span>
                        <span v-if="cartCount > 0" class="absolute right-0.5 top-0.5 rounded-full bg-red-600 px-1 text-[0.6rem] font-store-mono leading-tight text-white">{{ cartCount }}</span>
                    </Link>

                    <div v-if="user" ref="notifMenuRef" class="relative">
                        <button type="button" class="relative flex h-9 w-9 items-center justify-center rounded-full lg:h-10 lg:w-10 text-store-fg hover:bg-store-bg-sunken" aria-label="Notificações" @click="notifMenuOpen = !notifMenuOpen">
                            <i class="far fa-bell text-base"></i>
                            <span v-if="unreadNotifications > 0" class="absolute right-0.5 top-0.5 rounded-full bg-red-600 px-1 text-[0.6rem] font-store-mono leading-tight text-white">{{ unreadNotifications }}</span>
                        </button>

                        <div v-if="notifMenuOpen" class="topo-menu absolute right-0 z-50 mt-2 w-80 rounded-xl border border-store-border bg-store-bg-raised py-2 text-left shadow-lg">
                            <!-- Marcando todas como lidas: desfoque branco + loader até a requisição terminar (2026-10-11). -->
                            <div v-if="marcandoTodas" class="absolute inset-0 z-10 flex items-center justify-center rounded-xl bg-white/60 backdrop-blur-sm">
                                <i class="fas fa-circle-notch animate-spin text-2xl text-[#F27A2A]"></i>
                            </div>
                            <div class="flex items-center justify-between px-4 py-2">
                                <h5 class="font-store-mono text-xs uppercase tracking-wider text-store-fg-faint">🔔 Notificações</h5>
                                <button v-if="unreadNotifications > 0" type="button" class="text-xs font-medium text-store-accent hover:underline" @click="markAllNotificationsRead" :disabled="marcandoTodas">
                                    Marcar todas como lidas
                                </button>
                            </div>

                            <p v-if="notifications.length === 0" class="px-4 py-6 text-center text-sm text-store-fg-muted">
                                Nenhuma notificação por aqui ainda.
                            </p>

                            <div class="max-h-[60vh] overflow-y-auto overscroll-contain">
                            <button v-for="item in notifications" :key="item.id" type="button"
                                class="flex w-full items-start gap-2 px-4 py-2.5 text-left text-sm hover:bg-store-bg-sunken"
                                :class="{ 'bg-store-accent-soft/40': !item.read }"
                                @click="markNotificationRead(item)">
                                <span class="mt-1.5 shrink-0 rounded-full"
                                    :class="item.nivel === 'erro' ? 'h-2.5 w-2.5 bg-red-500 ring-2 ring-red-200' : item.nivel === 'sucesso' ? 'h-2.5 w-2.5 bg-green-500 ring-2 ring-green-200' : item.read ? 'h-1.5 w-1.5 bg-transparent' : 'h-1.5 w-1.5 bg-store-accent'"></span>
                                <span>
                                    <span class="block font-medium text-store-fg">{{ item.message }}</span>
                                    <span v-if="item.body" class="block text-xs text-store-fg-muted">{{ item.body }}</span>
                                    <span class="font-store-mono text-xs text-store-fg-faint">{{ item.createdAt }}</span>
                                </span>
                            </button>
                            </div>
                        </div>
                    </div>

                    <div ref="userMenuRef" class="relative">
                        <button v-if="user" type="button" class="hidden h-9 w-9 items-center justify-center rounded-full lg:flex lg:h-10 lg:w-10 hover:bg-store-bg-sunken" @click="userMenuOpen = !userMenuOpen">
                            <img v-if="user.avatar_url" :src="user.avatar_url" class="h-8 w-8 rounded-full object-cover" alt="">
                            <span v-else class="flex h-8 w-8 items-center justify-center rounded-full bg-store-accent-soft text-xs font-semibold text-store-accent-strong">{{ user.initials }}</span>
                        </button>
                        <!-- Celular: a conta e o Entrar ficam só no menu (pedido 2026-10-15); os ícones do topo são só do computador. -->
                        <Link v-else href="/entrar" class="hidden h-9 w-9 items-center justify-center rounded-full lg:flex lg:h-10 lg:w-10 text-store-fg hover:bg-store-bg-sunken" aria-label="Entrar">
                            <i class="far fa-user text-base"></i>
                        </Link>

                        <!-- Menu da conta (2026-10-11): DM Sans, ícones no lugar dos emojis. -->
                        <div v-if="userMenuOpen" class="topo-menu absolute right-0 z-50 mt-2 w-60 overflow-hidden rounded-2xl border border-store-border bg-store-bg-raised text-left font-sans shadow-xl">
                            <div class="border-b border-store-border bg-store-bg-sunken px-4 py-3">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-store-fg-faint">Olá,</p>
                                <p class="truncate text-[15px] font-bold text-store-fg">{{ user?.name }}</p>
                            </div>
                            <nav class="py-1.5 text-[15px] font-medium text-store-fg">
                                <Link href="/perfil" class="flex items-center gap-3 px-4 py-2.5 no-underline hover:bg-store-bg-sunken hover:text-store-link-hover"><i class="far fa-user w-4 text-center text-[#F27A2A]"></i> Meu Perfil</Link>
                                <Link href="/pedidos" class="flex items-center gap-3 px-4 py-2.5 no-underline hover:bg-store-bg-sunken hover:text-store-link-hover"><i class="fas fa-bag-shopping w-4 text-center text-[#F27A2A]"></i> Meus Pedidos</Link>
                                <Link href="/rastreio" class="flex items-center gap-3 px-4 py-2.5 no-underline hover:bg-store-bg-sunken hover:text-store-link-hover"><i class="fas fa-truck-fast w-4 text-center text-[#F27A2A]"></i> Rastrear Pedido</Link>
                                <!-- Configurações (E-mails, Idioma, Aparência, Privacidade) ainda é "Em breve": fora do menu até existir (2026-10-17).
                                <Link href="/configuracoes" class="flex items-center gap-3 px-4 py-2.5 no-underline hover:bg-store-bg-sunken hover:text-store-link-hover"><i class="fas fa-gear w-4 text-center text-[#F27A2A]"></i> Configurações</Link>
                                -->
                                <a v-if="user?.role === 'admin'" href="https://painel.kazakora.com/admin" class="flex items-center gap-3 px-4 py-2.5 no-underline hover:bg-store-bg-sunken hover:text-store-link-hover"><i class="fas fa-screwdriver-wrench w-4 text-center text-[#F27A2A]"></i> Painel Admin</a>
                            </nav>
                            <button type="button" class="flex w-full items-center gap-3 border-t border-store-border px-4 py-3 text-left text-[15px] font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30" @click="logout"><i class="fas fa-arrow-right-from-bracket w-4 text-center"></i> Sair</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Menu do celular (pedido 2026-10-15): no alto, Lupa, Favoritos (coração vermelho), Carrinho e
                 Conta, nessa ordem. A lupa abre a busca logo abaixo e o menu cresce junto. -->
            <div v-if="mobileMenuOpen" id="menu-mobile" class="border-t border-store-border px-4 py-3 lg:hidden">
                <!-- Duas linhas de 3 (pedido 2026-10-23, sem Favoritos e Carrinho): aqui Buscar, Rastrear pedido e Conta; abaixo da linha, Departamentos, Produtos e Mandar mensagem. -->
                <div class="grid grid-cols-3 gap-2">
                    <button type="button" class="menu-mobile-icone"
                        :class="{ 'menu-mobile-icone-ativo': buscaMobileAberta }"
                        :aria-expanded="buscaMobileAberta" aria-controls="busca-mobile" @click="alternarBuscaMobile">
                        <!-- Bolinha laranja sempre visível ao lado da lupa, mesmo sem termo pesquisado (pedido 2026-10-23). -->
                        <span class="relative"><i class="fas text-lg" :class="buscaMobileAberta ? 'fa-xmark' : 'fa-magnifying-glass'"></i>
                            <span class="absolute -right-2 -top-1 h-2.5 w-2.5 rounded-full bg-[#f27a2a]" aria-hidden="true"></span></span>
                        <span>{{ buscaMobileAberta ? 'Fechar' : 'Buscar' }}</span>
                    </button>
                    <!-- Favoritos desligados (pedido do Lira 2026-10-23); para voltar, descomente e troque grid-cols-3 por 4.
                    <Link href="/favoritos" class="menu-mobile-icone" preserve-scroll>
                        <span class="relative"><i class="fas fa-heart text-lg text-[#ef4444]"></i>
                            <span v-if="favoritesCount > 0" class="menu-mobile-contador">{{ favoritesCount }}</span></span>
                        <span>Favoritos</span>
                    </Link>
                    -->
                    <!-- Carrinho fora do menu do celular (pedido do Lira 2026-10-23); para voltar, descomente e tire o Rastrear pedido daqui.
                    <Link href="/carrinho" class="menu-mobile-icone">
                        <span class="relative"><i class="fas fa-cart-shopping text-lg"></i>
                            <span v-if="cartCount > 0" class="menu-mobile-contador">{{ cartCount }}</span></span>
                        <span>Carrinho</span>
                    </Link>
                    -->
                    <Link href="/rastreio" class="menu-mobile-icone">
                        <i class="fas fa-truck-fast text-lg text-[#10b981]"></i>
                        <span>Rastrear pedido</span>
                    </Link>
                    <button v-if="user" type="button" class="menu-mobile-icone"
                        :class="{ 'menu-mobile-icone-ativo': contaMobileAberta }"
                        :aria-expanded="contaMobileAberta" aria-controls="conta-mobile" @click="contaMobileAberta = !contaMobileAberta">
                        <img v-if="user.avatar_url" :src="user.avatar_url" class="h-6 w-6 rounded-full object-cover" alt="">
                        <span v-else class="flex h-6 w-6 items-center justify-center rounded-full bg-store-accent-soft text-[0.6rem] font-semibold text-store-accent-strong">{{ user.initials }}</span>
                        <span>Conta</span>
                    </button>
                    <Link v-else href="/entrar" class="menu-mobile-icone">
                        <i class="fa-regular fa-circle-user text-lg text-[#f27a2a]"></i>
                        <span>Entrar</span>
                    </Link>
                </div>

                <!-- Busca: abre e fecha pela lupa do menu. Aberta, deixa a lista da busca instantânea passar para fora. -->
                <div id="busca-mobile" class="grid transition-[grid-template-rows] duration-200 ease-out"
                    :class="buscaMobileAberta ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'" :inert="!buscaMobileAberta"
                    @transitionend.self="buscaMobilePronta = buscaMobileAberta">
                    <div :class="buscaMobilePronta && buscaMobileAberta ? 'overflow-visible' : 'overflow-hidden'">
                        <div class="pt-3" @keydown.esc="alternarBuscaMobile">
                            <BuscaInstantanea ref="buscaMobileInput" v-model="search" celular @buscar="submitSearch" />
                        </div>
                    </div>
                </div>

                <!-- Conta (logado): o que fica no menu do avatar no computador. -->
                <nav v-if="user && contaMobileAberta" id="conta-mobile" class="mt-3 overflow-hidden rounded-xl border border-store-border text-sm font-medium">
                    <p class="truncate bg-store-bg-sunken px-3 py-2 text-xs text-store-fg-muted">Olá, <strong class="text-store-fg">{{ user.name }}</strong></p>
                    <Link href="/perfil" class="flex items-center gap-3 px-3 py-2.5 no-underline"><i class="far fa-user w-4 text-center text-[#F27A2A]"></i> Meu Perfil</Link>
                    <Link href="/pedidos" class="flex items-center gap-3 px-3 py-2.5 no-underline"><i class="fas fa-bag-shopping w-4 text-center text-[#F27A2A]"></i> Meus Pedidos</Link>
                    <Link href="/rastreio" class="flex items-center gap-3 px-3 py-2.5 no-underline"><i class="fas fa-truck-fast w-4 text-center text-[#F27A2A]"></i> Rastrear Pedido</Link>
                    <!-- Configurações ainda é "Em breve": fora do menu até existir (2026-10-17).
                    <Link href="/configuracoes" class="flex items-center gap-3 px-3 py-2.5 no-underline"><i class="fas fa-gear w-4 text-center text-[#F27A2A]"></i> Configurações</Link>
                    -->
                    <a v-if="user.role === 'admin'" href="https://painel.kazakora.com/admin" class="flex items-center gap-3 px-3 py-2.5 no-underline"><i class="fas fa-screwdriver-wrench w-4 text-center text-[#F27A2A]"></i> Painel Admin</a>
                    <button type="button" class="flex w-full items-center gap-3 border-t border-store-border px-3 py-2.5 text-left text-red-600" @click="logout"><i class="fas fa-arrow-right-from-bracket w-4 text-center"></i> Sair</button>
                </nav>

                <!-- Abaixo da linha, os atalhos no mesmo padrão dos ícones do alto (pedido 2026-10-15); ícones coloridos e chamativos, uma cor por botão. -->
                <nav class="menu-mobile-atalhos mt-4 grid grid-cols-3 gap-2 border-t border-store-border pt-4">
                    <a href="/#categorias" class="menu-mobile-icone">
                        <i class="fas fa-table-cells-large text-xl text-[#8b5cf6]"></i>
                        <span>Departamentos</span>
                    </a>
                    <!-- Pedido 2026-10-24: Ofertas do Dia na linha de cima, logo depois de Departamentos e antes de Produtos; leva à seção da home. -->
                    <a href="/#ofertas" class="menu-mobile-icone">
                        <i class="fa-solid fa-stopwatch text-xl text-[#E02424]"></i>
                        <span>Ofertas do Dia</span>
                    </a>
                    <a href="/?todos=1#produtos" class="menu-mobile-icone">
                        <i class="fas fa-box-open text-xl text-[#f27a2a]"></i>
                        <span>Produtos</span>
                    </a>
                    <Link href="/fale-conosco" class="menu-mobile-icone">
                        <i class="fas fa-envelope text-xl text-[#3b82f6]"></i>
                        <span>Mandar mensagem</span>
                    </Link>
                    <a v-if="utilidadesLink" :href="`/?categoria=${utilidadesLink}#produtos`" class="menu-mobile-icone">
                        <i class="fas fa-house text-xl text-[#10b981]"></i>
                        <span>Utilidades para Casa</span>
                    </a>
                </nav>

                <!-- Fim do menu: só o símbolo do WhatsApp em verde, na coluna do Rastrear pedido (pedido 2026-10-23). -->
                <div class="mt-3 grid grid-cols-3 gap-2">
                    <a :href="COMPANY.whatsappLink" target="_blank" rel="noopener" aria-label="Falar no WhatsApp"
                        class="col-start-2 flex h-12 items-center justify-center rounded-xl no-underline">
                        <i class="fa-brands fa-whatsapp text-[2rem] text-[#25D366]"></i>
                    </a>
                </div>
            </div>
        </header>

        <!-- Page content -->
        <main>
            <slot />
        </main>

        <!-- Footer -->
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
                        <div>
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
                            <!-- Selo oficial RA Verificada (2026-10-14, verificação aprovada). Na mesma
                                 escala do Google: caixa branca da mesma altura e o selo com a altura
                                 do logo do Google dentro dela (pedido 2026-10-14). -->
                            <div class="flex h-[30px] items-center justify-center overflow-hidden rounded-[8px] bg-white px-2.5 sm:h-[44px] sm:px-3 lg:h-[51px]">
                                <SeloReclameAqui class="[--ra-altura:22] sm:[--ra-altura:34] lg:[--ra-altura:40]" />
                            </div>
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

        <!-- Modal inicial de desconto: visual conversion-first, sem criar cupom falso. -->
        <Teleport to="body">
            <div v-if="discountModalOpen" class="fixed inset-0 z-[115] flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="closeDiscountModal"></div>
                <div class="relative w-full max-w-[440px] overflow-hidden rounded-[2rem] border border-white/10 bg-store-bg-raised p-6 text-store-fg shadow-2xl">
                    <button type="button" class="absolute right-4 top-4 flex h-9 w-9 items-center justify-center rounded-full bg-store-bg-sunken text-store-fg-muted hover:text-store-fg" aria-label="Fechar" @click="closeDiscountModal">
                        <i class="fas fa-xmark"></i>
                    </button>
                    <div class="absolute inset-x-0 top-0 h-1.5 bg-store-accent"></div>
                    <span class="inline-flex rounded-full bg-store-accent-soft px-3 py-1 font-store-mono text-xs font-semibold uppercase tracking-[0.18em] text-store-accent-strong">Oferta KazaKora</span>
                    <h3 class="mt-4 font-display text-3xl font-semibold leading-tight">Desconto já aplicado nos produtos selecionados</h3>
                    <p class="mt-3 text-sm leading-relaxed text-store-fg-muted">Abra o catálogo, escolha seu produto e finalize com frete e pagamento em poucos passos.</p>
                    <div class="mt-5 grid gap-2 sm:grid-cols-2">
                        <Link href="/?todos=1#produtos" class="rounded-full bg-store-accent px-5 py-3 text-center text-sm font-bold text-store-accent-contrast no-underline" @click="closeDiscountModal">Ver ofertas</Link>
                        <button type="button" class="rounded-full border border-store-border-strong px-5 py-3 text-sm font-bold text-store-fg hover:bg-store-bg-sunken" @click="closeDiscountModal">Continuar navegando</button>
                    </div>
                </div>
            </div>
        </Teleport>

    </div>
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
