<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { notifyError, notifySuccess, notifyWarning } from '@/Shared/notify';
import { atualizarFavoritos, totalFavoritos } from '@/Shared/favoritos';
import { useClickOutside } from '@/Shared/useClickOutside';
import { COMPANY } from '@/Shared/company';
import TrocarSenhaModal from '@/Shared/Components/TrocarSenhaModal.vue';
import ModalAvaliacao from '@/Shared/Components/ModalAvaliacao.vue';
import BuscaInstantanea from '@/Shared/Components/BuscaInstantanea.vue';
import RodapeLoja from '@/Shared/Components/RodapeLoja.vue';

const page = usePage();
// semFaixaTopo: sem a faixa promocional e o letreiro do topo (página de brinquedo, 24/11/2026).
// logoTopo: { href, alt, png, webp, largura, altura } troca o logo do cabeçalho (KoraKids nos brinquedos).
// listraTopo: faixa de quadradinhos colada na base do cabeçalho (vai junto quando ele fica fixo).
defineProps({ semFaixaTopo: { type: Boolean, default: false }, logoTopo: { type: Object, default: null }, listraTopo: { type: Boolean, default: false } });
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

// "Entrar em Contato" do menu do celular (pedido 2026-10-24, 2ª versão): em vez
// de rolar até o rodapé, fecha o menu e abre um modal com "Por e-mail" (página
// Fale conosco) e "Mais rápido" (WhatsApp com a mensagem já escrita).
const contatoModalAberto = ref(false);
const WHATSAPP_CONTATO = `${COMPANY.whatsappLink}?text=${encodeURIComponent('Olá, vim pelo site e tenho dúvidas.')}`;
const abrirContato = () => {
    mobileMenuOpen.value = false;
    contatoModalAberto.value = true;
};
const fecharContato = () => { contatoModalAberto.value = false; };
const escFechaContato = (e) => { if (e.key === 'Escape') fecharContato(); };
onMounted(() => window.addEventListener('keydown', escFechaContato));
onUnmounted(() => window.removeEventListener('keydown', escFechaContato));

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
// Frases da faixa do topo (FaixaDoTopo.php); sem a prop, as duas de sempre.
const frasesDaFaixa = computed(() => {
    const frases = page.props.faixa;
    return Array.isArray(frases) && frases.length ? frases : [
        { texto: 'Frete grátis', icone: 'fa-solid fa-truck-fast' },
        { texto: 'Compra 100% segura', icone: 'fa-solid fa-lock' },
    ];
});
// Mesma velocidade de antes com mais frases: 30s por frase (as duas de sempre davam 60s); a da BF conta como duas.
const duracaoDaFaixa = computed(() => `${Math.max(60, (frasesDaFaixa.value.length + (page.props.blackfriday ? 2 : 0)) * 30)}s`);

onMounted(() => {
    medirRolagem();
    window.addEventListener('scroll', medirRolagem, { passive: true });
    window.addEventListener('resize', medirRolagem, { passive: true });
});
onUnmounted(() => {
    window.removeEventListener('scroll', medirRolagem);
    window.removeEventListener('resize', medirRolagem);
});

</script>

<template>
    <div class="storefront-shell min-h-screen bg-store-bg font-store text-store-fg">
        <!-- A faixa Black November com "X" (FaixaPromo.vue) saiu em 2026-11-23: o comportamento
             mostrou que as pessoas só fechavam a faixa. O componente fica no projeto para voltar se precisar. -->
        <TrocarSenhaModal />
        <ModalAvaliacao />
        <!-- Página de brinquedo (pedido do Lira 24/11/2026): sem o letreiro do topo; o cabeçalho e o
             rodapé continuam os da loja. -->
        <template v-if="!semFaixaTopo">
        <!-- Marquee. -->
        <div class="relative overflow-hidden whitespace-nowrap bg-store-accent-strong text-store-accent-contrast">
            <!-- Faixa contínua (pedido 2026-10-10): 6 cópias e a animação anda metade
                 (3 cópias, mais largo que qualquer tela) — nunca sobra espaço em branco.
                 Roda também com "reduzir movimento" ligado (pedido 2026-10-15): no celular
                 essa opção liga sozinha com a economia de bateria e a faixa ficava parada. -->
            <div class="inline-flex animate-[scroll-left_60s_linear_infinite] items-center py-2" :style="{ animationDuration: duracaoDaFaixa }">
                <span v-for="n in 6" :key="n" class="contents">
                    <!-- Black Friday automática (pedido do Lira 2026-11-24): a frase da BF vem primeiro e as
                         outras continuam depois dela; passou a data (loja.blackfriday_ate) o servidor manda null. -->
                    <span v-if="$page.props.blackfriday" class="font-store-mono px-5 text-[0.68rem] font-bold uppercase tracking-wider after:ml-5 after:content-['|']" data-faixa-blackfriday><i class="fa-solid fa-tag mr-1 text-white" aria-hidden="true"></i>Black Friday: {{ $page.props.blackfriday.rotulo }} em todo o site, já aplicado no carrinho</span>
                    <!-- Todas em negrito e ícones brancos como a tag da BF (pedido do Lira 2026-11-24). -->
                    <!-- Frases da faixa (FaixaDoTopo.php, pedido do Lira 2026-11-24): na página de produto, os
                         benefícios daquele produto; no resto da loja, as gerais. Sem raio (fa-bolt). -->
                    <span v-for="(frase, i) in frasesDaFaixa" :key="i" class="font-store-mono px-5 text-[0.68rem] font-bold uppercase tracking-wider after:ml-5 after:content-['|']" data-faixa-frase><i v-if="frase.icone" :class="[frase.icone, 'mr-1 text-white']" aria-hidden="true"></i>{{ frase.texto }}</span>
                </span>
            </div>
        </div>

        </template>

        <!-- Header -->
        <div ref="marcaDoTopo" aria-hidden="true"></div>
        <header class="loja-topo sticky top-0 z-40 border-b border-store-border bg-store-bg-raised"
            :class="{ 'loja-topo-fume': topoFlutuando, 'loja-topo-listra': listraTopo }">
            <!-- Topo mais baixo no celular (pedido 2026-10-15): py-2 e ícones de 36px abaixo do lg. -->
            <!-- max-lg:relative: no celular o logo fica centralizado em cima da linha (pedido do Lira 2026-10-23); no computador não, para o mega menu seguir com a largura do topo. -->
            <div class="mx-auto flex max-w-[1320px] items-center gap-2 px-3 py-2 max-lg:relative md:px-6 lg:gap-8 lg:py-4">
                <!-- Celular: menu (três listras) à esquerda do logo (pedido do Lira 2026-10-23). -->
                <button type="button" class="-ml-1.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-store-fg hover:bg-store-bg-sunken lg:hidden" aria-label="Menu" :aria-expanded="mobileMenuOpen" aria-controls="menu-mobile" @click="mobileMenuOpen = !mobileMenuOpen">
                    <i class="fas text-base" :class="mobileMenuOpen ? 'fa-xmark' : 'fa-bars'"></i>
                </button>

                <!-- Logo (Admin > Banners > Logos e favicon); no modo escuro usa a clara do rodapé. Centralizado no celular (2026-10-23). -->
                <!-- Logo próprio da página (página de brinquedo: KoraKids, 24/11/2026). -->
                <Link v-if="logoTopo" :href="logoTopo.href" class="shrink-0 no-underline max-lg:absolute max-lg:left-1/2 max-lg:-translate-x-1/2" :aria-label="`${logoTopo.alt} — início`">
                    <picture>
                        <source :srcset="logoTopo.webp" type="image/webp">
                        <img :src="logoTopo.png" :alt="logoTopo.alt" :class="logoTopo.classe || 'h-9 w-auto md:h-11'" :width="logoTopo.largura" :height="logoTopo.altura" fetchpriority="high">
                    </picture>
                </Link>
                <Link v-else href="/" class="shrink-0 no-underline max-lg:absolute max-lg:left-1/2 max-lg:-translate-x-1/2" aria-label="KazaKora — início">
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
                                            <p class="text-xs font-bold uppercase tracking-widest text-[#f6c343]"><i class="fa-solid fa-bolt mr-1"></i>Entrega Rápida</p>
                                            <p class="mt-2 text-xl font-bold leading-tight">Frete grátis em todos os produtos</p>
                                            <p class="mt-2 text-sm opacity-80">Em boa parte da Grande São Paulo, chega no mesmo dia.</p>
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
                <!-- Duas linhas de 3 (pedido 2026-10-23, sem Favoritos e Carrinho): aqui Buscar, Rastrear pedido e Conta; abaixo da linha, Departamentos, Produtos e Entrar em Contato (abre o modal de contato). -->
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

                <!-- Abaixo da linha, os atalhos no mesmo padrão dos ícones do alto (pedido 2026-10-15); ícones coloridos e chamativos, uma cor por botão.
                     Pedido 2026-10-24: só Departamentos, Produtos e Entrar em Contato; saíram Ofertas do Dia, Mandar mensagem, Utilidades para Casa e o WhatsApp. -->
                <nav class="menu-mobile-atalhos mt-4 grid grid-cols-3 gap-2 border-t border-store-border pt-4">
                    <a href="/#categorias" class="menu-mobile-icone">
                        <i class="fas fa-table-cells-large text-xl text-[#8b5cf6]"></i>
                        <span>Departamentos</span>
                    </a>
                    <a href="/?todos=1#produtos" class="menu-mobile-icone">
                        <i class="fas fa-box-open text-xl text-[#f27a2a]"></i>
                        <span>Produtos</span>
                    </a>
                    <button type="button" class="menu-mobile-icone" aria-haspopup="dialog" @click="abrirContato">
                        <i class="fas fa-headset text-xl text-[#3b82f6]"></i>
                        <span>Entrar em Contato</span>
                    </button>
                </nav>
            </div>
        </header>

        <!-- Page content -->
        <main>
            <slot />
        </main>

        <!-- Rodapé da loja: componente compartilhado com o blog (pedido do Lira 2026-11-22). -->
        <RodapeLoja />

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

        <!-- Entrar em Contato do menu do celular (pedido 2026-10-24): duas saídas, e-mail ou WhatsApp. -->
        <Teleport to="body">
            <div v-if="contatoModalAberto" class="fixed inset-0 z-[115] flex items-end justify-center p-4 sm:items-center">
                <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="fecharContato"></div>
                <div role="dialog" aria-modal="true" aria-labelledby="contato-modal-titulo" class="relative w-full max-w-[400px] rounded-[1.5rem] border border-store-border bg-store-bg-raised p-5 text-store-fg shadow-2xl">
                    <button type="button" class="absolute right-3 top-3 flex h-9 w-9 items-center justify-center rounded-full bg-store-bg-sunken text-store-fg-muted hover:text-store-fg" aria-label="Fechar" @click="fecharContato">
                        <i class="fas fa-xmark"></i>
                    </button>
                    <h3 id="contato-modal-titulo" class="pr-10 text-lg font-semibold">Como prefere falar com a gente?</h3>
                    <div class="mt-4 flex flex-col gap-2">
                        <Link href="/fale-conosco" class="flex items-center gap-3 rounded-xl border border-store-border p-3 no-underline hover:bg-store-bg-sunken" @click="fecharContato">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#3b82f6] text-xl text-white"><i class="fas fa-envelope"></i></span>
                            <span>
                                <span class="block text-sm font-semibold text-store-fg">Por e-mail</span>
                                <span class="block text-xs text-store-fg-muted">Formulário da página de contato</span>
                            </span>
                        </Link>
                        <a :href="WHATSAPP_CONTATO" target="_blank" rel="noopener" class="flex items-center gap-3 rounded-xl border border-store-border p-3 no-underline hover:bg-store-bg-sunken" @click="fecharContato">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#25D366] text-2xl text-white"><i class="fa-brands fa-whatsapp"></i></span>
                            <span>
                                <span class="block text-sm font-semibold text-store-fg">Mais rápido</span>
                                <span class="block text-xs text-store-fg-muted">WhatsApp {{ COMPANY.whatsappDisplay }}</span>
                            </span>
                        </a>
                    </div>
                </div>
            </div>
        </Teleport>

    </div>
</template>
