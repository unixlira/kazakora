<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { notifyError, notifySuccess, notifyWarning } from '@/Shared/notify';
import { useClickOutside } from '@/Shared/useClickOutside';
import { COMPANY } from '@/Shared/company';
import LowStockAlertModal from '@/Shared/Components/LowStockAlertModal.vue';

const page = usePage();
const cartCount = computed(() => page.props.cart?.count ?? 0);
const favoritesCount = computed(() => page.props.favorites?.count ?? 0);
const user = computed(() => page.props.auth?.user);
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

const search = ref('');
const submitSearch = () => {
    router.get('/', { search: search.value || undefined }, { preserveState: true });
};

const logout = () => router.post('/sair');

const userMenuOpen = ref(false);
const userMenuRef = ref(null);
useClickOutside(userMenuRef, () => (userMenuOpen.value = false));

const notifMenuOpen = ref(false);
const notifMenuRef = ref(null);
useClickOutside(notifMenuRef, () => (notifMenuOpen.value = false));

const markNotificationRead = (item) => {
    if (!item.read) {
        router.post(`/notificacoes/${item.id}/lida`, {}, { preserveScroll: true, preserveState: true });
    }

    // Notificação promocional pode ter um link (cupom, categoria em
    // promoção) — navega pra lá depois de marcar como lida.
    if (item.link) {
        router.visit(item.link);
    }
};

const markAllNotificationsRead = () => {
    router.post('/notificacoes/ler-todas', {}, { preserveScroll: true, preserveState: true });
};

const mobileMenuOpen = ref(false);

const PAYMENT_BRANDS = ['pix', 'visa', 'mastercard', 'elo', 'amex', 'diners'];
</script>

<template>
    <div class="storefront-shell min-h-screen bg-store-bg font-store text-store-fg">
        <!-- Marquee -->
        <div class="overflow-hidden whitespace-nowrap bg-store-accent-strong text-store-accent-contrast">
            <div class="inline-flex animate-[scroll-left_28s_linear_infinite] items-center py-2 motion-reduce:animate-none">
                <span v-for="n in 2" :key="n" class="contents">
                    <span class="font-store-mono px-5 text-[0.68rem] uppercase tracking-wider opacity-90 after:ml-5 after:content-['·']">Frete grátis para compras acima de R$ 299</span>
                    <span class="font-store-mono px-5 text-[0.68rem] uppercase tracking-wider opacity-90 after:ml-5 after:content-['·']">Pague no PIX e economize</span>
                    <span class="font-store-mono px-5 text-[0.68rem] uppercase tracking-wider opacity-90 after:ml-5 after:content-['·']">Suporte via WhatsApp</span>
                </span>
            </div>
        </div>

        <!-- Header -->
        <header class="sticky top-0 z-40 border-b border-store-border bg-store-bg/90 backdrop-blur">
            <div class="mx-auto flex max-w-[1320px] items-center gap-8 px-4 py-4 md:px-6">
                <Link href="/" class="whitespace-nowrap font-display text-2xl font-semibold text-store-fg no-underline">
                    Kaza<span class="text-store-accent">Kora</span>
                </Link>

                <nav class="ml-auto hidden items-center gap-7 lg:flex">
                    <a href="/#categorias" class="text-sm font-medium text-store-fg-muted hover:text-store-fg">Categorias</a>
                    <a href="/#produtos" class="text-sm font-medium text-store-fg-muted hover:text-store-fg">Produtos</a>
                    <a :href="COMPANY.whatsappLink" target="_blank" class="text-sm font-medium text-store-fg-muted hover:text-store-fg">Fale conosco</a>
                </nav>

                <form class="relative hidden max-w-xs flex-1 lg:block" @submit.prevent="submitSearch">
                    <input v-model="search" type="text" placeholder="O que você procura?"
                        class="w-full rounded-full border border-store-border-strong bg-store-bg-raised py-2 pl-4 pr-10 text-sm text-store-fg placeholder:text-store-fg-faint focus:border-store-accent focus:outline-none">
                    <button type="submit" class="absolute right-1 top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-full text-store-fg-muted hover:text-store-accent" aria-label="Buscar">
                        <i class="fas fa-magnifying-glass text-xs"></i>
                    </button>
                </form>

                <div class="ml-auto flex items-center gap-1 lg:ml-0">
                    <Link href="/favoritos" class="relative flex h-10 w-10 items-center justify-center rounded-full text-store-fg hover:bg-store-bg-sunken" aria-label="Favoritos">
                        <i class="far fa-heart text-base"></i>
                        <span v-if="favoritesCount > 0" class="absolute right-0.5 top-0.5 rounded-full bg-store-accent px-1 text-[0.6rem] font-store-mono leading-tight text-store-accent-contrast">{{ favoritesCount }}</span>
                    </Link>
                    <Link href="/carrinho" class="relative flex h-10 w-10 items-center justify-center rounded-full text-store-fg hover:bg-store-bg-sunken" aria-label="Carrinho">
                        <i class="fas fa-bag-shopping text-base"></i>
                        <span v-if="cartCount > 0" class="absolute right-0.5 top-0.5 rounded-full bg-store-accent px-1 text-[0.6rem] font-store-mono leading-tight text-store-accent-contrast">{{ cartCount }}</span>
                    </Link>

                    <div v-if="user" ref="notifMenuRef" class="relative">
                        <button type="button" class="relative flex h-10 w-10 items-center justify-center rounded-full text-store-fg hover:bg-store-bg-sunken" aria-label="Notificações" @click="notifMenuOpen = !notifMenuOpen">
                            <i class="far fa-bell text-base"></i>
                            <span v-if="unreadNotifications > 0" class="absolute right-0.5 top-0.5 rounded-full bg-store-accent px-1 text-[0.6rem] font-store-mono leading-tight text-store-accent-contrast">{{ unreadNotifications }}</span>
                        </button>

                        <div v-if="notifMenuOpen" class="absolute right-0 z-50 mt-2 w-80 rounded-xl border border-store-border bg-store-bg-raised py-2 text-left shadow-lg">
                            <div class="flex items-center justify-between px-4 py-2">
                                <h5 class="font-store-mono text-xs uppercase tracking-wider text-store-fg-faint">🔔 Notificações</h5>
                                <button v-if="unreadNotifications > 0" type="button" class="text-xs font-medium text-store-accent hover:underline" @click="markAllNotificationsRead">
                                    Marcar todas como lidas
                                </button>
                            </div>

                            <p v-if="notifications.length === 0" class="px-4 py-6 text-center text-sm text-store-fg-muted">
                                Nenhuma notificação por aqui ainda.
                            </p>

                            <button v-for="item in notifications" :key="item.id" type="button"
                                class="flex w-full items-start gap-2 px-4 py-2.5 text-left text-sm hover:bg-store-bg-sunken"
                                :class="{ 'bg-store-accent-soft/40': !item.read }"
                                @click="markNotificationRead(item)">
                                <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full" :class="item.read ? 'bg-transparent' : 'bg-store-accent'"></span>
                                <span>
                                    <span class="block font-medium text-store-fg">{{ item.message }}</span>
                                    <span v-if="item.body" class="block text-xs text-store-fg-muted">{{ item.body }}</span>
                                    <span class="font-store-mono text-xs text-store-fg-faint">{{ item.createdAt }}</span>
                                </span>
                            </button>
                        </div>
                    </div>

                    <div ref="userMenuRef" class="relative">
                        <button v-if="user" type="button" class="flex h-10 w-10 items-center justify-center rounded-full hover:bg-store-bg-sunken" @click="userMenuOpen = !userMenuOpen">
                            <img v-if="user.avatar_url" :src="user.avatar_url" class="h-8 w-8 rounded-full object-cover" alt="">
                            <span v-else class="flex h-8 w-8 items-center justify-center rounded-full bg-store-accent-soft text-xs font-semibold text-store-accent-strong">{{ user.initials }}</span>
                        </button>
                        <Link v-else href="/entrar" class="flex h-10 w-10 items-center justify-center rounded-full text-store-fg hover:bg-store-bg-sunken" aria-label="Entrar">
                            <i class="far fa-user text-base"></i>
                        </Link>

                        <div v-if="userMenuOpen" class="absolute right-0 z-50 mt-2 min-w-48 rounded-xl border border-store-border bg-store-bg-raised py-2 text-left shadow-lg">
                            <Link href="/perfil" class="block whitespace-nowrap px-4 py-2 text-sm hover:bg-store-bg-sunken">👤 Meu perfil</Link>
                            <Link href="/pedidos" class="block whitespace-nowrap px-4 py-2 text-sm hover:bg-store-bg-sunken">🛍️ Compras</Link>
                            <Link href="/configuracoes" class="block whitespace-nowrap px-4 py-2 text-sm hover:bg-store-bg-sunken">⚙️ Configurações</Link>
                            <Link v-if="user?.role === 'admin'" href="/admin" class="block whitespace-nowrap px-4 py-2 text-sm hover:bg-store-bg-sunken">🛠️ Painel admin</Link>
                            <hr class="my-1 border-store-border">
                            <button type="button" class="block w-full whitespace-nowrap px-4 py-2 text-left text-sm hover:bg-store-bg-sunken" @click="logout">🚪 Sair</button>
                        </div>
                    </div>

                    <button type="button" class="flex h-10 w-10 items-center justify-center rounded-full text-store-fg hover:bg-store-bg-sunken lg:hidden" aria-label="Menu" @click="mobileMenuOpen = !mobileMenuOpen">
                        <i class="fas fa-bars text-base"></i>
                    </button>
                </div>
            </div>

            <div v-if="mobileMenuOpen" class="border-t border-store-border px-4 py-4 lg:hidden">
                <form class="relative mb-4" @submit.prevent="submitSearch">
                    <input v-model="search" type="text" placeholder="O que você procura?"
                        class="w-full rounded-full border border-store-border-strong bg-store-bg-raised py-2 pl-4 pr-10 text-sm">
                </form>
                <nav class="flex flex-col gap-3">
                    <a href="/#categorias" class="text-sm font-medium">Categorias</a>
                    <a href="/#produtos" class="text-sm font-medium">Produtos</a>
                    <a :href="COMPANY.whatsappLink" target="_blank" class="text-sm font-medium">Fale conosco</a>
                </nav>
            </div>
        </header>

        <!-- Page content -->
        <main>
            <slot />
        </main>

        <!-- Footer -->
        <footer class="mt-20 bg-store-accent-strong text-store-accent-contrast">
            <div class="mx-auto max-w-[1320px] px-4 py-14 md:px-6">
                <div class="flex flex-col items-center gap-10 text-center lg:flex-row lg:items-start lg:justify-between lg:text-left">
                    <div class="lg:max-w-xs lg:shrink-0">
                        <span class="font-display text-xl font-semibold">Kaza<span class="text-store-accent">Kora</span></span>
                        <p class="mt-3 text-sm opacity-80 lg:max-w-[28ch]">
                            Curadoria de eletrônicos, gadgets e utensílios de cozinha, com entrega para todo o Brasil.
                        </p>
                    </div>

                    <div class="grid w-full grid-cols-2 gap-x-8 gap-y-10 sm:w-auto lg:grid-cols-4 lg:gap-x-12">
                        <div>
                            <h5 class="font-store-mono mb-4 text-xs uppercase tracking-wider opacity-60">Comprar</h5>
                            <ul class="flex flex-col gap-2 text-sm opacity-80">
                                <li><a href="/#categorias" class="hover:opacity-100">Categorias</a></li>
                                <li><a href="/#produtos" class="hover:opacity-100">Produtos</a></li>
                                <li><Link href="/carrinho" class="hover:opacity-100">Meu carrinho</Link></li>
                            </ul>
                        </div>
                        <div>
                            <h5 class="font-store-mono mb-4 text-xs uppercase tracking-wider opacity-60">Atendimento</h5>
                            <ul class="flex flex-col gap-2 text-sm opacity-80">
                                <li><a :href="`mailto:${COMPANY.email}`" class="hover:opacity-100">{{ COMPANY.email }}</a></li>
                                <li><a :href="COMPANY.whatsappLink" target="_blank" class="hover:opacity-100">{{ COMPANY.whatsappDisplay }}</a></li>
                                <li>São Paulo - SP</li>
                            </ul>
                        </div>
                        <div>
                            <h5 class="font-store-mono mb-4 text-xs uppercase tracking-wider opacity-60">Institucional</h5>
                            <ul class="flex flex-col gap-2 text-sm opacity-80">
                                <li><Link href="/politica-de-privacidade" class="hover:opacity-100">Política de Privacidade</Link></li>
                                <li><Link href="/termos-de-uso" class="hover:opacity-100">Termos de Uso</Link></li>
                                <li><Link href="/trocas-e-devolucoes" class="hover:opacity-100">Trocas e Devoluções</Link></li>
                            </ul>
                        </div>
                        <div>
                            <h5 class="font-store-mono mb-4 text-xs uppercase tracking-wider opacity-60">Minha conta</h5>
                            <ul class="flex flex-col gap-2 text-sm opacity-80">
                                <template v-if="!user">
                                    <li><Link href="/entrar" class="hover:opacity-100">Entrar</Link></li>
                                    <li><Link href="/cadastro" class="hover:opacity-100">Cadastrar</Link></li>
                                </template>
                                <template v-else>
                                    <li><Link href="/perfil" class="hover:opacity-100">Meu perfil</Link></li>
                                    <li><Link href="/pedidos" class="hover:opacity-100">Compras</Link></li>
                                </template>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="mt-10 flex flex-col items-center gap-6 text-center lg:mt-12 lg:grid lg:grid-cols-2 lg:items-center lg:gap-8 lg:text-left">
                    <div>
                        <h5 class="font-store-mono mb-3 text-xs uppercase tracking-wider opacity-60">Pagamentos</h5>
                        <div class="no-scrollbar flex flex-nowrap items-center justify-center gap-2 overflow-x-auto lg:flex-wrap lg:justify-start lg:overflow-visible">
                            <img v-for="brand in PAYMENT_BRANDS" :key="brand" :src="`/images/payments/${brand}@2x.png`" :alt="brand"
                                class="h-10 w-auto shrink-0 rounded-md bg-white p-1.5 lg:h-9">
                        </div>
                    </div>

                    <div class="flex justify-center">
                        <img src="/images/payments/google.png" alt="Google Safe Browsing — site verificado"
                            class="h-24 w-auto rounded-md bg-white p-2 lg:h-32">
                    </div>
                </div>

                <div class="mt-12 border-t border-store-accent-contrast/15 pt-6 text-center text-xs opacity-70">
                    <span>© 2026 KazaKora · CNPJ {{ COMPANY.cnpj }} · {{ COMPANY.enderecoResumido }}</span>
                </div>
            </div>
        </footer>

        <!-- WhatsApp float -->
        <a :href="COMPANY.whatsappLink" target="_blank" rel="noopener" aria-label="WhatsApp"
            class="fixed bottom-6 right-6 z-40 flex h-14 w-14 items-center justify-center rounded-full bg-[#25D366] text-2xl text-white shadow-lg">
            <i class="fab fa-whatsapp"></i>
        </a>

        <!-- Login sem "intended" (achado real 2026-08-16) cai aqui, não no
        admin — o modal de estoque baixo precisa existir nos dois layouts
        pra disparar de verdade em todo login, ver LowStockAlertModal.vue. -->
        <LowStockAlertModal />
    </div>
</template>
