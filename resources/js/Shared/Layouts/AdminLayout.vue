<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { notifyError, notifySuccess, notifyWarning } from '@/Shared/notify';
import { useClickOutside } from '@/Shared/useClickOutside';
import { useDarkMode } from '@/Shared/useDarkMode';
import { usePermissions } from '@/Shared/usePermissions';
import { sidebarSections } from '@/Shared/adminSidebarItems';
import LowStockAlertModal from '@/Shared/Components/LowStockAlertModal.vue';
import WhatsAppToasts from '@/Shared/Components/WhatsAppToasts.vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const notifications = computed(() => page.props.notifications?.items ?? []);
const unreadNotifications = computed(() => page.props.notifications?.unreadCount ?? 0);
// Contador por item do menu (ex.: alertas abertos dos Envios Flex).
// Clicou no item: o alerta some. Guardamos o número visto no navegador; o
// badge só volta se a contagem passar desse número (alerta novo).
const BADGES_VISTOS_KEY = 'admin.sidebarBadgesVistos';
const badgesVistos = ref({});
try {
    badgesVistos.value = JSON.parse(localStorage.getItem(BADGES_VISTOS_KEY) ?? '{}') || {};
} catch {
    badgesVistos.value = {};
}
const contagemBruta = (item) => (item.badgeKey ? page.props.sidebarBadges?.[item.badgeKey] ?? 0 : 0);
const sidebarBadge = (item) => {
    const total = contagemBruta(item);
    const visto = badgesVistos.value[item.badgeKey] ?? 0;
    return total > visto ? total : 0;
};
const dispensarBadge = (item) => {
    if (!item.badgeKey) return;
    badgesVistos.value = { ...badgesVistos.value, [item.badgeKey]: contagemBruta(item) };
    try {
        localStorage.setItem(BADGES_VISTOS_KEY, JSON.stringify(badgesVistos.value));
    } catch { /* storage indisponível: vale só nesta sessão */ }
};
// Contagem caiu abaixo do visto (pendência resolvida): o visto acompanha, para
// que o próximo alerta novo volte a aparecer.
watch(() => page.props.sidebarBadges, (badges) => {
    if (!badges) return;
    const ajustado = { ...badgesVistos.value };
    let mudou = false;
    for (const [chave, visto] of Object.entries(ajustado)) {
        const total = badges?.[chave] ?? 0;
        if (total < visto) { ajustado[chave] = total; mudou = true; }
    }
    if (!mudou) return;
    badgesVistos.value = ajustado;
    try {
        localStorage.setItem(BADGES_VISTOS_KEY, JSON.stringify(ajustado));
    } catch { /* idem */ }
}, { immediate: true });

const { isDark, toggle: toggleDarkMode } = useDarkMode();
// Menu fechado mostra o favicon da marca (o customizado de produção, se houver).
// O favicon padrão é de 32px e fica borrado em 40px: nesse caso usa o de 180.
const marcaFavicon = computed(() => {
    const f = page.props.marca?.favicon;
    return f && !f.includes('favicon-32') && !f.endsWith('.ico') ? f : '/images/marca/favicon-180.png';
});
const marcaLogo = computed(() => isDark.value
    ? (page.props.marca?.logoRodape || '/images/marca/logo-rodape.png')
    : (page.props.marca?.logoNav || '/images/marca/logo-nav.png'));
const { can } = usePermissions();

const visibleSections = computed(() =>
    sidebarSections
        .map((section) => ({
            ...section,
            items: section.items.filter((item) => !item.permission || can(item.permission)),
        }))
        .filter((section) => section.items.length > 0),
);

const collapseShow = ref('hidden');
const userMenuOpen = ref(false);
const userMenuRef = ref(null);
const notifMenuOpen = ref(false);
const notifMenuRef = ref(null);
const sidebarCollapsed = ref(false);

useClickOutside(userMenuRef, () => (userMenuOpen.value = false));
useClickOutside(notifMenuRef, () => (notifMenuOpen.value = false));

// Pedido explícito 2026-08-16: clicar num aviso do dropdown marca como
// lida (some da sineta, ver notificationsFor() no backend — só mostra não
// lidas agora) E abre a tela com todas listadas, não só marca inline.
const openNotification = (item) => {
    notifMenuOpen.value = false;

    if (!item.read) {
        router.post(`/notificacoes/${item.id}/lida`, {}, { preserveScroll: true, preserveState: true });
    }

    router.visit('/notificacoes');
};

// Botão de excluir (pedido explícito 2026-08-16) — some de vez, não some
// só da sineta como o mark-read. @click.stop pro clique não borbulhar pro
// botão do item inteiro (que abriria a tela de notificações).
const removeNotification = (item) => {
    router.delete(`/notificacoes/${item.id}`, { preserveScroll: true, preserveState: true });
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

onMounted(() => {
    sidebarCollapsed.value = localStorage.getItem('admin_sidebar_collapsed') === '1';
});

const toggleSidebar = () => {
    sidebarCollapsed.value = !sidebarCollapsed.value;
    localStorage.setItem('admin_sidebar_collapsed', sidebarCollapsed.value ? '1' : '0');
};

// Só o item de href mais específico fica marcado: em /admin/whatsapp/conversas,
// "Conversas" acende e "Configurações" (/admin/whatsapp) não — antes os dois
// ficavam selecionados porque um href é prefixo do outro.
const urlPath = computed(() => page.url.split(/[?#]/)[0]);
const matchesPath = (href) => urlPath.value === href || urlPath.value.startsWith(`${href}/`);
// Item com submenu (ex.: Radar): os filhos entram na disputa do mais específico.
const comFilhos = (item) => [item, ...(item.children ?? [])];
const activeHref = computed(() => {
    if (urlPath.value === '/admin') return '/admin';
    return sidebarSections
        .flatMap((section) => section.items.flatMap(comFilhos).map((item) => item.href))
        .filter((href) => href !== '/admin' && matchesPath(href))
        .sort((a, b) => b.length - a.length)[0] ?? null;
});
const isActive = (href) => href === activeHref.value;

const activeItem = computed(() => {
    for (const section of visibleSections.value) {
        const match = section.items.flatMap(comFilhos).find((item) => isActive(item.href));
        if (match) return match;
    }
    return null;
});

// Menu em dropdown (pedido 2026-10-11): cada seção abre e fecha; a seção da
// tela atual já vem aberta e o item continua marcado. O que o usuário abriu
// fica lembrado neste navegador. Chave nova desde o menu de 9 grupos
// (2026-10-11): a antiga guardava os nomes das seções que não existem mais.
const SECOES_ABERTAS = 'admin_menu_grupos';
const lerAbertas = () => {
    try { return JSON.parse(localStorage.getItem(SECOES_ABERTAS) ?? '[]'); } catch { return []; }
};
const secoesAbertas = ref(new Set(lerAbertas()));
const secaoAtiva = computed(() => visibleSections.value.find((s) => s.items.flatMap(comFilhos).some((i) => isActive(i.href)))?.heading ?? null);
const secaoAberta = (heading) => heading === secaoAtiva.value || secoesAbertas.value.has(heading);
// Alterna pelo que está na tela (secaoExpandida), não por secaoAberta: a
// seção da tela atual é sempre "aberta", então depois de recolhida ela
// nunca mais expandia (2026-10-12, Atendimento em E-mails do site).
const alternarSecao = (heading) => {
    const abertas = new Set(secoesAbertas.value);
    if (secaoExpandida(heading)) {
        abertas.delete(heading);
        if (heading === secaoAtiva.value) abertas.add(`!${heading}`);
    } else {
        abertas.add(heading);
        abertas.delete(`!${heading}`);
    }
    secoesAbertas.value = abertas;
    try { localStorage.setItem(SECOES_ABERTAS, JSON.stringify([...abertas])); } catch { /* sem storage: só não lembra */ }
};
const secaoExpandida = (heading) => !secoesAbertas.value.has(`!${heading}`) && secaoAberta(heading);
// Submenu recolhível (Radar, pedido do Lira 2026-10-12): abre e fecha pela
// setinha. Sem escolha guardada, fica aberto só se um filho for a tela atual.
const SUBMENUS = 'admin_menu_submenus';
const lerSubmenus = () => {
    try { return JSON.parse(localStorage.getItem(SUBMENUS) ?? '{}') ?? {}; } catch { return {}; }
};
const submenusEscolhidos = ref(lerSubmenus());
const submenuAberto = (item) => submenusEscolhidos.value[item.href]
    ?? item.children.some((filho) => isActive(filho.href));
const alternarSubmenu = (item) => {
    submenusEscolhidos.value = { ...submenusEscolhidos.value, [item.href]: !submenuAberto(item) };
    try { localStorage.setItem(SUBMENUS, JSON.stringify(submenusEscolhidos.value)); } catch { /* sem storage: só não lembra */ }
};
const badgeDaSecao = (section) => section.items.reduce((total, item) => total + sidebarBadge(item), 0);

const logout = () => router.post('/sair');

// Loader na área de conteúdo (pedido do Lira 2026-10-12): tela lenta (ex.:
// Oportunidades do Mercado Livre) deixava a página anterior parada sem
// aviso nenhum. Em toda troca de tela do admin, se a resposta passar de
// 250 ms, cobre o conteúdo com o mesmo desfoque + spinner do "Marcar todas
// como lidas". Fica de fora o que já tem retorno próprio ou roda por trás:
// POST/PUT/DELETE (formulários têm botão "Salvando…"), recarga parcial e
// prop adiada (only/except), prefetch, requisição async (polling) e
// preserveState (filtros que atualizam a mesma tela enquanto se digita).
const navegando = ref(false);
let timerNavegacao = null;
const pararLoader = () => {
    clearTimeout(timerNavegacao);
    timerNavegacao = null;
    navegando.value = false;
};
const ehTrocaDeTela = (visit) => visit
    && String(visit.method).toLowerCase() === 'get'
    && !visit.async && !visit.prefetch && !visit.deferredProps
    && !(visit.only?.length) && !(visit.except?.length)
    && visit.preserveState !== true;
const removerOuvintes = [];
onMounted(() => {
    removerOuvintes.push(
        router.on('start', (event) => {
            if (!ehTrocaDeTela(event.detail.visit)) return;
            clearTimeout(timerNavegacao);
            timerNavegacao = setTimeout(() => { navegando.value = true; }, 250);
        }),
        router.on('finish', pararLoader),
    );
});
onBeforeUnmount(() => {
    removerOuvintes.forEach((remover) => remover());
    pararLoader();
});

// Pedido do Lira 2026-10-12: com a página rolada, o topo fixo fica
// translúcido e fumê (o conteúdo aparece borrado por baixo). No alto da
// página volta ao normal. Estilo em .admin-topo-fume (app.css).
const topoFlutuando = ref(false);
const medirRolagem = () => { topoFlutuando.value = window.scrollY > 8; };
onMounted(() => {
    medirRolagem();
    window.addEventListener('scroll', medirRolagem, { passive: true });
});
onBeforeUnmount(() => window.removeEventListener('scroll', medirRolagem));

// Pedido explícito 2026-08-15: botão de voltar visível em toda tela do
// admin, sem atrapalhar o layout de nenhuma página — colocado só uma vez
// aqui no layout compartilhado (não em cada tela) em vez de duplicado.
// history.back() de propósito, não um Link fixo pra "/admin" — Inertia usa
// pushState nos visits normais, então o histórico do navegador já reflete
// a navegação real dentro do admin; volta pra tela anterior de verdade,
// não sempre pro dashboard. Escondido na própria raiz (/admin) porque não
// há "tela anterior" que faça sentido voltar a partir dali.
const goBack = () => window.history.back();

watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.success) notifySuccess(flash.success);
        if (flash?.error) notifyError(flash.error);
        if (flash?.warning) notifyWarning(flash.warning);
    },
    { immediate: true, deep: true },
);
</script>

<template>
    <div class="admin-shell admin-fundo flex min-h-screen flex-col bg-[var(--fundo)] text-[var(--surface-foreground)]">
        <!-- Sidebar -->
        <!-- Celular (2026-10-11): o cabeçalho é uma faixa só (a do topo, com
        hambúrguer e logo). Este menu vira uma gaveta aberta por cima da
        tela, logo abaixo do topo, e some quando está fechado. -->
        <nav id="menu-admin" class="relative z-20 flex flex-wrap items-center justify-between border-r border-[var(--surface-border)] bg-[var(--surface)] px-6 py-4 shadow-sm transition-all md:fixed md:bottom-0 md:left-0 md:top-0 md:block md:flex-row md:flex-nowrap md:overflow-y-auto"
            :class="[sidebarCollapsed ? 'md:w-20 md:px-3' : 'md:w-64', collapseShow === 'hidden' ? 'max-md:hidden' : 'max-md:fixed max-md:inset-x-0 max-md:bottom-0 max-md:top-[69px] max-md:z-30 max-md:overflow-y-auto']">
            <div class="mx-auto flex w-full flex-wrap items-center justify-between px-0 md:min-h-full md:flex-col md:flex-nowrap md:items-stretch">
                <!-- Menu recolhido: logo em cima e botão embaixo (lado a lado não cabe em 80px). -->
                <div class="hidden w-full items-center justify-between md:flex" :class="sidebarCollapsed ? 'flex-col gap-2' : ''">
                    <Link href="/admin" class="flex items-center py-3" title="KazaKora">
                        <img v-if="sidebarCollapsed" :src="marcaFavicon" alt="KazaKora" class="h-10 w-10 max-w-none shrink-0 rounded-xl object-cover shadow-sm">
                        <img v-else :src="marcaLogo" alt="KazaKora" class="h-7 w-auto">
                    </Link>
                    <button type="button" class="text-[var(--icone-menu)] hover:text-[var(--destaque)]" :title="sidebarCollapsed ? 'Abrir menu' : 'Recolher menu'" :aria-label="sidebarCollapsed ? 'Abrir menu' : 'Recolher menu'" @click="toggleSidebar">
                        <i :class="sidebarCollapsed ? 'fas fa-angles-right' : 'fas fa-angles-left'"></i>
                    </button>
                </div>

                <!-- Bug real reportado 2026-08-09: esse painel não tinha
                largura própria — virava mais um item dentro do
                "flex flex-wrap justify-between" do cabeçalho (linha acima,
                hambúrguer + logo) em vez de cair pra uma linha nova abaixo
                dele. w-full/basis-full força quebra de linha garantida no
                mobile (flex-wrap só quebra linha quando o item não cabe; um
                item de 100% de largura nunca cabe ao lado de outro). -->
                <div class="h-auto w-full basis-full items-center overflow-x-hidden overflow-y-auto rounded md:relative md:mt-4 md:flex md:w-auto md:flex-1 md:flex-col md:items-stretch md:opacity-100 md:shadow-none"
                    :class="collapseShow">
                    <div v-for="section in visibleSections" :key="section.heading" class="mb-1">
                        <button v-if="!sidebarCollapsed" type="button"
                            class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-xs font-bold uppercase tracking-wide transition-colors"
                            :class="secaoExpandida(section.heading)
                                ? 'text-[var(--surface-foreground)]'
                                : 'text-[var(--icone-menu)] hover:bg-[var(--surface-border)]/30 hover:text-[var(--sidebar-foreground)]'"
                            :aria-expanded="secaoExpandida(section.heading)" @click="alternarSecao(section.heading)">
                            <span class="flex-1 truncate">{{ section.heading }}</span>
                            <span v-if="!secaoExpandida(section.heading) && badgeDaSecao(section) > 0"
                                class="min-w-[1.25rem] rounded-full bg-error px-1.5 text-center text-[10px] font-bold leading-5 text-white">{{ badgeDaSecao(section) }}</span>
                            <i class="fas fa-chevron-down text-[10px] transition-transform" :class="{ 'rotate-180': secaoExpandida(section.heading) }"></i>
                        </button>

                        <ul v-show="sidebarCollapsed || secaoExpandida(section.heading)" class="mt-1 flex list-none flex-col md:min-w-full md:flex-col">
                            <li v-for="item in section.items" :key="item.href">
                                <!-- Cor só no item ativo (2026-10-11): fundo lightprimary,
                                texto e ícone na cor de destaque (grafite no claro, laranja
                                no escuro) e barra de 3px à esquerda. Ícones numa cor só. -->
                                <div class="flex items-center">
                                    <Link :href="item.href" :title="item.label"
                                        class="flex min-w-0 flex-1 items-center gap-2 rounded-lg border-l-[3px] py-2.5 text-sm font-medium transition-colors"
                                        :class="isActive(item.href)
                                            ? 'border-[var(--destaque)] bg-lightprimary font-semibold text-[var(--destaque)]'
                                            : 'border-transparent text-[var(--sidebar-foreground)] hover:bg-[var(--surface-border)]/30'"
                                        :aria-current="isActive(item.href) ? 'page' : undefined" @click="dispensarBadge(item)">
                                        <i class="w-8 text-center text-sm" :class="[item.icon, isActive(item.href) ? 'text-[var(--destaque)]' : 'text-[var(--icone-menu)]']"></i>
                                        <span v-if="!sidebarCollapsed" class="truncate">{{ item.label }}</span>
                                        <span v-if="sidebarBadge(item) > 0"
                                            class="ml-auto mr-2 min-w-[1.25rem] rounded-full bg-error px-1.5 text-center text-xs font-bold leading-5 text-white"
                                            :title="`${sidebarBadge(item)} pendência(s)`">
                                            {{ sidebarBadge(item) }}
                                        </span>
                                    </Link>
                                    <button v-if="item.children && !sidebarCollapsed" type="button"
                                        class="ml-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-[var(--icone-menu)] hover:bg-[var(--surface-border)]/30"
                                        :title="submenuAberto(item) ? 'Recolher' : 'Expandir'" :aria-label="`${submenuAberto(item) ? 'Recolher' : 'Expandir'} ${item.label}`"
                                        :aria-expanded="submenuAberto(item)" @click="alternarSubmenu(item)">
                                        <i class="fas fa-chevron-down text-[10px] transition-transform" :class="{ 'rotate-180': submenuAberto(item) }"></i>
                                    </button>
                                </div>
                                <!-- Submenu (Radar, pedido do Lira 2026-10-12): recolhível pela setinha,
                                recuado embaixo do pai; com o menu recolhido fica só o ícone. -->
                                <ul v-if="item.children" v-show="sidebarCollapsed || submenuAberto(item)" class="flex list-none flex-col" :class="{ 'ml-4 border-l border-[var(--surface-border)] pl-1': !sidebarCollapsed }">
                                    <li v-for="filho in item.children" :key="filho.href">
                                        <Link :href="filho.href" :title="`${item.label} › ${filho.label}`"
                                            class="flex items-center gap-2 rounded-lg border-l-[3px] py-2 text-sm font-medium transition-colors"
                                            :class="isActive(filho.href)
                                                ? 'border-[var(--destaque)] bg-lightprimary font-semibold text-[var(--destaque)]'
                                                : 'border-transparent text-[var(--sidebar-foreground)] hover:bg-[var(--surface-border)]/30'"
                                            :aria-current="isActive(filho.href) ? 'page' : undefined">
                                            <i class="w-8 text-center text-xs" :class="[filho.icon, isActive(filho.href) ? 'text-[var(--destaque)]' : 'text-[var(--icone-menu)]']"></i>
                                            <span v-if="!sidebarCollapsed" class="truncate">{{ filho.label }}</span>
                                        </Link>
                                    </li>
                                </ul>
                            </li>
                        </ul>
                    </div>

                    <hr class="my-4 border-[var(--surface-border)] md:min-w-full">

                    <ul class="mb-4 flex list-none flex-col md:min-w-full md:flex-col">
                        <li v-if="user?.role === 'admin'">
                            <Link href="/admin/filas" class="flex items-center gap-2 rounded-lg border-l-[3px] py-2.5 text-sm font-medium transition-colors"
                                :class="urlPath === '/admin/filas' ? 'border-[var(--destaque)] bg-lightprimary font-semibold text-[var(--destaque)]' : 'border-transparent text-[var(--sidebar-foreground)] hover:bg-[var(--surface-border)]/30'" title="Horizon">
                                <i class="fas fa-layer-group w-8 text-center text-sm" :class="urlPath === '/admin/filas' ? 'text-[var(--destaque)]' : 'text-[var(--icone-menu)]'"></i>
                                <span v-if="!sidebarCollapsed">Horizon</span>
                            </Link>
                        </li>
                        <li>
                            <Link href="/" class="flex items-center gap-2 rounded-lg border-l-[3px] border-transparent py-2.5 text-sm font-medium text-[var(--sidebar-foreground)] transition-colors hover:bg-[var(--surface-border)]/30" title="Ver loja">
                                <i class="fas fa-store w-8 text-center text-sm text-[var(--icone-menu)]"></i>
                                <span v-if="!sidebarCollapsed">Ver loja</span>
                            </Link>
                        </li>
                        <li>
                            <button type="button" class="flex w-full items-center gap-2 border-l-[3px] border-transparent py-2.5 text-left text-sm font-medium text-[var(--sidebar-foreground)] hover:text-error" title="Sair" @click="logout">
                                <i class="fas fa-sign-out-alt w-8 text-center text-sm text-[var(--icone-menu)]"></i>
                                <span v-if="!sidebarCollapsed">Sair</span>
                            </button>
                        </li>
                    </ul>
                </div>

                <!-- Sidebar footer: user profile -->
                <div v-if="!sidebarCollapsed" class="mt-auto hidden w-full items-center gap-3 border-t border-[var(--surface-border)] py-4 md:flex">
                    <img v-if="user?.avatar_url" :src="user.avatar_url" class="h-9 w-9 rounded-full object-cover" alt="">
                    <span v-else class="flex h-9 w-9 items-center justify-center rounded-full bg-lightprimary text-sm font-semibold text-primary">
                        {{ user?.initials }}
                    </span>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold">{{ user?.name }}</p>
                        <p class="truncate text-xs capitalize text-slate-400">{{ user?.role }}</p>
                    </div>
                </div>
            </div>
        </nav>

        <div class="relative flex flex-1 flex-col transition-all" :class="sidebarCollapsed ? 'md:ml-20' : 'md:ml-64'">
            <!-- Topbar -->
            <nav class="admin-topo sticky top-0 z-10 flex items-center border-b border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm md:flex-row md:flex-nowrap md:justify-start"
                :class="{ 'admin-topo-fume': topoFlutuando }">
                <div class="mx-auto flex w-full flex-nowrap items-center justify-between gap-2 sm:gap-4 md:px-4">
                    <div class="flex min-w-0 items-center gap-1 sm:gap-2">
                        <!-- Celular: hambúrguer e logo nesta mesma faixa. -->
                        <button type="button" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-[var(--icone-menu)] hover:bg-[var(--surface-muted)] md:hidden"
                            :aria-label="collapseShow === 'hidden' ? 'Abrir menu' : 'Fechar menu'" :aria-expanded="collapseShow !== 'hidden'" aria-controls="menu-admin"
                            @click="collapseShow = collapseShow === 'hidden' ? 'block' : 'hidden'">
                            <i :class="collapseShow === 'hidden' ? 'fas fa-bars' : 'fas fa-xmark'" class="text-lg"></i>
                        </button>
                        <Link href="/admin" class="shrink-0 md:hidden" title="KazaKora">
                            <img :src="marcaLogo" alt="KazaKora" class="h-5 w-auto">
                        </Link>
                        <!-- Voltar (pedido explícito 2026-08-15) — sempre
                        visível (não escondido em telas pequenas, diferente
                        do breadcrumb ao lado), mesmo estilo circular dos
                        outros botões da topbar. Some só na raiz do admin,
                        onde "voltar" não teria destino nenhum. -->
                        <button v-if="page.url !== '/admin'" type="button"
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-slate-500 hover:bg-[var(--surface-muted)] hover:text-primary"
                            title="Voltar" aria-label="Voltar" @click="goBack">
                            <i class="fas fa-arrow-left"></i>
                        </button>

                        <!-- Breadcrumb -->
                        <div class="hidden items-center gap-2 text-sm lg:flex">
                            <span class="text-slate-400">Painel</span>
                            <i v-if="activeItem" class="fas fa-chevron-right text-[10px] text-slate-300"></i>
                            <span v-if="activeItem" class="font-semibold">{{ activeItem.label }}</span>
                        </div>
                    </div>

                    <!-- Busca do topo desligada a pedido do Lira (2026-10-11): pode
                    ser que um dia ele habilite. Para voltar, é só descomentar.
                    <div class="relative hidden w-full max-w-xs md:block">
                        <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                        <input type="text" placeholder="Buscar..." disabled
                            class="w-full cursor-not-allowed rounded-lg border border-[var(--surface-border)] bg-[var(--surface-muted)] py-2 pl-9 pr-3 text-sm text-slate-400 placeholder:text-slate-400">
                    </div>
                    -->

                    <div class="ml-auto flex items-center gap-1">
                        <!-- Dark mode toggle -->
                        <button type="button" class="flex h-9 w-9 items-center justify-center rounded-full text-slate-500 hover:bg-[var(--surface-muted)] hover:text-primary"
                            title="Alternar tema" :aria-label="isDark ? 'Usar tema claro' : 'Usar tema escuro'" @click="toggleDarkMode">
                            <i :class="isDark ? 'fas fa-sun' : 'fas fa-moon'"></i>
                        </button>

                        <!-- Notifications -->
                        <div ref="notifMenuRef" class="relative">
                            <button type="button" class="relative flex h-9 w-9 items-center justify-center rounded-full text-slate-500 hover:bg-[var(--surface-muted)] hover:text-primary"
                                title="Notificações" :aria-label="`Notificações (${unreadNotifications})`" :aria-expanded="notifMenuOpen" @click="notifMenuOpen = !notifMenuOpen">
                                <i class="fas fa-bell"></i>
                                <span v-if="unreadNotifications > 0" class="absolute right-0.5 top-0.5 rounded-full bg-error px-1 text-[0.6rem] font-semibold leading-tight text-white">{{ unreadNotifications }}</span>
                            </button>

                            <div v-if="notifMenuOpen" class="topo-menu absolute right-0 z-50 mt-2 w-80 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] py-2 text-left shadow-md">
                                <!-- Marcando todas como lidas: desfoque branco + loader até a requisição terminar (2026-10-11). -->
                                <div v-if="marcandoTodas" class="absolute inset-0 z-10 flex items-center justify-center rounded-xl bg-white/60 backdrop-blur-sm dark:bg-slate-900/60">
                                    <i class="fas fa-circle-notch animate-spin text-2xl text-primary"></i>
                                </div>
                                <div class="flex items-center justify-between px-4 py-2">
                                    <h5 class="text-xs font-semibold uppercase tracking-wider text-slate-400">Notificações</h5>
                                    <button v-if="unreadNotifications > 0" type="button" class="text-xs font-medium text-primary hover:underline" @click="markAllNotificationsRead" :disabled="marcandoTodas">
                                        Marcar todas como lidas
                                    </button>
                                </div>

                                <!-- Só mostra não lidas (ver notificationsFor() no backend) —
                                pedido explícito 2026-08-16: "quando visualizadas não aparecem
                                na barra". -->
                                <p v-if="notifications.length === 0" class="px-4 py-6 text-center text-sm text-slate-400">
                                    Nenhuma notificação nova.
                                </p>

                                <!-- Lista com rolagem (2026-10-11): cabeçalho e "Ver todas" ficam fixos. -->
                                <div class="max-h-[60vh] overflow-y-auto overscroll-contain border-y border-[var(--surface-border)]">
                                <div v-for="item in notifications" :key="item.id"
                                    class="group flex w-full items-start gap-2 px-4 py-2.5 text-left text-sm hover:bg-[var(--surface-muted)]">
                                    <button type="button" class="flex min-w-0 flex-1 items-start gap-2 text-left" @click="openNotification(item)">
                                        <span class="mt-1.5 shrink-0 rounded-full"
                                            :class="item.nivel === 'erro' ? 'h-2.5 w-2.5 bg-red-500 ring-2 ring-red-200' : item.nivel === 'sucesso' ? 'h-2.5 w-2.5 bg-green-500 ring-2 ring-green-200' : 'h-1.5 w-1.5 bg-primary'"></span>
                                        <span class="min-w-0">
                                            <span class="block break-words text-slate-700 dark:text-slate-200">{{ item.message }}</span>
                                            <span class="text-xs text-slate-400">{{ item.createdAt }}</span>
                                        </span>
                                    </button>
                                    <button type="button" title="Excluir"
                                        class="shrink-0 rounded-full p-1.5 text-slate-300 opacity-0 hover:bg-error/10 hover:text-error group-hover:opacity-100"
                                        @click.stop="removeNotification(item)">
                                        <i class="fas fa-xmark text-xs"></i>
                                    </button>
                                </div>
                                </div>

                                <Link href="/notificacoes" class="block px-4 py-2 text-center text-xs font-medium text-primary hover:underline"
                                    @click="notifMenuOpen = false">
                                    Ver todas
                                </Link>
                            </div>
                        </div>

                        <div ref="userMenuRef" class="relative ml-1 sm:ml-2">
                            <button type="button" class="flex items-center text-sm" aria-label="Menu do usuário" :aria-expanded="userMenuOpen" @click="userMenuOpen = !userMenuOpen">
                                <img v-if="user?.avatar_url" :src="user.avatar_url" class="mr-2 h-9 w-9 rounded-full object-cover" alt="">
                                <span v-else class="mr-2 inline-flex h-9 w-9 items-center justify-center rounded-full bg-lightprimary text-sm font-semibold text-primary">
                                    {{ user?.initials }}
                                </span>
                                <span class="hidden md:inline">{{ user?.name }}</span>
                                <i class="fas fa-chevron-down ml-2 hidden text-xs text-slate-400 sm:inline-block"></i>
                            </button>
                            <div v-if="userMenuOpen" class="topo-menu absolute right-0 z-50 mt-2 min-w-48 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] py-2 text-left shadow-md">
                                <Link href="/perfil" class="block whitespace-nowrap px-4 py-2 text-sm hover:bg-[var(--surface-muted)]">
                                    <i class="fas fa-user mr-2 text-slate-400"></i> Meu Perfil
                                </Link>
                                <Link href="/configuracoes" class="block whitespace-nowrap px-4 py-2 text-sm hover:bg-[var(--surface-muted)]">
                                    <i class="fas fa-gear mr-2 text-slate-400"></i> Configurações
                                </Link>
                                <hr class="my-1 border-[var(--surface-border)]">
                                <button type="button" class="block w-full whitespace-nowrap bg-transparent px-4 py-2 text-left text-sm hover:bg-[var(--surface-muted)]" @click="logout">
                                    <i class="fas fa-arrow-right-from-bracket mr-2 text-slate-400"></i> Sair
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </nav>

            <div class="admin-conteudo relative mx-auto mt-4 w-full max-w-[1600px] flex-1 p-4 md:p-6" :aria-busy="navegando">
                <slot />

                <!-- Loader de troca de tela: só a área de conteúdo, menu e topo
                continuam usáveis. Spinner preso no meio da janela (sticky)
                mesmo com a página rolada. -->
                <div v-if="navegando" class="absolute inset-0 z-[5] rounded-xl bg-white/60 backdrop-blur-sm dark:bg-slate-900/60" role="status" aria-live="polite">
                    <div class="sticky top-[35vh] flex flex-col items-center gap-3 py-10">
                        <i class="fas fa-circle-notch animate-spin text-4xl text-primary" aria-hidden="true"></i>
                        <span class="text-sm font-medium text-[var(--surface-foreground)]">Carregando…</span>
                    </div>
                </div>
            </div>

            <!-- Rodapé no fluxo normal da página (não mais fixed) — fixo
            cobria o final do conteúdo real em telas com bastante coisa
            (tabelas grandes, formulários longos), dando a impressão de que
            o scroll nunca "chegava no final". Bug real reportado pelo
            usuário 2026-08-05. Em tela curta (Radar de Oportunidades,
            2026-10-12) ele ficava solto no meio da tela: o conteúdo cresce
            (flex-1) e empurra o rodapé para o fim da janela. -->
            <footer class="admin-rodape border-t border-[var(--surface-border)] bg-[var(--surface)]">
                <div class="px-4 py-3 text-center text-sm text-slate-500 md:px-10">
                    © 2026 KazaKora · CNPJ: 65.604.590/0001-07
                </div>
            </footer>
        </div>

        <LowStockAlertModal />
        <WhatsAppToasts v-if="can('pedidos.view')" />
    </div>
</template>
