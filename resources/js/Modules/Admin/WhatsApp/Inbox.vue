<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, Link } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// Tela de Conversas do WhatsApp oficial, no layout do WhatsApp Web.
// Atualiza sozinha por polling (3s com a aba visível, 15s escondida) —
// ver WhatsAppInboxController::updates().

const props = defineProps({
    conversations: { type: Array, required: true },
    serverTime: { type: String, required: true },
    status: { type: Object, required: true },
});

const { can } = usePermissions();

const POLL_VISIBLE_MS = 3000;
const POLL_HIDDEN_MS = 15000;

const conversations = ref([...props.conversations]);
const activeId = ref(null);
const messages = ref([]);
const hasMore = ref(false);
const loadingMessages = ref(false);
const loadingOlder = ref(false);
const search = ref('');
const filter = ref('all');
const draft = ref('');
const sending = ref(false);
const actionError = ref(null);
const showNewBelow = ref(false);
const soundOn = ref(readSoundPref());
const connectionLost = ref(false);

const messagesBox = ref(null);
const composer = ref(null);

let since = props.serverTime;
let pollTimer = null;
let polling = false;
let originalTitle = '';

const filters = [
    { key: 'all', label: 'Todas' },
    { key: 'unread', label: 'Não lidas' },
    { key: 'human', label: 'Precisa de humano' },
    { key: 'manuela', label: props.status.attendantName || 'Manuela' },
    { key: 'resolved', label: 'Encerradas' },
];

const active = computed(() => conversations.value.find((c) => c.id === activeId.value) ?? null);

const sortedConversations = computed(() =>
    [...conversations.value].sort((a, b) => (b.lastMessageAt ?? '').localeCompare(a.lastMessageAt ?? '') || b.id - a.id),
);

const visibleConversations = computed(() => {
    const term = search.value.trim().toLowerCase();
    const digits = term.replace(/\D/g, '');

    return sortedConversations.value.filter((c) => {
        if (filter.value === 'unread' && !c.unread) return false;
        if (filter.value === 'human' && !c.needsHuman) return false;
        if (filter.value === 'manuela' && (!c.aiEnabled || c.status === 'resolved')) return false;
        if (filter.value === 'resolved' && c.status !== 'resolved') return false;
        if (filter.value !== 'resolved' && filter.value !== 'all' && c.status === 'resolved') return false;
        if (!term) return true;

        return (c.name ?? '').toLowerCase().includes(term)
            || (digits && (c.phone ?? '').includes(digits))
            || (c.preview ?? '').toLowerCase().includes(term);
    });
});

const totalUnread = computed(() => conversations.value.filter((c) => c.unread > 0).length);

// Mensagens agrupadas por dia, com o "chip" de data igual ao WhatsApp.
const timeline = computed(() => {
    const items = [];
    let lastDay = null;

    for (const message of messages.value) {
        const day = message.at ? message.at.slice(0, 10) : null;
        const localDay = message.at ? new Date(message.at).toDateString() : null;
        if (localDay && localDay !== lastDay) {
            items.push({ kind: 'day', key: `d-${day}-${message.id}`, label: dayLabel(message.at) });
            lastDay = localDay;
        }
        items.push({ kind: 'message', key: `m-${message.id}`, message });
    }

    return items;
});

const composerBlocked = computed(() => {
    if (!active.value) return null;
    if (!props.status.readyToSend) return 'Credenciais da Meta ainda não configuradas: a mensagem fica salva aqui, mas não é entregue.';
    if (!active.value.insideWindow) return 'Fora da janela de 24h da Meta: o cliente precisa escrever primeiro (ou use um modelo aprovado em Disparos).';
    return null;
});

watch(totalUnread, (count) => {
    document.title = count ? `(${count}) ${originalTitle}` : originalTitle;
});

onMounted(() => {
    originalTitle = document.title.replace(/^\(\d+\)\s*/, '');
    const fromUrl = Number(new URLSearchParams(window.location.search).get('c'));
    if (fromUrl && conversations.value.some((c) => c.id === fromUrl)) openConversation(fromUrl);
    document.addEventListener('visibilitychange', onVisibility);
    schedulePoll(POLL_VISIBLE_MS);
});

onBeforeUnmount(() => {
    clearTimeout(pollTimer);
    document.removeEventListener('visibilitychange', onVisibility);
    document.title = originalTitle;
});

function onVisibility() {
    if (document.visibilityState === 'visible') {
        clearTimeout(pollTimer);
        poll();
    }
}

function schedulePoll(ms) {
    clearTimeout(pollTimer);
    pollTimer = setTimeout(poll, ms);
}

async function poll() {
    if (polling) return;
    polling = true;

    try {
        const params = new URLSearchParams({ since });
        if (activeId.value) params.set('conversation', activeId.value);

        const data = await request(`/admin/whatsapp/conversas/atualizacoes?${params}`);
        since = data.serverTime;
        connectionLost.value = false;

        let newInbound = false;
        for (const conversation of data.conversations) {
            const previous = conversations.value.find((c) => c.id === conversation.id);
            if (conversation.unread > (previous?.unread ?? 0) && conversation.id !== activeId.value) newInbound = true;
            upsertConversation(conversation);
        }

        if (data.messages.length && activeId.value) {
            const nearBottom = isNearBottom();
            const added = mergeMessages(data.messages);
            if (added.some((m) => m.direction === 'inbound')) {
                if (document.visibilityState !== 'visible') newInbound = true;
                markRead(activeId.value);
            }
            if (added.length) {
                if (nearBottom) scrollToBottom();
                else showNewBelow.value = true;
            }
        }

        if (newInbound) beep();
    } catch {
        connectionLost.value = true;
    } finally {
        polling = false;
        schedulePoll(document.visibilityState === 'visible' ? POLL_VISIBLE_MS : POLL_HIDDEN_MS);
    }
}

function upsertConversation(conversation) {
    const index = conversations.value.findIndex((c) => c.id === conversation.id);
    if (index === -1) conversations.value.push(conversation);
    else conversations.value[index] = { ...conversations.value[index], ...conversation };

    // Conversa aberta não acumula "não lida" na lista.
    if (conversation.id === activeId.value && conversation.unread > 0 && document.visibilityState === 'visible') {
        const current = conversations.value.find((c) => c.id === conversation.id);
        current.unread = 0;
    }
}

// Junta mensagens novas/atualizadas (status ✓ → ✓✓ → azul) por id.
function mergeMessages(incoming) {
    const added = [];
    for (const message of incoming) {
        const index = messages.value.findIndex((m) => m.id === message.id);
        if (index === -1) {
            messages.value.push(message);
            added.push(message);
        } else {
            messages.value[index] = message;
        }
    }
    if (added.length) messages.value.sort((a, b) => a.id - b.id);
    return added;
}

async function openConversation(id) {
    if (activeId.value === id) return;

    activeId.value = id;
    messages.value = [];
    hasMore.value = false;
    draft.value = '';
    actionError.value = null;
    showNewBelow.value = false;
    loadingMessages.value = true;

    const url = new URL(window.location.href);
    url.searchParams.set('c', id);
    window.history.replaceState(window.history.state, '', url);

    try {
        const data = await request(`/admin/whatsapp/conversas/${id}/mensagens`);
        if (activeId.value !== id) return;
        messages.value = data.messages;
        hasMore.value = data.hasMore;
        upsertConversation(data.conversation);
        await scrollToBottom();
        markRead(id);
        nextTick(() => composer.value?.focus());
    } catch {
        actionError.value = 'Não consegui carregar essa conversa. Tente de novo.';
    } finally {
        loadingMessages.value = false;
    }
}

function closeConversation() {
    activeId.value = null;
    const url = new URL(window.location.href);
    url.searchParams.delete('c');
    window.history.replaceState(window.history.state, '', url);
}

async function loadOlder() {
    if (!messages.value.length || loadingOlder.value) return;
    loadingOlder.value = true;
    const box = messagesBox.value;
    const previousHeight = box?.scrollHeight ?? 0;

    try {
        const id = activeId.value;
        const data = await request(`/admin/whatsapp/conversas/${id}/mensagens?before=${messages.value[0].id}`);
        if (activeId.value !== id) return;
        messages.value = [...data.messages, ...messages.value];
        hasMore.value = data.hasMore;
        await nextTick();
        if (box) box.scrollTop = box.scrollHeight - previousHeight;
    } finally {
        loadingOlder.value = false;
    }
}

async function markRead(id) {
    const conversation = conversations.value.find((c) => c.id === id);
    if (conversation) conversation.unread = 0;
    try {
        await request(`/admin/whatsapp/conversas/${id}/lida`, { method: 'POST' });
    } catch {
        // Sem problema: o próximo poll acerta o contador.
    }
}

async function send() {
    const body = draft.value.trim();
    if (!body || sending.value || !active.value) return;

    sending.value = true;
    actionError.value = null;
    const id = active.value.id;

    try {
        const data = await request(`/admin/whatsapp/conversas/${id}/mensagens`, { method: 'POST', body: { body } });
        draft.value = '';
        resizeComposer();
        mergeMessages([data.message]);
        upsertConversation(data.conversation);
        scrollToBottom();
        if (data.message.status === 'failed') actionError.value = 'A Meta recusou a mensagem. Passe o mouse no ícone vermelho pra ver o motivo.';
    } catch (error) {
        actionError.value = error.message || 'Não foi possível enviar.';
    } finally {
        sending.value = false;
        nextTick(() => composer.value?.focus());
    }
}

async function toggleManuela() {
    if (!active.value) return;
    try {
        const data = await request(`/admin/whatsapp/conversas/${active.value.id}/manuela`, {
            method: 'POST',
            body: { ai_enabled: !active.value.aiEnabled },
        });
        upsertConversation(data.conversation);
    } catch (error) {
        actionError.value = error.message;
    }
}

async function toggleResolved() {
    if (!active.value) return;
    try {
        const data = await request(`/admin/whatsapp/conversas/${active.value.id}/status`, {
            method: 'POST',
            body: { status: active.value.status === 'resolved' ? 'open' : 'resolved' },
        });
        upsertConversation(data.conversation);
    } catch (error) {
        actionError.value = error.message;
    }
}

function onComposerKey(event) {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        send();
    }
}

function resizeComposer() {
    nextTick(() => {
        const el = composer.value;
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = `${Math.min(el.scrollHeight, 140)}px`;
    });
}

function isNearBottom() {
    const box = messagesBox.value;
    if (!box) return true;
    return box.scrollHeight - box.scrollTop - box.clientHeight < 120;
}

async function scrollToBottom() {
    await nextTick();
    const box = messagesBox.value;
    if (box) box.scrollTop = box.scrollHeight;
    showNewBelow.value = false;
}

function onMessagesScroll() {
    if (isNearBottom()) showNewBelow.value = false;
}

async function request(url, { method = 'GET', body } = {}) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            ...(body ? { 'Content-Type': 'application/json' } : {}),
            ...(method !== 'GET' ? { 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
    });

    if (!response.ok) {
        let message = `Erro ${response.status}`;
        try {
            const data = await response.json();
            message = data.message || message;
        } catch {
            // resposta sem JSON
        }
        if (response.status === 419) message = 'Sessão expirada. Recarregue a página.';
        throw new Error(message);
    }

    return response.json();
}

function cookie(name) {
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
    return match ? decodeURIComponent(match[1]) : '';
}

function readSoundPref() {
    try {
        return localStorage.getItem('kz-wa-sound') !== '0';
    } catch {
        return true;
    }
}

function toggleSound() {
    soundOn.value = !soundOn.value;
    try {
        localStorage.setItem('kz-wa-sound', soundOn.value ? '1' : '0');
    } catch {
        // navegador sem storage
    }
}

let audioContext = null;
function beep() {
    if (!soundOn.value) return;
    try {
        audioContext ??= new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = audioContext.createOscillator();
        const gain = audioContext.createGain();
        oscillator.type = 'sine';
        oscillator.frequency.setValueAtTime(880, audioContext.currentTime);
        oscillator.frequency.setValueAtTime(1320, audioContext.currentTime + 0.09);
        gain.gain.setValueAtTime(0.0001, audioContext.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.15, audioContext.currentTime + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, audioContext.currentTime + 0.25);
        oscillator.connect(gain).connect(audioContext.destination);
        oscillator.start();
        oscillator.stop(audioContext.currentTime + 0.26);
    } catch {
        // navegador bloqueou áudio antes de interação
    }
}

const AVATAR_COLORS = ['#25d366', '#53bdeb', '#ff8a65', '#a78bfa', '#f472b6', '#fbbf24', '#34d399', '#60a5fa'];

function avatarColor(conversation) {
    return AVATAR_COLORS[(conversation?.id ?? 0) % AVATAR_COLORS.length];
}

function initials(conversation) {
    const name = (conversation?.name ?? '').trim();
    if (!name) return null;
    const parts = name.split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
}

function displayName(conversation) {
    return conversation?.name || formatPhone(conversation?.phone);
}

function formatPhone(phone) {
    const digits = String(phone ?? '').replace(/\D/g, '');
    const match = digits.match(/^55(\d{2})(\d{4,5})(\d{4})$/);
    return match ? `+55 ${match[1]} ${match[2]}-${match[3]}` : (digits ? `+${digits}` : 'Sem número');
}

function listTime(iso) {
    if (!iso) return '';
    const date = new Date(iso);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);

    if (date.toDateString() === today.toDateString()) return hourMinute(iso);
    if (date.toDateString() === yesterday.toDateString()) return 'Ontem';
    if (today - date < 6 * 86400000) return date.toLocaleDateString('pt-BR', { weekday: 'long' });
    return date.toLocaleDateString('pt-BR');
}

function dayLabel(iso) {
    const label = listTime(iso);
    if (/^\d{2}:\d{2}$/.test(label)) return 'Hoje';
    return label.charAt(0).toUpperCase() + label.slice(1);
}

function hourMinute(iso) {
    return iso ? new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) : '';
}

function tick(message) {
    switch (message.status) {
        case 'read': return { icon: 'fas fa-check-double', class: 'text-[#53bdeb]', title: 'Lida' };
        case 'delivered': return { icon: 'fas fa-check-double', class: 'text-[#8696a0]', title: 'Entregue' };
        case 'sent': return { icon: 'fas fa-check', class: 'text-[#8696a0]', title: 'Enviada' };
        case 'failed': return { icon: 'fas fa-circle-exclamation', class: 'text-red-500', title: message.error || 'Falhou' };
        case 'draft_no_token': return { icon: 'far fa-clock', class: 'text-amber-500', title: 'Não enviada: falta o token da Meta' };
        default: return { icon: 'far fa-clock', class: 'text-[#8696a0]', title: 'Enviando' };
    }
}

function mediaUrl(message) {
    return `/admin/whatsapp/conversas/midia/${message.id}`;
}

function linkify(text) {
    const escaped = String(text ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
    return escaped
        .replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer" class="text-[#027eb5] underline dark:text-[#53bdeb]">$1</a>')
        .replace(/\*([^*\n]+)\*/g, '<strong>$1</strong>')
        .replace(/_([^_\n]+)_/g, '<em>$1</em>');
}
</script>

<template>
    <Head title="Conversas" />

    <AdminLayout>
        <div class="wa-shell flex h-[calc(100dvh-10.5rem)] min-h-[520px] overflow-hidden rounded-2xl border border-[var(--surface-border)] shadow-sm">
            <!-- Lista de conversas -->
            <aside
                class="wa-panel flex w-full min-w-0 flex-col border-r md:w-[380px] md:shrink-0"
                :class="active ? 'hidden md:flex' : 'flex'"
            >
                <header class="wa-header flex h-[60px] shrink-0 items-center justify-between px-4">
                    <div class="flex min-w-0 items-center gap-2">
                        <i class="fab fa-whatsapp text-2xl text-[#25d366]"></i>
                        <h1 class="truncate text-lg font-semibold">Conversas</h1>
                    </div>
                    <div class="flex items-center gap-1">
                        <button
                            type="button"
                            class="wa-icon-btn"
                            :title="soundOn ? 'Som de nova mensagem ligado' : 'Som desligado'"
                            @click="toggleSound"
                        >
                            <i :class="soundOn ? 'fas fa-bell' : 'fas fa-bell-slash'"></i>
                        </button>
                        <Link v-if="can('*')" href="/admin/whatsapp" class="wa-icon-btn" title="Configurações do WhatsApp">
                            <i class="fas fa-gear"></i>
                        </Link>
                    </div>
                </header>

                <div v-if="!status.readyToSend || !status.enabled || !status.autoReply" class="shrink-0 space-y-1 px-3 pt-2 text-xs">
                    <p v-if="!status.readyToSend" class="rounded-lg bg-amber-100 px-3 py-2 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                        <i class="fas fa-key mr-1"></i> Falta o token da Meta: dá pra ver e responder aqui, mas nada é entregue ainda.
                    </p>
                    <p v-else-if="!status.enabled || !status.autoReply" class="rounded-lg bg-sky-100 px-3 py-2 text-sky-800 dark:bg-sky-500/10 dark:text-sky-300">
                        <i class="fas fa-robot mr-1"></i> {{ status.attendantName }} está desligada nas configurações: só humanos respondem.
                    </p>
                </div>

                <div class="shrink-0 px-3 pb-2 pt-2">
                    <label class="wa-search flex items-center gap-3 rounded-lg px-3 py-1.5">
                        <i class="fas fa-magnifying-glass text-sm text-[#54656f] dark:text-[#aebac1]"></i>
                        <input
                            v-model="search"
                            type="search"
                            placeholder="Pesquisar nome, número ou mensagem"
                            class="w-full border-0 bg-transparent p-0 text-sm focus:ring-0"
                        >
                    </label>
                    <div class="mt-2 flex gap-2 overflow-x-auto pb-1">
                        <button
                            v-for="item in filters"
                            :key="item.key"
                            type="button"
                            class="shrink-0 rounded-full px-3 py-1 text-xs font-medium transition"
                            :class="filter === item.key ? 'bg-[#d9fdd3] text-[#008069] dark:bg-[#0a332c] dark:text-[#25d366]' : 'wa-chip'"
                            @click="filter = item.key"
                        >
                            {{ item.label }}
                        </button>
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto">
                    <button
                        v-for="conversation in visibleConversations"
                        :key="conversation.id"
                        type="button"
                        class="wa-row flex w-full items-center gap-3 px-3 text-left"
                        :class="{ 'wa-row-active': conversation.id === activeId }"
                        @click="openConversation(conversation.id)"
                    >
                        <span
                            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full text-sm font-semibold text-white"
                            :style="{ backgroundColor: avatarColor(conversation) }"
                        >
                            <template v-if="initials(conversation)">{{ initials(conversation) }}</template>
                            <i v-else class="fas fa-user"></i>
                        </span>
                        <span class="wa-row-body flex min-w-0 flex-1 flex-col justify-center gap-0.5 py-3">
                            <span class="flex items-baseline justify-between gap-2">
                                <span class="truncate text-[15px] font-medium">{{ displayName(conversation) }}</span>
                                <span
                                    class="shrink-0 text-xs"
                                    :class="conversation.unread ? 'font-semibold text-[#25d366]' : 'text-[#667781] dark:text-[#8696a0]'"
                                >{{ listTime(conversation.lastMessageAt) }}</span>
                            </span>
                            <span class="flex items-center justify-between gap-2">
                                <span class="flex min-w-0 items-center gap-1 text-sm text-[#667781] dark:text-[#8696a0]">
                                    <i v-if="conversation.previewDirection === 'outbound'" class="fas fa-check-double shrink-0 text-xs"></i>
                                    <span class="truncate">{{ conversation.preview }}</span>
                                </span>
                                <span class="flex shrink-0 items-center gap-1.5">
                                    <i v-if="conversation.needsHuman" class="fas fa-hand text-xs text-amber-500" title="Precisa de humano"></i>
                                    <i v-else-if="conversation.aiEnabled && conversation.status !== 'resolved'" class="fas fa-robot text-xs text-[#8696a0]" :title="`${status.attendantName} atendendo`"></i>
                                    <span
                                        v-if="conversation.unread"
                                        class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-[#25d366] px-1.5 text-[11px] font-semibold text-white dark:text-[#111b21]"
                                    >{{ conversation.unread }}</span>
                                </span>
                            </span>
                        </span>
                    </button>

                    <p v-if="!visibleConversations.length" class="px-6 py-12 text-center text-sm text-[#667781] dark:text-[#8696a0]">
                        {{ conversations.length ? 'Nenhuma conversa nesse filtro.' : 'Nenhuma conversa ainda. Quando um cliente escrever no WhatsApp oficial, aparece aqui na hora.' }}
                    </p>
                </div>
            </aside>

            <!-- Chat -->
            <section class="relative min-w-0 flex-1 flex-col" :class="active ? 'flex' : 'hidden md:flex'">
                <template v-if="active">
                    <header class="wa-header flex h-[60px] shrink-0 items-center gap-3 px-3">
                        <button type="button" class="wa-icon-btn md:hidden" title="Voltar" @click="closeConversation">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <span
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold text-white"
                            :style="{ backgroundColor: avatarColor(active) }"
                        >
                            <template v-if="initials(active)">{{ initials(active) }}</template>
                            <i v-else class="fas fa-user"></i>
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium">{{ displayName(active) }}</p>
                            <p class="truncate text-xs text-[#667781] dark:text-[#8696a0]">
                                {{ formatPhone(active.phone) }}
                                <span v-if="active.needsHuman" class="ml-1 font-semibold text-amber-600 dark:text-amber-400">· precisa de humano</span>
                                <span v-if="active.status === 'resolved'" class="ml-1">· encerrada</span>
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex shrink-0 items-center gap-2 rounded-full px-3 py-1.5 text-xs font-semibold transition"
                            :class="active.aiEnabled
                                ? 'bg-[#d9fdd3] text-[#008069] hover:bg-[#c5f5bd] dark:bg-[#0a332c] dark:text-[#25d366]'
                                : 'bg-slate-200 text-slate-700 hover:bg-slate-300 dark:bg-[#2a3942] dark:text-[#d1d7db]'"
                            :title="active.aiEnabled ? 'Clique pra assumir a conversa' : `Clique pra devolver pra ${status.attendantName}`"
                            @click="toggleManuela"
                        >
                            <i :class="active.aiEnabled ? 'fas fa-robot' : 'fas fa-headset'"></i>
                            <span class="hidden sm:inline">{{ active.aiEnabled ? `${status.attendantName} atendendo` : 'Você atendendo' }}</span>
                        </button>
                        <button
                            type="button"
                            class="wa-icon-btn"
                            :title="active.status === 'resolved' ? 'Reabrir conversa' : 'Encerrar conversa'"
                            @click="toggleResolved"
                        >
                            <i :class="active.status === 'resolved' ? 'fas fa-rotate-left' : 'fas fa-circle-check'"></i>
                        </button>
                    </header>

                    <div ref="messagesBox" class="wa-wallpaper min-h-0 flex-1 overflow-y-auto px-[4%] py-3" @scroll.passive="onMessagesScroll">
                        <div v-if="hasMore" class="mb-3 flex justify-center">
                            <button type="button" class="wa-day-chip text-xs" :disabled="loadingOlder" @click="loadOlder">
                                {{ loadingOlder ? 'Carregando…' : 'Carregar mensagens anteriores' }}
                            </button>
                        </div>
                        <p v-if="loadingMessages" class="py-10 text-center text-sm text-[#667781]">
                            <i class="fas fa-circle-notch fa-spin mr-1"></i> Carregando conversa…
                        </p>

                        <template v-for="item in timeline" :key="item.key">
                            <div v-if="item.kind === 'day'" class="my-3 flex justify-center">
                                <span class="wa-day-chip text-xs">{{ item.label }}</span>
                            </div>
                            <div
                                v-else
                                class="mb-1 flex"
                                :class="item.message.direction === 'outbound' ? 'justify-end' : 'justify-start'"
                            >
                                <div
                                    class="wa-bubble relative max-w-[85%] rounded-lg px-2 pb-1.5 pt-1.5 text-[14.2px] leading-[19px] shadow-sm sm:max-w-[65%]"
                                    :class="item.message.direction === 'outbound' ? 'wa-bubble-out' : 'wa-bubble-in'"
                                >
                                    <p
                                        v-if="item.message.direction === 'outbound' && item.message.sentBy"
                                        class="mb-0.5 text-[12px] font-semibold"
                                        :class="item.message.sentBy === 'manuela' ? 'text-[#008069] dark:text-[#25d366]' : 'text-[#7f66ff] dark:text-[#a78bfa]'"
                                    >
                                        <i v-if="item.message.sentBy === 'manuela'" class="fas fa-robot mr-1"></i>{{ item.message.sentBy === 'manuela' ? status.attendantName : item.message.sentBy }}
                                    </p>

                                    <template v-if="item.message.hasMedia">
                                        <a v-if="item.message.type === 'image' || item.message.type === 'sticker'" :href="mediaUrl(item.message)" target="_blank" rel="noopener">
                                            <img :src="mediaUrl(item.message)" loading="lazy" alt="Imagem recebida" class="mb-1 max-h-80 rounded-md object-contain" :class="item.message.type === 'sticker' ? 'w-32' : 'w-full'">
                                        </a>
                                        <audio v-else-if="item.message.type === 'audio'" :src="mediaUrl(item.message)" controls preload="none" class="mb-1 w-64 max-w-full"></audio>
                                        <video v-else-if="item.message.type === 'video'" :src="mediaUrl(item.message)" controls preload="metadata" class="mb-1 max-h-80 w-full rounded-md"></video>
                                        <a v-else :href="mediaUrl(item.message)" target="_blank" rel="noopener" class="mb-1 flex items-center gap-2 rounded-md bg-black/5 px-3 py-2 dark:bg-white/5">
                                            <i class="fas fa-file-lines text-2xl text-[#8696a0]"></i>
                                            <span class="truncate text-sm">{{ item.message.fileName || 'Documento' }}</span>
                                        </a>
                                    </template>
                                    <p v-else-if="!['text', 'button', 'interactive', 'template'].includes(item.message.type)" class="italic text-[#667781] dark:text-[#8696a0]">
                                        <i class="fas fa-paperclip mr-1"></i> Mensagem do tipo "{{ item.message.type }}"
                                    </p>

                                    <span
                                        v-if="item.message.body && !(item.message.hasMedia && item.message.type === 'document')"
                                        class="whitespace-pre-wrap break-words"
                                        v-html="linkify(item.message.body)"
                                    ></span>
                                    <span class="wa-meta float-right ml-2 mt-1.5 flex translate-y-1 items-center gap-1 text-[11px] text-[#667781] dark:text-[#8696a0]">
                                        {{ hourMinute(item.message.at) }}
                                        <i
                                            v-if="item.message.direction === 'outbound'"
                                            :class="[tick(item.message).icon, tick(item.message).class]"
                                            :title="tick(item.message).title"
                                            class="text-[11px]"
                                        ></i>
                                    </span>
                                </div>
                            </div>
                        </template>
                    </div>

                    <button
                        v-if="showNewBelow"
                        type="button"
                        class="absolute bottom-24 right-6 flex h-10 w-10 items-center justify-center rounded-full bg-white text-[#54656f] shadow-md dark:bg-[#202c33] dark:text-[#aebac1]"
                        title="Novas mensagens"
                        @click="scrollToBottom"
                    >
                        <i class="fas fa-chevron-down"></i>
                        <span class="absolute -right-1 -top-1 h-3 w-3 rounded-full bg-[#25d366]"></span>
                    </button>

                    <footer class="wa-header shrink-0 px-3 py-2">
                        <p v-if="composerBlocked" class="mb-2 rounded-md bg-amber-100 px-3 py-1.5 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                            <i class="fas fa-triangle-exclamation mr-1"></i> {{ composerBlocked }}
                        </p>
                        <p v-if="actionError" class="mb-2 rounded-md bg-red-100 px-3 py-1.5 text-xs text-red-700 dark:bg-red-500/10 dark:text-red-300">
                            {{ actionError }}
                        </p>
                        <p v-else-if="active.aiEnabled" class="mb-2 text-center text-xs text-[#667781] dark:text-[#8696a0]">
                            {{ status.attendantName }} está respondendo. Se você mandar uma mensagem, assume a conversa.
                        </p>
                        <form class="flex items-end gap-2" @submit.prevent="send">
                            <textarea
                                ref="composer"
                                v-model="draft"
                                rows="1"
                                placeholder="Digite uma mensagem"
                                class="wa-input max-h-[140px] min-h-[42px] flex-1 resize-none rounded-lg border-0 px-3 py-2.5 text-[15px] focus:ring-0"
                                @keydown="onComposerKey"
                                @input="resizeComposer"
                            ></textarea>
                            <button
                                type="submit"
                                class="flex h-[42px] w-[42px] shrink-0 items-center justify-center rounded-full bg-[#00a884] text-white transition hover:bg-[#008f72] disabled:opacity-50"
                                :disabled="!draft.trim() || sending"
                                title="Enviar (Enter)"
                            >
                                <i :class="sending ? 'fas fa-circle-notch fa-spin' : 'fas fa-paper-plane'"></i>
                            </button>
                        </form>
                    </footer>
                </template>

                <div v-else class="wa-empty flex flex-1 flex-col items-center justify-center gap-4 border-b-[6px] border-[#25d366] px-8 text-center">
                    <span class="flex h-24 w-24 items-center justify-center rounded-full bg-[#25d366]/10">
                        <i class="fab fa-whatsapp text-5xl text-[#25d366]"></i>
                    </span>
                    <h2 class="text-2xl font-light">WhatsApp {{ status.brandName }}</h2>
                    <p class="max-w-md text-sm text-[#667781] dark:text-[#8696a0]">
                        Escolha uma conversa à esquerda. As mensagens chegam sozinhas, sem recarregar a página.
                        {{ status.manuelaRemote ? `A ${status.attendantName} da equipe da Naia responde enquanto ninguém assume.` : `A ${status.attendantName} responde pelas regras locais até o Hermes ser conectado.` }}
                    </p>
                    <p class="text-xs text-[#8696a0]">
                        <i class="fas fa-circle mr-1 text-[8px]" :class="connectionLost ? 'text-red-500' : 'text-[#25d366]'"></i>
                        {{ connectionLost ? 'Sem conexão com o servidor, tentando de novo…' : 'Atualizando em tempo real' }}
                    </p>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>

<style scoped>
.wa-shell { background: #ffffff; color: #111b21; }
.wa-panel { background: #ffffff; border-color: #e9edef; }
.wa-header { background: #f0f2f5; color: #111b21; }
.wa-search, .wa-chip { background: #f0f2f5; color: #54656f; }
.wa-search input { color: #111b21; }
.wa-row { transition: background-color 0.12s; }
.wa-row:hover { background: #f5f6f6; }
.wa-row-active, .wa-row-active:hover { background: #f0f2f5; }
.wa-row-body { border-bottom: 1px solid #e9edef; }
.wa-icon-btn { display: inline-flex; height: 2.25rem; width: 2.25rem; align-items: center; justify-content: center; border-radius: 9999px; color: #54656f; }
.wa-icon-btn:hover { background: rgba(11, 20, 26, 0.08); }
.wa-input { background: #ffffff; color: #111b21; }
.wa-empty { background: #f0f2f5; color: #41525d; }
.wa-day-chip { background: #ffffff; color: #54656f; border-radius: 7.5px; padding: 5px 12px; box-shadow: 0 1px 0.5px rgba(11, 20, 26, 0.13); }
.wa-bubble-in { background: #ffffff; color: #111b21; border-top-left-radius: 0; }
.wa-bubble-out { background: #d9fdd3; color: #111b21; border-top-right-radius: 0; }
.wa-bubble-in::before, .wa-bubble-out::before { content: ''; position: absolute; top: 0; width: 8px; height: 13px; }
.wa-bubble-in::before { left: -8px; background: inherit; clip-path: polygon(100% 0, 0 0, 100% 100%); }
.wa-bubble-out::before { right: -8px; background: inherit; clip-path: polygon(0 0, 100% 0, 0 100%); }
.wa-wallpaper {
    background-color: #efeae2;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='80' height='80' viewBox='0 0 80 80'%3E%3Cg fill='none' stroke='%23d6cfc4' stroke-width='1.2' opacity='.55'%3E%3Ccircle cx='14' cy='16' r='5'/%3E%3Cpath d='M52 10l6 6-6 6-6-6z'/%3E%3Cpath d='M20 54c4-6 10-6 14 0'/%3E%3Ccircle cx='62' cy='58' r='3'/%3E%3Cpath d='M40 34h8M44 30v8'/%3E%3C/g%3E%3C/svg%3E");
}
</style>

<style>
/* Modo escuro do admin (classe .dark no html), cores do WhatsApp Web. Fora do
   scoped porque :global(.dark) .x compila só pra .dark e pintaria a página toda. */
.dark .wa-shell { background: #111b21; color: #e9edef; }
.dark .wa-panel { background: #111b21; border-color: #222d34; }
.dark .wa-header { background: #202c33; color: #e9edef; }
.dark .wa-search, .dark .wa-chip { background: #202c33; color: #aebac1; }
.dark .wa-search input { color: #e9edef; }
.dark .wa-row:hover { background: #202c33; }
.dark .wa-row-active, .dark .wa-row-active:hover { background: #2a3942; }
.dark .wa-row-body { border-color: #222d34; }
.dark .wa-icon-btn { color: #aebac1; }
.dark .wa-icon-btn:hover { background: rgba(255, 255, 255, 0.08); }
.dark .wa-input { background: #2a3942; color: #e9edef; }
.dark .wa-empty { background: #222e35; color: #e9edef; }
.dark .wa-day-chip { background: #182229; color: #8696a0; }
.dark .wa-bubble-in { background: #202c33; color: #e9edef; }
.dark .wa-bubble-out { background: #005c4b; color: #e9edef; }
.dark .wa-wallpaper { background-color: #0b141a; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='80' height='80' viewBox='0 0 80 80'%3E%3Cg fill='none' stroke='%23ffffff' stroke-width='1.2' opacity='.05'%3E%3Ccircle cx='14' cy='16' r='5'/%3E%3Cpath d='M52 10l6 6-6 6-6-6z'/%3E%3Cpath d='M20 54c4-6 10-6 14 0'/%3E%3Ccircle cx='62' cy='58' r='3'/%3E%3Cpath d='M40 34h8M44 30v8'/%3E%3C/g%3E%3C/svg%3E"); }
</style>
