<script>
// Fora do setup: o AdminLayout remonta a cada navegação do Inertia e o
// último id visto precisa sobreviver, senão some mensagem que chegou no meio.
let lastId = null;
let lastEmailId = null;
</script>

<script setup>
import { router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

// Avisos de mensagem nova do WhatsApp no admin inteiro (pedido explícito
// 2026-10-08): quem estiver logado vê a mensagem chegando no canto superior
// direito. Vários avisos ficam em coluna; cada um vive 3s desde que chegou e
// some com desfoque, e os de baixo sobem. Se o de baixo também já deu os 3s,
// some junto. Clique abre a conversa. Polling de 3s com a aba visível.
// Desde 2026-10-10 os e-mails do site (Fale conosco) entram na MESMA fila,
// com o ícone de envelope; clique abre a mensagem em Admin > E-mails do site.

const LIFETIME_MS = 3000;
const POLL_MS = 3000;
const MAX_VISIBLE = 6;

const page = usePage();
const toasts = ref([]);

let timer = null;
let busy = false;

onMounted(() => {
    document.addEventListener('visibilitychange', onVisibility);
    poll();
});

onBeforeUnmount(() => {
    clearTimeout(timer);
    document.removeEventListener('visibilitychange', onVisibility);
});

function onVisibility() {
    if (document.visibilityState === 'visible') {
        clearTimeout(timer);
        poll();
    }
}

async function poll() {
    if (busy) return;
    busy = true;

    try {
        await Promise.all([pollWhatsApp(), pollEmails()]);
    } finally {
        busy = false;
        if (document.visibilityState === 'visible') timer = setTimeout(poll, POLL_MS);
    }
}

async function getJson(url) {
    try {
        const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        return response.ok ? await response.json() : null;
    } catch {
        return null; // rede caiu: tenta de novo no próximo ciclo
    }
}

async function pollWhatsApp() {
    const data = await getJson(`/admin/whatsapp/conversas/chegando${lastId === null ? '' : `?after=${lastId}`}`);
    if (!data) return;

    const firstRun = lastId === null;
    lastId = data.lastId;

    // Contador do item "Conversas" do menu acompanha em tempo real.
    if (page.props.sidebarBadges) page.props.sidebarBadges.whatsappNaoLidas = data.unreadConversations;

    if (!firstRun && document.visibilityState === 'visible') data.messages.forEach((m) => push({ ...m, tipo: 'whatsapp', key: `wa-${m.id}` }));
}

async function pollEmails() {
    const data = await getJson(`/admin/mensagens-site/chegando${lastEmailId === null ? '' : `?after=${lastEmailId}`}`);
    if (!data) return;

    const firstRun = lastEmailId === null;
    lastEmailId = data.lastId;

    if (page.props.sidebarBadges) page.props.sidebarBadges.emailsSiteNaoLidos = data.naoLidas;
    if (firstRun || !data.mensagens.length) return;

    // Na própria caixa de e-mails, a lista se atualiza sozinha.
    if (window.location.pathname === '/admin/mensagens-site') {
        router.reload({ only: ['mensagens', 'naoLidas'], preserveScroll: true });
    }

    if (document.visibilityState === 'visible') {
        data.mensagens.forEach((m) => push({
            tipo: 'email',
            key: `email-${m.id}`,
            id: m.id,
            name: m.nome,
            assunto: m.assunto,
            preview: m.previa,
        }));
    }
}

function push(message) {
    if (toasts.value.some((t) => t.key === message.key)) return;
    toasts.value.push(message);
    if (toasts.value.length > MAX_VISIBLE) toasts.value.shift();
    setTimeout(() => dismiss(message.key), LIFETIME_MS);
}

function dismiss(key) {
    toasts.value = toasts.value.filter((t) => t.key !== key);
}

function open(toast) {
    dismiss(toast.key);
    router.visit(toast.tipo === 'email' ? `/admin/mensagens-site?m=${toast.id}` : `/admin/whatsapp/conversas?c=${toast.conversationId}`);
}

function title(toast) {
    if (toast.name) return toast.name;
    const digits = String(toast.phone ?? '').replace(/\D/g, '');
    const match = digits.match(/^55(\d{2})(\d{4,5})(\d{4})$/);
    return match ? `+55 ${match[1]} ${match[2]}-${match[3]}` : `+${digits}`;
}

function initials(toast) {
    const parts = (toast.name ?? '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return null;
    return ((parts[0][0] ?? '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
}

const COLORS = ['#25d366', '#53bdeb', '#ff8a65', '#a78bfa', '#f472b6', '#fbbf24', '#34d399', '#60a5fa'];
const color = (toast) => (toast.tipo === 'email' ? '#f27a2a' : COLORS[toast.conversationId % COLORS.length]);
</script>

<template>
    <div class="pointer-events-none fixed right-4 top-4 z-[70] w-[min(360px,calc(100vw-2rem))]">
        <TransitionGroup tag="div" name="wa-toast" class="relative flex flex-col gap-2">
            <button
                v-for="toast in toasts"
                :key="toast.key"
                type="button"
                class="wa-toast pointer-events-auto flex w-full items-start gap-3 rounded-xl border border-black/5 bg-white p-3 text-left shadow-lg dark:border-white/5 dark:bg-[#233138]"
                @click="open(toast)"
            >
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-sm font-semibold text-white" :style="{ backgroundColor: color(toast) }">
                    <i v-if="toast.tipo === 'email'" class="fas fa-envelope"></i>
                    <template v-else-if="initials(toast)">{{ initials(toast) }}</template>
                    <i v-else class="fas fa-user"></i>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="flex items-center justify-between gap-2">
                        <span class="truncate text-[15px] font-semibold text-[#111b21] dark:text-[#e9edef]">{{ title(toast) }}</span>
                        <span v-if="toast.tipo === 'email'" class="flex shrink-0 items-center gap-1 text-[11px] text-[#f27a2a]"><i class="fas fa-envelope"></i> e-mail · agora</span>
                        <span v-else class="flex shrink-0 items-center gap-1 text-[11px] text-[#25d366]"><i class="fab fa-whatsapp"></i> agora</span>
                    </span>
                    <span v-if="toast.assunto" class="block truncate text-sm font-medium text-[#111b21] dark:text-[#e9edef]">{{ toast.assunto }}</span>
                    <span class="mt-0.5 line-clamp-2 text-sm text-[#667781] dark:text-[#8696a0]">{{ toast.preview }}</span>
                </span>
            </button>
        </TransitionGroup>
    </div>
</template>

<style scoped>
.wa-toast { transition: transform 0.35s ease, opacity 0.35s ease, filter 0.35s ease; }
.wa-toast-enter-from { opacity: 0; transform: translateX(32px); }
.wa-toast-leave-to { opacity: 0; filter: blur(8px); transform: scale(0.96); }
/* Quem sai fica fora do fluxo pra os de baixo subirem suavemente. */
.wa-toast-leave-active { position: absolute; left: 0; right: 0; }
.wa-toast-move { transition: transform 0.35s ease; }
</style>
