<script>
// Fora do setup: o AdminLayout remonta a cada navegação do Inertia e o
// último id visto precisa sobreviver, senão some mensagem que chegou no meio.
let lastId = null;
</script>

<script setup>
import { router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

// Avisos de mensagem nova do WhatsApp no admin inteiro (pedido explícito
// 2026-10-08): quem estiver logado vê a mensagem chegando no canto superior
// direito. Vários avisos ficam em coluna; cada um vive 3s desde que chegou e
// some com desfoque, e os de baixo sobem. Se o de baixo também já deu os 3s,
// some junto. Clique abre a conversa. Polling de 3s com a aba visível.

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
        const query = lastId === null ? '' : `?after=${lastId}`;
        const response = await fetch(`/admin/whatsapp/conversas/chegando${query}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) return;

        const data = await response.json();
        const firstRun = lastId === null;
        lastId = data.lastId;

        // Contador do item "Conversas" do menu acompanha em tempo real.
        if (page.props.sidebarBadges) page.props.sidebarBadges.whatsappNaoLidas = data.unreadConversations;

        if (!firstRun && document.visibilityState === 'visible') data.messages.forEach(push);
    } catch {
        // rede caiu: tenta de novo no próximo ciclo
    } finally {
        busy = false;
        if (document.visibilityState === 'visible') timer = setTimeout(poll, POLL_MS);
    }
}

function push(message) {
    if (toasts.value.some((t) => t.id === message.id)) return;
    toasts.value.push(message);
    if (toasts.value.length > MAX_VISIBLE) toasts.value.shift();
    setTimeout(() => dismiss(message.id), LIFETIME_MS);
}

function dismiss(id) {
    toasts.value = toasts.value.filter((t) => t.id !== id);
}

function open(toast) {
    dismiss(toast.id);
    router.visit(`/admin/whatsapp/conversas?c=${toast.conversationId}`);
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
const color = (toast) => COLORS[toast.conversationId % COLORS.length];
</script>

<template>
    <div class="pointer-events-none fixed right-4 top-4 z-[70] w-[min(360px,calc(100vw-2rem))]">
        <TransitionGroup tag="div" name="wa-toast" class="relative flex flex-col gap-2">
            <button
                v-for="toast in toasts"
                :key="toast.id"
                type="button"
                class="wa-toast pointer-events-auto flex w-full items-start gap-3 rounded-xl border border-black/5 bg-white p-3 text-left shadow-lg dark:border-white/5 dark:bg-[#233138]"
                @click="open(toast)"
            >
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-sm font-semibold text-white" :style="{ backgroundColor: color(toast) }">
                    <template v-if="initials(toast)">{{ initials(toast) }}</template>
                    <i v-else class="fas fa-user"></i>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="flex items-center justify-between gap-2">
                        <span class="truncate text-[15px] font-semibold text-[#111b21] dark:text-[#e9edef]">{{ title(toast) }}</span>
                        <span class="flex shrink-0 items-center gap-1 text-[11px] text-[#25d366]"><i class="fab fa-whatsapp"></i> agora</span>
                    </span>
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
