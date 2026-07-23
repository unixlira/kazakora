<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { notifyError, notifySuccess, notifyWarning } from '@/Shared/notify';
import { useClickOutside } from '@/Shared/useClickOutside';

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
};

const markAllNotificationsRead = () => {
    router.post('/notificacoes/ler-todas', {}, { preserveScroll: true, preserveState: true });
};

const mobileMenuOpen = ref(false);

const WHATSAPP_NUMBER = '5511965723990';
const WHATSAPP_DISPLAY = '(11) 96572-3990';
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
                    <a :href="`https://wa.me/${WHATSAPP_NUMBER}`" target="_blank" class="text-sm font-medium text-store-fg-muted hover:text-store-fg">Fale conosco</a>
                </nav>

                <form class="relative hidden max-w-xs flex-1 lg:block" @submit.prevent="submitSearch">
                    <input v-model="search" type="text" placeholder="O que você procura?"
                        class="w-full rounded-full border border-store-border-strong bg-store-bg-raised py-2 pl-4 pr-10 text-sm text-store-fg placeholder:text-store-fg-faint focus:border-store-accent focus:outline-none">
                    <button type="submit" class="absolute right-1 top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-full text-store-fg-muted hover:text-store-accent" aria-label="Buscar">
                        <i class="fas fa-magnifying-glass text-xs"></i>
                    </button>
                </form>

                <div class="ml-auto flex items-center gap-1 lg:ml-0">
                    <Link href="/perfil" class="relative flex h-10 w-10 items-center justify-center rounded-full text-store-fg hover:bg-store-bg-sunken" aria-label="Favoritos">
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
                                    <span class="block">{{ item.message }}</span>
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
                    <a :href="`https://wa.me/${WHATSAPP_NUMBER}`" target="_blank" class="text-sm font-medium">Fale conosco</a>
                </nav>
            </div>
        </header>

        <!-- Page content -->
        <main>
            <slot />
        </main>

        <!-- Footer -->
        <footer class="mt-20 border-t border-store-border">
            <div class="mx-auto max-w-[1320px] px-4 py-14 md:px-6">
                <div class="grid grid-cols-1 gap-10 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <span class="font-display text-xl font-semibold">Kaza<span class="text-store-accent">Kora</span></span>
                        <p class="mt-3 max-w-[28ch] text-sm text-store-fg-muted">
                            Curadoria de eletrônicos, gadgets e utensílios de cozinha, com entrega para todo o Brasil.
                        </p>
                    </div>
                    <div>
                        <h5 class="font-store-mono mb-4 text-xs uppercase tracking-wider text-store-fg-faint">Comprar</h5>
                        <ul class="flex flex-col gap-2 text-sm">
                            <li><a href="/#categorias" class="text-store-fg-muted hover:text-store-fg">Categorias</a></li>
                            <li><a href="/#produtos" class="text-store-fg-muted hover:text-store-fg">Produtos</a></li>
                            <li><Link href="/carrinho" class="text-store-fg-muted hover:text-store-fg">Meu carrinho</Link></li>
                        </ul>
                    </div>
                    <div>
                        <h5 class="font-store-mono mb-4 text-xs uppercase tracking-wider text-store-fg-faint">Atendimento</h5>
                        <ul class="flex flex-col gap-2 text-sm">
                            <li><a href="mailto:contato@kazakora.com" class="text-store-fg-muted hover:text-store-fg">contato@kazakora.com</a></li>
                            <li><a :href="`https://wa.me/${WHATSAPP_NUMBER}`" target="_blank" class="text-store-fg-muted hover:text-store-fg">{{ WHATSAPP_DISPLAY }}</a></li>
                            <li class="text-store-fg-muted">São Paulo - SP</li>
                        </ul>
                    </div>
                    <div>
                        <h5 class="font-store-mono mb-4 text-xs uppercase tracking-wider text-store-fg-faint">Minha conta</h5>
                        <ul class="flex flex-col gap-2 text-sm">
                            <template v-if="!user">
                                <li><Link href="/entrar" class="text-store-fg-muted hover:text-store-fg">Entrar</Link></li>
                                <li><Link href="/cadastro" class="text-store-fg-muted hover:text-store-fg">Cadastrar</Link></li>
                            </template>
                            <li v-else><Link href="/perfil" class="text-store-fg-muted hover:text-store-fg">Meu perfil</Link></li>
                        </ul>
                    </div>
                </div>

                <div class="mt-12 flex flex-col items-center justify-between gap-3 border-t border-store-border pt-6 text-xs text-store-fg-faint sm:flex-row">
                    <span>© 2026 KazaKora · CNPJ 65.604.590/0001-07</span>
                    <div class="font-store-mono flex gap-2">
                        <span class="rounded border border-store-border px-2 py-1">PIX</span>
                        <span class="rounded border border-store-border px-2 py-1">VISA</span>
                        <span class="rounded border border-store-border px-2 py-1">MASTER</span>
                    </div>
                </div>
            </div>
        </footer>

        <!-- WhatsApp float -->
        <a :href="`https://wa.me/${WHATSAPP_NUMBER}`" target="_blank" rel="noopener" aria-label="WhatsApp"
            class="fixed bottom-6 right-6 z-40 flex h-14 w-14 items-center justify-center rounded-full bg-[#25D366] text-2xl text-white shadow-lg">
            <i class="fab fa-whatsapp"></i>
        </a>
    </div>
</template>

<style>
@keyframes scroll-left {
    from { transform: translateX(0); }
    to { transform: translateX(-50%); }
}
</style>
